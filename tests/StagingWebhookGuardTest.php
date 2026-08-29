<?php
/**
 * Unit tests for RP_Care_Staging_Webhook_Guard
 */

declare( strict_types=1 );

if ( ! class_exists( 'RP_Care_Pipeline_Client' ) ) {
    require_once __DIR__ . '/../inc/class-pipeline-client.php';
}

require_once __DIR__ . '/../inc/class-staging-webhook-guard.php';

use PHPUnit\Framework\TestCase;

class StagingWebhookGuardTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [];
    }

    // ── is_internal() ─────────────────────────────────────────────────────────

    public function test_localhost_is_internal(): void {
        $this->assertTrue( RP_Care_Staging_Webhook_Guard::is_internal( 'localhost' ) );
    }

    public function test_loopback_ip_is_internal(): void {
        $this->assertTrue( RP_Care_Staging_Webhook_Guard::is_internal( '127.0.0.1' ) );
    }

    public function test_ipv6_loopback_is_internal(): void {
        $this->assertTrue( RP_Care_Staging_Webhook_Guard::is_internal( '::1' ) );
    }

    public function test_dot_local_is_internal(): void {
        $this->assertTrue( RP_Care_Staging_Webhook_Guard::is_internal( 'mysite.local' ) );
    }

    public function test_dot_test_is_internal(): void {
        $this->assertTrue( RP_Care_Staging_Webhook_Guard::is_internal( 'app.test' ) );
    }

    public function test_dot_invalid_is_internal(): void {
        $this->assertTrue( RP_Care_Staging_Webhook_Guard::is_internal( 'staging.invalid' ) );
    }

    public function test_private_10_range_is_internal(): void {
        $this->assertTrue( RP_Care_Staging_Webhook_Guard::is_internal( '10.0.0.5' ) );
    }

    public function test_private_172_range_is_internal(): void {
        $this->assertTrue( RP_Care_Staging_Webhook_Guard::is_internal( '172.20.0.1' ) );
    }

    public function test_private_192_168_range_is_internal(): void {
        $this->assertTrue( RP_Care_Staging_Webhook_Guard::is_internal( '192.168.1.100' ) );
    }

    public function test_public_host_is_not_internal(): void {
        $this->assertFalse( RP_Care_Staging_Webhook_Guard::is_internal( 'api.stripe.com' ) );
    }

    public function test_public_ip_is_not_internal(): void {
        $this->assertFalse( RP_Care_Staging_Webhook_Guard::is_internal( '8.8.8.8' ) );
    }

    // ── is_allowed() + get_allowlist() ────────────────────────────────────────

    public function test_no_allowlist_by_default(): void {
        $this->assertSame( [], RP_Care_Staging_Webhook_Guard::get_allowlist() );
    }

    public function test_allowlist_from_option_json(): void {
        update_option( RP_Care_Staging_Webhook_Guard::OPT_ALLOWLIST, '["replanta.dev","example.com"]' );
        $this->assertSame( [ 'replanta.dev', 'example.com' ], RP_Care_Staging_Webhook_Guard::get_allowlist() );
    }

    public function test_is_allowed_exact_match(): void {
        update_option( RP_Care_Staging_Webhook_Guard::OPT_ALLOWLIST, '["replanta.dev"]' );
        $this->assertTrue( RP_Care_Staging_Webhook_Guard::is_allowed( 'replanta.dev' ) );
    }

    public function test_is_allowed_subdomain_match(): void {
        update_option( RP_Care_Staging_Webhook_Guard::OPT_ALLOWLIST, '["replanta.dev"]' );
        $this->assertTrue( RP_Care_Staging_Webhook_Guard::is_allowed( 'api.replanta.dev' ) );
    }

    public function test_is_not_allowed_different_host(): void {
        update_option( RP_Care_Staging_Webhook_Guard::OPT_ALLOWLIST, '["replanta.dev"]' );
        $this->assertFalse( RP_Care_Staging_Webhook_Guard::is_allowed( 'api.stripe.com' ) );
    }

    // ── maybe_block() ─────────────────────────────────────────────────────────

    public function test_get_request_always_allowed(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $result = RP_Care_Staging_Webhook_Guard::maybe_block(
            false,
            [ 'method' => 'GET' ],
            'https://external.example.com/endpoint'
        );
        $this->assertFalse( $result, 'GET requests must not be blocked' );
    }

    public function test_post_to_internal_host_is_allowed(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $result = RP_Care_Staging_Webhook_Guard::maybe_block(
            false,
            [ 'method' => 'POST' ],
            'http://localhost/wp-json/replanta-hub/v1/heartbeat'
        );
        $this->assertFalse( $result );
    }

    public function test_post_to_external_blocked_when_not_in_allowlist(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $result = RP_Care_Staging_Webhook_Guard::maybe_block(
            false,
            [ 'method' => 'POST' ],
            'https://hooks.slack.com/services/FAKE/FAKE'
        );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rpcare_staging_webhook_blocked', $result->get_error_code() );
    }

    public function test_post_to_allowlisted_host_passes(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        update_option( RP_Care_Staging_Webhook_Guard::OPT_ALLOWLIST, '["hooks.slack.com"]' );
        $result = RP_Care_Staging_Webhook_Guard::maybe_block(
            false,
            [ 'method' => 'POST' ],
            'https://hooks.slack.com/services/FAKE/FAKE'
        );
        $this->assertFalse( $result );
    }

	public function test_pipeline_post_to_exact_configured_hub_origin_is_allowed(): void {
		update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
		update_option( 'rpcare_options', [ 'hub_url' => 'https://replanta.net' ] );

		$result = RP_Care_Staging_Webhook_Guard::maybe_block(
			false,
			[ 'method' => 'POST' ],
			'https://replanta.net/wp-json/replanta-pc/v1/pipeline/pairing/consume'
		);

		$this->assertFalse( $result );
	}

	public function test_non_pipeline_post_on_hub_remains_blocked(): void {
		update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
		update_option( 'rpcare_options', [ 'hub_url' => 'https://replanta.net' ] );

		$result = RP_Care_Staging_Webhook_Guard::maybe_block(
			false,
			[ 'method' => 'POST' ],
			'https://replanta.net/wp-json/rphub/v1/care-event'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_pipeline_path_on_subdomain_or_different_port_remains_blocked(): void {
		update_option( 'rpcare_options', [ 'hub_url' => 'https://replanta.net' ] );
		$this->assertFalse( RP_Care_Staging_Webhook_Guard::is_pipeline_control_plane_url(
			'https://evil.replanta.net/wp-json/replanta-pc/v1/pipeline/command-ack'
		) );
		$this->assertFalse( RP_Care_Staging_Webhook_Guard::is_pipeline_control_plane_url(
			'https://replanta.net:444/wp-json/replanta-pc/v1/pipeline/command-ack'
		) );
	}

	public function test_public_pipeline_control_plane_requires_https(): void {
		update_option( 'rpcare_options', [ 'hub_url' => 'http://replanta.net' ] );
		$this->assertFalse( RP_Care_Staging_Webhook_Guard::is_pipeline_control_plane_url(
			'http://replanta.net/wp-json/replanta-pc/v1/pipeline/command-ack'
		) );
	}

	public function test_encoded_or_plain_path_traversal_is_not_treated_as_pipeline(): void {
		update_option( 'rpcare_options', [ 'hub_url' => 'https://replanta.net' ] );
		$this->assertFalse( RP_Care_Staging_Webhook_Guard::is_pipeline_control_plane_url(
			'https://replanta.net/wp-json/replanta-pc/v1/pipeline/%2e%2e/rphub/v1/care-event'
		) );
		$this->assertFalse( RP_Care_Staging_Webhook_Guard::is_pipeline_control_plane_url(
			'https://replanta.net/wp-json/replanta-pc/v1/pipeline/../rphub/v1/care-event'
		) );
	}

    public function test_already_handled_response_is_passed_through(): void {
        $existing = [ 'response' => [ 'code' => 200 ], 'body' => 'ok' ];
        $result   = RP_Care_Staging_Webhook_Guard::maybe_block(
            $existing,
            [ 'method' => 'POST' ],
            'https://external.example.com/endpoint'
        );
        $this->assertSame( $existing, $result, 'Pre-handled response must not be blocked' );
    }

    public function test_put_to_external_blocked_when_not_in_allowlist(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $result = RP_Care_Staging_Webhook_Guard::maybe_block(
            false,
            [ 'method' => 'PUT' ],
            'https://api.example.com/resource/1'
        );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rpcare_staging_webhook_blocked', $result->get_error_code() );
    }

    public function test_patch_to_external_blocked_when_not_in_allowlist(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $result = RP_Care_Staging_Webhook_Guard::maybe_block(
            false,
            [ 'method' => 'PATCH' ],
            'https://api.example.com/resource/1'
        );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rpcare_staging_webhook_blocked', $result->get_error_code() );
    }

    public function test_delete_to_external_blocked_when_not_in_allowlist(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $result = RP_Care_Staging_Webhook_Guard::maybe_block(
            false,
            [ 'method' => 'DELETE' ],
            'https://api.example.com/resource/1'
        );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rpcare_staging_webhook_blocked', $result->get_error_code() );
    }

    public function test_head_request_always_allowed(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'staging' );
        $result = RP_Care_Staging_Webhook_Guard::maybe_block(
            false,
            [ 'method' => 'HEAD' ],
            'https://external.example.com/endpoint'
        );
        $this->assertFalse( $result, 'HEAD requests must not be blocked (read-only)' );
    }
}
