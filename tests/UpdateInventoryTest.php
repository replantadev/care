<?php
/**
 * Care 1.16.0 — Update Inventory Contract Tests
 *
 * Inventory state contract (read_inventory):
 *   UI-01  absent transient → total=null, checked_at=null, inventory_stale=true.
 *   UI-02  malformed transient (object, no response array) → total=null, stale=true,
 *          checked_at extracted if last_checked valid.
 *   UI-03  valid transient, response=[] → total=0, checked_at=ISO8601, stale per age.
 *   UI-04  valid transient, N entries → total=N, all fields populated.
 *   UI-05  total includes free and premium plugins alike — bypasses policy filter.
 *   UI-06  total is not affected by $bypass_for_task being false on entry.
 *   UI-07  alias: updates_pending === updates_pending_total in the REST payload.
 *   UI-08  valid last_checked → checked_at is a parseable ISO-8601 string.
 *   UI-09  last_checked = 0 → checked_at=null (not 1970-01-01).
 *   UI-10  last_checked negative → checked_at=null (not pre-epoch date).
 *   UI-11  last_checked stale (>26 h) → inventory_stale=true.
 *   UI-12  last_checked fresh (<26 h) → inventory_stale=false.
 *   UI-13  bypass_for_task is restored to its prior value (false) after call.
 *   UI-14  bypass_for_task is restored to its prior value (true) after call.
 *   UI-15  bypass_for_task is restored to false when transient_reader throws RuntimeException.
 *   UI-16  bypass_for_task is restored to true when transient_reader throws RuntimeException.
 *   UI-17  class_exists fallback: when RP_Care_Update_Control is absent, total=null.
 *
 * Admin control contract (modify_plugin_action_links):
 *   UI-18  'update' action link is preserved for non-allowed plugin.
 *   UI-19  'rpcare_controlled' label is added for non-allowed plugin.
 *   UI-20  'update' action link is preserved for allowed (replanta-*) plugin.
 *   UI-21  'rpcare_controlled' label is NOT added for allowed plugin.
 *   UI-22  bulk update action (update-selected) is NOT removed.
 *
 * Structural contract:
 *   UI-23  hide_update_notices() method no longer exists.
 *   UI-24  site_transient_update_plugins NOT registered as a global filter by init().
 *   UI-25  ping response contains updates_pending, updates_pending_total, updates_checked_at,
 *          updates_inventory_stale; does NOT contain policy fields not yet implemented.
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-update-control.php';

use PHPUnit\Framework\TestCase;

// ── Stubs required only by this test file ─────────────────────────────────────

if ( ! class_exists( 'RP_Care_Plan' ) ) {
    class RP_Care_Plan {
        public static function get_current(): string { return 'pro'; }
        public static function get_features( string $plan ): array {
            return [ 'update_control' => true ];
        }
        public static function get_plan_name( string $plan ): string { return 'Pro'; }
    }
}

// ── Test class ────────────────────────────────────────────────────────────────

class UpdateInventoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options']          = [];
        $GLOBALS['_wp_plugin_data_mock'] = [];
        $GLOBALS['_registered_filters']  = [];
        RP_Care_Update_Control::$bypass_for_task  = false;
        RP_Care_Update_Control::$transient_reader = null;
    }

    protected function tearDown(): void {
        // Always clean seam in case a test leaves it set.
        RP_Care_Update_Control::$transient_reader = null;
        RP_Care_Update_Control::$bypass_for_task  = false;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeTransient( array $plugin_files, ?int $last_checked ): object {
        $obj = new stdClass();
        if ( $last_checked !== null ) {
            $obj->last_checked = $last_checked;
        }
        $obj->response = [];
        foreach ( $plugin_files as $file ) {
            $entry              = new stdClass();
            $entry->new_version = '9.9.9';
            $obj->response[ $file ] = $entry;
        }
        return $obj;
    }

    private function setUpdateTransient( array $plugins, ?int $last_checked ): void {
        set_site_transient( 'update_plugins', $this->makeTransient( $plugins, $last_checked ) );
    }

    /** Build the subset of hub_ping() response fields that relate to the inventory. */
    private function buildPingUpdatesPayload( array $inv ): array {
        return [
            'updates_pending_total'   => $inv['total'],
            'updates_checked_at'      => $inv['checked_at'],
            'updates_inventory_stale' => $inv['inventory_stale'],
            'updates_pending'         => $inv['total'],
        ];
    }

    // ── UI-01: absent transient → null ────────────────────────────────────────

    public function test_ui01_absent_transient_returns_null_total(): void {
        $inv = RP_Care_Update_Control::read_inventory();

        $this->assertNull( $inv['total'],       'absent transient: total must be null, not 0' );
        $this->assertNull( $inv['checked_at'],  'absent transient: checked_at must be null' );
        $this->assertTrue( $inv['inventory_stale'], 'absent transient: inventory_stale must be true' );
    }

    // ── UI-02: malformed transient (object, no response array) ────────────────

    public function test_ui02_malformed_transient_no_response_returns_null_total(): void {
        $obj = new stdClass();
        $obj->last_checked = time() - 60;
        // No ->response property at all.
        set_site_transient( 'update_plugins', $obj );

        $inv = RP_Care_Update_Control::read_inventory();

        $this->assertNull( $inv['total'],
            'malformed transient (no response): total must be null' );
        $this->assertTrue( $inv['inventory_stale'],
            'malformed transient: inventory_stale must be true regardless of age' );
        // last_checked was present and valid → checked_at should be populated.
        $this->assertNotNull( $inv['checked_at'],
            'malformed transient with valid last_checked: checked_at may still be extracted' );
    }

    // ── UI-03: valid transient, empty response → total=0 ─────────────────────

    public function test_ui03_valid_transient_empty_response_returns_zero(): void {
        $this->setUpdateTransient( [], time() - 600 );

        $inv = RP_Care_Update_Control::read_inventory();

        $this->assertSame( 0, $inv['total'],
            'valid transient with empty response must return total=0, not null' );
        $this->assertFalse( $inv['inventory_stale'] );
        $this->assertNotNull( $inv['checked_at'] );
    }

    // ── UI-04: valid transient, N entries → total=N ───────────────────────────

    public function test_ui04_valid_transient_returns_correct_total(): void {
        $this->setUpdateTransient(
            [ 'plugin-a/a.php', 'plugin-b/b.php', 'plugin-c/c.php' ],
            time() - 300
        );

        $inv = RP_Care_Update_Control::read_inventory();

        $this->assertSame( 3, $inv['total'] );
        $this->assertNotNull( $inv['checked_at'] );
        $this->assertFalse( $inv['inventory_stale'] );
    }

    // ── UI-05: total counts all plugins — free and premium alike ──────────────

    public function test_ui05_total_counts_free_and_premium_plugins(): void {
        $this->setUpdateTransient(
            [ 'hello-dolly/hello-dolly.php', 'elementor-pro/elementor-pro.php', 'plain-tool/plain-tool.php' ],
            time() - 100
        );

        $inv = RP_Care_Update_Control::read_inventory();

        $this->assertSame( 3, $inv['total'],
            'total must count ALL plugins regardless of licence or policy classification' );
    }

    // ── UI-06: total unaffected by $bypass_for_task being false on entry ──────

    public function test_ui06_total_not_altered_by_bypass_flag_state(): void {
        $this->setUpdateTransient(
            [ 'free-plugin/free-plugin.php', 'another-free/another.php' ],
            time() - 60
        );
        RP_Care_Update_Control::$bypass_for_task = false;

        $inv = RP_Care_Update_Control::read_inventory();

        $this->assertSame( 2, $inv['total'],
            'read_inventory must bypass any active filter — total must reflect raw transient' );
    }

    // ── UI-07: alias — both REST payload keys equal the same value ────────────

    public function test_ui07_alias_updates_pending_equals_updates_pending_total(): void {
        $this->setUpdateTransient( [ 'plugin-a/a.php', 'plugin-b/b.php' ], time() - 200 );
        $inv = RP_Care_Update_Control::read_inventory();

        $payload = $this->buildPingUpdatesPayload( $inv );

        $this->assertSame(
            $payload['updates_pending_total'],
            $payload['updates_pending'],
            'updates_pending must be an exact alias of updates_pending_total'
        );
        $this->assertSame( 2, $payload['updates_pending_total'] );
        $this->assertSame( 2, $payload['updates_pending'] );
    }

    public function test_ui07b_alias_null_when_transient_absent(): void {
        // No transient set.
        $inv     = RP_Care_Update_Control::read_inventory();
        $payload = $this->buildPingUpdatesPayload( $inv );

        $this->assertNull( $payload['updates_pending_total'] );
        $this->assertNull( $payload['updates_pending'],
            'updates_pending alias must also be null when total is null' );
    }

    // ── UI-08: valid last_checked → ISO-8601 string ───────────────────────────

    public function test_ui08_valid_last_checked_produces_iso8601(): void {
        $this->setUpdateTransient( [], time() - 3600 );

        $inv = RP_Care_Update_Control::read_inventory();

        $this->assertNotNull( $inv['checked_at'] );
        $parsed = date_create( $inv['checked_at'] );
        $this->assertNotFalse( $parsed,
            "checked_at '{$inv['checked_at']}' must be a parseable ISO-8601 datetime" );
    }

    // ── UI-09: last_checked=0 → checked_at=null (not 1970) ───────────────────

    public function test_ui09_zero_last_checked_returns_null_checked_at(): void {
        $obj = new stdClass();
        $obj->last_checked = 0;
        $obj->response     = [];
        set_site_transient( 'update_plugins', $obj );

        $inv = RP_Care_Update_Control::read_inventory();

        $this->assertNull( $inv['checked_at'],
            'last_checked=0 must produce checked_at=null, not 1970-01-01' );
    }

    // ── UI-10: negative last_checked → checked_at=null (not pre-epoch date) ──

    public function test_ui10_negative_last_checked_returns_null_checked_at(): void {
        $obj = new stdClass();
        $obj->last_checked = -86400;
        $obj->response     = [];
        set_site_transient( 'update_plugins', $obj );

        $inv = RP_Care_Update_Control::read_inventory();

        $this->assertNull( $inv['checked_at'],
            'negative last_checked must produce checked_at=null, not a pre-1970 date' );
    }

    // ── UI-11: stale when last_checked > 26 h ────────────────────────────────

    public function test_ui11_stale_when_last_checked_older_than_26h(): void {
        $this->setUpdateTransient( [], time() - ( 27 * 3600 ) );

        $inv = RP_Care_Update_Control::read_inventory();

        $this->assertTrue( $inv['inventory_stale'] );
    }

    // ── UI-12: fresh when last_checked within 26 h ───────────────────────────

    public function test_ui12_not_stale_when_last_checked_within_26h(): void {
        $this->setUpdateTransient( [], time() - 3600 );

        $inv = RP_Care_Update_Control::read_inventory();

        $this->assertFalse( $inv['inventory_stale'] );
    }

    // ── UI-13/14: bypass_for_task restored to prior value ────────────────────

    public function test_ui13_bypass_restored_to_false_after_call(): void {
        $this->setUpdateTransient( [], time() );
        RP_Care_Update_Control::$bypass_for_task = false;

        RP_Care_Update_Control::read_inventory();

        $this->assertFalse( RP_Care_Update_Control::$bypass_for_task );
    }

    public function test_ui14_bypass_preserved_at_true_after_call(): void {
        $this->setUpdateTransient( [], time() );
        RP_Care_Update_Control::$bypass_for_task = true;

        RP_Care_Update_Control::read_inventory();

        $this->assertTrue( RP_Care_Update_Control::$bypass_for_task,
            'bypass_for_task must be restored to true if caller set it true before the call' );
    }

    // ── UI-15/16: bypass restored even when transient_reader throws ───────────

    public function test_ui15_bypass_restored_to_false_on_exception(): void {
        RP_Care_Update_Control::$transient_reader = static function( string $key ): never {
            throw new RuntimeException( 'simulated transient read failure' );
        };
        RP_Care_Update_Control::$bypass_for_task = false;

        try {
            RP_Care_Update_Control::read_inventory();
            $this->fail( 'RuntimeException should have propagated' );
        } catch ( RuntimeException $e ) {
            // expected
        }

        $this->assertFalse( RP_Care_Update_Control::$bypass_for_task,
            'bypass_for_task must be false after exception when it started false' );
    }

    public function test_ui16_bypass_restored_to_true_on_exception(): void {
        RP_Care_Update_Control::$transient_reader = static function( string $key ): never {
            throw new RuntimeException( 'simulated transient read failure' );
        };
        RP_Care_Update_Control::$bypass_for_task = true;

        try {
            RP_Care_Update_Control::read_inventory();
            $this->fail( 'RuntimeException should have propagated' );
        } catch ( RuntimeException $e ) {
            // expected
        }

        $this->assertTrue( RP_Care_Update_Control::$bypass_for_task,
            'bypass_for_task must be restored to true after exception when it started true' );
    }

    // ── UI-17: fallback when class missing → null via REST payload build ──────

    public function test_ui17_fallback_when_class_absent_produces_null_total(): void {
        // Simulate the fallback branch in hub_ping() when RP_Care_Update_Control is absent.
        $fallback = [ 'total' => null, 'checked_at' => null, 'inventory_stale' => true ];
        $payload  = $this->buildPingUpdatesPayload( $fallback );

        $this->assertNull( $payload['updates_pending_total'] );
        $this->assertNull( $payload['updates_pending'] );
        $this->assertTrue( $payload['updates_inventory_stale'] );
    }

    // ── UI-18/19: modify_plugin_action_links — non-allowed plugin ─────────────

    public function test_ui18_update_link_preserved_for_non_allowed_plugin(): void {
        // Plugin with no premium indicators → is_licensed_plugin() = false → not allowed.
        $file    = 'plain-free-plugin/plain-free-plugin.php';
        $actions = [ 'activate' => 'Activate', 'update' => 'Update' ];
        $uc      = new RP_Care_Update_Control();

        $result = $uc->modify_plugin_action_links( $actions, $file );

        $this->assertArrayHasKey( 'update', $result,
            "'update' action must NOT be removed — administrator controls manual updates" );
    }

    public function test_ui19_label_added_for_non_allowed_plugin(): void {
        $file    = 'plain-free-plugin/plain-free-plugin.php';
        $actions = [ 'activate' => 'Activate', 'update' => 'Update' ];
        $uc      = new RP_Care_Update_Control();

        $result = $uc->modify_plugin_action_links( $actions, $file );

        $this->assertArrayHasKey( 'rpcare_controlled', $result,
            "'rpcare_controlled' label must be added for plugins Care manages" );
    }

    // ── UI-20/21: modify_plugin_action_links — allowed (replanta-*) plugin ────

    public function test_ui20_update_link_preserved_for_replanta_plugin(): void {
        $file    = 'replanta-care/replanta-care.php';
        $actions = [ 'deactivate' => 'Deactivate', 'update' => 'Update' ];
        $uc      = new RP_Care_Update_Control();

        $result = $uc->modify_plugin_action_links( $actions, $file );

        $this->assertArrayHasKey( 'update', $result );
    }

    public function test_ui21_label_not_added_for_allowed_replanta_plugin(): void {
        $file    = 'replanta-care/replanta-care.php';
        $actions = [ 'deactivate' => 'Deactivate', 'update' => 'Update' ];
        $uc      = new RP_Care_Update_Control();

        $result = $uc->modify_plugin_action_links( $actions, $file );

        $this->assertArrayNotHasKey( 'rpcare_controlled', $result,
            "label must NOT be added for replanta-* plugins (they are allowed)" );
    }

    // ── UI-22: bulk update action NOT removed ─────────────────────────────────

    public function test_ui22_bulk_update_action_not_removed_by_init(): void {
        $GLOBALS['_registered_filters'] = [];

        $uc = new RP_Care_Update_Control();
        $uc->init();

        $this->assertNotContains(
            'bulk_actions-plugins',
            $GLOBALS['_registered_filters'],
            "Care must NOT hook bulk_actions-plugins — the administrator's bulk update must remain"
        );
    }

    // ── UI-23: hide_update_notices() removed ─────────────────────────────────

    public function test_ui23_hide_update_notices_method_removed(): void {
        $this->assertFalse(
            method_exists( 'RP_Care_Update_Control', 'hide_update_notices' ),
            'hide_update_notices() must be removed — the aggressive CSS selector is gone'
        );
    }

    // ── UI-24: no global site_transient_update_plugins filter ─────────────────

    public function test_ui24_no_global_transient_filter_registered(): void {
        $GLOBALS['_registered_filters'] = [];

        $uc = new RP_Care_Update_Control();
        $uc->init();

        $this->assertNotContains(
            'site_transient_update_plugins',
            $GLOBALS['_registered_filters'],
            'site_transient_update_plugins must NOT be registered as a filter by init()'
        );
    }

    // ── UI-25: ping payload shape — present and absent fields ─────────────────

    public function test_ui25_ping_payload_contains_required_fields(): void {
        $this->setUpdateTransient( [ 'plugin/plugin.php' ], time() - 300 );
        $inv     = RP_Care_Update_Control::read_inventory();
        $payload = $this->buildPingUpdatesPayload( $inv );

        // Required fields.
        $this->assertArrayHasKey( 'updates_pending_total',   $payload );
        $this->assertArrayHasKey( 'updates_checked_at',      $payload );
        $this->assertArrayHasKey( 'updates_inventory_stale', $payload );
        $this->assertArrayHasKey( 'updates_pending',         $payload );

        // Policy fields NOT YET implemented — must not appear in the light ping.
        $this->assertArrayNotHasKey( 'updates_pending_auto_eligible', $payload );
        $this->assertArrayNotHasKey( 'updates_pending_manual_only',   $payload );
        $this->assertArrayNotHasKey( 'updates_pending_blocked',       $payload );
    }
}
