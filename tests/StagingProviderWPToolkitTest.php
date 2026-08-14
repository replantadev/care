<?php
/**
 * Care — Staging Provider WP Toolkit Tests
 *
 * SPT-01  is_available() returns false when binary seam is empty string.
 * SPT-02  is_available() returns true when binary seam is set to a non-empty path.
 * SPT-03  capabilities() returns can_create=true, can_refresh=true, can_verify_isolation=true, provider_name='wptoolkit'.
 * SPT-04  get_url() returns staging URL from rpcare_options.
 * SPT-05  get_url() returns null when staging_url absent from options.
 * SPT-06  create_or_refresh() returns WP_Error 'wptk_no_binary' when binary seam is empty.
 * SPT-07  create_or_refresh() returns WP_Error 'wptk_no_staging_url' when staging_url not in options.
 * SPT-08  status() returns 'unconfigured' and ready=false when no staging_url.
 * SPT-09  status() returns 'ready' and ready=true when staging_url is set.
 * SPT-10  Factory get() returns RP_Care_Staging_Provider_WPToolkit when staging_method='wptoolkit' (binary seam set).
 * SPT-11  Factory get() returns RP_Care_Staging_Provider_Manual when staging_method='wptoolkit' but no binary.
 * SPT-12  Factory auto chain: WPToolkit selected when Paired/WPStaging unavailable and binary seam set.
 * SPT-13  requires_staging bypass: returns false when staging_method='none' even for PLAN_ECOSISTEMA.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-staging-provider.php';
require_once __DIR__ . '/../inc/task-updates.php';
require_once __DIR__ . '/../inc/class-plan.php';

use PHPUnit\Framework\TestCase;

// Minimal stub for RP_Care_Isolation_Checker (used by verify_isolation).
if ( ! class_exists( 'RP_Care_Isolation_Checker' ) ) {
    class RP_Care_Isolation_Checker {
        public static function run(): array {
            return [ 'passed' => true, 'checks' => [] ];
        }
    }
}

// Minimal stub for RP_Care_Pipeline_Client constants (used by Paired provider).
if ( ! class_exists( 'RP_Care_Pipeline_Client' ) ) {
    class RP_Care_Pipeline_Client {
        const OPT_PAIRING_STATUS = 'rpcare_pipeline_pairing_status';
        const OPT_ENVIRONMENT    = 'rpcare_pipeline_environment';
        const STATUS_READY       = 'ready';
        public static function blocks_direct_updates(): bool { return false; }
    }
}

// Minimal stub for RP_Care_Task_Staging (used indirectly by check_staging_gate).
if ( ! class_exists( 'RP_Care_Task_Staging' ) ) {
    class RP_Care_Task_Staging {
        public static function create_clone( string $label ): array {
            return [ 'triggered' => false ];
        }
    }
}

class StagingProviderWPToolkitTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options']       = [];
        $GLOBALS['_wptk_binary_seam'] = ''; // default: no binary
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_wptk_binary_seam'] );
    }

    // ── SPT-01 ────────────────────────────────────────────────────────────────

    public function test_is_available_false_when_no_binary(): void {
        $GLOBALS['_wptk_binary_seam'] = '';
        $this->assertFalse( RP_Care_Staging_Provider_WPToolkit::is_available() );
    }

    // ── SPT-02 ────────────────────────────────────────────────────────────────

    public function test_is_available_true_when_binary_seam_set(): void {
        $GLOBALS['_wptk_binary_seam'] = '/usr/local/cpanel/3rdparty/wp-toolkit/bin/wp-toolkit';
        $this->assertTrue( RP_Care_Staging_Provider_WPToolkit::is_available() );
    }

    // ── SPT-03 ────────────────────────────────────────────────────────────────

    public function test_capabilities(): void {
        $GLOBALS['_wptk_binary_seam'] = '/usr/bin/wp-toolkit';
        $provider = new RP_Care_Staging_Provider_WPToolkit();
        $caps     = $provider->capabilities();

        $this->assertTrue( $caps['can_create'] );
        $this->assertTrue( $caps['can_refresh'] );
        $this->assertTrue( $caps['can_verify_isolation'] );
        $this->assertSame( 'wptoolkit', $caps['provider_name'] );
    }

    // ── SPT-04 ────────────────────────────────────────────────────────────────

    public function test_get_url_returns_staging_url_from_options(): void {
        $GLOBALS['_wptk_binary_seam'] = '/usr/bin/wp-toolkit';
        update_option( 'rpcare_options', [ 'staging_url' => 'https://staging.example.com' ] );

        $provider = new RP_Care_Staging_Provider_WPToolkit();
        $this->assertSame( 'https://staging.example.com', $provider->get_url() );
    }

    // ── SPT-05 ────────────────────────────────────────────────────────────────

    public function test_get_url_returns_null_when_not_configured(): void {
        $GLOBALS['_wptk_binary_seam'] = '/usr/bin/wp-toolkit';
        update_option( 'rpcare_options', [] );

        $provider = new RP_Care_Staging_Provider_WPToolkit();
        $this->assertNull( $provider->get_url() );
    }

    // ── SPT-06 ────────────────────────────────────────────────────────────────

    public function test_create_or_refresh_returns_error_when_no_binary(): void {
        $GLOBALS['_wptk_binary_seam'] = '';
        $provider = new RP_Care_Staging_Provider_WPToolkit();
        $result   = $provider->create_or_refresh();

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wptk_no_binary', $result->get_error_code() );
    }

    // ── SPT-07 ────────────────────────────────────────────────────────────────

    public function test_create_or_refresh_returns_error_when_no_staging_url(): void {
        $GLOBALS['_wptk_binary_seam'] = '/usr/bin/wp-toolkit';
        update_option( 'rpcare_options', [] ); // no staging_url

        $provider = new RP_Care_Staging_Provider_WPToolkit();
        $result   = $provider->create_or_refresh();

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wptk_no_staging_url', $result->get_error_code() );
    }

    // ── SPT-08 ────────────────────────────────────────────────────────────────

    public function test_status_unconfigured_when_no_staging_url(): void {
        $GLOBALS['_wptk_binary_seam'] = '/usr/bin/wp-toolkit';
        update_option( 'rpcare_options', [] );

        $provider = new RP_Care_Staging_Provider_WPToolkit();
        $status   = $provider->status();

        $this->assertSame( 'unconfigured', $status['status'] );
        $this->assertFalse( $status['ready'] );
    }

    // ── SPT-09 ────────────────────────────────────────────────────────────────

    public function test_status_ready_when_staging_url_set(): void {
        $GLOBALS['_wptk_binary_seam'] = '/usr/bin/wp-toolkit';
        update_option( 'rpcare_options', [ 'staging_url' => 'https://staging.example.com' ] );

        $provider = new RP_Care_Staging_Provider_WPToolkit();
        $status   = $provider->status();

        $this->assertSame( 'ready', $status['status'] );
        $this->assertTrue( $status['ready'] );
        $this->assertSame( 'https://staging.example.com', $status['staging_url'] );
    }

    // ── SPT-10 ────────────────────────────────────────────────────────────────

    public function test_factory_returns_wptoolkit_when_method_is_wptoolkit(): void {
        $GLOBALS['_wptk_binary_seam'] = '/usr/bin/wp-toolkit';
        update_option( 'rpcare_options', [ 'staging_method' => 'wptoolkit' ] );

        $provider = RP_Care_Staging_Provider::get();
        $this->assertInstanceOf( RP_Care_Staging_Provider_WPToolkit::class, $provider );
    }

    // ── SPT-11 ────────────────────────────────────────────────────────────────

    public function test_factory_still_returns_wptoolkit_when_no_binary_explicit_method(): void {
        $GLOBALS['_wptk_binary_seam'] = '';
        update_option( 'rpcare_options', [ 'staging_method' => 'wptoolkit' ] );

        $provider = RP_Care_Staging_Provider::get();
        // Admin explicitly chose 'wptoolkit' — factory honours that; error surfaces on create_or_refresh.
        $this->assertInstanceOf( RP_Care_Staging_Provider_WPToolkit::class, $provider );
        // Verify the explicit fail: create_or_refresh returns WP_Error 'wptk_no_binary'.
        $result = $provider->create_or_refresh();
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wptk_no_binary', $result->get_error_code() );
    }

    // ── SPT-12 ────────────────────────────────────────────────────────────────

    public function test_factory_auto_selects_wptoolkit_when_paired_and_wpstaging_unavailable(): void {
        $GLOBALS['_wptk_binary_seam'] = '/usr/bin/wp-toolkit';
        // No pairing status set → Paired unavailable.
        // WPStaging not installed → WPStaging unavailable.
        update_option( 'rpcare_options', [ 'staging_method' => 'auto' ] );

        $provider = RP_Care_Staging_Provider::get();
        $this->assertInstanceOf( RP_Care_Staging_Provider_WPToolkit::class, $provider );
    }

    // ── SPT-13 ────────────────────────────────────────────────────────────────

    public function test_requires_staging_returns_false_when_method_is_none(): void {
        update_option( 'rpcare_options', [ 'staging_method' => 'none' ] );

        $ref    = new \ReflectionClass( RP_Care_Task_Updates::class );
        $method = $ref->getMethod( 'requires_staging' );
        $method->setAccessible( true );

        // Even PLAN_ECOSISTEMA is bypassed when staging_method='none'.
        $this->assertFalse( $method->invoke( null, 'ecosistema' ) );
    }

    // ── SPT-14 ────────────────────────────────────────────────────────────────

    public function test_requires_staging_normal_when_method_is_auto(): void {
        update_option( 'rpcare_options', [ 'staging_method' => 'auto' ] );

        $ref    = new \ReflectionClass( RP_Care_Task_Updates::class );
        $method = $ref->getMethod( 'requires_staging' );
        $method->setAccessible( true );

        // PLAN_ECOSISTEMA still requires staging under 'auto'.
        $this->assertTrue( $method->invoke( null, 'ecosistema' ) );
    }
}
