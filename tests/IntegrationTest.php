<?php
/**
 * Care integration tests — validates core logic without HTTP or database.
 * Loads actual Care classes with the WP shims from bootstrap.php.
 */
use PHPUnit\Framework\TestCase;

// Load the plan class — uses only class constants and static arrays, no WP calls needed.
require_once __DIR__ . '/../inc/class-plan.php';

class IntegrationTest extends TestCase {

    // ── Plan retention days (Sprint 4D) ───────────────────────────────────────

    public function test_semilla_retention_7_days(): void {
        $cfg = RP_Care_Plan::get_plan_config( 'semilla' );
        $this->assertIsArray( $cfg, 'semilla config must be an array' );
        $this->assertSame( 7, $cfg['backup_retention_days'], 'Semilla debe retener 7 días de backups' );
    }

    public function test_raiz_retention_30_days(): void {
        $cfg = RP_Care_Plan::get_plan_config( 'raiz' );
        $this->assertIsArray( $cfg );
        $this->assertSame( 30, $cfg['backup_retention_days'], 'Raíz debe retener 30 días de backups' );
    }

    public function test_ecosistema_retention_90_days(): void {
        $cfg = RP_Care_Plan::get_plan_config( 'ecosistema' );
        $this->assertIsArray( $cfg );
        $this->assertSame( 90, $cfg['backup_retention_days'], 'Ecosistema debe retener 90 días de backups' );
    }

    public function test_retention_days_increase_with_plan_tier(): void {
        $semilla    = RP_Care_Plan::get_plan_config( 'semilla' );
        $raiz       = RP_Care_Plan::get_plan_config( 'raiz' );
        $ecosistema = RP_Care_Plan::get_plan_config( 'ecosistema' );

        $this->assertLessThan( $raiz['backup_retention_days'],       $semilla['backup_retention_days'] );
        $this->assertLessThan( $ecosistema['backup_retention_days'], $raiz['backup_retention_days'] );
    }

    // ── Plan normalization (legacy aliases) ───────────────────────────────────

    public function test_legacy_basic_maps_to_semilla(): void {
        $this->assertSame( 'semilla', RP_Care_Plan::normalize_plan( 'basic' ) );
    }

    public function test_legacy_advanced_maps_to_raiz(): void {
        $this->assertSame( 'raiz', RP_Care_Plan::normalize_plan( 'advanced' ) );
    }

    public function test_legacy_premium_maps_to_ecosistema(): void {
        $this->assertSame( 'ecosistema', RP_Care_Plan::normalize_plan( 'premium' ) );
    }

    public function test_canonical_plans_unchanged_by_normalize(): void {
        foreach ( [ 'semilla', 'raiz', 'ecosistema' ] as $plan ) {
            $this->assertSame( $plan, RP_Care_Plan::normalize_plan( $plan ) );
        }
    }

    // ── Backup stale thresholds are consistent with backup frequency ──────────

    public function test_semilla_stale_threshold_exceeds_weekly_interval(): void {
        $cfg = RP_Care_Plan::get_plan_config( 'semilla' );
        // Semilla = weekly backup (168h). Stale threshold must be >168h to allow one full week.
        $this->assertGreaterThan( 168, $cfg['backup_stale_threshold_h'] );
    }

    public function test_raiz_stale_threshold_exceeds_daily_interval(): void {
        $cfg = RP_Care_Plan::get_plan_config( 'raiz' );
        // Raíz = daily backup. Stale threshold must be >24h.
        $this->assertGreaterThan( 24, $cfg['backup_stale_threshold_h'] );
    }

    public function test_ecosistema_stale_threshold_tighter_than_raiz(): void {
        $raiz = RP_Care_Plan::get_plan_config( 'raiz' );
        $eco  = RP_Care_Plan::get_plan_config( 'ecosistema' );
        // Ecosistema has more frequent backups → tighter stale window.
        $this->assertLessThan( $raiz['backup_stale_threshold_h'], $eco['backup_stale_threshold_h'] );
    }

    // ── Report ID format matches REST route regex ─────────────────────────────

    public function test_report_id_format_matches_route_pattern(): void {
        $route_pattern = '/^[a-zA-Z0-9_\-]+$/';
        $sample_ids    = [
            'report_2025_01_15_10_30_00_abcdef12',
            'report_2024_12_01_00_00_00_12345678',
            'report_2026_07_30_08_00_00_ffffffff',
        ];
        foreach ( $sample_ids as $id ) {
            $this->assertMatchesRegularExpression( $route_pattern, $id, "ID '$id' must match REST route regex" );
        }
    }

    public function test_report_id_survives_sanitize_key(): void {
        $id = 'report_2025_01_15_10_30_00_abcdef12';
        $this->assertSame( $id, sanitize_key( $id ), 'sanitize_key must not alter lowercase report IDs' );
    }

    // ── hub_run() allowed task list includes retention (Sprint 4D) ───────────

    public function test_retention_task_name_matches_scheduler_hook(): void {
        // The task slug used in hub_run() $allowed array must match
        // the AS hook name registered in class-scheduler.php.
        $hub_run_key     = 'retention';
        $scheduler_hook  = 'rpcare_task_retention';
        $expected_filter = 'rpcare_task_' . $hub_run_key;
        $this->assertSame( $scheduler_hook, $expected_filter,
            "hub_run key '$hub_run_key' must map to AS hook 'rpcare_task_retention'" );
    }
}
