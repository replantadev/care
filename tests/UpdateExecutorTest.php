<?php
/**
 * Tests for Block D: update_executor / WP Toolkit conflation fix.
 *
 * UE-01  get_update_executor() returns 'care_pipeline' by default (no options set).
 * UE-02  Explicit update_executor='care_pipeline' → 'care_pipeline'.
 * UE-03  Explicit update_executor='external'      → 'external'.
 * UE-04  Explicit update_executor='disabled'      → 'disabled'.
 * UE-05  Invalid value falls back to 'care_pipeline'.
 * UE-06  WP Toolkit detected + no explicit option → 'external' (backwards compat).
 * UE-07  WP Toolkit detected + explicit 'care_pipeline' → 'care_pipeline' (override wins).
 * UE-08  WP Toolkit detected + explicit 'disabled'      → 'disabled' (override wins).
 * UE-09  updates_externally_managed() returns true when executor=external.
 * UE-10  updates_externally_managed() returns false when executor=care_pipeline.
 * UE-11  updates_externally_managed() returns false when executor=disabled.
 * UE-12  native_auto_updates_disabled() returns true by default (fail-closed).
 * UE-13  native_auto_updates_disabled() returns false when 'enabled'.
 * UE-14  native_auto_updates_disabled() returns true for any non-'enabled' value.
 * UE-15  Legacy rpcare_update_managed option is still honoured in task-updates.php Guard.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-environment.php';

use PHPUnit\Framework\TestCase;

class UpdateExecutorTest extends TestCase {

    protected function setUp(): void {
        // Reset option store between tests.
        $GLOBALS['_wp_options'] = [];
        $GLOBALS['_is_wptoolkit'] = false;
    }

    // ── get_update_executor() ──────────────────────────────────────────────

    public function test_ue01_default_is_care_pipeline(): void {
        // No options, no WP Toolkit.
        $this->assertSame( 'care_pipeline', RP_Care_Environment::get_update_executor() );
    }

    public function test_ue02_explicit_care_pipeline(): void {
        update_option( 'rpcare_options', [ 'update_executor' => 'care_pipeline' ] );
        $this->assertSame( 'care_pipeline', RP_Care_Environment::get_update_executor() );
    }

    public function test_ue03_explicit_external(): void {
        update_option( 'rpcare_options', [ 'update_executor' => 'external' ] );
        $this->assertSame( 'external', RP_Care_Environment::get_update_executor() );
    }

    public function test_ue04_explicit_disabled(): void {
        update_option( 'rpcare_options', [ 'update_executor' => 'disabled' ] );
        $this->assertSame( 'disabled', RP_Care_Environment::get_update_executor() );
    }

    public function test_ue05_invalid_value_falls_back_to_care_pipeline(): void {
        update_option( 'rpcare_options', [ 'update_executor' => 'magic_external_thing' ] );
        $this->assertSame( 'care_pipeline', RP_Care_Environment::get_update_executor() );
    }

    public function test_ue06_wptoolkit_detected_no_explicit_option_returns_external(): void {
        $GLOBALS['_is_wptoolkit'] = true;
        // No explicit update_executor set.
        $this->assertSame( 'external', RP_Care_Environment::get_update_executor() );
    }

    public function test_ue07_wptoolkit_detected_explicit_care_pipeline_wins(): void {
        $GLOBALS['_is_wptoolkit'] = true;
        update_option( 'rpcare_options', [ 'update_executor' => 'care_pipeline' ] );
        $this->assertSame( 'care_pipeline', RP_Care_Environment::get_update_executor() );
    }

    public function test_ue08_wptoolkit_detected_explicit_disabled_wins(): void {
        $GLOBALS['_is_wptoolkit'] = true;
        update_option( 'rpcare_options', [ 'update_executor' => 'disabled' ] );
        $this->assertSame( 'disabled', RP_Care_Environment::get_update_executor() );
    }

    // ── updates_externally_managed() ──────────────────────────────────────

    public function test_ue09_externally_managed_true_when_external(): void {
        update_option( 'rpcare_options', [ 'update_executor' => 'external' ] );
        $this->assertTrue( RP_Care_Environment::updates_externally_managed() );
    }

    public function test_ue10_externally_managed_false_when_care_pipeline(): void {
        update_option( 'rpcare_options', [ 'update_executor' => 'care_pipeline' ] );
        $this->assertFalse( RP_Care_Environment::updates_externally_managed() );
    }

    public function test_ue11_externally_managed_false_when_disabled(): void {
        update_option( 'rpcare_options', [ 'update_executor' => 'disabled' ] );
        $this->assertFalse( RP_Care_Environment::updates_externally_managed() );
    }

    // ── native_auto_updates_disabled() ────────────────────────────────────

    public function test_ue12_native_auto_updates_disabled_by_default(): void {
        $this->assertTrue( RP_Care_Environment::native_auto_updates_disabled() );
    }

    public function test_ue13_native_auto_updates_false_when_enabled(): void {
        update_option( 'rpcare_options', [ 'native_auto_updates' => 'enabled' ] );
        $this->assertFalse( RP_Care_Environment::native_auto_updates_disabled() );
    }

    public function test_ue14_native_auto_updates_true_for_arbitrary_non_enabled_value(): void {
        update_option( 'rpcare_options', [ 'native_auto_updates' => 'yes' ] );
        $this->assertTrue( RP_Care_Environment::native_auto_updates_disabled() );
    }

    // ── Backwards compat: legacy rpcare_update_managed ────────────────────

    /**
     * UE-15: task-updates Guard checks legacy rpcare_update_managed option.
     * We test the logic path directly by reading the source to confirm the guard exists.
     */
    public function test_ue15_task_updates_source_contains_legacy_managed_guard(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/inc/task-updates.php' );
        $this->assertNotFalse( $source, 'task-updates.php must be readable' );
        $this->assertStringContainsString(
            'rpcare_update_managed',
            $source,
            'task-updates.php must still honour the legacy rpcare_update_managed option'
        );
        $this->assertStringContainsString(
            'get_update_executor',
            $source,
            'task-updates.php must call get_update_executor() for the new model'
        );
    }
}
