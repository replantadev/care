<?php
/**
 * Replanta Care — Route Monitor Tests
 *
 * RM-01  check() returns required keys: checked_at, healthy, routes, issues
 * RM-02  all-200 results → healthy=true, issues empty
 * RM-03  non-200 route → healthy=false, issues has entry for that route
 * RM-04  results are persisted in wp_options (care_route_monitor_results)
 * RM-05  last check transient is set after check()
 * RM-06  is_check_due() → true when transient missing
 * RM-07  is_check_due() → false when transient present
 * RM-08  false-positive guard triggers when home=200 and checkout=non-200 with cache_bypass header
 * RM-09  false-positive guard does NOT trigger without cache_bypass_header
 * RM-10  get_last_results() returns null before first check
 * RM-11  get_last_results() returns results after check()
 * RM-12  NEG: no body content (HTML/PII) is stored in results
 *
 * @since 1.15.74
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-rp-care-route-monitor.php';

class RouteMonitorTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options']         = [];
        $GLOBALS['_wp_remote_get_mock'] = null;
    }

    protected function tearDown(): void {
        $GLOBALS['_wp_remote_get_mock'] = null;
    }

    // ── RM-01: required keys ───────────────────────────────────────────────────

    /** @test */
    public function test_rm01_check_returns_required_keys(): void {
        $result = RP_Care_Route_Monitor::check();
        $this->assertArrayHasKey( 'checked_at', $result );
        $this->assertArrayHasKey( 'healthy', $result );
        $this->assertArrayHasKey( 'routes', $result );
        $this->assertArrayHasKey( 'issues', $result );
    }

    // ── RM-02: all 200 → healthy ───────────────────────────────────────────────

    /** @test */
    public function test_rm02_all_200_routes_healthy(): void {
        $GLOBALS['_wp_remote_get_mock'] = [ 'response' => [ 'code' => 200 ], 'body' => '<html></html>' ];
        $result = RP_Care_Route_Monitor::check();
        $this->assertTrue( $result['healthy'] );
        $this->assertEmpty( $result['issues'] );
    }

    // ── RM-03: non-200 route → unhealthy ─────────────────────────────────────

    /** @test */
    public function test_rm03_non200_route_unhealthy(): void {
        $GLOBALS['_wp_remote_get_mock'] = function( string $url, array $args ): array {
            if ( str_contains( $url, 'checkout' ) ) {
                return [ 'response' => [ 'code' => 404 ], 'body' => '' ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
        };
        $result = RP_Care_Route_Monitor::check();
        $this->assertFalse( $result['healthy'] );
        $this->assertNotEmpty( $result['issues'] );
        $issue_routes = array_column( $result['issues'], 'route' );
        $this->assertContains( 'checkout', $issue_routes );
    }

    // ── RM-04: results persisted ──────────────────────────────────────────────

    /** @test */
    public function test_rm04_results_persisted_in_options(): void {
        $GLOBALS['_wp_remote_get_mock'] = [ 'response' => [ 'code' => 200 ], 'body' => '' ];
        RP_Care_Route_Monitor::check();
        $stored = get_option( RP_Care_Route_Monitor::RESULTS_KEY, null );
        $this->assertIsArray( $stored );
        $this->assertArrayHasKey( 'healthy', $stored );
    }

    // ── RM-05: last check transient set ───────────────────────────────────────

    /** @test */
    public function test_rm05_last_check_transient_set(): void {
        $GLOBALS['_wp_remote_get_mock'] = [ 'response' => [ 'code' => 200 ], 'body' => '' ];
        RP_Care_Route_Monitor::check();
        $ts = get_transient( RP_Care_Route_Monitor::LAST_CHECK_KEY );
        $this->assertNotFalse( $ts );
    }

    // ── RM-06 / RM-07: is_check_due() ────────────────────────────────────────

    /** @test */
    public function test_rm06_check_due_when_transient_missing(): void {
        delete_transient( RP_Care_Route_Monitor::LAST_CHECK_KEY );
        $this->assertTrue( RP_Care_Route_Monitor::is_check_due() );
    }

    /** @test */
    public function test_rm07_check_not_due_when_transient_present(): void {
        set_transient( RP_Care_Route_Monitor::LAST_CHECK_KEY, time(), 3600 );
        $this->assertFalse( RP_Care_Route_Monitor::is_check_due() );
    }

    // ── RM-08: false-positive guard ───────────────────────────────────────────

    /** @test */
    public function test_rm08_false_positive_guard_triggers_with_bypass_header(): void {
        $GLOBALS['_wp_remote_get_mock'] = function( string $url, array $args ): array {
            if ( str_contains( $url, 'checkout' ) ) {
                return [ 'response' => [ 'code' => 404 ], 'body' => '' ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
        };
        $result = RP_Care_Route_Monitor::check( [ 'cache_bypass_header' => 'X-Bypass-Cache: 1' ] );
        $this->assertFalse( $result['healthy'] );
        $issue_routes = array_column( $result['issues'], 'route' );
        $this->assertContains( '_false_positive_check', $issue_routes );
        $this->assertArrayHasKey( '_false_positive_warning', $result['routes'] );
    }

    // ── RM-09: false-positive guard does NOT trigger without header ────────────

    /** @test */
    public function test_rm09_false_positive_guard_skipped_without_bypass_header(): void {
        $GLOBALS['_wp_remote_get_mock'] = function( string $url, array $args ): array {
            if ( str_contains( $url, 'checkout' ) ) {
                return [ 'response' => [ 'code' => 404 ], 'body' => '' ];
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
        };
        $result = RP_Care_Route_Monitor::check(); // No cache_bypass_header
        $this->assertFalse( $result['healthy'] );
        $issue_routes = array_column( $result['issues'], 'route' );
        // _false_positive_check should NOT be present
        $this->assertNotContains( '_false_positive_check', $issue_routes );
    }

    // ── RM-10 / RM-11: get_last_results() ────────────────────────────────────

    /** @test */
    public function test_rm10_get_last_results_null_before_check(): void {
        $this->assertNull( RP_Care_Route_Monitor::get_last_results() );
    }

    /** @test */
    public function test_rm11_get_last_results_returns_after_check(): void {
        $GLOBALS['_wp_remote_get_mock'] = [ 'response' => [ 'code' => 200 ], 'body' => '' ];
        RP_Care_Route_Monitor::check();
        $results = RP_Care_Route_Monitor::get_last_results();
        $this->assertIsArray( $results );
        $this->assertArrayHasKey( 'checked_at', $results );
    }

    // ── RM-12: no body content stored ────────────────────────────────────────

    /** @test */
    public function test_rm12_body_content_not_stored_in_results(): void {
        $sensitive_body = '<html><body>PII data: user@example.com Order #1234</body></html>';
        $GLOBALS['_wp_remote_get_mock'] = [ 'response' => [ 'code' => 200 ], 'body' => $sensitive_body ];
        $result = RP_Care_Route_Monitor::check();
        // Serialize result and verify no body content is present.
        $serialized = serialize( $result );
        $this->assertStringNotContainsString( 'PII data', $serialized );
        $this->assertStringNotContainsString( 'user@example.com', $serialized );
    }
}
