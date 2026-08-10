<?php
/**
 * Replanta Care — Woo Autonomous Maintenance REST Endpoints (Capa 2)
 *
 * RE-01  register_routes() registers all 5 Woo routes
 * RE-02  Woo routes are in the replanta-care/v1 control namespace
 * RE-03  All 5 Woo routes use the POST method
 * RE-04  All 5 Woo routes have __return_true as permission_callback
 * RE-05  Route callback is an array pointing to a method on RP_Care_REST
 *
 * RE-06  hub_woo_telemetry — valid token → 200
 * RE-07  hub_woo_routes — valid token → 200
 * RE-08  hub_woo_snapshot — valid token → 200
 * RE-09  hub_woo_hygiene_dry — valid token → 200
 * RE-10  hub_woo_anomalies — valid token → 200
 *
 * RE-11  Missing X-Hub-Token → 403 for telemetry
 * RE-12  Wrong X-Hub-Token → 403 for telemetry
 * RE-13  Empty site_token option → 403 (fail-closed when require_token=true)
 *
 * RE-14  telemetry envelope has schema_version=1 and generated_at
 * RE-15  routes envelope has schema_version=1 and generated_at
 * RE-16  snapshot envelope has schema_version=1 and generated_at
 * RE-17  hygiene_dry envelope has schema_version=1 and generated_at
 * RE-18  anomalies envelope has schema_version=1 and generated_at
 *
 * RE-19  telemetry — empty buffer returns event_count=0
 * RE-20  telemetry — only warning+ events are returned (debug/info excluded)
 * RE-21  telemetry — events capped at 20 (most-recent first)
 * RE-22  telemetry — buffer_size and max_buffer present in response
 *
 * RE-23  routes — no stored results → status=no_data, is_stale=true
 * RE-24  routes — healthy cached results → status=ok, is_stale matches
 * RE-25  routes — unhealthy cached results → status=unhealthy, issues non-empty
 *
 * RE-26  snapshot — no golden → has_golden=false, status=no_snapshot
 * RE-27  snapshot — golden promoted, no drift → has_drift=false
 * RE-28  snapshot — drift detected → has_drift=true, diffs non-empty
 * RE-29  snapshot — golden_snapshot_id and golden_captured_at present
 *
 * RE-30  hygiene_dry — dry_run=true always in response (non-overridable)
 * RE-31  hygiene_dry — caller request param dry_run=false is silently ignored
 * RE-32  hygiene_dry — operations and total_rows_deleted present
 * RE-33  hygiene_dry — wall_seconds present
 * RE-34  hygiene_dry — no actual DB write queries (query() not called with DELETE)
 *
 * RE-35  anomalies — no active anomalies → count=0, list empty
 * RE-36  anomalies — anomaly_count matches length of anomalies array
 * RE-37  anomalies — anomaly entries have required keys without raw message content
 *
 * RE-38  NEG: site_token never appears in any endpoint response body
 * RE-39  NEG: generated_at is a valid ISO-8601 datetime
 * RE-40  NEG: auth guard applies to every handler independently
 *
 * @since 1.15.75
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-rp-care-telemetry.php';
require_once __DIR__ . '/../inc/class-rp-care-route-monitor.php';
require_once __DIR__ . '/../inc/class-rp-care-golden-snapshot.php';
require_once __DIR__ . '/../inc/class-rp-care-woo-hygiene.php';
require_once __DIR__ . '/../inc/class-rp-care-integration-detector.php';
require_once __DIR__ . '/../inc/class-rest.php';

class WooRestEndpointsTest extends TestCase {

    private const RAW_TOKEN = 'test-site-token-capa2-abc123';

    private RP_Care_REST $rest;

    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
        $GLOBALS['_rest_routes'] = [];
        RP_Care_Telemetry::clear_buffer();

        // Set up a valid site_token so validate_hub_token passes by default.
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );

        $this->rest = new RP_Care_REST();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function make_request( bool $valid_token = true ): WP_REST_Request {
        $req = new WP_REST_Request();
        if ( $valid_token ) {
            $req->set_header( 'X-Hub-Token', hash( 'sha256', self::RAW_TOKEN ) );
        }
        return $req;
    }

    private function call( string $method, WP_REST_Request $req ): WP_REST_Response {
        return $this->rest->$method( $req );
    }

    // ── RE-01 through RE-05: Route registration ───────────────────────────────

    /** @test */
    public function test_re01_all_five_woo_routes_are_registered(): void {
        $this->rest->register_routes();
        $routes = $GLOBALS['_rest_routes'];
        $paths  = array_map( fn( $r ) => $r['route'], $routes );
        foreach ( [ '/woo/telemetry', '/woo/routes', '/woo/snapshot', '/woo/hygiene-dry', '/woo/anomalies' ] as $expected ) {
            $this->assertContains( $expected, $paths, "Missing route: $expected" );
        }
    }

    /** @test */
    public function test_re02_woo_routes_in_control_namespace(): void {
        $this->rest->register_routes();
        $woo = array_filter( $GLOBALS['_rest_routes'], fn( $r ) => str_starts_with( $r['route'], '/woo/' ) );
        foreach ( $woo as $route ) {
            $this->assertSame( 'replanta-care/v1', $route['namespace'] );
        }
    }

    /** @test */
    public function test_re03_all_woo_routes_use_post_method(): void {
        $this->rest->register_routes();
        $woo = array_filter( $GLOBALS['_rest_routes'], fn( $r ) => str_starts_with( $r['route'], '/woo/' ) );
        $this->assertCount( 5, $woo );
        foreach ( $woo as $route ) {
            $this->assertSame( 'POST', $route['args']['methods'] );
        }
    }

    /** @test */
    public function test_re04_all_woo_routes_have_return_true_permission_callback(): void {
        $this->rest->register_routes();
        $woo = array_filter( $GLOBALS['_rest_routes'], fn( $r ) => str_starts_with( $r['route'], '/woo/' ) );
        foreach ( $woo as $route ) {
            $this->assertSame( '__return_true', $route['args']['permission_callback'],
                "Route {$route['route']} must use __return_true (auth is inside the handler)" );
        }
    }

    /** @test */
    public function test_re05_woo_route_callbacks_point_to_rest_instance(): void {
        $this->rest->register_routes();
        $woo = array_filter( $GLOBALS['_rest_routes'], fn( $r ) => str_starts_with( $r['route'], '/woo/' ) );
        foreach ( $woo as $route ) {
            $cb = $route['args']['callback'];
            $this->assertIsArray( $cb );
            $this->assertInstanceOf( RP_Care_REST::class, $cb[0] );
            $this->assertIsString( $cb[1] );
        }
    }

    // ── RE-06 through RE-10: Auth valid → 200 ────────────────────────────────

    /** @test */
    public function test_re06_telemetry_valid_token_returns_200(): void {
        $this->assertSame( 200, $this->call( 'hub_woo_telemetry', $this->make_request() )->get_status() );
    }

    /** @test */
    public function test_re07_routes_valid_token_returns_200(): void {
        $this->assertSame( 200, $this->call( 'hub_woo_routes', $this->make_request() )->get_status() );
    }

    /** @test */
    public function test_re08_snapshot_valid_token_returns_200(): void {
        $this->assertSame( 200, $this->call( 'hub_woo_snapshot', $this->make_request() )->get_status() );
    }

    /** @test */
    public function test_re09_hygiene_dry_valid_token_returns_200(): void {
        $this->assertSame( 200, $this->call( 'hub_woo_hygiene_dry', $this->make_request() )->get_status() );
    }

    /** @test */
    public function test_re10_anomalies_valid_token_returns_200(): void {
        $this->assertSame( 200, $this->call( 'hub_woo_anomalies', $this->make_request() )->get_status() );
    }

    // ── RE-11 through RE-13: Auth failures ───────────────────────────────────

    /** @test */
    public function test_re11_missing_token_header_returns_403(): void {
        $req = new WP_REST_Request(); // no X-Hub-Token set
        $res = $this->call( 'hub_woo_telemetry', $req );
        $this->assertSame( 403, $res->get_status() );
        $this->assertArrayHasKey( 'error', $res->get_data() );
    }

    /** @test */
    public function test_re12_wrong_token_value_returns_403(): void {
        $req = new WP_REST_Request();
        $req->set_header( 'X-Hub-Token', 'wrong-hash-value' );
        $res = $this->call( 'hub_woo_telemetry', $req );
        $this->assertSame( 403, $res->get_status() );
    }

    /** @test */
    public function test_re13_empty_site_token_fail_closed_returns_403(): void {
        // When no site_token is configured, require_token=true must reject requests.
        update_option( 'rpcare_options', [] ); // no site_token key

        $req = new WP_REST_Request();
        $req->set_header( 'X-Hub-Token', hash( 'sha256', self::RAW_TOKEN ) );

        foreach ( [ 'hub_woo_telemetry', 'hub_woo_routes', 'hub_woo_snapshot', 'hub_woo_hygiene_dry', 'hub_woo_anomalies' ] as $handler ) {
            $res = $this->call( $handler, $req );
            $this->assertSame( 403, $res->get_status(), "$handler must fail-closed when site_token is absent" );
        }
    }

    // ── RE-14 through RE-18: Schema envelope ──────────────────────────────────

    /** @test */
    public function test_re14_telemetry_envelope_has_schema_version_and_generated_at(): void {
        $data = $this->call( 'hub_woo_telemetry', $this->make_request() )->get_data();
        $this->assertSame( 1, $data['schema_version'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $data['generated_at'] );
    }

    /** @test */
    public function test_re15_routes_envelope_has_schema_version_and_generated_at(): void {
        $data = $this->call( 'hub_woo_routes', $this->make_request() )->get_data();
        $this->assertSame( 1, $data['schema_version'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $data['generated_at'] );
    }

    /** @test */
    public function test_re16_snapshot_envelope_has_schema_version_and_generated_at(): void {
        $data = $this->call( 'hub_woo_snapshot', $this->make_request() )->get_data();
        $this->assertSame( 1, $data['schema_version'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $data['generated_at'] );
    }

    /** @test */
    public function test_re17_hygiene_dry_envelope_has_schema_version_and_generated_at(): void {
        $data = $this->call( 'hub_woo_hygiene_dry', $this->make_request() )->get_data();
        $this->assertSame( 1, $data['schema_version'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $data['generated_at'] );
    }

    /** @test */
    public function test_re18_anomalies_envelope_has_schema_version_and_generated_at(): void {
        $data = $this->call( 'hub_woo_anomalies', $this->make_request() )->get_data();
        $this->assertSame( 1, $data['schema_version'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $data['generated_at'] );
    }

    // ── RE-19 through RE-22: Telemetry specifics ─────────────────────────────

    /** @test */
    public function test_re19_telemetry_empty_buffer_returns_zero_event_count(): void {
        // Buffer is empty (no events recorded).
        $data = $this->call( 'hub_woo_telemetry', $this->make_request() )->get_data();
        $this->assertSame( 0, $data['event_count'] );
        $this->assertSame( 0, $data['buffer_size'] );
        $this->assertIsArray( $data['events'] );
        $this->assertEmpty( $data['events'] );
    }

    /** @test */
    public function test_re20_telemetry_only_warning_plus_events_returned(): void {
        // record() is the direct buffer write (emit() goes through do_action which is a no-op in tests).
        RP_Care_Telemetry::record( [ 'source' => 'rest-test', 'severity' => 'debug',   'message' => 'debug event unique-a'   ] );
        RP_Care_Telemetry::record( [ 'source' => 'rest-test', 'severity' => 'info',    'message' => 'info event unique-b'    ] );
        RP_Care_Telemetry::record( [ 'source' => 'rest-test', 'severity' => 'warning', 'message' => 'warning event unique-c' ] );
        RP_Care_Telemetry::record( [ 'source' => 'rest-test', 'severity' => 'error',   'message' => 'error event unique-d'   ] );

        $data   = $this->call( 'hub_woo_telemetry', $this->make_request() )->get_data();
        $events = $data['events'];

        // Only warning+ should appear.
        $severities = array_column( $events, 'severity' );
        $this->assertNotContains( 'debug',   $severities, 'debug events must not be surfaced via REST' );
        $this->assertNotContains( 'info',    $severities, 'info events must not be surfaced via REST' );
        $this->assertContains(    'warning', $severities );
        $this->assertContains(    'error',   $severities );

        // buffer_size includes all 4; event_count only the warning+.
        $this->assertSame( 4, $data['buffer_size'] );
        $this->assertSame( 2, $data['event_count'] );
    }

    /** @test */
    public function test_re21_telemetry_events_capped_at_20(): void {
        // Record 30 error events — the REST layer must return at most 20.
        for ( $i = 1; $i <= 30; $i++ ) {
            RP_Care_Telemetry::record( [ 'source' => 'rest-test', 'severity' => 'error', 'message' => "event-$i unique-fingerprint-$i" ] );
        }

        $data = $this->call( 'hub_woo_telemetry', $this->make_request() )->get_data();
        $this->assertLessThanOrEqual( 20, count( $data['events'] ),
            'REST endpoint must cap events at 20 to bound response size' );
    }

    /** @test */
    public function test_re22_telemetry_response_has_buffer_metadata(): void {
        $data = $this->call( 'hub_woo_telemetry', $this->make_request() )->get_data();
        $this->assertArrayHasKey( 'buffer_size', $data );
        $this->assertArrayHasKey( 'max_buffer',  $data );
        $this->assertSame( RP_Care_Telemetry::MAX_BUFFER_EVENTS, $data['max_buffer'] );
    }

    // ── RE-23 through RE-25: Routes specifics ────────────────────────────────

    /** @test */
    public function test_re23_routes_no_stored_data_returns_no_data(): void {
        // No results stored — option is absent.
        $data = $this->call( 'hub_woo_routes', $this->make_request() )->get_data();
        $this->assertSame( 'no_data', $data['status'] );
        $this->assertTrue( $data['is_stale'], 'is_stale must be true when no check has ever run' );
    }

    /** @test */
    public function test_re24_routes_healthy_results_returns_ok_status(): void {
        // Manually store healthy route monitor results.
        $results = [
            'healthy'    => true,
            'checked_at' => gmdate( 'c' ),
            'routes'     => [ 'home' => [ 'status' => 200 ] ],
            'issues'     => [],
        ];
        update_option( RP_Care_Route_Monitor::RESULTS_KEY, $results, false );
        // Mark check as fresh (transient present).
        set_transient( RP_Care_Route_Monitor::LAST_CHECK_KEY, time(), RP_Care_Route_Monitor::CHECK_INTERVAL );

        $data = $this->call( 'hub_woo_routes', $this->make_request() )->get_data();
        $this->assertSame( 'ok', $data['status'] );
        $this->assertTrue( $data['healthy'] );
        $this->assertFalse( $data['is_stale'] );
    }

    /** @test */
    public function test_re25_routes_unhealthy_results_returns_unhealthy_status(): void {
        $results = [
            'healthy'    => false,
            'checked_at' => gmdate( 'c' ),
            'routes'     => [ 'checkout' => [ 'status' => 404 ] ],
            'issues'     => [ 'checkout' ],
        ];
        update_option( RP_Care_Route_Monitor::RESULTS_KEY, $results, false );
        set_transient( RP_Care_Route_Monitor::LAST_CHECK_KEY, time(), RP_Care_Route_Monitor::CHECK_INTERVAL );

        $data = $this->call( 'hub_woo_routes', $this->make_request() )->get_data();
        $this->assertSame( 'unhealthy', $data['status'] );
        $this->assertFalse( $data['healthy'] );
        $this->assertNotEmpty( $data['issues'] );
    }

    // ── RE-26 through RE-29: Snapshot specifics ──────────────────────────────

    /** @test */
    public function test_re26_snapshot_no_golden_returns_has_golden_false(): void {
        // No golden stored.
        $data = $this->call( 'hub_woo_snapshot', $this->make_request() )->get_data();
        $this->assertFalse( $data['has_golden'] );
        $this->assertSame( 'no_snapshot', $data['status'] );
    }

    /** @test */
    public function test_re27_snapshot_golden_no_drift_returns_has_drift_false(): void {
        // Capture current state and promote it as golden — no drift possible.
        $snap = RP_Care_Golden_Snapshot::capture();
        RP_Care_Golden_Snapshot::promote( $snap );

        $data = $this->call( 'hub_woo_snapshot', $this->make_request() )->get_data();
        $this->assertTrue( $data['has_golden'] );
        $this->assertFalse( $data['has_drift'] );
        $this->assertEmpty( $data['diffs'] );
    }

    /** @test */
    public function test_re28_snapshot_drift_detected_returns_has_drift_true(): void {
        // Capture and promote current state as golden.
        $snap = RP_Care_Golden_Snapshot::capture();
        RP_Care_Golden_Snapshot::promote( $snap );

        // Simulate drift by changing the permalink structure option.
        update_option( 'permalink_structure', '/changed/%postname%/' );

        $data = $this->call( 'hub_woo_snapshot', $this->make_request() )->get_data();
        $this->assertTrue( $data['has_golden'] );
        $this->assertTrue( $data['has_drift'] );
        $this->assertNotEmpty( $data['diffs'] );
    }

    /** @test */
    public function test_re29_snapshot_golden_metadata_present(): void {
        $snap = RP_Care_Golden_Snapshot::capture();
        RP_Care_Golden_Snapshot::promote( $snap );

        $data = $this->call( 'hub_woo_snapshot', $this->make_request() )->get_data();
        $this->assertNotEmpty( $data['golden_snapshot_id'],   'golden_snapshot_id must be present' );
        $this->assertNotEmpty( $data['golden_captured_at'],   'golden_captured_at must be present' );
        $this->assertIsInt(    $data['snapshot_age_sec'],      'snapshot_age_sec must be an integer' );
    }

    // ── RE-30 through RE-34: HygieneDry specifics ────────────────────────────

    /** @test */
    public function test_re30_hygiene_dry_response_always_contains_dry_run_true(): void {
        $data = $this->call( 'hub_woo_hygiene_dry', $this->make_request() )->get_data();
        $this->assertTrue( $data['dry_run'],
            'dry_run must always be true in the REST response — it is not overridable by callers' );
    }

    /** @test */
    public function test_re31_hygiene_dry_caller_cannot_override_dry_run(): void {
        // Even if the caller sends dry_run=false in the request params, the
        // handler MUST hardcode dry_run=true and never mutate the database.
        $req = $this->make_request();
        $req->set_param( 'dry_run', false ); // attempted override
        $data = $this->call( 'hub_woo_hygiene_dry', $req )->get_data();
        $this->assertTrue( $data['dry_run'], 'dry_run override by caller must be silently rejected' );
    }

    /** @test */
    public function test_re32_hygiene_dry_response_has_operations_and_total_rows(): void {
        $data = $this->call( 'hub_woo_hygiene_dry', $this->make_request() )->get_data();
        $this->assertArrayHasKey( 'operations',         $data );
        $this->assertArrayHasKey( 'total_rows_deleted', $data );
        $this->assertIsArray( $data['operations'] );
        $this->assertIsInt(   $data['total_rows_deleted'] );
    }

    /** @test */
    public function test_re33_hygiene_dry_response_has_wall_seconds(): void {
        $data = $this->call( 'hub_woo_hygiene_dry', $this->make_request() )->get_data();
        $this->assertArrayHasKey( 'wall_seconds', $data );
        $this->assertIsFloat( $data['wall_seconds'] );
    }

    /** @test */
    public function test_re34_hygiene_dry_no_delete_queries_executed(): void {
        // Track calls to wpdb::query() — any DELETE would appear here.
        $queries = [];
        $GLOBALS['wpdb'] = new class( $queries ) extends wpdb {
            private array $log;
            public function __construct( array &$log ) { $this->log = &$log; }
            public function query( string $sql ): int|bool {
                $this->log[] = $sql;
                return true;
            }
            public function get_var( string $sql, int $col = 0, int $row = 0 ): ?string { return null; }
            public function prepare( string $sql, ...$args ): string { return $sql; }
            public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
        };

        $this->call( 'hub_woo_hygiene_dry', $this->make_request() );

        $deletes = array_filter( $queries, fn( $q ) => stripos( $q, 'DELETE' ) !== false );
        $this->assertEmpty( $deletes, 'No DELETE queries must be executed during a dry-run hygiene check' );

        // Restore original wpdb stub.
        $GLOBALS['wpdb'] = new wpdb();
    }

    // ── RE-35 through RE-37: Anomalies specifics ─────────────────────────────

    /** @test */
    public function test_re35_anomalies_no_active_anomalies_returns_zero_count(): void {
        // No anomalies stored in option.
        $data = $this->call( 'hub_woo_anomalies', $this->make_request() )->get_data();
        $this->assertSame( 0, $data['anomaly_count'] );
        $this->assertSame( [], $data['anomalies'] );
    }

    /** @test */
    public function test_re36_anomalies_count_matches_list_length(): void {
        // Populate the active anomalies option with synthetic entries.
        $fake_anomalies = [
            [
                'family'       => 'gateway_fail',
                'count'        => 3,
                'threshold'    => 5,
                'escalated'    => false,
                'detected_at'  => gmdate( 'c' ),
                'message_hash' => hash( 'sha256', 'fake-message' ),
                'context_keys' => [ 'code', 'gateway' ],
            ],
            [
                'family'       => 'as_command_sync',
                'count'        => 2,
                'threshold'    => 3,
                'escalated'    => false,
                'detected_at'  => gmdate( 'c' ),
                'message_hash' => hash( 'sha256', 'another-fake' ),
                'context_keys' => [],
            ],
        ];
        update_option( RP_Care_Integration_Detector::ANOMALIES_KEY, $fake_anomalies, false );

        $data = $this->call( 'hub_woo_anomalies', $this->make_request() )->get_data();
        $this->assertSame( 2, $data['anomaly_count'] );
        $this->assertCount( 2, $data['anomalies'] );
    }

    /** @test */
    public function test_re37_anomalies_entries_have_required_keys_no_raw_message(): void {
        $anomaly = [
            'family'       => 'gateway_fail',
            'count'        => 1,
            'threshold'    => 5,
            'escalated'    => false,
            'detected_at'  => gmdate( 'c' ),
            'message_hash' => hash( 'sha256', 'test-error-message' ),
            'context_keys' => [ 'gateway_id' ],
        ];
        update_option( RP_Care_Integration_Detector::ANOMALIES_KEY, [ $anomaly ], false );

        $data  = $this->call( 'hub_woo_anomalies', $this->make_request() )->get_data();
        $entry = $data['anomalies'][0];

        // Required structural keys.
        foreach ( [ 'family', 'count', 'threshold', 'escalated', 'detected_at', 'message_hash' ] as $key ) {
            $this->assertArrayHasKey( $key, $entry, "Anomaly entry missing key: $key" );
        }

        // message_hash must be a sha256 hex, NOT raw message content.
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $entry['message_hash'],
            'message_hash must be a sha256 hex digest, never raw error text' );
        $this->assertStringNotContainsString( 'test-error-message', $entry['message_hash'] );
    }

    // ── RE-38 through RE-40: Security / fail-closed ──────────────────────────

    /** @test */
    public function test_re38_site_token_never_appears_in_response_body(): void {
        $handlers = [ 'hub_woo_telemetry', 'hub_woo_routes', 'hub_woo_snapshot', 'hub_woo_hygiene_dry', 'hub_woo_anomalies' ];
        $req      = $this->make_request();

        foreach ( $handlers as $handler ) {
            $body = json_encode( $this->call( $handler, $req )->get_data() );
            $this->assertStringNotContainsString( self::RAW_TOKEN, $body,
                "$handler must not expose the raw site_token in any response field" );
        }
    }

    /** @test */
    public function test_re39_generated_at_is_valid_iso8601(): void {
        $handlers = [ 'hub_woo_telemetry', 'hub_woo_routes', 'hub_woo_snapshot', 'hub_woo_hygiene_dry', 'hub_woo_anomalies' ];

        foreach ( $handlers as $handler ) {
            $data = $this->call( $handler, $this->make_request() )->get_data();
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-Z]/',
                $data['generated_at'],
                "$handler generated_at must be ISO-8601"
            );
        }
    }

    /** @test */
    public function test_re40_auth_guard_applies_independently_to_each_handler(): void {
        // Verify every handler returns 403 when no token is provided,
        // confirming each has its own auth check (not shared middleware).
        $req      = new WP_REST_Request(); // no token
        $handlers = [ 'hub_woo_telemetry', 'hub_woo_routes', 'hub_woo_snapshot', 'hub_woo_hygiene_dry', 'hub_woo_anomalies' ];

        foreach ( $handlers as $handler ) {
            $res = $this->call( $handler, $req );
            $this->assertSame( 403, $res->get_status(),
                "$handler must independently enforce auth and return 403 without a valid token" );
        }
    }
}
