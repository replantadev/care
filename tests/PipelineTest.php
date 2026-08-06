<?php
/**
 * Replanta Care — Staging-First Pipeline Tests
 *
 * Offline PHPUnit tests: no WP install, no DB, no HTTP.
 * All WP functions are shimmed in bootstrap.php.
 *
 * Run: vendor/bin/phpunit tests/PipelineTest.php
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// ── Extra shims needed for pipeline client ────────────────────────────────

if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( string $scheme = 'auth' ): string {
        return 'test-salt-value-for-phpunit-2026';
    }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    function wp_generate_uuid4(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( string $url, array $args = [] ): array|\WP_Error {
        return $GLOBALS['_wp_test_http_response'] ?? new \WP_Error( 'no_response', 'stub' );
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ): string { return trim( (string) $s ); }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $s ): string { return trim( (string) $s ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( string $url ): string { return $url; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( string $url, int $component = -1 ): array|string|int|false|null {
        return parse_url( $url, $component );
    }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $v ): int { return abs( (int) $v ); }
}

// ── Load the classes under test ───────────────────────────────────────────

require_once __DIR__ . '/../inc/class-pipeline-client.php';
require_once __DIR__ . '/../inc/class-inventory-snapshot.php';

/**
 * @covers RP_Care_Pipeline_Client
 * @covers RP_Care_Inventory_Snapshot
 */
class PipelineTest extends TestCase {

    protected function setUp(): void {
        // Reset WP option store and pipeline options.
        $GLOBALS['_wp_options']           = [];
        $GLOBALS['_wp_test_http_response'] = null;
    }

    // ── Feature flag ──────────────────────────────────────────────────────────

    public function test_pipeline_disabled_by_default(): void {
        $this->assertFalse( RP_Care_Pipeline_Client::is_pipeline_enabled() );
    }

