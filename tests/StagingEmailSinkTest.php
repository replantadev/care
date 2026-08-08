<?php
/**
 * Unit tests for RP_Care_Staging_Email_Sink
 */

declare( strict_types=1 );

if ( ! class_exists( 'RP_Care_Pipeline_Client' ) ) {
    require_once __DIR__ . '/../inc/class-pipeline-client.php';
}

require_once __DIR__ . '/../inc/class-staging-email-sink.php';

use PHPUnit\Framework\TestCase;

class StagingEmailSinkTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
    }

    // ── is_active() ───────────────────────────────────────────────────────────

    public function test_not_active_when_no_staging_env(): void {
        $this->assertFalse( RP_Care_Staging_Email_Sink::is_active() );
    }

    public function test_active_when_option_is_staging(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $this->assertTrue( RP_Care_Staging_Email_Sink::is_active() );
    }

    public function test_active_when_suppress_constant_defined(): void {
        // Constant RPCARE_STAGING_SUPPRESS_EMAIL already defined in another test
        // if this runs after — skip to avoid double-define noise.
        if ( ! defined( 'RPCARE_STAGING_SUPPRESS_EMAIL' ) ) {
            define( 'RPCARE_STAGING_SUPPRESS_EMAIL', true );
        }
        $this->assertTrue( RP_Care_Staging_Email_Sink::is_active() );
    }

    // ── get_sink_address() ────────────────────────────────────────────────────

    public function test_no_sink_by_default(): void {
        $this->assertNull( RP_Care_Staging_Email_Sink::get_sink_address() );
    }

    public function test_sink_from_option(): void {
        update_option( RP_Care_Staging_Email_Sink::OPT_SINK_ADDRESS, 'dev@example.com' );
        $this->assertSame( 'dev@example.com', RP_Care_Staging_Email_Sink::get_sink_address() );
    }

    // ── intercept() — redirect path ──────────────────────────────────────────

    public function test_intercept_redirects_to_sink(): void {
        update_option( RP_Care_Staging_Email_Sink::OPT_SINK_ADDRESS, 'sink@example.com' );
        $args = [
            'to'          => 'real.user@prod.com',
            'subject'     => 'Order confirmed',
            'message'     => 'Hello',
            'headers'     => [ 'CC: another@prod.com', 'BCC: third@prod.com' ],
            'attachments' => [],
        ];
        $out = RP_Care_Staging_Email_Sink::intercept( $args );

        $this->assertSame( 'sink@example.com', $out['to'] );
        $this->assertStringStartsWith( '[STAGING] ', $out['subject'] );
        $this->assertSame( [], $out['headers'], 'CC/BCC must be stripped' );
    }

    public function test_intercept_preserves_non_address_headers(): void {
        update_option( RP_Care_Staging_Email_Sink::OPT_SINK_ADDRESS, 'sink@example.com' );
        $args = [
            'to'      => 'user@prod.com',
            'subject' => 'Test',
            'message' => '',
            'headers' => [ 'Content-Type: text/html; charset=UTF-8', 'CC: leak@prod.com' ],
        ];
        $out = RP_Care_Staging_Email_Sink::intercept( $args );

        $this->assertContains( 'Content-Type: text/html; charset=UTF-8', $out['headers'] );
        $this->assertNotContains( 'CC: leak@prod.com', $out['headers'] );
    }

    // ── intercept() — suppress path ──────────────────────────────────────────

    public function test_intercept_suppresses_when_no_sink(): void {
        $args = [
            'to'      => 'real.user@prod.com',
            'subject' => 'Invoice',
            'message' => 'Hi',
            'headers' => [ 'CC: another@prod.com' ],
        ];
        $out = RP_Care_Staging_Email_Sink::intercept( $args );

        $this->assertStringContainsString( 'staging.invalid', $out['to'],
            'Suppressed mail must go to a non-deliverable .invalid address' );
        $this->assertStringStartsWith( '[STAGING SUPPRESSED] ', $out['subject'] );
        $this->assertSame( [], $out['headers'] );
    }

    public function test_suppressed_address_is_not_deliverable(): void {
        $args = [ 'to' => 'real@prod.com', 'subject' => 'S', 'message' => 'M' ];
        $out  = RP_Care_Staging_Email_Sink::intercept( $args );
        // The .invalid TLD is IANA-reserved (RFC 2606) and must never resolve.
        $this->assertStringContainsString( '.invalid', $out['to'] );
    }
}
