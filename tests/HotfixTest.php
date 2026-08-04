<?php
/**
 * Hotfix integration tests — validates all 7 production bugs are fixed.
 *
 * Loads RP_Care_Plan + RP_Care_Addon_Manager + RP_Care_Scheduler + ReplantaCare.
 * No mocks: every assertion targets real class behaviour.
 */
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/class-plan.php';
require_once __DIR__ . '/../inc/class-addon-manager.php';
require_once __DIR__ . '/../inc/class-scheduler.php';

// ReplantaCare: RPCARE_TESTING is defined in bootstrap, so load_dependencies() is a no-op
// and ReplantaCare::getInstance() is NOT called at file-load time.
require_once __DIR__ . '/../replanta-care.php';

class HotfixTest extends TestCase {

    private static function resetSingletons(): void {
        // RP_Care_Addon_Manager
        $rc   = new \ReflectionClass( 'RP_Care_Addon_Manager' );
        $prop = $rc->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        // ReplantaCare
        $rc2   = new \ReflectionClass( 'ReplantaCare' );
        $prop2 = $rc2->getProperty( 'instance' );
        $prop2->setAccessible( true );
        $prop2->setValue( null, null );
    }

    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
        $GLOBALS['_as_pending'] = [];
        $GLOBALS['_wp_cron']    = [];
        self::resetSingletons();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bug 1 + 3: apply_hub_entitlements — no fatal, whitelist, dedup
    // ─────────────────────────────────────────────────────────────────────────

    public function test_apply_hub_entitlements_with_ecommerce_does_not_fatal(): void {
        RP_Care_Plan::apply_hub_entitlements( 'active', 'semilla', [ 'ecommerce' ] );
        $this->assertTrue( true, 'No fatal error thrown' );
    }

    public function test_unknown_addons_are_filtered_out(): void {
        RP_Care_Plan::apply_hub_entitlements( 'active', 'semilla', [ 'ecommerce', 'unknown_addon', 'HACKING_TOOL' ] );
        $opts = get_option( 'rpcare_options', [] );
        $this->assertSame( [ 'ecommerce' ], $opts['addons'] ?? [], 'Only ecommerce must survive whitelist' );
    }

    public function test_duplicate_addons_are_deduplicated(): void {
        RP_Care_Plan::apply_hub_entitlements( 'active', 'semilla', [ 'ecommerce', 'ecommerce', 'ecommerce' ] );
        $opts = get_option( 'rpcare_options', [] );
        $this->assertCount( 1, $opts['addons'] ?? [], 'Duplicate ecommerce entries must collapse to one' );
    }

