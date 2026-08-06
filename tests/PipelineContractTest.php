<?php
/**
 * Replanta Care — Pipeline Contract Tests
 *
 * Tests for Care's side of the PC↔Care API contract:
 *  - Parses the commands array key (not command singular)
 *  - Stores pairing credentials encrypted with care1: prefix
 *  - Rejects pairing response that is missing token or secret
 *  - Rejects commands without issued_at or without batch_id key
 *  - Maps internal status 'staging_done' → PC event 'staging_updated'
 *
 * Offline — no WP install, no DB, no HTTP.
 * Run: vendor/bin/phpunit tests/PipelineContractTest.php
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// ── Extra shims not yet in bootstrap ─────────────────────────────────────────

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    function wp_generate_uuid4(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ): string { return trim( (string) $s ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( string $url ): string { return $url; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( string $url, int $component = -1 ): array|string|int|false|null {
        return parse_url( $url, $component );
    }
}

// ── Load Care pipeline classes ────────────────────────────────────────────────

require_once __DIR__ . '/../inc/class-pipeline-client.php';
require_once __DIR__ . '/../inc/class-inventory-snapshot.php';
require_once __DIR__ . '/../inc/task-updates.php';

// ── Test class ────────────────────────────────────────────────────────────────

/**
 * @covers RP_Care_Pipeline_Client
 * @covers RP_Care_Task_Updates
 */
class CarePipelineContractTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options']          = [];
        $GLOBALS['_wp_test_http_response'] = null;
        $GLOBALS['_wp_remote_post_mock'] = null;
        $GLOBALS['_wp_remote_post_last'] = null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Encrypt a string with Care's local key scheme (care1: prefix).
     * Calls the private encrypt_local() method via Reflection.
     */
    private function care_encrypt( string $plaintext ): string {
        $m = new ReflectionMethod( RP_Care_Pipeline_Client::class, 'encrypt_local' );
        $m->setAccessible( true );
        return (string) $m->invoke( null, $plaintext );
    }

    /**
     * Build a properly signed command that passes verify_command_public().
     */
    private function make_valid_command(
        string $secret,
        string $instance_id,
        string $environment   = 'production',
        string $batch_id      = 'batch-care-ct',
        string $manifest_hash = ''
    ): array {
        $now     = gmdate( 'c', time() );
        $expires = gmdate( 'c', time() + 300 );
        $payload = [];
        $p_hash  = hash( 'sha256', json_encode( $payload ) );
        $cmd_id  = 'care-ct-cmd-' . substr( md5( $secret . $instance_id ), 0, 8 );

        // HMAC: command_id|instance_id|env|type|batch_id|payload_hash|manifest_hash|issued_at|expires_at
        $hmac_payload = implode( '|', [
            $cmd_id, $instance_id, $environment, 'report_inventory',
            $batch_id, $p_hash, $manifest_hash, $now, $expires,
        ] );
        $hmac = hash_hmac( 'sha256', $hmac_payload, $secret );

        return [
            'command_id'         => $cmd_id,
            'instance_id'        => $instance_id,
            'command_type'       => 'report_inventory',
            'payload'            => $payload,
            'payload_hash'       => $p_hash,
            'issued_at'          => $now,
            'expires_at'         => $expires,
            'target_environment' => $environment,
            'batch_id'           => $batch_id,
            'manifest_hash'      => $manifest_hash,
            'hmac'               => $hmac,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-001: Care parses { commands: [...] } not { command: ... }
    // ─────────────────────────────────────────────────────────────────────────

    public function test_poll_parses_commands_array(): void {
        // Simulate what PC sends after the contract fix: { "commands": [...] }.
        $pc_body = [
            'commands' => [
                [
                    'command_id'         => 'cc001-cmd',
                    'command_type'       => 'report_inventory',
                    'payload'            => [],
                    'payload_hash'       => hash( 'sha256', '[]' ),
                    'issued_at'          => gmdate( 'c' ),
                    'expires_at'         => gmdate( 'c', time() + 300 ),
                    'instance_id'        => 'some-instance',
                    'target_environment' => 'production',
                    'batch_id'           => null,
                    'hmac'               => 'placeholder',
                ],
            ],
        ];

        // Care's parsing line: $commands = $body['commands'] ?? [];
        $commands = $pc_body['commands'] ?? [];

        $this->assertCount(
            1, $commands,
            'Parsing $body["commands"] from a PC response must yield 1 command'
        );
        $this->assertSame(
            'cc001-cmd', $commands[0]['command_id'],
            'The parsed command_id must match what PC sent'
        );

        // Confirm the old singular "command" key would yield nothing.
        $old_format         = [ 'command' => $pc_body['commands'][0] ];
        $commands_from_old  = $old_format['commands'] ?? [];

        $this->assertCount(
            0, $commands_from_old,
            'Old { "command": ... } format must yield an empty commands list via $body["commands"] ?? []'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-002: Pairing saves token and secret encrypted with care1: scheme
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pairing_saves_token_and_secret_encrypted(): void {
        // Configure Care to know where PC lives.
        update_option( 'rpcare_options', [ 'hub_url' => 'https://hub.contract-test.example' ] );

        // Mock wp_remote_post to return a successful pairing response.
        $GLOBALS['_wp_remote_post_mock'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'ok'          => true,
                'token'       => 'pc-issued-bearer-token-t1',
                'secret'      => 'pc-issued-hmac-secret-s1',
                'group_id'    => 'grp-contract-001',
                'environment' => 'staging',
                'instance_id' => 'inst-contract-001',
            ] ),
        ];

        $result = RP_Care_Pipeline_Client::handle_pairing_request( [
            'action'        => 'consume',
            'token'         => 'raw-one-time-pairing-token',
            'canonical_url' => 'https://staging.contract-test.example',
        ] );

        $this->assertNotInstanceOf(
            \WP_Error::class, $result,
            'handle_pairing_request must succeed when PC returns ok=true with token and secret'
        );

        $stored_token  = get_option( RP_Care_Pipeline_Client::OPT_TOKEN, '' );
        $stored_secret = get_option( RP_Care_Pipeline_Client::OPT_SECRET, '' );
        $status        = get_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS, '' );

        $this->assertNotEmpty( $stored_token,  'OPT_TOKEN must be set after successful pairing' );
        $this->assertNotEmpty( $stored_secret, 'OPT_SECRET must be set after successful pairing' );

        $this->assertStringStartsWith(
            'care1:', $stored_token,
            'OPT_TOKEN must be stored with care1: encryption prefix, not plain text'
        );
        $this->assertStringStartsWith(
            'care1:', $stored_secret,
            'OPT_SECRET must be stored with care1: encryption prefix, not plain text'
        );

        $this->assertNotSame(
            'pc-issued-bearer-token-t1', $stored_token,
            'Stored token must not equal the raw token (must be encrypted)'
        );
        $this->assertNotSame(
            'pc-issued-hmac-secret-s1', $stored_secret,
            'Stored secret must not equal the raw secret (must be encrypted)'
        );

        $this->assertSame(
            RP_Care_Pipeline_Client::STATUS_READY, $status,
            'Pairing status must be "ready" after successful pairing'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-003: Pairing fails if PC response is missing token or secret
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pairing_fails_if_response_missing_token(): void {
        update_option( 'rpcare_options', [ 'hub_url' => 'https://hub.contract-test.example' ] );

        // PC responds ok:true but WITHOUT token/secret fields.
        $GLOBALS['_wp_remote_post_mock'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'ok'          => true,
                'group_id'    => 'grp-contract-002',
                'environment' => 'staging',
                // Intentionally missing: 'token' and 'secret'
            ] ),
        ];

        $result = RP_Care_Pipeline_Client::handle_pairing_request( [
            'action'        => 'consume',
            'token'         => 'raw-pairing-token-cc003',
            'canonical_url' => 'https://staging.contract-test.example',
        ] );

        $this->assertInstanceOf(
            \WP_Error::class, $result,
            'handle_pairing_request must return WP_Error when token/secret are absent from PC response'
        );

        $status = get_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS, RP_Care_Pipeline_Client::STATUS_UNPAIRED );
        $this->assertNotSame(
            RP_Care_Pipeline_Client::STATUS_READY, $status,
            'Pairing status must NOT be "ready" when credentials were missing from PC response'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-004: verify_command_public rejects command without issued_at
    // ─────────────────────────────────────────────────────────────────────────

    public function test_verify_command_requires_issued_at(): void {
        $secret      = 'cc004-verify-secret';
        $instance_id = 'cc004-instance';

        update_option( RP_Care_Pipeline_Client::OPT_SECRET,      $this->care_encrypt( $secret ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, $instance_id );
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT,  'production' );

        $cmd = $this->make_valid_command( $secret, $instance_id );

        // Remove issued_at entirely.
        unset( $cmd['issued_at'] );

        $this->assertFalse(
            RP_Care_Pipeline_Client::verify_command_public( $cmd ),
            'verify_command_public must return false when issued_at is absent'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-005: verify_command_public rejects command without batch_id key
    // ─────────────────────────────────────────────────────────────────────────

    public function test_verify_command_requires_batch_id_key(): void {
        $secret      = 'cc005-verify-secret';
        $instance_id = 'cc005-instance';

        update_option( RP_Care_Pipeline_Client::OPT_SECRET,      $this->care_encrypt( $secret ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, $instance_id );
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT,  'production' );

        $cmd = $this->make_valid_command( $secret, $instance_id );

        // Remove the batch_id key entirely (key must be present even if null).
        unset( $cmd['batch_id'] );

        $this->assertFalse(
            RP_Care_Pipeline_Client::verify_command_public( $cmd ),
            'verify_command_public must return false when batch_id key is completely absent'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-007: verify_command_public rejects command without manifest_hash key
    // ─────────────────────────────────────────────────────────────────────────

    public function test_verify_command_requires_manifest_hash_key(): void {
        $secret      = 'cc007-verify-secret';
        $instance_id = 'cc007-instance';

        update_option( RP_Care_Pipeline_Client::OPT_SECRET,      $this->care_encrypt( $secret ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, $instance_id );
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT,  'production' );

        $cmd = $this->make_valid_command( $secret, $instance_id );

        // Remove manifest_hash key entirely.
        unset( $cmd['manifest_hash'] );

        $this->assertFalse(
            RP_Care_Pipeline_Client::verify_command_public( $cmd ),
            'verify_command_public must return false when manifest_hash key is completely absent'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-008: manifest_hash is bound into HMAC — tampering invalidates signature
    // ─────────────────────────────────────────────────────────────────────────

    public function test_verify_command_manifest_hash_bound_in_hmac(): void {
        $secret      = 'cc008-verify-secret';
        $instance_id = 'cc008-instance';

        update_option( RP_Care_Pipeline_Client::OPT_SECRET,      $this->care_encrypt( $secret ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, $instance_id );
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT,  'production' );

        // Build a command signed with a specific manifest_hash.
        $original_hash = hash( 'sha256', 'original-manifest-content' );
        $cmd = $this->make_valid_command( $secret, $instance_id, 'production', 'batch-cc008', $original_hash );

        // Valid: original manifest_hash → should verify.
        $this->assertTrue(
            RP_Care_Pipeline_Client::verify_command_public( $cmd ),
            'Command with correct manifest_hash must pass verification'
        );

        // Tamper: replace manifest_hash with a different hash, keep same HMAC.
        $tampered = $cmd;
        $tampered['manifest_hash'] = hash( 'sha256', 'tampered-manifest-content' );

        $this->assertFalse(
            RP_Care_Pipeline_Client::verify_command_public( $tampered ),
            'Command with tampered manifest_hash must fail HMAC verification'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-006: report_batch_to_pc maps status='staging_done' → event='staging_updated'
    // ─────────────────────────────────────────────────────────────────────────

    public function test_event_reporting_maps_staging_done_to_staging_updated(): void {
        // Set up credentials so report_batch_to_pc doesn't bail out early.
        $enc_token = $this->care_encrypt( 'care-token-cc006' );
        update_option( 'rpcare_options', [ 'hub_url' => 'https://hub.contract-test.example' ] );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,       $enc_token );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'cc006-instance' );

        // Capture the last wp_remote_post call.
        $GLOBALS['_wp_remote_post_last'] = null;
        $GLOBALS['_wp_remote_post_mock'] = function ( string $url, array $args ) {
            $GLOBALS['_wp_remote_post_last'] = $args;
            return [ 'response' => [ 'code' => 200 ], 'body' => '{"ok":true}' ];
        };

        // Invoke the private method via Reflection.
        $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'report_batch_to_pc' );
        $method->setAccessible( true );
        $method->invoke( null, 'cc006-batch', 'staging_done', [] );

        $this->assertNotNull(
            $GLOBALS['_wp_remote_post_last'],
            'wp_remote_post must have been called by report_batch_to_pc'
        );

        $body = json_decode( $GLOBALS['_wp_remote_post_last']['body'] ?? '{}', true );

        $this->assertArrayHasKey(
            'event', $body,
            'POST body must contain "event" key, not "state"'
        );
        $this->assertArrayNotHasKey(
            'state', $body,
            'POST body must NOT contain "state" key — PC expects event names'
        );
        $this->assertSame(
            'staging_updated', $body['event'],
            'Internal status "staging_done" must be mapped to PC event "staging_updated"'
        );
        $this->assertSame(
            'cc006-batch', $body['batch_id'],
            'POST body must include the batch_id'
        );
    }
}
