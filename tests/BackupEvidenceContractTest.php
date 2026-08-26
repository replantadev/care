<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../inc/class-backup-provider.php';
require_once __DIR__ . '/../inc/class-environment.php';

use PHPUnit\Framework\TestCase;

final class BackupEvidenceContractTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
    }

    public function test_managed_host_never_reuses_stale_b2_evidence(): void {
        update_option( 'rpcare_last_b2_backup', [
            'backup_id' => 'old-b2', 'timestamp' => time(), 'status' => 'complete',
            'artifacts' => [ [ 'file_name' => 'db.zip' ] ],
        ] );

        $report = RP_Care_Environment::get_backup_report( 'managed_by_host' );

        $this->assertSame( 'hosting_observed', $report['backup_provider'] );
        $this->assertNull( $report['backup_evidence_id'] );
        $this->assertFalse( $report['backup_usable'] );
    }

    public function test_complete_b2_with_artifacts_is_verified_and_restorable(): void {
        update_option( 'rpcare_b2_key_id', 'key-id' );
        update_option( 'rpcare_b2_app_key', 'secret' );
        update_option( 'rpcare_b2_bucket_id', 'bucket' );
        update_option( 'rpcare_last_b2_backup', [
            'backup_id' => 'backup-123', 'timestamp' => 1787745600,
            'status' => 'complete', 'artifacts' => [ [ 'file_name' => 'database.zip' ] ],
        ] );

        $report = RP_Care_Environment::get_backup_report( 'b2' );

        $this->assertTrue( $report['backup_configured'] );
        $this->assertSame( 'backup-123', $report['backup_evidence_id'] );
        $this->assertTrue( $report['backup_verified'] );
        $this->assertTrue( $report['backup_restore_available'] );
        $this->assertTrue( $report['backup_usable'] );
    }

    public function test_b2_without_artifacts_is_not_usable_even_if_marked_complete(): void {
        update_option( 'rpcare_last_b2_backup', [
            'backup_id' => 'empty-backup', 'timestamp' => time(), 'status' => 'complete',
            'artifacts' => [],
        ] );

        $report = RP_Care_Environment::get_backup_report( 'b2' );

        $this->assertFalse( $report['backup_verified'] );
        $this->assertFalse( $report['backup_usable'] );
    }

    public function test_backuply_evidence_is_observed_but_not_rollback_capable(): void {
        update_option( 'backuply_backup_list', [ [
            'id' => 'backuply-7', 'time' => 1787745600, 'status' => 'completed', 'size' => 100,
        ] ] );

        $report = RP_Care_Environment::get_backup_report( 'managed_by_host' );

        $this->assertSame( 'backuply_observed', $report['backup_provider'] );
        $this->assertSame( 'backuply-7', $report['backup_evidence_id'] );
        $this->assertTrue( $report['backup_verified'] );
        $this->assertFalse( $report['backup_restore_available'] );
        $this->assertFalse( $report['backup_usable'] );
    }

    public function test_unimplemented_remote_provider_fails_closed(): void {
        $report = RP_Care_Environment::get_backup_report( 'cloudflare_r2' );

        $this->assertSame( 'provider_not_implemented', $report['backup_status'] );
        $this->assertFalse( $report['backup_configured'] );
        $this->assertFalse( $report['backup_usable'] );
    }
}