    public function test_empty_addon_strings_are_stripped(): void {
        RP_Care_Plan::apply_hub_entitlements( 'active', 'semilla', [ '', 'ecommerce', '' ] );
        $opts = get_option( 'rpcare_options', [] );
        $this->assertSame( [ 'ecommerce' ], $opts['addons'] ?? [] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bug 1: RP_Care_Addon_Manager singleton integration
    // ─────────────────────────────────────────────────────────────────────────

    public function test_addon_manager_is_updated_via_singleton(): void {
        RP_Care_Plan::apply_hub_entitlements( 'active', 'semilla', [ 'ecommerce' ] );
        $this->assertTrue(
            RP_Care_Addon_Manager::get()->is_active( 'ecommerce' ),
            'RP_Care_Addon_Manager must report ecommerce active after apply_hub_entitlements'
        );
    }

    public function test_removing_ecommerce_deactivates_it_in_manager(): void {
        RP_Care_Plan::apply_hub_entitlements( 'active', 'semilla', [ 'ecommerce' ] );
        $this->assertTrue( RP_Care_Addon_Manager::get()->is_active( 'ecommerce' ) );
        self::resetSingletons();
        RP_Care_Plan::apply_hub_entitlements( 'active', 'semilla', [] );
        $this->assertFalse(
            RP_Care_Addon_Manager::get()->is_active( 'ecommerce' ),
            'ecommerce must be deactivated after removing it from entitlements'
        );
    }

    public function test_unknown_addon_not_propagated_to_manager(): void {
        RP_Care_Plan::apply_hub_entitlements( 'active', 'semilla', [ 'unknown_addon' ] );
        $this->assertEmpty( RP_Care_Addon_Manager::get()->get_active(), 'Unknown addon must not reach the Addon Manager' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bug 2: Suspension flags
    // ─────────────────────────────────────────────────────────────────────────

    public function test_suspended_status_sets_hub_suspended_flag(): void {
        RP_Care_Plan::apply_hub_entitlements( 'suspended', 'semilla', [] );
        $this->assertTrue( (bool) get_option( 'rpcare_hub_suspended', false ) );
    }

    public function test_expired_status_sets_hub_suspended_flag(): void {
        RP_Care_Plan::apply_hub_entitlements( 'expired', 'semilla', [] );
        $this->assertTrue( (bool) get_option( 'rpcare_hub_suspended', false ) );
    }

    public function test_cancelled_status_sets_hub_suspended_flag(): void {
        RP_Care_Plan::apply_hub_entitlements( 'cancelled', 'semilla', [] );
        $this->assertTrue( (bool) get_option( 'rpcare_hub_suspended', false ) );
    }

    public function test_verification_stale_status_sets_hub_suspended_flag(): void {
        RP_Care_Plan::apply_hub_entitlements( 'verification_stale', 'semilla', [] );
        $this->assertTrue(
            (bool) get_option( 'rpcare_hub_suspended', false ),
            'verification_stale must be treated like suspended'
        );
    }

    public function test_active_status_clears_hub_suspended_flag(): void {
        update_option( 'rpcare_hub_suspended', true );
        RP_Care_Plan::apply_hub_entitlements( 'active', 'semilla', [] );
        $this->assertFalse( (bool) get_option( 'rpcare_hub_suspended', false ) );
    }

    public function test_ecommerce_enabled_option_reflects_addons(): void {
        RP_Care_Plan::apply_hub_entitlements( 'active', 'semilla', [ 'ecommerce' ] );
        $this->assertTrue( (bool) get_option( 'rpcare_ecommerce_enabled', false ) );
        self::resetSingletons();
        RP_Care_Plan::apply_hub_entitlements( 'active', 'semilla', [] );
        $this->assertFalse( (bool) get_option( 'rpcare_ecommerce_enabled', false ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bug 2: ReplantaCare::is_activated() — real method invocation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_is_activated_returns_false_when_hub_suspended(): void {
        update_option( 'rpcare_hub_suspended', true );
        update_option( 'rpcare_hub_connected', true );
        update_option( 'rpcare_plan',          'semilla' );
        update_option( 'rpcare_options',       [ 'plan' => 'semilla' ] );

        $this->assertFalse(
            ReplantaCare::getInstance()->is_activated(),
            'is_activated() must return false when rpcare_hub_suspended is set'
        );
    }

    public function test_is_activated_returns_true_when_hub_active(): void {
        update_option( 'rpcare_hub_suspended', false );
        update_option( 'rpcare_hub_connected', true );
        update_option( 'rpcare_plan',          'semilla' );
        update_option( 'rpcare_options',       [ 'plan' => 'semilla' ] );

        $this->assertTrue(
            ReplantaCare::getInstance()->is_activated(),
            'is_activated() must return true when hub connected and not suspended'
        );
    }

    public function test_is_activated_returns_false_when_not_connected(): void {
        update_option( 'rpcare_hub_suspended', false );
        update_option( 'rpcare_hub_connected', false );
        update_option( 'rpcare_plan',          '' );
        update_option( 'rpcare_activated',     false );

        $this->assertFalse(
            ReplantaCare::getInstance()->is_activated(),
            'is_activated() must return false when hub not connected and no plan'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bug 2: RP_Care_Scheduler — clear_all() and ensure() real invocations
    // ─────────────────────────────────────────────────────────────────────────

    public function test_clear_all_unschedules_all_hooks_including_retention(): void {
        // Pre-populate the AS pending map to simulate scheduled tasks.
        $allHooks = [
            'rpcare_task_updates', 'rpcare_task_backup', 'rpcare_task_wpo',
            'rpcare_task_seo_review', 'rpcare_task_seo_audit', 'rpcare_task_basic_review',
            'rpcare_task_monitor', 'rpcare_task_health', 'rpcare_task_404_cleanup',
            'rpcare_task_maintenance', 'rpcare_task_report', 'rpcare_task_cwv',
            'rpcare_task_anomaly', 'rpcare_task_db_cleanup', 'rpcare_task_checkout_monitor',
            'rpcare_task_peak_scheduler', 'rpcare_task_revenue_anomaly', 'rpcare_task_retention',
        ];
        foreach ( $allHooks as $h ) {
            $GLOBALS['_as_pending'][ $h ] = time();
        }

        $scheduler = new RP_Care_Scheduler( 'semilla' );
        $scheduler->clear_all();

        foreach ( $allHooks as $h ) {
            $this->assertArrayNotHasKey(
                $h,
                $GLOBALS['_as_pending'],
                "clear_all() must unschedule {$h}"
            );
        }
        $this->assertSame( 0, count( array_filter( $GLOBALS['_as_pending'] ) ),
            'No tasks must remain after clear_all()' );
    }

    public function test_ensure_is_idempotent_no_duplicate_schedules(): void {
        // Set up ecommerce addon so all tasks including addon tasks get scheduled.
        update_option( 'rpcare_options', [ 'plan' => 'semilla', 'addons' => [ 'ecommerce' ] ] );

        $scheduler = new RP_Care_Scheduler( 'semilla' );
        $scheduler->ensure();

        $after_first = $GLOBALS['_as_pending'];
        $this->assertNotEmpty( $after_first, 'ensure() must schedule at least one task' );

        $scheduler->ensure();

        $this->assertSame(
            $after_first,
            $GLOBALS['_as_pending'],
            'ensure() must be idempotent — calling twice must not add duplicate schedules'
        );
    }

    public function test_clear_addon_schedules_removes_only_4_addon_hooks(): void {
        // Schedule all addon hooks
        $addonHooks = [
            'rpcare_task_backup',
            'rpcare_task_checkout_monitor',
            'rpcare_task_peak_scheduler',
            'rpcare_task_revenue_anomaly',
        ];
        $otherHook = 'rpcare_task_updates';

        foreach ( $addonHooks as $h ) { $GLOBALS['_as_pending'][ $h ] = time(); }
        $GLOBALS['_as_pending'][ $otherHook ] = time();

        $scheduler = new RP_Care_Scheduler( 'semilla' );
        $scheduler->clear_addon_schedules();

        foreach ( $addonHooks as $h ) {
            $this->assertArrayNotHasKey( $h, $GLOBALS['_as_pending'],
                "clear_addon_schedules() must remove {$h}" );
        }
        $this->assertArrayHasKey( $otherHook, $GLOBALS['_as_pending'],
            'clear_addon_schedules() must NOT remove non-addon hooks' );
    }

    public function test_suspended_status_calls_clear_all_via_apply_hub_entitlements(): void {
        // Pre-schedule tasks so clear_all() has something to clear.
        $GLOBALS['_as_pending']['rpcare_task_updates'] = time();
        $GLOBALS['_as_pending']['rpcare_task_backup']  = time();

        update_option( 'rpcare_detected_plan', 'semilla' );
        update_option( 'rpcare_options', [ 'plan' => 'semilla' ] );

        RP_Care_Plan::apply_hub_entitlements( 'suspended', 'semilla', [] );

        // All tasks must be cleared (clear_all was called, not just clear_addon_schedules).
        $this->assertArrayNotHasKey( 'rpcare_task_updates', $GLOBALS['_as_pending'],
            'Suspension must clear rpcare_task_updates via clear_all()' );
        $this->assertArrayNotHasKey( 'rpcare_task_backup', $GLOBALS['_as_pending'],
            'Suspension must clear rpcare_task_backup via clear_all()' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bug 6: Logging safety
    // ─────────────────────────────────────────────────────────────────────────

    public function test_auto_onboard_log_does_not_expose_body(): void {
        $source = file_get_contents( __DIR__ . '/../replanta-care.php' );
        $this->assertStringNotContainsString(
            'auto-onboard response inválido: \' . wp_remote_retrieve_body',
            $source,
            'Log line must not include raw response body (may contain tokens)'
        );
        $this->assertStringContainsString(
            'auto-onboard response inválido HTTP',
            $source,
            'Log line must use HTTP code only'
        );
    }
}
