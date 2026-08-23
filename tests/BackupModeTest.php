<?php
/**
 * Care 1.16.7 — Backup mode system
 *
 * Tests the backup mode configuration and routing introduced in 1.16.7.
 *
 * RP_Care_Environment — get_effective_backup_mode() auto-detection:
 *   BM-01  environment 'local'      → 'local_dev'
 *   BM-02  environment 'cyberpanel' → 'b2'
 *   BM-03  environment 'cpanel'     → 'managed_by_host'
 *   BM-04  environment 'external'   → 'managed_by_host'
 *   BM-05  environment 'wptoolkit'  → 'managed_by_host'
 *   BM-06  explicit mode overrides environment auto-detection
 *   BM-07  invalid stored mode falls back to 'auto'
 *
 * RP_Care_Task_Backup::run() routing per mode:
 *   BR-05  mode 'disabled'        → success=true, skipped=true, managed_by_hub=true, no backup_dir
 *   BR-06  mode 'b2' unconfigured → success=false, method='b2', no backup_dir
 *   BR-07  mode 'managed_by_host' → routes to handle_whm_backup() (managed_by_hub=true)
 *
 * Safety:
 *   SAFETY-01  non-local_dev mode must never produce backup_dir in results
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-environment.php';
require_once __DIR__ . '/../inc/integrations-backup.php';

use PHPUnit\Framework\TestCase;

// ── Stubs needed only by integrations-backup.php ─────────────────────────────

if ( ! function_exists( 'is_plugin_active' ) ) {
    function is_plugin_active( string $p ): bool { return false; }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( string $path = '' ): string { return 'https://example.com' . $path; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'get_current_user' ) ) {
    function get_current_user(): string { return 'testuser'; }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( string $path ): bool {
        return is_dir( $path ) || mkdir( $path, 0777, true );
    }
}

if ( ! defined( 'RPCARE_TESTING' ) ) define( 'RPCARE_TESTING', true );
if ( ! defined( 'DB_NAME' ) )        define( 'DB_NAME', 'test_db' );

// ─────────────────────────────────────────────────────────────────────────────

class BackupModeTest extends TestCase {

    /** Temp base dir for cleanup tests. */
    private string $backup_base;

    protected function setUp(): void {
        // Clear WP option store so each test starts clean.
        $GLOBALS['_wp_options'] = [];

        // Clear environment class static cache.
        delete_option( RP_Care_Environment::OPT_KEY );

        // Reset backup seams.
        RP_Care_Task_Backup::$environment_reader      = null;
        RP_Care_Task_Backup::$backup_base_dir_override = null;
        $this->backup_base = sys_get_temp_dir() . '/rpcare_bm_' . uniqid();
        mkdir( $this->backup_base, 0777, true );
        RP_Care_Task_Backup::$backup_base_dir_override = $this->backup_base;

        // Clear WP Toolkit detection seam.
        unset( $GLOBALS['_is_wptoolkit'] );
    }

    protected function tearDown(): void {
        RP_Care_Task_Backup::$environment_reader      = null;
        RP_Care_Task_Backup::$backup_base_dir_override = null;
        if ( is_dir( $this->backup_base ) ) {
            $this->rmdirR( $this->backup_base );
        }
        unset( $GLOBALS['_is_wptoolkit'] );
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

    // ── BM: get_effective_backup_mode() auto-detection ───────────────────────

    /** @dataProvider auto_detection_provider */
    public function test_bm_auto_detection( string $env, string $expected_mode ): void {
        $GLOBALS['_wp_options']['rpcare_options'] = []; // no explicit backup_mode set
        $this->assertSame(
            $expected_mode,
            RP_Care_Environment::get_effective_backup_mode( $env ),
            "Environment '{$env}' must auto-detect to mode '{$expected_mode}'"
        );
    }

    public static function auto_detection_provider(): array {
        return [
            'BM-01 local→local_dev'           => [ 'local',      'local_dev'       ],
            'BM-02 cyberpanel→b2'              => [ 'cyberpanel', 'b2'              ],
            'BM-03 cpanel→managed_by_host'     => [ 'cpanel',     'managed_by_host' ],
            'BM-04 external→managed_by_host'   => [ 'external',   'managed_by_host' ],
            'BM-05 wptoolkit→managed_by_host'  => [ 'wptoolkit',  'managed_by_host' ],
        ];
    }

    public function test_bm06_explicit_mode_overrides_environment(): void {
        $GLOBALS['_wp_options']['rpcare_options'] = [ 'backup_mode' => 'updraftplus' ];
        // Even though env is 'cyberpanel' (which would auto → 'b2'), explicit mode wins.
        $this->assertSame(
            'updraftplus',
            RP_Care_Environment::get_effective_backup_mode( 'cyberpanel' ),
            'Explicit backup_mode must override environment auto-detection'
        );
    }

    public function test_bm07_invalid_stored_mode_falls_back_to_auto(): void {
        $GLOBALS['_wp_options']['rpcare_options'] = [ 'backup_mode' => 'not_a_real_mode' ];
        $this->assertSame( 'auto', RP_Care_Environment::get_backup_mode(),
            'Invalid stored mode must normalise to auto' );
        // auto + cyberpanel env → b2
        $this->assertSame( 'b2', RP_Care_Environment::get_effective_backup_mode( 'cyberpanel' ) );
    }

    // ── BR: run() routing per mode ────────────────────────────────────────────

    /**
     * Sets rpcare_options['backup_mode'] and runs the backup task.
     * RP_Care_Environment IS loaded here (included above), so the real class is used.
     */
    private function runWithMode( string $mode, string $env = 'external' ): array {
        $GLOBALS['_wp_options']['rpcare_options']       = [ 'backup_mode' => $mode ];
        $GLOBALS['_wp_options']['rpcare_b2_key_id']    = '';
        $GLOBALS['_wp_options']['rpcare_b2_app_key']   = '';
        $GLOBALS['_wp_options']['rpcare_b2_bucket_id'] = '';
        RP_Care_Task_Backup::$environment_reader       = static fn() => $env;
        // Clear the environment class cache so it re-reads from options.
        delete_option( RP_Care_Environment::OPT_KEY );
        return RP_Care_Task_Backup::run( [] );
    }

    public function test_br05_disabled_mode_skips_backup(): void {
        $result = $this->runWithMode( 'disabled' );
        $this->assertTrue( $result['success']        ?? false, 'disabled must return success=true' );
        $this->assertTrue( $result['skipped']        ?? false, 'disabled must return skipped=true' );
        $this->assertTrue( $result['managed_by_hub'] ?? false, 'disabled must return managed_by_hub=true' );
        $this->assertSame( 'disabled', $result['method'] ?? '', 'disabled must set method=disabled' );
    }

    public function test_br06_b2_mode_without_credentials_returns_error(): void {
        $result = $this->runWithMode( 'b2' );
        $this->assertFalse( $result['success'] ?? true, 'b2 without credentials must return success=false' );
        $this->assertSame( 'b2', $result['method'] ?? '', 'must report method=b2' );
        $this->assertArrayNotHasKey( 'backup_dir', $result, 'must not produce a local backup_dir' );
    }

    public function test_br07_managed_by_host_routes_to_whm_handler(): void {
        $result = $this->runWithMode( 'managed_by_host' );
        $this->assertTrue( $result['managed_by_hub'] ?? false,
            'managed_by_host must call handle_whm_backup() which sets managed_by_hub=true' );
    }

    // ── Safety ────────────────────────────────────────────────────────────────

    /** @dataProvider non_local_dev_modes_provider */
    public function test_safety01_non_local_dev_never_produces_backup_dir( string $mode ): void {
        $result = $this->runWithMode( $mode );
        $this->assertArrayNotHasKey( 'backup_dir', $result,
            "Mode '{$mode}' must NEVER produce a local backup_dir (safety constraint)" );
    }

    public static function non_local_dev_modes_provider(): array {
        return [
            'b2'              => [ 'b2'              ],
            'cloudflare_r2'   => [ 'cloudflare_r2'   ],
            's3'              => [ 's3'              ],
            'updraftplus'     => [ 'updraftplus'     ],
            'managed_by_host' => [ 'managed_by_host' ],
            'disabled'        => [ 'disabled'        ],
        ];
    }
}
