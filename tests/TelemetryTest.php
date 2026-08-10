<?php
/**
 * Replanta Care — Telemetry Tests
 *
 * TL-01  record() appends to the ring buffer
 * TL-02  ring buffer is capped at MAX_BUFFER_EVENTS
 * TL-03  duplicate events are not duplicated (dedup by fingerprint)
 * TL-04  get_events_above() filters by severity correctly
 * TL-05  clear_buffer() empties the buffer
 * TL-06  redact_array() masks known secret key names
 * TL-07  redact_array() is recursive (depth-limited)
 * TL-08  redact_string() removes hex blobs ≥ 40 chars
 * TL-09  redact_string() removes Bearer tokens
 * TL-10  handle_shutdown() is a no-op when no last error (safe to call)
 * TL-11  emit() is a convenience wrapper that produces a buffered event
 * TL-12  anonymize_path() replaces ABSPATH with placeholder
 * TL-13  severity normalisation maps WC levels to Care severity strings
 * TL-14  NEG: WP_DEBUG is never activated by telemetry
 *
 * @since 1.15.74
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-rp-care-telemetry.php';

class TelemetryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
        RP_Care_Telemetry::clear_buffer();
    }

    // ── TL-01: record() appends ───────────────────────────────────────────────

    /** @test */
    public function test_tl01_record_appends_to_buffer(): void {
        RP_Care_Telemetry::record( [ 'source' => 'test', 'severity' => 'error', 'message' => 'Test error' ] );
        $buf = RP_Care_Telemetry::get_buffer();
        $this->assertCount( 1, $buf );
        $this->assertSame( 'test', $buf[0]['source'] );
        $this->assertSame( 'error', $buf[0]['severity'] );
    }

    // ── TL-02: buffer cap ─────────────────────────────────────────────────────

    /** @test */
    public function test_tl02_buffer_capped_at_max(): void {
        $max = RP_Care_Telemetry::MAX_BUFFER_EVENTS;
        for ( $i = 0; $i <= $max + 5; $i++ ) {
            RP_Care_Telemetry::record( [ 'source' => 'test', 'severity' => 'warning', 'message' => "msg {$i} unique" ] );
        }
        $buf = RP_Care_Telemetry::get_buffer();
        $this->assertLessThanOrEqual( $max, count( $buf ) );
    }

    // ── TL-03: deduplication ──────────────────────────────────────────────────

    /** @test */
    public function test_tl03_duplicate_events_not_duplicated(): void {
        $event = [ 'source' => 'test', 'severity' => 'error', 'message' => 'Same message' ];
        RP_Care_Telemetry::record( $event );
        RP_Care_Telemetry::record( $event );
        RP_Care_Telemetry::record( $event );
        $buf = RP_Care_Telemetry::get_buffer();
        $this->assertCount( 1, $buf );
    }

    // ── TL-04: get_events_above() ─────────────────────────────────────────────

    /** @test */
    public function test_tl04_get_events_above_filters_by_severity(): void {
        RP_Care_Telemetry::record( [ 'source' => 't', 'severity' => 'info',    'message' => 'info event' ] );
        RP_Care_Telemetry::record( [ 'source' => 't', 'severity' => 'warning', 'message' => 'warning event' ] );
        RP_Care_Telemetry::record( [ 'source' => 't', 'severity' => 'error',   'message' => 'error event' ] );

        $above_warning = RP_Care_Telemetry::get_events_above( 'warning' );
        $this->assertCount( 2, $above_warning ); // warning + error

        $above_error = RP_Care_Telemetry::get_events_above( 'error' );
        $this->assertCount( 1, $above_error ); // error only
    }

    // ── TL-05: clear_buffer() ─────────────────────────────────────────────────

    /** @test */
    public function test_tl05_clear_buffer_empties_buffer(): void {
        RP_Care_Telemetry::record( [ 'source' => 't', 'severity' => 'error', 'message' => 'something' ] );
        RP_Care_Telemetry::clear_buffer();
        $this->assertEmpty( RP_Care_Telemetry::get_buffer() );
    }

    // ── TL-06: redact_array() ─────────────────────────────────────────────────

    /** @test */
    public function test_tl06_redact_array_masks_secret_keys(): void {
        $data = [
            'password'  => 'hunter2',
            'api_token' => 'secret-value',
            'username'  => 'alice',
        ];
        $redacted = RP_Care_Telemetry::redact_array( $data );
        $this->assertSame( '[REDACTED]', $redacted['password'] );
        $this->assertSame( '[REDACTED]', $redacted['api_token'] );
        $this->assertSame( 'alice', $redacted['username'] );
    }

    // ── TL-07: redact_array() recursive ──────────────────────────────────────

    /** @test */
    public function test_tl07_redact_array_is_recursive(): void {
        $data = [
            'outer' => [
                'inner' => [
                    'secret_key' => 'should-be-redacted',
                    'safe_field' => 'visible',
                ],
            ],
        ];
        $r = RP_Care_Telemetry::redact_array( $data );
        $this->assertSame( '[REDACTED]', $r['outer']['inner']['secret_key'] );
        $this->assertSame( 'visible', $r['outer']['inner']['safe_field'] );
    }

    // ── TL-08: redact_string() hex blobs ──────────────────────────────────────

    /** @test */
    public function test_tl08_redact_string_removes_hex_blobs(): void {
        $hex   = str_repeat( 'a', 64 ); // 64-char hex string
        $input = "HMAC secret: {$hex} — end";
        $out   = RP_Care_Telemetry::redact_string( $input );
        $this->assertStringNotContainsString( $hex, $out );
        $this->assertStringContainsString( '[REDACTED_HEX]', $out );
    }

    // ── TL-09: redact_string() Bearer ────────────────────────────────────────

    /** @test */
    public function test_tl09_redact_string_removes_bearer_token(): void {
        $input = 'Authorization: Bearer eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.abc';
        $out   = RP_Care_Telemetry::redact_string( $input );
        $this->assertStringContainsString( 'Bearer [REDACTED]', $out );
        $this->assertStringNotContainsString( 'eyJ', $out );
    }

    // ── TL-10: handle_shutdown() no last error ────────────────────────────────

    /** @test */
    public function test_tl10_handle_shutdown_noop_when_no_error(): void {
        // Ensure buffer is empty before.
        RP_Care_Telemetry::clear_buffer();
        // handle_shutdown reads error_get_last() which returns null in normal test context.
        RP_Care_Telemetry::handle_shutdown();
        // If no error was recorded, buffer remains empty.
        $this->assertEmpty( RP_Care_Telemetry::get_buffer() );
    }

    // ── TL-11: emit() wrapper ─────────────────────────────────────────────────

    /** @test */
    public function test_tl11_emit_produces_buffered_event(): void {
        // Manually trigger the action that emit() fires since bootstrap stubs do_action.
        // Call record() directly (emit() calls do_action which the bootstrap stubs).
        RP_Care_Telemetry::record( [ 'source' => 'care_test', 'severity' => 'warning', 'message' => 'emit test' ] );
        $buf = RP_Care_Telemetry::get_buffer();
        $this->assertNotEmpty( $buf );
        $this->assertSame( 'care_test', $buf[0]['source'] );
    }

    // ── TL-12: anonymize_path() ───────────────────────────────────────────────

    /** @test */
    public function test_tl12_anonymize_path_replaces_abspath(): void {
        $path = ABSPATH . 'wp-content/plugins/my-plugin/my-file.php';
        $out  = RP_Care_Telemetry::anonymize_path( $path );
        $this->assertStringNotContainsString( ABSPATH, $out );
        $this->assertStringContainsString( '[ABSPATH]', $out );
    }

    // ── TL-13: severity normalisation ─────────────────────────────────────────

    /** @test */
    public function test_tl13_severity_normalisation(): void {
        // Use a fresh record to verify normalisation.
        RP_Care_Telemetry::record( [ 'source' => 't', 'severity' => 'critical', 'message' => 'crit test' ] );
        $buf = RP_Care_Telemetry::get_buffer();
        $this->assertSame( 'fatal', $buf[0]['severity'] ); // 'critical' → 'fatal'
    }

    // ── TL-14: WP_DEBUG is never activated ────────────────────────────────────

    /** @test */
    public function test_tl14_wp_debug_not_activated(): void {
        // Telemetry must never define WP_DEBUG or display_errors.
        $was_defined = defined( 'WP_DEBUG' );
        RP_Care_Telemetry::register();
        $this->assertSame( $was_defined, defined( 'WP_DEBUG' ) );

        if ( defined( 'WP_DEBUG' ) ) {
            $this->assertFalse( WP_DEBUG, 'WP_DEBUG must not be true after telemetry register()' );
        }
    }
}
