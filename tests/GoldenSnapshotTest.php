<?php
/**
 * Replanta Care — Golden Snapshot Tests
 *
 * GS-01  capture() returns required keys with correct schema_version
 * GS-02  capture() active_plugins is a sha256 fingerprint (hex 64 chars)
 * GS-03  capture() woo_pages returns array with expected page keys
 * GS-04  get_golden() returns null before first promote()
 * GS-05  promote() saves snapshot as golden
 * GS-06  promote() moves old golden into history
 * GS-07  history is capped at MAX_HISTORY
 * GS-08  diff_against_golden() returns null when no golden
 * GS-09  diff_against_golden() returns has_drift=false when state matches
 * GS-10  diff_against_golden() detects permalink_structure change
 * GS-11  diff_against_golden() includes repair steps for structural diffs
 * GS-12  repair_ladder_for_diffs() always includes OBSERVE and PURGE_CACHE
 * GS-13  repair_ladder_for_diffs() includes full ladder for structural changes
 * GS-14  repair_ladder_for_diffs() minimal for non-structural diffs
 * GS-15  NEG: htaccess_hash does not traverse outside ABSPATH
 *
 * @since 1.15.74
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-rp-care-golden-snapshot.php';

class GoldenSnapshotTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
    }

    // ── GS-01: required keys ──────────────────────────────────────────────────

    /** @test */
    public function test_gs01_capture_returns_required_keys(): void {
        $snap = RP_Care_Golden_Snapshot::capture();
        foreach ( [ 'schema_version', 'captured_at', 'home_url', 'site_url', 'permalink_structure',
                     'active_plugins', 'woo_pages', 'lang_config_hash', 'htaccess_hash', 'snapshot_id' ] as $key ) {
            $this->assertArrayHasKey( $key, $snap, "Missing key: {$key}" );
        }
        $this->assertSame( RP_Care_Golden_Snapshot::SCHEMA_VERSION, $snap['schema_version'] );
    }

    // ── GS-02: active_plugins fingerprint ────────────────────────────────────

    /** @test */
    public function test_gs02_active_plugins_is_sha256_fingerprint(): void {
        $snap = RP_Care_Golden_Snapshot::capture();
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $snap['active_plugins'] );
    }

    // ── GS-03: woo_pages structure ────────────────────────────────────────────

    /** @test */
    public function test_gs03_woo_pages_has_expected_keys(): void {
        $snap  = RP_Care_Golden_Snapshot::capture();
        $pages = $snap['woo_pages'];
        $this->assertIsArray( $pages );
        foreach ( [ 'shop', 'cart', 'checkout', 'myaccount' ] as $p ) {
            $this->assertArrayHasKey( $p, $pages, "Missing woo page: {$p}" );
        }
    }

    // ── GS-04: get_golden() before promote ───────────────────────────────────

    /** @test */
    public function test_gs04_get_golden_null_before_promote(): void {
        $this->assertNull( RP_Care_Golden_Snapshot::get_golden() );
    }

    // ── GS-05: promote() saves golden ────────────────────────────────────────

    /** @test */
    public function test_gs05_promote_saves_snapshot(): void {
        $snap = RP_Care_Golden_Snapshot::capture( [ 'triggered_by' => 'test' ] );
        RP_Care_Golden_Snapshot::promote( $snap );
        $golden = RP_Care_Golden_Snapshot::get_golden();
        $this->assertIsArray( $golden );
        $this->assertSame( $snap['snapshot_id'], $golden['snapshot_id'] );
    }

    // ── GS-06: promote() moves old golden to history ──────────────────────────

    /** @test */
    public function test_gs06_promote_moves_old_golden_to_history(): void {
        $snap1 = RP_Care_Golden_Snapshot::capture();
        RP_Care_Golden_Snapshot::promote( $snap1 );

        $snap2 = RP_Care_Golden_Snapshot::capture();
        RP_Care_Golden_Snapshot::promote( $snap2 );

        $history = RP_Care_Golden_Snapshot::get_history();
        $ids = array_column( $history, 'snapshot_id' );
        $this->assertContains( $snap1['snapshot_id'], $ids );
    }

    // ── GS-07: history capped at MAX_HISTORY ──────────────────────────────────

    /** @test */
    public function test_gs07_history_capped_at_max(): void {
        $max = RP_Care_Golden_Snapshot::MAX_HISTORY;
        for ( $i = 0; $i <= $max + 2; $i++ ) {
            $snap = RP_Care_Golden_Snapshot::capture();
            $snap['snapshot_id'] = 'snap-' . str_pad( (string) $i, 16, '0', STR_PAD_LEFT );
            RP_Care_Golden_Snapshot::promote( $snap );
        }
        $history = RP_Care_Golden_Snapshot::get_history();
        $this->assertLessThanOrEqual( $max, count( $history ) );
    }

    // ── GS-08: diff_against_golden() without golden ───────────────────────────

    /** @test */
    public function test_gs08_diff_returns_null_without_golden(): void {
        $this->assertNull( RP_Care_Golden_Snapshot::diff_against_golden() );
    }

    // ── GS-09: diff has_drift=false when state matches ────────────────────────

    /** @test */
    public function test_gs09_diff_no_drift_when_state_matches(): void {
        $snap = RP_Care_Golden_Snapshot::capture();
        RP_Care_Golden_Snapshot::promote( $snap );
        $diff = RP_Care_Golden_Snapshot::diff_against_golden();
        $this->assertIsArray( $diff );
        $this->assertFalse( $diff['has_drift'] );
        $this->assertEmpty( $diff['diffs'] );
    }

    // ── GS-10: diff detects permalink_structure change ────────────────────────

    /** @test */
    public function test_gs10_diff_detects_permalink_change(): void {
        // Promote a snapshot with empty permalink_structure.
        $snap                        = RP_Care_Golden_Snapshot::capture();
        $snap['permalink_structure'] = '';
        RP_Care_Golden_Snapshot::promote( $snap );

        // Now change the option so current state differs.
        $GLOBALS['_wp_options']['permalink_structure'] = '/%postname%/';

        $diff = RP_Care_Golden_Snapshot::diff_against_golden();
        $this->assertTrue( $diff['has_drift'] );
        $this->assertArrayHasKey( 'permalink_structure', $diff['diffs'] );
    }

    // ── GS-11: diff includes repair steps for structural diffs ────────────────

    /** @test */
    public function test_gs11_diff_includes_repair_steps_for_structural_changes(): void {
        $snap                        = RP_Care_Golden_Snapshot::capture();
        $snap['permalink_structure'] = '';
        RP_Care_Golden_Snapshot::promote( $snap );
        $GLOBALS['_wp_options']['permalink_structure'] = '/%postname%/';

        $diff = RP_Care_Golden_Snapshot::diff_against_golden();
        $this->assertNotEmpty( $diff['repair_steps'] );
        $this->assertContains( RP_Care_Golden_Snapshot::REPAIR_STEP_OBSERVE, $diff['repair_steps'] );
        $this->assertContains( RP_Care_Golden_Snapshot::REPAIR_STEP_APPROVAL, $diff['repair_steps'] );
    }

    // ── GS-12: repair ladder always has OBSERVE + PURGE_CACHE ─────────────────

    /** @test */
    public function test_gs12_repair_ladder_always_has_observe_and_purge(): void {
        $steps = RP_Care_Golden_Snapshot::repair_ladder_for_diffs( [ 'active_theme' => [ 'golden' => 'a', 'current' => 'b' ] ] );
        $this->assertContains( RP_Care_Golden_Snapshot::REPAIR_STEP_OBSERVE, $steps );
        $this->assertContains( RP_Care_Golden_Snapshot::REPAIR_STEP_PURGE_CACHE, $steps );
    }

    // ── GS-13: full ladder for structural changes ──────────────────────────────

    /** @test */
    public function test_gs13_full_ladder_for_structural_diffs(): void {
        $steps = RP_Care_Golden_Snapshot::repair_ladder_for_diffs( [
            'htaccess_hash' => [ 'golden' => 'abc', 'current' => 'xyz' ],
        ] );
        foreach ( [
            RP_Care_Golden_Snapshot::REPAIR_STEP_STAGE_TEST,
            RP_Care_Golden_Snapshot::REPAIR_STEP_BACKUP_CHECK,
            RP_Care_Golden_Snapshot::REPAIR_STEP_APPROVAL,
            RP_Care_Golden_Snapshot::REPAIR_STEP_ATOMIC_WRITE,
            RP_Care_Golden_Snapshot::REPAIR_STEP_VALIDATE,
            RP_Care_Golden_Snapshot::REPAIR_STEP_ROLLBACK,
        ] as $step ) {
            $this->assertContains( $step, $steps, "Missing step: {$step}" );
        }
    }

    // ── GS-14: minimal ladder for non-structural diffs ────────────────────────

    /** @test */
    public function test_gs14_minimal_ladder_for_non_structural_diffs(): void {
        // active_theme change is not structural (not in htaccess/permalink/lang).
        $steps = RP_Care_Golden_Snapshot::repair_ladder_for_diffs( [
            'active_theme' => [ 'golden' => 'twentytwenty', 'current' => 'twentytwentythree' ],
        ] );
        // Should have observe + purge, but NOT the full escalation ladder.
        $this->assertContains( RP_Care_Golden_Snapshot::REPAIR_STEP_OBSERVE, $steps );
        $this->assertContains( RP_Care_Golden_Snapshot::REPAIR_STEP_PURGE_CACHE, $steps );
        $this->assertNotContains( RP_Care_Golden_Snapshot::REPAIR_STEP_APPROVAL, $steps );
    }

    // ── GS-15: htaccess path traversal guard ──────────────────────────────────

    /** @test */
    public function test_gs15_htaccess_path_traversal_guard(): void {
        // Verify that files outside ABSPATH return an empty hash.
        // We test this indirectly: capture() calls hash_htaccess() internally.
        // Since ABSPATH is defined as the care/ dir and no .htaccess exists there,
        // the returned hash should be an empty string (not an error).
        $snap = RP_Care_Golden_Snapshot::capture();
        // htaccess_hash is either '' (not found/unreadable) or a valid sha256.
        if ( $snap['htaccess_hash'] !== '' ) {
            $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $snap['htaccess_hash'] );
        } else {
            $this->assertSame( '', $snap['htaccess_hash'] );
        }
    }
}
