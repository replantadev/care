<?php
/**
 * Care — Smart Updates Status Endpoint Tests
 *
 * HES-01  get_status_report() returns schema_version = 2.
 * HES-02  All required fields are present in the status report.
 * HES-03  update_executor_source = 'explicit' when update_executor is set in options.
 * HES-04  update_executor_source = 'wptoolkit_fallback' when no explicit setting and WP Toolkit detected.
 * HES-05  update_executor_source = 'default' when no explicit setting and WP Toolkit not detected.
 * HES-06  native_auto_updates defaults to 'disabled' when option is absent.
 * HES-07  native_auto_updates = 'enabled' when option is set to 'enabled'.
 * HES-08  native_auto_updates clamps unknown values to 'disabled'.
 * HES-09  staging_role defaults to 'unset' when option is absent.
 * HES-10  wp_toolkit_detected reflects the environment seam ($GLOBALS['_is_wptoolkit']).
 * HES-11  direct_updates_blocked is true when native_auto_updates is 'disabled'.
 * HES-12  plugin_version matches the RPCARE_VERSION constant.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-environment.php';

use PHPUnit\Framework\TestCase;

class SmartUpdatesStatusTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options']   = [];
        $GLOBALS['_is_wptoolkit'] = false;
    }

    // ── HES-01 ────────────────────────────────────────────────────────────────

    public function test_schema_version_is_2(): void {
        $report = RP_Care_Environment::get_status_report();
        $this->assertSame( 2, $report['schema_version'] );
    }

    // ── HES-02 ────────────────────────────────────────────────────────────────

    public function test_all_required_fields_present(): void {
        $report   = RP_Care_Environment::get_status_report();
        $required = [
            'schema_version', 'generated_at', 'plugin_version',
            'update_executor', 'update_executor_source', 'native_auto_updates',
            'pipeline_enabled', 'staging_role', 'wp_toolkit_detected', 'direct_updates_blocked',
			'pipeline_last_poll', 'pipeline_poll_age_seconds', 'pipeline_poll_scheduled',
			'action_scheduler_available', 'as_failed_24h', 'care_failed_24h', 'pipeline_failed_24h', 'backup_mode', 'backup_usable',
			'pipeline_failed_actions',
			'pipeline_instance_fingerprint',
        ];
        foreach ( $required as $key ) {
            $this->assertArrayHasKey( $key, $report, "Status report must include key: {$key}" );
        }
    }

    // ── HES-03 ────────────────────────────────────────────────────────────────

    public function test_source_is_explicit_when_executor_option_set(): void {
        update_option( 'rpcare_options', [ 'update_executor' => 'care_pipeline' ] );
        $GLOBALS['_is_wptoolkit'] = false;

        $report = RP_Care_Environment::get_status_report();

        $this->assertSame( 'explicit', $report['update_executor_source'] );
        $this->assertSame( 'care_pipeline', $report['update_executor'] );
    }

    // ── HES-04 ────────────────────────────────────────────────────────────────

    public function test_source_is_wptoolkit_fallback_when_wptoolkit_detected_and_no_explicit(): void {
        update_option( 'rpcare_options', [] );           // no explicit update_executor
        $GLOBALS['_is_wptoolkit'] = true;

        $report = RP_Care_Environment::get_status_report();

        $this->assertSame( 'wptoolkit_fallback', $report['update_executor_source'] );
        $this->assertTrue( $report['wp_toolkit_detected'] );
    }

    // ── HES-05 ────────────────────────────────────────────────────────────────

    public function test_source_is_default_when_no_explicit_and_no_wptoolkit(): void {
        update_option( 'rpcare_options', [] );
        $GLOBALS['_is_wptoolkit'] = false;

        $report = RP_Care_Environment::get_status_report();

        $this->assertSame( 'default', $report['update_executor_source'] );
    }

    // ── HES-06 ────────────────────────────────────────────────────────────────

    public function test_native_auto_updates_defaults_to_disabled(): void {
        update_option( 'rpcare_options', [] );  // native_auto_updates absent

        $report = RP_Care_Environment::get_status_report();

        $this->assertSame( 'disabled', $report['native_auto_updates'] );
    }

    // ── HES-07 ────────────────────────────────────────────────────────────────

    public function test_native_auto_updates_enabled_when_option_is_enabled(): void {
        update_option( 'rpcare_options', [ 'native_auto_updates' => 'enabled' ] );

        $report = RP_Care_Environment::get_status_report();

        $this->assertSame( 'enabled', $report['native_auto_updates'] );
    }

    // ── HES-08 ────────────────────────────────────────────────────────────────

    public function test_native_auto_updates_clamps_unknown_value_to_disabled(): void {
        update_option( 'rpcare_options', [ 'native_auto_updates' => 'partial' ] );

        $report = RP_Care_Environment::get_status_report();

        $this->assertSame( 'disabled', $report['native_auto_updates'],
            'Unknown native_auto_updates value must clamp to disabled (fail-closed).' );
    }

    // ── HES-09 ────────────────────────────────────────────────────────────────

    public function test_staging_role_defaults_to_unset(): void {
        update_option( 'rpcare_options', [] );

        $report = RP_Care_Environment::get_status_report();

        $this->assertSame( 'unset', $report['staging_role'] );
    }

    // ── HES-10 ────────────────────────────────────────────────────────────────

    public function test_wp_toolkit_detected_reflects_seam_true(): void {
        $GLOBALS['_is_wptoolkit'] = true;

        $report = RP_Care_Environment::get_status_report();

        $this->assertTrue( $report['wp_toolkit_detected'] );
    }

    public function test_wp_toolkit_detected_reflects_seam_false(): void {
        $GLOBALS['_is_wptoolkit'] = false;

        $report = RP_Care_Environment::get_status_report();

        $this->assertFalse( $report['wp_toolkit_detected'] );
    }

    // ── HES-11 ────────────────────────────────────────────────────────────────

    public function test_direct_updates_blocked_true_when_native_auto_updates_disabled(): void {
        update_option( 'rpcare_options', [ 'native_auto_updates' => 'disabled' ] );

        $report = RP_Care_Environment::get_status_report();

        $this->assertTrue( $report['direct_updates_blocked'],
            'direct_updates_blocked must be true when native_auto_updates=disabled.' );
    }

    // ── HES-12 ────────────────────────────────────────────────────────────────

    public function test_plugin_version_matches_constant(): void {
        $report = RP_Care_Environment::get_status_report();

        $this->assertSame( RPCARE_VERSION, $report['plugin_version'] );
    }

    public function test_pipeline_instance_is_exposed_only_as_sha256_fingerprint(): void {
        $raw = '5983e230-6e75-40e0-e8db-24981d210d76';
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, $raw );

        $report = RP_Care_Environment::get_status_report();

        $this->assertSame( hash( 'sha256', $raw ), $report['pipeline_instance_fingerprint'] );
        $this->assertStringNotContainsString( $raw, wp_json_encode( $report ) );
    }
}
