<?php
/**
 * Unit tests for the three new RP_Care_Isolation_Checker checks:
 *   check_wp_environment_type(), check_redis_isolated(), check_db_isolated()
 *
 * Loaded via phpunit.xml.dist bootstrap — no real WP or DB required.
 */

declare( strict_types=1 );

// Additional shims not in bootstrap.
if ( ! function_exists( 'wp_get_environment_type' ) ) {
    function wp_get_environment_type(): string {
        return $GLOBALS['_wp_environment_type'] ?? 'production';
    }
}

if ( ! class_exists( 'RP_Care_Pipeline_Client' ) ) {
    require_once __DIR__ . '/../inc/class-pipeline-client.php';
}

require_once __DIR__ . '/../inc/class-staging-email-sink.php';
require_once __DIR__ . '/../inc/class-isolation-checker.php';

use PHPUnit\Framework\TestCase;

class IsolationCheckerNewChecksTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options']        = [];
        $GLOBALS['_wp_environment_type'] = 'staging';
        $GLOBALS['wpdb']->dbname       = 'test_staging_db';
    }

    // ── check_wp_environment_type ─────────────────────────────────────────────

    public function test_environment_type_staging_passes(): void {
        $GLOBALS['_wp_environment_type'] = 'staging';
        $result = $this->run_check( 'wp_environment_type' );
        $this->assertSame( 'ok', $result['status'] );
    }

    public function test_environment_type_local_passes(): void {
        $GLOBALS['_wp_environment_type'] = 'local';
        $result = $this->run_check( 'wp_environment_type' );
        $this->assertSame( 'ok', $result['status'] );
    }

    public function test_environment_type_production_is_critical(): void {
        $GLOBALS['_wp_environment_type'] = 'production';
        $result = $this->run_check( 'wp_environment_type' );
        $this->assertSame( 'failed', $result['status'] );
        $this->assertSame( 'critical', $result['level'] );
    }

    // ── check_redis_isolated ──────────────────────────────────────────────────

    public function test_redis_skipped_when_not_configured(): void {
        // No WP_REDIS_HOST defined, no WP_Object_Cache class.
        $result = $this->run_check( 'redis_isolated' );
        $this->assertSame( 'skipped', $result['status'] );
    }

    public function test_redis_fails_critical_when_no_prefix(): void {
        define( 'WP_REDIS_HOST', '127.0.0.1' );

        $result = $this->run_check( 'redis_isolated' );
        // WP_REDIS_HOST is set but WP_REDIS_PREFIX is not → critical.
        $this->assertSame( 'failed', $result['status'] );
        $this->assertSame( 'critical', $result['level'] );
    }

    public function test_redis_passes_when_prefix_set(): void {
        // WP_REDIS_HOST defined in previous test (constants persist).
        define( 'WP_REDIS_PREFIX', 'staging-mysite:' );

        $result = $this->run_check( 'redis_isolated' );
        $this->assertSame( 'ok', $result['status'] );
        $this->assertStringContainsString( 'staging-mysite:', $result['message'] );
    }

    // ── check_db_isolated ─────────────────────────────────────────────────────

    public function test_db_passes_with_staging_in_name(): void {
        $GLOBALS['wpdb']->dbname = 'replanta_staging';
        $result = $this->run_check( 'db_isolated' );
        $this->assertSame( 'ok', $result['status'] );
    }

    public function test_db_warns_when_no_staging_keyword_and_no_reference(): void {
        $GLOBALS['wpdb']->dbname = 'my_production_db';
        $result = $this->run_check( 'db_isolated' );
        $this->assertSame( 'failed', $result['status'] );
        $this->assertSame( 'warning', $result['level'] );
    }

    public function test_db_passes_when_different_from_stored_prod_db(): void {
        $GLOBALS['wpdb']->dbname = 'my_staging_clone';
        update_option( 'rpcare_production_db_name', 'my_production_db' );
        $result = $this->run_check( 'db_isolated' );
        $this->assertSame( 'ok', $result['status'] );
        $this->assertStringContainsString( 'my_production_db', $result['message'] );
    }

    public function test_db_fails_critical_when_matches_prod_db(): void {
        $GLOBALS['wpdb']->dbname = 'my_prod_db';
        update_option( 'rpcare_production_db_name', 'my_prod_db' );
        $result = $this->run_check( 'db_isolated' );
        $this->assertSame( 'failed', $result['status'] );
        $this->assertSame( 'critical', $result['level'] );
    }

    // ── check_email_suppressed picks up EmailSink ─────────────────────────────

    public function test_email_suppressed_ok_when_staging_env_set(): void {
        // OPT_ENVIRONMENT = 'staging' → EmailSink::is_active() = true → check passes.
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $result = $this->run_check( 'email_suppressed' );
        $this->assertSame( 'ok', $result['status'] );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Run a full RP_Care_Isolation_Checker::run() and return the check with the given name.
     */
    private function run_check( string $name ): array {
        // The checker's run() makes a wp_remote_head() call for robots_header,
        // and calls WC() for woo checks — add minimal stubs.
        if ( ! function_exists( 'wp_remote_head' ) ) {
            function wp_remote_head( string $url, array $args = [] ) {
                return new \WP_Error( 'loopback_unavailable', 'stub' );
            }
        }

        $result = RP_Care_Isolation_Checker::run();
        foreach ( $result['checks'] as $check ) {
            if ( $check['name'] === $name ) {
                return $check;
            }
        }
        $this->fail( "Check '$name' not found in isolation checker output." );
    }
}
