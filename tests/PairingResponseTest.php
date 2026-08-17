<?php
/**
 * Care 1.16.1 — Pre-pairing response contract (Section G)
 *
 * The /ping endpoint has a three-state auth gate:
 *
 *   PG-01  No site_token configured (unpaired) → HTTP 200, status=unpaired.
 *   PG-02  Unpaired response carries pairing_required=true.
 *   PG-03  Unpaired response includes plugin_ver and schema_version=1.
 *   PG-04  Unpaired response does NOT reveal inventory (updates_pending_total absent).
 *   PG-05  Unpaired response does NOT reveal site_url.
 *   PG-06  Unpaired response does NOT reveal backup timestamps or status.
 *   PG-07  Unpaired response does NOT reveal SSL expiry or days_left.
 *   PG-08  Unpaired response does NOT reveal TTFB or performance data.
 *   PG-09  Unpaired response does NOT reveal wp_version.
 *   PG-10  Unpaired response does NOT reveal AS (as_failed_24h) or plan.
 *
 * Paired — token configured, header absent or wrong → 403:
 *   PG-11  Token configured, X-Hub-Token header absent → HTTP 403.
 *   PG-12  Token configured, X-Hub-Token wrong value → HTTP 403.
 *   PG-13  403 response does NOT echo back the submitted token.
 *
 * Paired — token configured, header correct → full data:
 *   PG-14  Valid token → HTTP 200, status=ok.
 *   PG-15  Valid token response includes plugin_ver, plan, wp_version, site_url.
 *
 * Token comparison:
 *   PG-16  Comparison uses hash_equals (constant-time) — header carries sha256 hash,
 *          not the raw token. Structural test: wrong hash length → 403.
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

// Additional stubs needed by class-rest.php but not in bootstrap.
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
    function rest_ensure_response( $response ) {
        if ( $response instanceof WP_REST_Response ) return $response;
        return new WP_REST_Response( $response );
    }
}

// Stubs for classes loaded by class-rest.php that are not needed for hub_ping().
// We only require the one class file that contains hub_ping, so minimal.
require_once __DIR__ . '/../inc/class-update-control.php';
require_once __DIR__ . '/../inc/class-rest.php';

use PHPUnit\Framework\TestCase;

// ── RP_Care_Plan stub for this test ───────────────────────────────────────────
if ( ! class_exists( 'RP_Care_Plan' ) ) {
    class RP_Care_Plan {
        public static function get_current(): string { return 'raiz'; }
        public static function get_features( string $plan ): array { return []; }
        public static function get_plan_name( string $plan ): string { return 'Raíz'; }
        public static function get_plan_config( string $plan ): array { return [ 'backup_stale_threshold_h' => 192 ]; }
        public static function count_as_failures_24h(): int { return 0; }
    }
}

class PairingResponseTest extends TestCase {

    private const RAW_TOKEN = 'test-pairing-token-abc123-xyz';
    // What Care expects in the X-Hub-Token header: sha256 of the raw token.
    private const HEADER_VAL = ''; // computed in setUpBeforeClass

    private RP_Care_REST $rest;

    public static function setUpBeforeClass(): void {
        // Pre-compute the expected header value (sha256 of the raw token).
        // Stored in a static variable for reuse across tests.
        // (Cannot assign to const at runtime — use a static helper instead.)
    }

    protected function setUp(): void {
        $GLOBALS['_wp_options']  = [];
        $GLOBALS['_rest_routes'] = [];
        RP_Care_Update_Control::$bypass_for_task  = false;
        RP_Care_Update_Control::$transient_reader = null;

        $this->rest = new RP_Care_REST();
    }

    protected function tearDown(): void {
        RP_Care_Update_Control::$transient_reader = null;
        RP_Care_Update_Control::$bypass_for_task  = false;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeRequest( ?string $headerToken = null ): WP_REST_Request {
        $req = new WP_REST_Request();
        if ( $headerToken !== null ) {
            $req->set_header( 'X-Hub-Token', $headerToken );
        }
        return $req;
    }

    private function validHeader(): string {
        return hash( 'sha256', self::RAW_TOKEN );
    }

    private function callPing( ?string $headerToken = null ): WP_REST_Response {
        return $this->rest->hub_ping( $this->makeRequest( $headerToken ) );
    }

    // ── PG-01..10: Unpaired — no site_token configured ────────────────────────

    public function test_pg01_unpaired_returns_http_200(): void {
        // No site_token → unpaired state.
        $resp = $this->callPing();
        $this->assertSame( 200, $resp->get_status(),
            'Unpaired /ping must return HTTP 200 (not 403)' );
    }

    public function test_pg02_unpaired_status_is_unpaired(): void {
        $data = $this->callPing()->get_data();
        $this->assertSame( 'unpaired', $data['status'] ?? null,
            'Unpaired response must carry status=unpaired' );
    }

    public function test_pg03a_unpaired_has_pairing_required_true(): void {
        $data = $this->callPing()->get_data();
        $this->assertTrue( $data['pairing_required'] ?? false,
            'Unpaired response must have pairing_required=true' );
    }

    public function test_pg03b_unpaired_has_plugin_ver_and_schema_version(): void {
        $data = $this->callPing()->get_data();
        $this->assertArrayHasKey( 'plugin_ver',     $data, 'plugin_ver must be present' );
        $this->assertArrayHasKey( 'schema_version', $data, 'schema_version must be present' );
        $this->assertSame( 1, $data['schema_version'], 'schema_version must be 1' );
        $this->assertNotEmpty( $data['plugin_ver'], 'plugin_ver must not be empty' );
    }

    public function test_pg04_unpaired_no_inventory_data(): void {
        $data = $this->callPing()->get_data();
        $this->assertArrayNotHasKey( 'updates_pending_total',   $data,
            'Unpaired must not reveal updates_pending_total' );
        $this->assertArrayNotHasKey( 'updates_pending',         $data,
            'Unpaired must not reveal updates_pending' );
        $this->assertArrayNotHasKey( 'updates_checked_at',      $data,
            'Unpaired must not reveal updates_checked_at' );
        $this->assertArrayNotHasKey( 'updates_inventory_stale', $data,
            'Unpaired must not reveal updates_inventory_stale' );
    }

    public function test_pg05_unpaired_no_site_url(): void {
        $data = $this->callPing()->get_data();
        $this->assertArrayNotHasKey( 'site_url', $data,
            'Unpaired must not reveal site_url' );
    }

    public function test_pg06_unpaired_no_backup_data(): void {
        $data = $this->callPing()->get_data();
        $this->assertArrayNotHasKey( 'backup_last_at', $data,
            'Unpaired must not reveal backup_last_at' );
        $this->assertArrayNotHasKey( 'backup_status',  $data,
            'Unpaired must not reveal backup_status' );
        $this->assertArrayNotHasKey( 'backup_stale',   $data,
            'Unpaired must not reveal backup_stale' );
    }

    public function test_pg07_unpaired_no_ssl_data(): void {
        $data = $this->callPing()->get_data();
        $this->assertArrayNotHasKey( 'ssl_expires_at', $data,
            'Unpaired must not reveal ssl_expires_at' );
        $this->assertArrayNotHasKey( 'ssl_days_left',  $data,
            'Unpaired must not reveal ssl_days_left' );
    }

    public function test_pg08_unpaired_no_performance_data(): void {
        $data = $this->callPing()->get_data();
        $this->assertArrayNotHasKey( 'ttfb_ms', $data,
            'Unpaired must not reveal ttfb_ms' );
    }

    public function test_pg09_unpaired_no_wp_version(): void {
        $data = $this->callPing()->get_data();
        $this->assertArrayNotHasKey( 'wp_version', $data,
            'Unpaired must not reveal wp_version' );
    }

    public function test_pg10_unpaired_no_as_or_plan(): void {
        $data = $this->callPing()->get_data();
        $this->assertArrayNotHasKey( 'as_failed_24h',          $data,
            'Unpaired must not reveal as_failed_24h' );
        $this->assertArrayNotHasKey( 'plan',                   $data,
            'Unpaired must not reveal plan' );
        $this->assertArrayNotHasKey( 'notification_channels',  $data,
            'Unpaired must not reveal notification_channels' );
    }

    // ── PG-11..13: Paired — wrong/absent header → 403 ────────────────────────

    public function test_pg11_paired_absent_header_returns_403(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $resp = $this->callPing(); // no header
        $this->assertSame( 403, $resp->get_status(),
            'Paired site with missing X-Hub-Token must return 403' );
    }

    public function test_pg12_paired_wrong_header_returns_403(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $resp = $this->callPing( 'wrong-token-value-not-a-valid-hash' );
        $this->assertSame( 403, $resp->get_status(),
            'Paired site with wrong X-Hub-Token must return 403' );
    }

    public function test_pg13_403_does_not_echo_submitted_token(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $badToken = 'attacker-submitted-token-abc';
        $resp     = $this->callPing( $badToken );
        $body     = json_encode( $resp->get_data() );
        $this->assertStringNotContainsString( $badToken, $body,
            '403 body must not echo back the submitted token value' );
        $this->assertStringNotContainsString( self::RAW_TOKEN, $body,
            '403 body must not reveal the stored raw token' );
    }

    // ── PG-14..15: Paired — valid token → full data ───────────────────────────

    public function test_pg14_valid_token_returns_200_ok(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $resp = $this->callPing( $this->validHeader() );
        $this->assertSame( 200, $resp->get_status(),
            'Valid X-Hub-Token must return 200' );
        $data = $resp->get_data();
        $this->assertSame( 'ok', $data['status'] ?? null,
            'Valid token response must have status=ok' );
    }

    public function test_pg15_valid_token_response_has_operational_fields(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $data = $this->callPing( $this->validHeader() )->get_data();
        $this->assertArrayHasKey( 'plugin_ver',  $data, 'plugin_ver must be present' );
        $this->assertArrayHasKey( 'wp_version',  $data, 'wp_version must be present' );
        $this->assertArrayHasKey( 'site_url',    $data, 'site_url must be present' );
        $this->assertArrayHasKey( 'plan',        $data, 'plan must be present' );
    }

    // ── PG-16: Constant-time comparison ──────────────────────────────────────

    public function test_pg16_header_must_be_sha256_of_raw_token(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );

        // Submitting the raw token (not its sha256) must be rejected.
        $resp = $this->callPing( self::RAW_TOKEN );
        $this->assertSame( 403, $resp->get_status(),
            'Raw token in header must be rejected; header must carry sha256(token)' );

        // Submitting the sha256 hash must be accepted.
        $resp = $this->callPing( $this->validHeader() );
        $this->assertSame( 200, $resp->get_status(),
            'sha256(token) in header must be accepted' );
    }
}
