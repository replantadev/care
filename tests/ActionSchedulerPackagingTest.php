<?php
use PHPUnit\Framework\TestCase;

final class ActionSchedulerPackagingTest extends TestCase {

    public function test_production_package_contains_action_scheduler_bootstrap_and_migration_config(): void {
        $root = dirname( __DIR__ );

        $this->assertFileExists(
            $root . '/vendor/woocommerce/action-scheduler/action-scheduler.php',
            'The production package must contain the Action Scheduler bootstrap.'
        );
        $this->assertFileExists(
            $root . '/vendor/woocommerce/action-scheduler/classes/migration/Config.php',
            'The production package must contain the migration Config class required at runtime.'
        );
    }

    public function test_care_bootstraps_bundled_action_scheduler(): void {
        $plugin = file_get_contents( dirname( __DIR__ ) . '/replanta-care.php' );

        $this->assertIsString( $plugin );
        $this->assertStringContainsString(
            "vendor/woocommerce/action-scheduler/action-scheduler.php",
            $plugin,
            'Care must explicitly load Action Scheduler because Composer autoload alone does not bootstrap it.'
        );
    }
}
