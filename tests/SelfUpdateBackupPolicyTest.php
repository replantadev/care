<?php

use PHPUnit\Framework\TestCase;

final class SelfUpdateBackupPolicyTest extends TestCase {
    public function test_self_update_backup_is_gated_by_the_effective_b2_provider(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/inc/class-rest.php' );

        $this->assertStringContainsString(
            "RP_Care_Environment::get_effective_backup_mode()",
            $source
        );
        $mode_gate  = strpos( $source, "'b2' === \$effective_backup_mode" );
        $credential = strpos( $source, 'RP_Care_Task_Backup::is_b2_configured_public()', $mode_gate );

        $this->assertNotFalse( $mode_gate );
        $this->assertNotFalse( $credential );
        $this->assertLessThan( 300, $credential - $mode_gate );
    }

    public function test_self_update_never_runs_the_b2_backup_on_staging(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/inc/class-rest.php' );

        $staging_gate = strpos( $source, '! $is_pipeline_staging' );
        $mode_gate    = strpos( $source, "'b2' === \$effective_backup_mode", $staging_gate );

        $this->assertNotFalse( $staging_gate );
        $this->assertNotFalse( $mode_gate );
        $this->assertLessThan( 120, $mode_gate - $staging_gate );
    }
}
