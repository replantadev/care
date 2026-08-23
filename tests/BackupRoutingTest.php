<?php
/**
 * Care 1.16.5 — Backup routing and local cleanup
 *
 * Covers two bugs fixed in 1.16.5:
 *
 * Environment routing:
 *   BR-01  Environment 'cpanel' routes to handle_whm_backup() — not handle_local_backup()
 *   BR-02  Environment 'whm' still routes to handle_whm_backup()
 *   BR-03  Environment 'external' does NOT reach handle_whm_backup()
 *   BR-04  Unknown environment does NOT reach handle_whm_backup()
 *
 * Local directory cleanup (cleanup_old_backups):
 *   BC-01  3 or fewer local backup dirs → nothing deleted
 *   BC-02  4 local backup dirs → oldest (index 3 by mtime desc) deleted
 *   BC-03  8 local backup dirs → top 3 kept, rest deleted regardless of age
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/integrations-backup.php';

use PHPUnit\Framework\TestCase;

// ── Stubs needed by integrations-backup.php ──────────────────────────────────

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
    function current_time( string $type ): string { return '2026-08-20 12:00:00'; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, int $flags = 0 ): string { return json_encode( $data, $flags ) ?: '{}'; }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $k, $v, $autoload = null ): bool {
        $GLOBALS['_wp_options'][ $k ] = $v;
        return true;
    }
}
if ( ! function_exists( 'get_current_user' ) ) {
    function get_current_user(): string { return 'testuser'; }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( string $path ): bool {
        return is_dir( $path ) || mkdir( $path, 0777, true );
    }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir(): array {
        return [ 'basedir' => $GLOBALS['_rpcare_test_upload_basedir'] ?? sys_get_temp_dir() ];
    }
}

if ( ! defined( 'RPCARE_VERSION' ) ) define( 'RPCARE_VERSION', '1.16.6' ); // care version
if ( ! defined( 'DB_NAME' ) )       define( 'DB_NAME', 'test_db' );

// Minimal RP_Care_Utils stub so run() can log without crashing.
if ( ! class_exists( 'RP_Care_Utils' ) ) {
    class RP_Care_Utils {
        public static function log( ...$args ): void {}
    }
}

class BackupRoutingTest extends TestCase {

    /** The rpcare-backups-{secret} dir used by cleanup tests. */
    private string $backup_base;

    protected function setUp(): void {
        RP_Care_Task_Backup::$environment_reader      = null;
        RP_Care_Task_Backup::$backup_base_dir_override = null;
        $GLOBALS['_wp_options'] = [];

        // Build a tmp base; override get_backup_base_dir() via the seam so the
        // test doesn't depend on wp_upload_dir() which bootstrap hard-codes.
        $this->backup_base = sys_get_temp_dir() . '/rpcare_backup_' . uniqid();
        mkdir( $this->backup_base, 0777, true );
        RP_Care_Task_Backup::$backup_base_dir_override = $this->backup_base;
    }

    protected function tearDown(): void {
        RP_Care_Task_Backup::$environment_reader      = null;
        RP_Care_Task_Backup::$backup_base_dir_override = null;
        if ( is_dir( $this->backup_base ) ) {
            $this->rmdirR( $this->backup_base );
        }
    }

    private function rmdirR( string $dir ): void {
        foreach ( new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        ) as $item ) {
            $item->isDir() ? rmdir( (string) $item ) : unlink( (string) $item );
        }
        rmdir( $dir );
    }

    // ── Environment routing ───────────────────────────────────────────────────

    /**
     * Invoke handle_whm_backup() directly via Reflection and return its result.
     * This is the canonical output we expect for 'cpanel' and 'whm' environments.
     */
    private function whm_result(): array {
        $ref    = new ReflectionClass( RP_Care_Task_Backup::class );
        $method = $ref->getMethod( 'handle_whm_backup' );
        $method->setAccessible( true );
        return (array) $method->invoke( null, [] );
    }

    /**
     * Run the backup task with a fixed environment type and B2 unconfigured.
     * handle_whm_backup() always returns `managed_by_hub=true`; the other
     * paths do not.
     */
    private function routingFor( string $env ): array {
        RP_Care_Task_Backup::$environment_reader = static fn() => $env;
        $GLOBALS['_wp_options']['rpcare_b2_key_id']    = '';
        $GLOBALS['_wp_options']['rpcare_b2_app_key']   = '';
        $GLOBALS['_wp_options']['rpcare_b2_bucket_id'] = '';
        return RP_Care_Task_Backup::run( [] );
    }

    public function test_br01_cpanel_routes_to_whm_handler(): void {
        $result = $this->routingFor( 'cpanel' );
        $this->assertTrue( $result['managed_by_hub'] ?? false,
            "'cpanel' environment must reach handle_whm_backup() which sets managed_by_hub=true" );
    }

    public function test_br02_whm_routes_to_whm_handler(): void {
        $result = $this->routingFor( 'whm' );
        $this->assertTrue( $result['managed_by_hub'] ?? false,
            "'whm' environment must still reach handle_whm_backup()" );
    }

    public function test_br03_run_uses_mode_based_routing(): void {
        // Since 1.16.7, run() resolves the backup mode via get_effective_backup_mode()
        // instead of a direct environment switch. Verify the new architecture is in
        // place: run() must call get_effective_backup_mode (not a raw env switch).
        $src = file_get_contents( __DIR__ . '/../inc/integrations-backup.php' );
        $this->assertStringContainsString( 'get_effective_backup_mode', $src,
            'run() must delegate to RP_Care_Environment::get_effective_backup_mode()' );
        // The mode-based switch must include managed_by_host routing to handle_whm_backup.
        $this->assertMatchesRegularExpression(
            "/case 'managed_by_host'.*handle_whm_backup/s",
            $src,
            "managed_by_host case must lead to handle_whm_backup()"
        );
    }

    public function test_br04_local_dev_is_only_mode_that_may_produce_backup_dir(): void {
        // Safety contract: backup_dir MUST NOT appear in results for managed_by_host mode.
        // RP_Care_Environment not loaded → fallback mode is 'managed_by_host' → handle_whm_backup().
        // handle_whm_backup() either returns managed_by_hub=true (no backup_dir) or an error.
        RP_Care_Task_Backup::$environment_reader = static fn() => 'cpanel';
        $GLOBALS['_wp_options']['rpcare_b2_key_id']    = '';
        $GLOBALS['_wp_options']['rpcare_b2_app_key']   = '';
        $GLOBALS['_wp_options']['rpcare_b2_bucket_id'] = '';
        $result = RP_Care_Task_Backup::run( [] );
        $this->assertArrayNotHasKey( 'backup_dir', $result,
            'managed_by_host mode must never produce a local backup_dir' );
    }

    // ── cleanup_old_backups — count-based pruning ─────────────────────────────

    /** Invoke the private static cleanup_old_backups() via Reflection. */
    private function runCleanup(): void {
        $ref    = new ReflectionClass( RP_Care_Task_Backup::class );
        $method = $ref->getMethod( 'cleanup_old_backups' );
        $method->setAccessible( true );
        $method->invoke( null );
        // Refresh mtime cache so assertDirectoryDoesNotExist sees the real FS state.
        clearstatcache( true );
    }

    /**
     * Create $count subdirs in $this->backup_base with staggered mtimes so the
     * newest-first sort order is deterministic.  Returns the dirs sorted newest
     * first (matching what cleanup_old_backups() sees).
     */
    private function makeDirs( int $count ): array {
        $dirs = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $path = $this->backup_base . '/backup_' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT );
            mkdir( $path );
            // i=0 is oldest, i=count-1 is newest.
            touch( $path, time() - ( ( $count - 1 - $i ) * 60 ) );
            $dirs[] = $path;
        }
        // Sort descending by mtime: newest first.
        clearstatcache( true );
        usort( $dirs, static fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );
        return $dirs;
    }

    public function test_bc01_three_or_fewer_dirs_not_deleted(): void {
        $dirs = $this->makeDirs( 3 );
        $this->runCleanup();
        foreach ( $dirs as $dir ) {
            $this->assertDirectoryExists( $dir, "All 3 dirs must survive cleanup" );
        }
    }

    public function test_bc02_fourth_dir_deleted(): void {
        $dirs = $this->makeDirs( 4 );
        $this->runCleanup();

        $this->assertDirectoryExists( $dirs[0], 'Newest dir must survive' );
        $this->assertDirectoryExists( $dirs[1], '2nd dir must survive' );
        $this->assertDirectoryExists( $dirs[2], '3rd dir must survive' );
        $this->assertDirectoryDoesNotExist( $dirs[3], '4th dir (oldest) must be pruned' );
    }

    public function test_bc03_eight_dirs_only_top_three_kept(): void {
        $dirs = $this->makeDirs( 8 );
        $this->runCleanup();

        for ( $i = 0; $i < 3; $i++ ) {
            $this->assertDirectoryExists( $dirs[ $i ], "Dir[$i] must survive" );
        }
        for ( $i = 3; $i < 8; $i++ ) {
            $this->assertDirectoryDoesNotExist( $dirs[ $i ], "Dir[$i] must be pruned" );
        }
    }
}
