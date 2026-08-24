<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class PolicyProjectionContractTest extends TestCase {
    public function test_config_accepts_and_persists_pc_policy_projection(): void {
        $source = (string) file_get_contents( __DIR__ . '/../inc/class-rest.php' );
        foreach ( [ 'smart_updates_mode', 'approval_policy', 'staging_method', 'maximum_batch_size', 'maintenance_window_auto' ] as $field ) {
            $this->assertStringContainsString( "'{$field}'", $source );
        }
        $this->assertStringContainsString( "update_option( 'rpcare_options', \$opts )", $source );
    }

    public function test_local_admin_sees_policy_and_ecommerce_entitlements(): void {
        $source = (string) file_get_contents( __DIR__ . '/../inc/settings-page.php' );
        $this->assertStringContainsString( 'Política Smart Updates desde Plugin Center', $source );
        $this->assertStringContainsString( 'Staging obligatorio', $source );
        $this->assertStringContainsString( 'Impulso Ecommerce', $source );
        $this->assertStringContainsString( 'Backups cada 12 h', $source );
        $this->assertStringContainsString( 'Retención 90 días', $source );
    }

    public function test_ecommerce_retention_is_applied_to_b2_cleanup(): void {
        $source = (string) file_get_contents( __DIR__ . '/../inc/integrations-backup.php' );
        $this->assertStringContainsString( "is_active( 'ecommerce' )", $source );
        $this->assertStringContainsString( "['backup_retention_days'] ?? 90", $source );
    }
}
