<?php
/**
 * Replanta Care — Woo Policy Registry Tests
 *
 * PR-01  knows() returns true for registered options
 * PR-02  knows() returns false for unknown options
 * PR-03  is_sensible() returns true for payment options
 * PR-04  is_sensible() returns true for checkout page options
 * PR-05  is_autocorrigible() returns true for housekeeping options
 * PR-06  is_autocorrigible() returns false for sensible options (double-safety)
 * PR-07  classification() returns correct CLASS_* string
 * PR-08  by_class() returns only options of the requested class
 * PR-09  assert_write_permitted() returns true for auto-corrigible in enforce_safe mode
 * PR-10  assert_write_permitted() returns WP_Error for sensible options
 * PR-11  assert_write_permitted() returns WP_Error for unknown options
 * PR-12  assert_write_permitted() returns WP_Error in observe mode
 * PR-13  assert_write_permitted() returns WP_Error in approval_required mode
 * PR-14  describe_drift() returns structured event with all required keys
 * PR-15  schema_version() returns an integer >= 1
 * PR-16  NEG: no sensible option is classified as autocorrigible
 *
 * @since 1.15.74
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-rp-care-woo-policy-registry.php';

class WooPolicyRegistryTest extends TestCase {

    // ── PR-01, PR-02: knows() ─────────────────────────────────────────────────

    /** @test */
    public function test_pr01_knows_registered_option(): void {
        $this->assertTrue( RP_Care_Woo_Policy_Registry::knows( 'woocommerce_currency' ) );
        $this->assertTrue( RP_Care_Woo_Policy_Registry::knows( 'woocommerce_queue_flush_rewrite_rules' ) );
    }

    /** @test */
    public function test_pr02_does_not_know_unknown_option(): void {
        $this->assertFalse( RP_Care_Woo_Policy_Registry::knows( 'my_custom_plugin_option' ) );
        $this->assertFalse( RP_Care_Woo_Policy_Registry::knows( '' ) );
    }

    // ── PR-03, PR-04: is_sensible() ───────────────────────────────────────────

    /** @test */
    public function test_pr03_payment_option_is_sensible(): void {
        $this->assertTrue( RP_Care_Woo_Policy_Registry::is_sensible( 'woocommerce_paypal_settings' ) );
        $this->assertTrue( RP_Care_Woo_Policy_Registry::is_sensible( 'woocommerce_gateway_order' ) );
    }

    /** @test */
    public function test_pr04_checkout_page_option_is_sensible(): void {
        $this->assertTrue( RP_Care_Woo_Policy_Registry::is_sensible( 'woocommerce_checkout_page_id' ) );
        $this->assertTrue( RP_Care_Woo_Policy_Registry::is_sensible( 'woocommerce_cart_page_id' ) );
    }

    // ── PR-05, PR-06: is_autocorrigible() ────────────────────────────────────

    /** @test */
    public function test_pr05_housekeeping_option_is_autocorrigible(): void {
        $this->assertTrue( RP_Care_Woo_Policy_Registry::is_autocorrigible( 'woocommerce_queue_flush_rewrite_rules' ) );
    }

    /** @test */
    public function test_pr06_sensible_option_never_autocorrigible(): void {
        // Double-safety: even though sensible options have a different class,
        // is_autocorrigible() must return false for all of them.
        $sensible = RP_Care_Woo_Policy_Registry::by_class( RP_Care_Woo_Policy_Registry::CLASS_SENSIBLE );
        foreach ( $sensible as $option ) {
            $this->assertFalse(
                RP_Care_Woo_Policy_Registry::is_autocorrigible( $option ),
                "Sensible option '{$option}' must not be autocorrigible"
            );
        }
    }

    // ── PR-07: classification() ───────────────────────────────────────────────

    /** @test */
    public function test_pr07_classification_returns_correct_class(): void {
        $this->assertSame(
            RP_Care_Woo_Policy_Registry::CLASS_SENSIBLE,
            RP_Care_Woo_Policy_Registry::classification( 'woocommerce_currency' )
        );
        $this->assertSame(
            RP_Care_Woo_Policy_Registry::CLASS_AUTOCORRIGIBLE,
            RP_Care_Woo_Policy_Registry::classification( 'woocommerce_queue_flush_rewrite_rules' )
        );
        $this->assertNull( RP_Care_Woo_Policy_Registry::classification( 'unknown_option' ) );
    }

    // ── PR-08: by_class() ─────────────────────────────────────────────────────

    /** @test */
    public function test_pr08_by_class_returns_only_requested_class(): void {
        $autocorrigible = RP_Care_Woo_Policy_Registry::by_class( RP_Care_Woo_Policy_Registry::CLASS_AUTOCORRIGIBLE );
        $this->assertIsArray( $autocorrigible );
        $this->assertNotEmpty( $autocorrigible );

        foreach ( $autocorrigible as $option ) {
            $this->assertSame(
                RP_Care_Woo_Policy_Registry::CLASS_AUTOCORRIGIBLE,
                RP_Care_Woo_Policy_Registry::classification( $option )
            );
        }
    }

    // ── PR-09 .. PR-13: assert_write_permitted() ──────────────────────────────

    /** @test */
    public function test_pr09_write_permitted_for_autocorrigible_enforce_safe(): void {
        $result = RP_Care_Woo_Policy_Registry::assert_write_permitted(
            'woocommerce_queue_flush_rewrite_rules',
            RP_Care_Woo_Policy_Registry::MODE_ENFORCE_SAFE,
            false
        );
        $this->assertTrue( $result );
    }

    /** @test */
    public function test_pr10_write_blocked_for_sensible_option(): void {
        $result = RP_Care_Woo_Policy_Registry::assert_write_permitted(
            'woocommerce_currency',
            RP_Care_Woo_Policy_Registry::MODE_ENFORCE_SAFE,
            true
        );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'option_is_sensible', $result->get_error_code() );
    }

    /** @test */
    public function test_pr11_write_blocked_for_unknown_option(): void {
        $result = RP_Care_Woo_Policy_Registry::assert_write_permitted(
            'not_in_the_registry_at_all',
            RP_Care_Woo_Policy_Registry::MODE_ENFORCE_SAFE,
            false
        );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'option_not_in_registry', $result->get_error_code() );
    }

    /** @test */
    public function test_pr12_write_blocked_in_observe_mode(): void {
        $result = RP_Care_Woo_Policy_Registry::assert_write_permitted(
            'woocommerce_queue_flush_rewrite_rules',
            RP_Care_Woo_Policy_Registry::MODE_OBSERVE,
            false
        );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'mode_observe', $result->get_error_code() );
    }

    /** @test */
    public function test_pr13_write_blocked_in_approval_required_mode(): void {
        $result = RP_Care_Woo_Policy_Registry::assert_write_permitted(
            'woocommerce_queue_flush_rewrite_rules',
            RP_Care_Woo_Policy_Registry::MODE_APPROVAL_REQUIRED,
            false
        );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'approval_required', $result->get_error_code() );
    }

    // ── PR-14: describe_drift() ────────────────────────────────────────────────

    /** @test */
    public function test_pr14_describe_drift_returns_required_keys(): void {
        $drift = RP_Care_Woo_Policy_Registry::describe_drift( 'woocommerce_currency', 'EUR', 'USD' );
        $this->assertArrayHasKey( 'option', $drift );
        $this->assertArrayHasKey( 'class', $drift );
        $this->assertArrayHasKey( 'observed', $drift );
        $this->assertArrayHasKey( 'expected', $drift );
        $this->assertArrayHasKey( 'permitted_actions', $drift );
        $this->assertArrayHasKey( 'schema_version', $drift );
        $this->assertArrayHasKey( 'detected_at', $drift );
        $this->assertSame( 'EUR', $drift['observed'] );
        $this->assertSame( 'USD', $drift['expected'] );
        $this->assertContains( 'approval_required', $drift['permitted_actions'] );
        $this->assertNotContains( 'autocorrect', $drift['permitted_actions'] );
    }

    // ── PR-15: schema_version() ───────────────────────────────────────────────

    /** @test */
    public function test_pr15_schema_version_is_integer(): void {
        $this->assertIsInt( RP_Care_Woo_Policy_Registry::schema_version() );
        $this->assertGreaterThanOrEqual( 1, RP_Care_Woo_Policy_Registry::schema_version() );
    }

    // ── PR-16: NEG test — no sensible option is autocorrigible ────────────────

    /** @test */
    public function test_pr16_no_sensible_option_is_autocorrigible(): void {
        $all = RP_Care_Woo_Policy_Registry::all();
        foreach ( $all as $option => $def ) {
            if ( $def['class'] === RP_Care_Woo_Policy_Registry::CLASS_SENSIBLE ) {
                $this->assertFalse(
                    RP_Care_Woo_Policy_Registry::is_autocorrigible( $option ),
                    "Option '{$option}' classified SENSIBLE must not be autocorrigible"
                );
            }
        }
    }
}
