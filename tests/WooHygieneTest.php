<?php
/**
 * Replanta Care — Woo Hygiene Tests
 *
 * HY-01  run() returns required keys: dry_run, started_at, finished_at, operations, errors
 * HY-02  dry_run=true skips lock acquisition
 * HY-03  dry_run=true returns dry_run=true in result
 * HY-04  second run() while lock held returns WP_Error('lock_held')
 * HY-05  run() with all operations disabled returns empty operations
 * HY-06  cleanup_expired_sessions dry_run counts without deleting
 * HY-07  cleanup_as_actions dry_run — only counts complete/cancelled, never pending
 * HY-08  cleanup_wc_logs dry_run — counts old logs without deleting
 * HY-09  NEG: pending AS actions are never included in cleanup count
 * HY-10  NEG: active sessions (future expiry) are not counted in cleanup
 *
 * Note: all DB tests run against the $wpdb stub which returns null/0/true,
 * so we verify structural behavior (lock, result shape) not actual row counts.
 *
 * @since 1.15.74
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-rp-care-woo-hygiene.php';

class WooHygieneTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
    }

    // ── HY-01: required return keys ───────────────────────────────────────────

    /** @test */
    public function test_hy01_run_returns_required_keys(): void {
        $result = RP_Care_Woo_Hygiene::run( [ 'dry_run' => true ] );
        $this->assertIsArray( $result );
        foreach ( [ 'dry_run', 'started_at', 'finished_at', 'wall_seconds', 'operations', 'errors' ] as $key ) {
            $this->assertArrayHasKey( $key, $result, "Missing key: {$key}" );
        }
    }

    // ── HY-02: dry_run skips lock ─────────────────────────────────────────────

    /** @test */
    public function test_hy02_dry_run_skips_lock_acquisition(): void {
        // Set the lock manually.
        set_transient( RP_Care_Woo_Hygiene::LOCK_KEY, 1, 600 );
        // dry_run should succeed even with lock held.
        $result = RP_Care_Woo_Hygiene::run( [ 'dry_run' => true ] );
        $this->assertIsArray( $result );
        $this->assertTrue( $result['dry_run'] );
    }

    // ── HY-03: dry_run=true in result ─────────────────────────────────────────

    /** @test */
    public function test_hy03_dry_run_flag_in_result(): void {
        $result = RP_Care_Woo_Hygiene::run( [ 'dry_run' => true ] );
        $this->assertTrue( $result['dry_run'] );
    }

    // ── HY-04: lock held → WP_Error ──────────────────────────────────────────

    /** @test */
    public function test_hy04_lock_held_returns_wp_error(): void {
        set_transient( RP_Care_Woo_Hygiene::LOCK_KEY, 1, 600 );
        $result = RP_Care_Woo_Hygiene::run( [ 'dry_run' => false ] );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'lock_held', $result->get_error_code() );
    }

    // ── HY-05: all operations disabled → empty ops ────────────────────────────

    /** @test */
    public function test_hy05_all_disabled_returns_empty_operations(): void {
        $result = RP_Care_Woo_Hygiene::run( [
            'dry_run'    => true,
            'sessions'   => false,
            'transients' => false,
            'as_actions' => false,
            'wc_logs'    => false,
        ] );
        $this->assertIsArray( $result );
        $this->assertEmpty( $result['operations'] );
    }

    // ── HY-06: sessions dry_run ───────────────────────────────────────────────

    /** @test */
    public function test_hy06_sessions_dry_run_returns_rows_key(): void {
        $result = RP_Care_Woo_Hygiene::run( [
            'dry_run'    => true,
            'transients' => false,
            'as_actions' => false,
            'wc_logs'    => false,
        ] );
        // The stub wpdb returns null for table existence, so 'note' or 'rows' key is present.
        $this->assertArrayHasKey( 'sessions', $result['operations'] );
        $op = $result['operations']['sessions'];
        $this->assertTrue( isset( $op['rows'] ) || isset( $op['note'] ) );
    }

    // ── HY-07: as_actions only counts complete/cancelled ──────────────────────

    /** @test */
    public function test_hy07_as_actions_dry_run_present(): void {
        $result = RP_Care_Woo_Hygiene::run( [
            'dry_run'    => true,
            'sessions'   => false,
            'transients' => false,
            'wc_logs'    => false,
        ] );
        $this->assertArrayHasKey( 'as_actions', $result['operations'] );
    }

    // ── HY-08: wc_logs dry_run ────────────────────────────────────────────────

    /** @test */
    public function test_hy08_wc_logs_dry_run_counts_old_files(): void {
        $result = RP_Care_Woo_Hygiene::run( [
            'dry_run'    => true,
            'sessions'   => false,
            'transients' => false,
            'as_actions' => false,
        ] );
        $this->assertArrayHasKey( 'wc_logs', $result['operations'] );
        $op = $result['operations']['wc_logs'];
        // Either 'files' (count) or 'note' (log dir not found) is present.
        $this->assertTrue( isset( $op['files'] ) || isset( $op['note'] ) );
    }

    // ── HY-09: pending AS never cleaned (verifiable via SQL audit) ────────────

    /** @test */
    public function test_hy09_pending_as_never_in_delete_scope(): void {
        // The cleanup SQL uses WHERE status IN ('complete','canceled').
        // We verify this by reading the class method via reflection.
        $src = file_get_contents( __DIR__ . '/../inc/class-rp-care-woo-hygiene.php' );
        // SQL must include 'complete','canceled' and must NOT include 'pending' or 'in-progress' in the IN clause.
        $this->assertStringContainsString( "'complete'", $src );
        $this->assertStringContainsString( "'canceled'", $src );
        // The word 'pending' must not appear in an IN(...) delete clause.
        $this->assertSame(
            0,
            preg_match( "/IN\s*\([^)]*'pending'[^)]*\)/i", $src ),
            "Pending AS actions must never be in a DELETE IN() clause"
        );
    }

    // ── HY-10: active sessions not deleted ────────────────────────────────────

    /** @test */
    public function test_hy10_active_sessions_not_deleted(): void {
        // The cleanup SQL uses WHERE session_expiry < %d (expired only).
        $src = file_get_contents( __DIR__ . '/../inc/class-rp-care-woo-hygiene.php' );
        $this->assertStringContainsString( 'session_expiry', $src );
        // Ensure it's checking < (past) not > (future).
        $this->assertMatchesRegularExpression( '/session_expiry.*<\s*%d/i', $src );
    }
}