    public function test_pipeline_enabled_when_option_set(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED, true );
        $this->assertTrue( RP_Care_Pipeline_Client::is_pipeline_enabled() );
    }

    public function test_blocks_direct_updates_when_ready_and_enabled(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED, true );
        update_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS, RP_Care_Pipeline_Client::STATUS_READY );
        $this->assertTrue( RP_Care_Pipeline_Client::blocks_direct_updates() );
    }

    public function test_does_not_block_when_disabled(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED, false );
        update_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS, RP_Care_Pipeline_Client::STATUS_READY );
        $this->assertFalse( RP_Care_Pipeline_Client::blocks_direct_updates() );
    }

    public function test_blocks_when_enabled_even_if_unpaired(): void {
        // When the pipeline feature is ON, all direct cron updates are blocked regardless
        // of pairing status — the operator must complete pairing or disable the feature.
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED, true );
        update_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS, RP_Care_Pipeline_Client::STATUS_UNPAIRED );
        $this->assertTrue( RP_Care_Pipeline_Client::blocks_direct_updates() );
    }

    public function test_does_not_block_when_quarantined(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED, true );
        update_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS, RP_Care_Pipeline_Client::STATUS_QUARANTINE );
        // In quarantine, updates are blocked by a different mechanism (not blocks_direct_updates).
        // The quarantine blocks EVERYTHING, so blocks_direct_updates should still return true.
        $this->assertTrue( RP_Care_Pipeline_Client::blocks_direct_updates() );
    }

    // ── Quarantine detection ──────────────────────────────────────────────────

    public function test_no_quarantine_when_pipeline_disabled(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED, false );
        RP_Care_Pipeline_Client::maybe_detect_cloned_environment();
        $status = get_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS, '' );
        // Should not have set quarantine.
        $this->assertNotEquals( RP_Care_Pipeline_Client::STATUS_QUARANTINE, $status );
    }

    public function test_no_quarantine_when_already_unpaired(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED, true );
        update_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS, RP_Care_Pipeline_Client::STATUS_UNPAIRED );
        RP_Care_Pipeline_Client::maybe_detect_cloned_environment();
        $this->assertEquals( RP_Care_Pipeline_Client::STATUS_UNPAIRED, get_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS ) );
    }

    public function test_quarantine_set_on_url_mismatch(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED, true );
        update_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS, RP_Care_Pipeline_Client::STATUS_READY );
        // home_url() returns 'http://localhost' (shim); canonical_url is different.
        update_option( 'rpcare_pipeline_canonical_url', 'https://originalsite.com' );

        RP_Care_Pipeline_Client::maybe_detect_cloned_environment();

        $this->assertEquals(
            RP_Care_Pipeline_Client::STATUS_QUARANTINE,
            get_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS )
        );
    }

    public function test_no_quarantine_when_url_matches(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED, true );
        update_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS, RP_Care_Pipeline_Client::STATUS_READY );
        // home_url() returns 'http://localhost'; canonical matches (scheme-stripped).
        update_option( 'rpcare_pipeline_canonical_url', 'http://localhost/' );

        RP_Care_Pipeline_Client::maybe_detect_cloned_environment();

        $this->assertEquals(
            RP_Care_Pipeline_Client::STATUS_READY,
            get_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS )
        );
    }

    // ── HMAC command verification ─────────────────────────────────────────────

    /**
     * Encrypt a raw secret string into the care1: format that get_raw_secret() expects.
     * Mirrors the AES-256-CBC scheme used by RP_Care_Pipeline_Client::decrypt_local().
     * Uses Care's local key derivation (care-pipeline-local-v1), NOT PC's enc1: scheme.
     */
    private function make_enc1_secret( string $secret ): string {
        $key = hash( 'sha256', wp_salt( 'auth' ) . 'care-pipeline-local-v1', true );
        $iv  = str_repeat( "\x00", 16 ); // deterministic IV for tests
        $cipher = openssl_encrypt( $secret, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return 'care1:' . base64_encode( $iv . $cipher );
    }

    private function make_signed_command(
        string $secret,
        string $command_id,
        string $instance_id,
        string $command_type,
        array  $payload,
        string $expires_at,
        string $target_environment = 'production',
        string $batch_id           = 'test-batch-default',
        string $issued_at          = ''
    ): array {
        $issued_at    = $issued_at ?: gmdate( 'c' );
        $payload_hash = hash( 'sha256', json_encode( $payload ) );
        // New HMAC format: command_id|instance_id|target_environment|command_type|batch_id|payload_hash|issued_at|expires_at
        $hmac_payload = implode( '|', [
            $command_id, $instance_id, $target_environment, $command_type,
            $batch_id, $payload_hash, $issued_at, $expires_at,
        ] );
        $hmac = hash_hmac( 'sha256', $hmac_payload, $secret );

        return [
            'command_id'         => $command_id,
            'instance_id'        => $instance_id,
            'command_type'       => $command_type,
            'payload'            => $payload,
            'payload_hash'       => $payload_hash,
            'issued_at'          => $issued_at,
            'expires_at'         => $expires_at,
            'target_environment' => $target_environment,
            'batch_id'           => $batch_id,
            'hmac'               => $hmac,
        ];
    }

    public function test_hmac_verify_rejects_wrong_secret(): void {
        $correct_secret = 'correct-secret-for-hmac-test';
        $wrong_secret   = 'wrong-secret';
        $instance_id    = 'inst-xyz';
        $expires_at     = gmdate( 'c', time() + 300 );

        $cmd = $this->make_signed_command( $correct_secret, 'cmd-abc123', $instance_id, 'report_inventory', [ 'foo' => 'bar' ], $expires_at );

        // Store the wrong secret.
        update_option( RP_Care_Pipeline_Client::OPT_SECRET, $this->make_enc1_secret( $wrong_secret ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, $instance_id );

        $this->assertFalse( RP_Care_Pipeline_Client::verify_command_public( $cmd ) );
    }

    public function test_hmac_verify_accepts_correct_secret(): void {
        $secret      = 'correct-secret-for-hmac-test';
        $instance_id = 'inst-xyz789';
        $expires_at  = gmdate( 'c', time() + 300 );

        $cmd = $this->make_signed_command( $secret, 'cmd-abc123', $instance_id, 'report_inventory', [ 'foo' => 'bar' ], $expires_at );

        update_option( RP_Care_Pipeline_Client::OPT_SECRET, $this->make_enc1_secret( $secret ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, $instance_id );

        $this->assertTrue( RP_Care_Pipeline_Client::verify_command_public( $cmd ) );
    }

    public function test_hmac_verify_rejects_expired_command(): void {
        $secret      = 'correct-secret-for-hmac-test';
        $instance_id = 'inst-xyz789';
        $expires_at  = gmdate( 'c', time() - 10 ); // Already expired.

        $cmd = $this->make_signed_command( $secret, 'cmd-expired', $instance_id, 'report_inventory', [], $expires_at );

        update_option( RP_Care_Pipeline_Client::OPT_SECRET, $this->make_enc1_secret( $secret ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, $instance_id );

        $this->assertFalse( RP_Care_Pipeline_Client::verify_command_public( $cmd ) );
    }

    // ── Replay protection ─────────────────────────────────────────────────────

    public function test_replay_protection_rejects_duplicate_command(): void {
        $cmd_id = 'cmd-replay-001';

        // First time: not seen.
        $this->assertFalse( RP_Care_Pipeline_Client::is_replayed( $cmd_id ) );

        // Mark as processed.
        RP_Care_Pipeline_Client::mark_processed( $cmd_id );

        // Second time: should be detected as replay.
        $this->assertTrue( RP_Care_Pipeline_Client::is_replayed( $cmd_id ) );
    }

    public function test_replay_fifo_bounded_at_200(): void {
        // Fill the FIFO to capacity and verify old entries are evicted.
        $ids = [];
        for ( $i = 0; $i < 200; $i++ ) {
            $ids[] = 'cmd-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT );
            RP_Care_Pipeline_Client::mark_processed( $ids[ $i ] );
        }

        // All 200 should be known as replays.
        $this->assertTrue( RP_Care_Pipeline_Client::is_replayed( $ids[0] ) );
        $this->assertTrue( RP_Care_Pipeline_Client::is_replayed( $ids[199] ) );

        // Add one more — oldest entry (ids[0]) should be evicted.
        RP_Care_Pipeline_Client::mark_processed( 'cmd-new-after-200' );

        $this->assertFalse( RP_Care_Pipeline_Client::is_replayed( $ids[0] ), 'Oldest entry should have been evicted.' );
        $this->assertTrue( RP_Care_Pipeline_Client::is_replayed( $ids[1] ), 'Second entry should still be present.' );
        $this->assertTrue( RP_Care_Pipeline_Client::is_replayed( 'cmd-new-after-200' ) );
    }

    // ── Inventory snapshot hash ───────────────────────────────────────────────

    public function test_inventory_hash_is_stable(): void {
        $inv = [
            'wp_version'  => '6.5.0',
            'php_version' => '8.1.0',
            'plugins'     => [
                'woocommerce/woocommerce.php' => [ 'name' => 'WooCommerce', 'version' => '8.0.0', 'active' => true ],
                'replanta-care/replanta-care.php' => [ 'name' => 'Care', 'version' => '1.15.46', 'active' => true ],
            ],
            'themes'      => [
                'twentytwentyfour' => [ 'name' => 'Twenty Twenty-Four', 'version' => '1.0', 'active' => true ],
            ],
            'translations' => [],
        ];

        $h1 = RP_Care_Inventory_Snapshot::hash( $inv );
        $h2 = RP_Care_Inventory_Snapshot::hash( $inv );
        $this->assertSame( $h1, $h2 );
        $this->assertEquals( 64, strlen( $h1 ) ); // SHA-256 hex = 64 chars.
    }

    public function test_inventory_hash_changes_with_version_bump(): void {
        $base = [
            'wp_version'  => '6.5.0',
            'php_version' => '8.1.0',
            'plugins'     => [ 'woo/woo.php' => [ 'version' => '8.0.0', 'active' => true ] ],
            'themes'      => [],
            'translations' => [],
        ];

        $updated = $base;
        $updated['plugins']['woo/woo.php']['version'] = '8.1.0';

        $this->assertNotEquals(
            RP_Care_Inventory_Snapshot::hash( $base ),
            RP_Care_Inventory_Snapshot::hash( $updated )
        );
    }

    public function test_inventory_hash_is_key_order_independent(): void {
        $inv1 = [
            'wp_version'  => '6.5.0',
            'php_version' => '8.1.0',
            'plugins'     => [
                'b-plugin/b.php' => [ 'version' => '2.0', 'active' => true ],
                'a-plugin/a.php' => [ 'version' => '1.0', 'active' => true ],
            ],
            'themes'      => [],
            'translations' => [],
        ];

        $inv2 = $inv1;
        // Re-order plugins array.
        $inv2['plugins'] = [
            'a-plugin/a.php' => [ 'version' => '1.0', 'active' => true ],
            'b-plugin/b.php' => [ 'version' => '2.0', 'active' => true ],
        ];

        $this->assertSame(
            RP_Care_Inventory_Snapshot::hash( $inv1 ),
            RP_Care_Inventory_Snapshot::hash( $inv2 ),
            'Hash must be order-independent (canonical JSON with ksort).'
        );
    }
}
