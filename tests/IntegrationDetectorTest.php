<?php
/**
 * Replanta Care — Integration Anomaly Detector Tests
 *
 * ID-01  observe() with unrelated message → no anomaly detected
 * ID-02  observe() with 'commands out of sync' → counter increments
 * ID-03  observe() reaching threshold → anomaly recorded
 * ID-04  anomaly has required keys: family, count, threshold, escalate_at, escalated, detected_at, message_hash, context_keys
 * ID-05  anomaly message_hash is sha256 of the original message (not the message itself)
 * ID-06  anomaly context_keys is list of keys, not values
 * ID-07  escalated=true when count >= escalate_at
 * ID-08  escalated=false when count < escalate_at
 * ID-09  get_active_anomalies() returns active anomaly list
 * ID-10  resolve() removes anomaly from active list
 * ID-11  resolve() resets counter transient
 * ID-12  multiple different families accumulate independently
 * ID-13  upsert: second detection of same family updates count, not duplicates
 * ID-14  NEG: message content is never stored in anomaly (only hash)
 * ID-15  NEG: observe() never auto-disables gateways (no relevant function called)
 *
 * @since 1.15.74
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-rp-care-integration-detector.php';

class IntegrationDetectorTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
        RP_Care_Integration_Detector::reset();
    }

    // ── ID-01: no match → no anomaly ──────────────────────────────────────────

    /** @test */
    public function test_id01_unrelated_message_no_anomaly(): void {
        $detected = RP_Care_Integration_Detector::observe( 'Product saved successfully.' );
        $this->assertEmpty( $detected );
        $this->assertEmpty( RP_Care_Integration_Detector::get_active_anomalies() );
    }

    // ── ID-02: matching message → counter increments ──────────────────────────

    /** @test */
    public function test_id02_matching_message_increments_counter(): void {
        RP_Care_Integration_Detector::observe( 'Commands out of sync — did you run two queries at once?' );
        $this->assertSame( 1, RP_Care_Integration_Detector::get_counter( 'mysql_sync' ) );
    }

    // ── ID-03: reaching threshold → anomaly recorded ──────────────────────────

    /** @test */
    public function test_id03_threshold_reached_records_anomaly(): void {
        $message = 'Commands out of sync error detected';
        for ( $i = 0; $i < 3; $i++ ) {
            RP_Care_Integration_Detector::observe( $message . " #{$i}" );
        }
        $anomalies = RP_Care_Integration_Detector::get_active_anomalies();
        $this->assertNotEmpty( $anomalies );
        $families = array_column( $anomalies, 'family' );
        $this->assertContains( 'mysql_sync', $families );
    }

    // ── ID-04: anomaly required keys ──────────────────────────────────────────

    /** @test */
    public function test_id04_anomaly_has_required_keys(): void {
        for ( $i = 0; $i < 3; $i++ ) {
            RP_Care_Integration_Detector::observe( "Commands out of sync event #{$i}" );
        }
        $anomalies = RP_Care_Integration_Detector::get_active_anomalies();
        $anomaly   = array_values( array_filter( $anomalies, fn( $a ) => $a['family'] === 'mysql_sync' ) )[0];
        foreach ( [ 'family', 'count', 'threshold', 'escalate_at', 'escalated', 'detected_at', 'message_hash', 'context_keys' ] as $key ) {
            $this->assertArrayHasKey( $key, $anomaly, "Missing key: {$key}" );
        }
    }

    // ── ID-05: message_hash is sha256, not the raw message ────────────────────

    /** @test */
    public function test_id05_message_hash_is_sha256_not_raw(): void {
        $message = 'Commands out of sync — unique marker xyz';
        for ( $i = 0; $i < 3; $i++ ) {
            RP_Care_Integration_Detector::observe( "{$message} #{$i}" );
        }
        $anomalies = RP_Care_Integration_Detector::get_active_anomalies();
        $anomaly   = $anomalies[0];
        // message_hash should look like a sha256 hex string.
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $anomaly['message_hash'] );
        // Should not contain the raw message text.
        $this->assertStringNotContainsString( 'Commands out of sync', $anomaly['message_hash'] );
    }

    // ── ID-06: context_keys not values ───────────────────────────────────────

    /** @test */
    public function test_id06_context_keys_not_values(): void {
        $context = [ 'order_id' => 9876, 'password' => 'supersecret' ];
        for ( $i = 0; $i < 3; $i++ ) {
            RP_Care_Integration_Detector::observe( "Commands out of sync #{$i}", $context );
        }
        $anomalies = RP_Care_Integration_Detector::get_active_anomalies();
        $anomaly   = $anomalies[0];
        $this->assertIsArray( $anomaly['context_keys'] );
        $this->assertContains( 'order_id', $anomaly['context_keys'] );
        $this->assertContains( 'password', $anomaly['context_keys'] );
        // Serialized anomaly must not contain the actual secret value.
        $serialized = serialize( $anomaly );
        $this->assertStringNotContainsString( 'supersecret', $serialized );
        $this->assertStringNotContainsString( '9876', $serialized ); // numeric value not stored either
    }

    // ── ID-07: escalated=true when count >= escalate_at ──────────────────────

    /** @test */
    public function test_id07_escalated_when_count_reaches_escalate_at(): void {
        // mysql_sync escalate_at = 5.
        for ( $i = 0; $i < 5; $i++ ) {
            RP_Care_Integration_Detector::observe( "Commands out of sync hit #{$i}" );
        }
        $anomalies = RP_Care_Integration_Detector::get_active_anomalies();
        $anomaly   = array_values( array_filter( $anomalies, fn( $a ) => $a['family'] === 'mysql_sync' ) )[0];
        $this->assertTrue( $anomaly['escalated'] );
    }

    // ── ID-08: escalated=false below escalate_at ─────────────────────────────

    /** @test */
    public function test_id08_not_escalated_below_escalate_at(): void {
        // mysql_sync threshold=3, escalate_at=5 — observe 3 (threshold), not yet at 5.
        for ( $i = 0; $i < 3; $i++ ) {
            RP_Care_Integration_Detector::observe( "Commands out of sync below escalate #{$i}" );
        }
        $anomalies = RP_Care_Integration_Detector::get_active_anomalies();
        $anomaly   = array_values( array_filter( $anomalies, fn( $a ) => $a['family'] === 'mysql_sync' ) )[0];
        $this->assertFalse( $anomaly['escalated'] );
    }

    // ── ID-09: get_active_anomalies ───────────────────────────────────────────

    /** @test */
    public function test_id09_get_active_anomalies_returns_list(): void {
        for ( $i = 0; $i < 3; $i++ ) {
            RP_Care_Integration_Detector::observe( "Commands out of sync result #{$i}" );
        }
        $list = RP_Care_Integration_Detector::get_active_anomalies();
        $this->assertIsArray( $list );
        $this->assertNotEmpty( $list );
    }

    // ── ID-10: resolve() removes anomaly ──────────────────────────────────────

    /** @test */
    public function test_id10_resolve_removes_anomaly(): void {
        for ( $i = 0; $i < 3; $i++ ) {
            RP_Care_Integration_Detector::observe( "Commands out of sync resolve #{$i}" );
        }
        RP_Care_Integration_Detector::resolve( 'mysql_sync' );
        $families = array_column( RP_Care_Integration_Detector::get_active_anomalies(), 'family' );
        $this->assertNotContains( 'mysql_sync', $families );
    }

    // ── ID-11: resolve() resets counter ──────────────────────────────────────

    /** @test */
    public function test_id11_resolve_resets_counter(): void {
        for ( $i = 0; $i < 3; $i++ ) {
            RP_Care_Integration_Detector::observe( "Commands out of sync counter #{$i}" );
        }
        RP_Care_Integration_Detector::resolve( 'mysql_sync' );
        $this->assertSame( 0, RP_Care_Integration_Detector::get_counter( 'mysql_sync' ) );
    }

    // ── ID-12: multiple families independent ─────────────────────────────────

    /** @test */
    public function test_id12_multiple_families_accumulate_independently(): void {
        // Trigger mysql_sync family.
        for ( $i = 0; $i < 3; $i++ ) {
            RP_Care_Integration_Detector::observe( "Commands out of sync family A #{$i}" );
        }
        // Trigger gateway_fail family (threshold=5).
        for ( $i = 0; $i < 5; $i++ ) {
            RP_Care_Integration_Detector::observe( "Payment failed gateway event #{$i}" );
        }
        $anomalies = RP_Care_Integration_Detector::get_active_anomalies();
        $families  = array_column( $anomalies, 'family' );
        $this->assertContains( 'mysql_sync', $families );
        $this->assertContains( 'gateway_fail', $families );
    }

    // ── ID-13: upsert by family ───────────────────────────────────────────────

    /** @test */
    public function test_id13_upsert_updates_not_duplicates(): void {
        for ( $i = 0; $i < 3; $i++ ) {
            RP_Care_Integration_Detector::observe( "Commands out of sync upsert #{$i}" );
        }
        // One more detection — should update, not add a second mysql_sync entry.
        RP_Care_Integration_Detector::observe( "Commands out of sync upsert #3" );
        $anomalies = RP_Care_Integration_Detector::get_active_anomalies();
        $mysql_entries = array_filter( $anomalies, fn( $a ) => $a['family'] === 'mysql_sync' );
        $this->assertCount( 1, $mysql_entries );
    }

    // ── ID-14: message content never stored ──────────────────────────────────

    /** @test */
    public function test_id14_message_content_not_stored_in_anomaly(): void {
        $sensitive = 'Commands out of sync SENSITIVE_SECRET_XYZ-MARKER-1234';
        for ( $i = 0; $i < 3; $i++ ) {
            RP_Care_Integration_Detector::observe( "{$sensitive} #{$i}" );
        }
        $anomalies  = RP_Care_Integration_Detector::get_active_anomalies();
        $serialized = serialize( $anomalies );
        $this->assertStringNotContainsString( 'SENSITIVE_SECRET_XYZ-MARKER-1234', $serialized );
    }

    // ── ID-15: no gateway auto-disable ────────────────────────────────────────

    /** @test */
    public function test_id15_observe_never_auto_disables_gateways(): void {
        // Verify the implementation does not call update_option() with payment gateway settings.
        // We set a watch on update_option results via our stub.
        for ( $i = 0; $i < 10; $i++ ) {
            RP_Care_Integration_Detector::observe( "Payment failed at gateway #{$i}" );
        }
        // Examine stored options — no WooCommerce gateway settings should be modified.
        // (Counter transients like _transient_care_anomaly_gateway_fail are allowed;
        //  what must NOT appear are actual WC gateway settings like woocommerce_*_settings.)
        $stored_keys = array_keys( $GLOBALS['_wp_options'] ?? [] );
        $wc_gateway_keys = array_filter(
            $stored_keys,
            fn( $k ) => preg_match( '/^woocommerce_.*settings/i', $k ) === 1
        );
        $this->assertEmpty( $wc_gateway_keys, 'Integration detector must not modify gateway options' );
    }
}
