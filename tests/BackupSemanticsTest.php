<?php
/**
 * Care 1.16.2 — Backup status semantics and B2 structured error codes
 *
 * Contract for compute_backup_status():
 *   BS-01  No artifacts at all → failed
 *   BS-02  Manifest-only (no data artifact) → failed
 *   BS-03  Database scope requested, DB artifact absent, other artifacts present → failed
 *   BS-04  Database scope not requested, non-DB artifact present, no errors → complete
 *   BS-05  All scopes present, no errors → complete
 *   BS-06  All scopes present, some errors → partial
 *   BS-07  Some scopes uploaded, some errors → partial (not failed — something was saved)
 *   BS-08  Action 14892 scenario: 6 scopes, 0 uploads, all errors → failed
 *   BS-09  backup_usable=true only when status=complete
 *   BS-10  backup_usable=false when status=failed
 *   BS-11  backup_usable=false when status=partial
 *
 * B2 auth error classification (b2_classify_auth_error):
 *   BC-01  HTTP 401 → authentication_failed
 *   BC-02  HTTP 403 → key_unauthorized
 *   BC-03  HTTP 429 → provider_rate_limited
 *   BC-04  HTTP 503 → provider_unreachable
 *   BC-05  HTTP 200 but bad_auth_token B2 code → authentication_failed
 *   BC-06  HTTP 200 with storage_cap_exceeded → storage_cap_exceeded
 *   BC-07  Unknown HTTP with unknown code → unknown_provider_error
 *
 * B2 upload URL error classification (b2_classify_upload_url_error):
 *   BU-01  HTTP 401 → authentication_failed
 *   BU-02  HTTP 403 + access_denied → key_unauthorized
 *   BU-03  HTTP 403 + other → bucket_not_allowed
 *   BU-04  HTTP 404 → bucket_not_found
 *   BU-05  HTTP 429 → provider_rate_limited
 *   BU-06  HTTP 500 → provider_unreachable
 *   BU-07  HTTP 0 (network error) → provider_unreachable
 *   BU-08  storage_cap_exceeded B2 code → storage_cap_exceeded
 *   BU-09  bad_auth_token B2 code → authentication_failed
 *   BU-10  Unrecognised HTTP → unknown_provider_error
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/integrations-backup.php';

use PHPUnit\Framework\TestCase;

// Stubs needed by integrations-backup.php when running standalone
if ( ! function_exists( 'is_plugin_active' ) ) {
    function is_plugin_active( string $p ): bool { return false; }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( string $path = '' ): string { return 'https://example.com' . $path; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ): string { return '6.5'; }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type ): string { return '2026-08-17 12:00:00'; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, int $flags = 0 ): string { return json_encode( $data, $flags ) ?: '{}'; }
}
if ( ! defined( 'RPCARE_VERSION' ) ) define( 'RPCARE_VERSION', '1.16.2' );
if ( ! defined( 'DB_NAME' ) )       define( 'DB_NAME', 'test_db' );

class BackupSemanticsTest extends TestCase {

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function computeStatus( array $scopes, array $artifacts, array $errors ): string {
        return RP_Care_Task_Backup::compute_backup_status( $scopes, $artifacts, $errors ); // public static
    }

    /** Build an artifact entry for a given scope. */
    private function artifact( string $scope ): array {
        return [ 'scope' => $scope, 'file_name' => $scope . '.zip', 'file_id' => 'f123' ];
    }

    // ── BS-01..BS-08: compute_backup_status scenarios ─────────────────────────

    public function test_bs01_no_artifacts_returns_failed(): void {
        $this->assertSame( 'failed', $this->computeStatus( ['database', 'plugins'], [], ['db export failed'] ) );
    }

    public function test_bs02_manifest_only_returns_failed(): void {
        $artifacts = [ $this->artifact( 'manifest' ) ];
        $this->assertSame( 'failed', $this->computeStatus( ['database'], $artifacts, ['database upload failed'] ) );
    }

    public function test_bs03_database_scope_requested_but_absent_returns_failed(): void {
        $artifacts = [ $this->artifact( 'plugins' ), $this->artifact( 'themes' ) ];
        $errors    = [ 'database: B2 upload URL failed (401/unauthorized)' ];
        $this->assertSame( 'failed', $this->computeStatus( ['database', 'plugins', 'themes'], $artifacts, $errors ) );
    }

    public function test_bs04_no_database_scope_non_db_artifact_no_errors_returns_complete(): void {
        $artifacts = [ $this->artifact( 'config' ) ];
        $this->assertSame( 'complete', $this->computeStatus( ['config'], $artifacts, [] ) );
    }

    public function test_bs05_all_scopes_no_errors_returns_complete(): void {
        $artifacts = [
            $this->artifact( 'database' ),
            $this->artifact( 'plugins' ),
            $this->artifact( 'themes' ),
            $this->artifact( 'uploads' ),
            $this->artifact( 'manifest' ),
        ];
        $this->assertSame( 'complete', $this->computeStatus( ['database', 'plugins', 'themes', 'uploads'], $artifacts, [] ) );
    }

    public function test_bs06_all_db_present_but_errors_returns_partial(): void {
        $artifacts = [
            $this->artifact( 'database' ),
            $this->artifact( 'plugins' ),
        ];
        $errors = [ 'uploads: B2 upload URL failed' ];
        $this->assertSame( 'partial', $this->computeStatus( ['database', 'plugins', 'uploads'], $artifacts, $errors ) );
    }

    public function test_bs07_some_scopes_some_errors_returns_partial(): void {
        $artifacts = [ $this->artifact( 'database' ), $this->artifact( 'plugins' ) ];
        $errors    = [ 'themes: B2 upload URL failed', 'uploads: B2 upload URL failed' ];
        $this->assertSame( 'partial', $this->computeStatus( ['database', 'plugins', 'themes', 'uploads'], $artifacts, $errors ) );
    }

    public function test_bs08_action_14892_all_six_scopes_zero_uploads_returns_failed(): void {
        // Reproduces dev.banbancosmetics.com action 14892:
        // database, config, uploads, plugins, themes, manifest — all fail at b2_get_upload_url.
        // artifacts=[] → must return 'failed', not 'partial'.
        $scopes = ['database', 'plugins', 'themes', 'uploads', 'config'];
        $errors = [
            'database: B2 upload URL failed (0/unknown)',
            'config: B2 upload URL failed (0/unknown)',
            'uploads: B2 upload URL failed (0/unknown)',
            'plugins: B2 upload URL failed (0/unknown)',
            'themes: B2 upload URL failed (0/unknown)',
        ];
        $this->assertSame( 'failed', $this->computeStatus( $scopes, [], $errors ),
            'Action 14892: all uploads failed → status must be "failed", not "partial"' );
    }

    // ── BS-09..BS-11: backup_usable ───────────────────────────────────────────

    public function test_bs09_backup_usable_true_only_when_complete(): void {
        $artifacts = [ $this->artifact( 'database' ), $this->artifact( 'plugins' ) ];
        $status    = $this->computeStatus( ['database', 'plugins'], $artifacts, [] );
        $usable    = $status === 'complete';
        $this->assertTrue( $usable, 'backup_usable must be true when status=complete' );
        $this->assertSame( 'complete', $status );
    }

    public function test_bs10_backup_usable_false_when_failed(): void {
        $status = $this->computeStatus( ['database'], [], ['all failed'] );
        $usable = $status === 'complete';
        $this->assertFalse( $usable, 'backup_usable must be false when status=failed' );
        $this->assertSame( 'failed', $status );
    }

    public function test_bs11_backup_usable_false_when_partial(): void {
        $artifacts = [ $this->artifact( 'database' ) ];
        $errors    = [ 'plugins: upload failed' ];
        $status    = $this->computeStatus( ['database', 'plugins'], $artifacts, $errors );
        $usable    = $status === 'complete';
        $this->assertFalse( $usable, 'backup_usable must be false when status=partial' );
        $this->assertSame( 'partial', $status );
    }

    // ── BC-01..BC-07: b2_classify_auth_error ─────────────────────────────────

    public function test_bc01_http_401_authentication_failed(): void {
        $this->assertSame( 'authentication_failed',
            RP_Care_Task_Backup::b2_classify_auth_error( 401, '' ) );
    }

    public function test_bc02_http_403_key_unauthorized(): void {
        $this->assertSame( 'key_unauthorized',
            RP_Care_Task_Backup::b2_classify_auth_error( 403, '' ) );
    }

    public function test_bc03_http_429_rate_limited(): void {
        $this->assertSame( 'provider_rate_limited',
            RP_Care_Task_Backup::b2_classify_auth_error( 429, '' ) );
    }

    public function test_bc04_http_503_provider_unreachable(): void {
        $this->assertSame( 'provider_unreachable',
            RP_Care_Task_Backup::b2_classify_auth_error( 503, '' ) );
    }

    public function test_bc05_bad_auth_token_b2_code_authentication_failed(): void {
        $this->assertSame( 'authentication_failed',
            RP_Care_Task_Backup::b2_classify_auth_error( 0, 'bad_auth_token' ) );
    }

    public function test_bc06_storage_cap_exceeded(): void {
        $this->assertSame( 'storage_cap_exceeded',
            RP_Care_Task_Backup::b2_classify_auth_error( 0, 'storage_cap_exceeded' ) );
    }

    public function test_bc07_unknown_http_unknown_b2_code_returns_unknown(): void {
        $this->assertSame( 'unknown_provider_error',
            RP_Care_Task_Backup::b2_classify_auth_error( 418, 'teapot' ) );
    }

    // ── BU-01..BU-10: b2_classify_upload_url_error ───────────────────────────

    public function test_bu01_http_401_authentication_failed(): void {
        $this->assertSame( 'authentication_failed',
            RP_Care_Task_Backup::b2_classify_upload_url_error( 401, '' ) );
    }

    public function test_bu02_http_403_access_denied_key_unauthorized(): void {
        $this->assertSame( 'key_unauthorized',
            RP_Care_Task_Backup::b2_classify_upload_url_error( 403, 'access_denied' ) );
    }

    public function test_bu03_http_403_other_bucket_not_allowed(): void {
        $this->assertSame( 'bucket_not_allowed',
            RP_Care_Task_Backup::b2_classify_upload_url_error( 403, 'not_allowed' ) );
    }

    public function test_bu04_http_404_bucket_not_found(): void {
        $this->assertSame( 'bucket_not_found',
            RP_Care_Task_Backup::b2_classify_upload_url_error( 404, '' ) );
    }

    public function test_bu05_http_429_rate_limited(): void {
        $this->assertSame( 'provider_rate_limited',
            RP_Care_Task_Backup::b2_classify_upload_url_error( 429, '' ) );
    }

    public function test_bu06_http_500_provider_unreachable(): void {
        $this->assertSame( 'provider_unreachable',
            RP_Care_Task_Backup::b2_classify_upload_url_error( 500, '' ) );
    }

    public function test_bu07_http_0_network_error_provider_unreachable(): void {
        $this->assertSame( 'provider_unreachable',
            RP_Care_Task_Backup::b2_classify_upload_url_error( 0, '' ) );
    }

    public function test_bu08_storage_cap_exceeded_b2_code(): void {
        $this->assertSame( 'storage_cap_exceeded',
            RP_Care_Task_Backup::b2_classify_upload_url_error( 0, 'storage_cap_exceeded' ) );
    }

    public function test_bu09_bad_auth_token_b2_code(): void {
        $this->assertSame( 'authentication_failed',
            RP_Care_Task_Backup::b2_classify_upload_url_error( 0, 'bad_auth_token' ) );
    }

    public function test_bu10_unrecognised_http_unknown_provider_error(): void {
        $this->assertSame( 'unknown_provider_error',
            RP_Care_Task_Backup::b2_classify_upload_url_error( 418, 'teapot' ) );
    }
}
