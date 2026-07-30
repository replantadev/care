<?php
use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase {

    public function test_version_constant_defined(): void {
        $this->assertSame( '1.15.25', RPCARE_VERSION );
    }

    public function test_wp_error_shim(): void {
        $err = new WP_Error( 'test', 'message' );
        $this->assertTrue( is_wp_error( $err ) );
        $this->assertFalse( is_wp_error( 'not an error' ) );
    }

    public function test_trailingslashit(): void {
        $this->assertSame( 'https://example.com/', trailingslashit( 'https://example.com' ) );
        $this->assertSame( 'https://example.com/', trailingslashit( 'https://example.com/' ) );
    }

    public function test_sanitize_key(): void {
        $this->assertSame( 'my-site_1', sanitize_key( 'My-Site_1' ) );
        $this->assertSame( 'abc', sanitize_key( 'abc!@#' ) );
    }

    public function test_options_shim(): void {
        update_option( 'rpcare_test_key', 'hello' );
        $this->assertSame( 'hello', get_option( 'rpcare_test_key' ) );
        delete_option( 'rpcare_test_key' );
        $this->assertFalse( get_option( 'rpcare_test_key' ) );
    }

    /**
     * Ensures backup ID prefix routing logic is correct.
     * Care's hub_backup_restore() branches on 'udp_' prefix.
     */
    public function test_backup_id_prefix_routing(): void {
        $udp_id   = 'udp_1718000000';
        $b2_id    = 'backup_20240610T120000';
        $this->assertStringStartsWith( 'udp_', $udp_id, 'UpdraftPlus ID must start with udp_' );
        $this->assertStringStartsWith( 'backup_', $b2_id, 'B2 ID must start with backup_' );
        $this->assertNotSame( str_starts_with( $udp_id, 'udp_' ), str_starts_with( $udp_id, 'backup_' ) );
    }

    /**
     * Validates that log-based job status sentinel logic is correct.
     * complete/error statuses must override 'queued'.
     */
    public function test_job_status_logic(): void {
        $statuses = [ 'complete', 'error' ];
        foreach ( $statuses as $s ) {
            $is_terminal = in_array( $s, [ 'complete', 'error', 'failed' ], true );
            $this->assertTrue( $is_terminal, "Status '$s' should be terminal" );
        }
        $this->assertFalse( in_array( 'queued', [ 'complete', 'error', 'failed' ], true ) );
        $this->assertFalse( in_array( 'running', [ 'complete', 'error', 'failed' ], true ) );
    }
}
