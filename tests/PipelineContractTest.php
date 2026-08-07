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
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( string $path ): bool {
        if ( is_dir( $path ) ) { return true; }
        return mkdir( $path, 0755, true );
    }
}
// WP_PLUGIN_DIR must point to a real directory with at least one file so that
// copy_directory() returns true for plugins, letting create_pipeline_backup()
// proceed past the snapshot_failed check and reach the db_ok check.
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
    $cc_test_plugin_dir = sys_get_temp_dir() . '/care-test-plugins-' . getmypid();
    if ( ! is_dir( $cc_test_plugin_dir ) ) {
        mkdir( $cc_test_plugin_dir, 0755, true );
    }
    file_put_contents( $cc_test_plugin_dir . '/dummy-test-plugin.php', '<?php // test placeholder' );
    define( 'WP_PLUGIN_DIR', $cc_test_plugin_dir );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/care-test-wpcontent-' . getmypid() );
}
if ( ! function_exists( 'get_theme_root' ) ) {
    function get_theme_root(): string {
        return WP_CONTENT_DIR . '/themes'; // intentionally nonexistent → themes_ok = false in snapshot
    }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir(): array {
        return [
            'basedir' => sys_get_temp_dir() . '/care-test-uploads-' . getmypid(),
            'baseurl' => 'http://localhost/wp-content/uploads',
        ];
    }
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
    function sanitize_file_name( string $filename ): string {
        return preg_replace( '/[^a-zA-Z0-9\-_.]/', '-', $filename );
    }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( string $url, array $args = [] ): array|\WP_Error {
        if ( isset( $GLOBALS['_wp_remote_get_mock'] ) ) {
            $m = $GLOBALS['_wp_remote_get_mock'];
            return is_callable( $m ) ? $m( $url, $args ) : $m;
        }
        return new \WP_Error( 'not_implemented', 'wp_remote_get stub — configure $GLOBALS[_wp_remote_get_mock]' );
    }
}
if ( ! function_exists( 'esc_sql' ) ) {
    function esc_sql( $data ): string { return addslashes( (string) $data ); }
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
        $GLOBALS['_wp_options']            = [];
        $GLOBALS['_wp_test_http_response'] = null;
        $GLOBALS['_wp_remote_post_mock']   = null;
        $GLOBALS['_wp_remote_post_last']   = null;
        $GLOBALS['_wp_remote_get_mock']    = null;
        $GLOBALS['_as_pending']            = [];
        $GLOBALS['_as_enqueued']           = [];
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

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S01: do_create_production_backup() is callable; returns void when pipeline disabled
    // ─────────────────────────────────────────────────────────────────────────

    public function test_do_create_production_backup_callable_and_exits_when_pipeline_disabled(): void {
        // OPT_ENABLED not set → is_pipeline_enabled() returns false.
        RP_Care_Task_Updates::do_create_production_backup( 'cc-s01-batch' );

        // Method returns void — no exception thrown, no backup option written.
        $this->assertFalse(
            (bool) get_option( 'rpcare_prod_backup_done_cc-s01-batch' ),
            'No backup must be recorded when pipeline is disabled (early return path)'
        );
        $this->assertEmpty(
            $GLOBALS['_as_enqueued'],
            'No AS action must have been enqueued on the disabled-pipeline early exit'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S02: send_approval_to_pc() 5xx → WP_Error('retry_later'), pending option preserved
    // ─────────────────────────────────────────────────────────────────────────

    public function test_send_approval_to_pc_5xx_returns_retry_later_and_preserves_pending(): void {
        $enc_token = $this->care_encrypt( 'test-pc-token-cc-s02' );
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED,    true );
        update_option( 'rpcare_hub_url',                         'https://hub.example.com' );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,       $enc_token );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'cc-s02-instance' );

        $GLOBALS['_wp_remote_post_mock'] = [ 'response' => [ 'code' => 503 ], 'body' => '{"error":"service_unavailable"}' ];

        $approval_data = [ 'batch_id' => 'cc-s02-batch', 'decision' => 'approved', 'manifest_hash' => str_repeat( 'a', 64 ) ];
        $result = RP_Care_Pipeline_Client::send_approval_to_pc( $approval_data );

        $this->assertInstanceOf( WP_Error::class, $result, '5xx must return WP_Error' );
        $this->assertSame( 'retry_later', $result->get_error_code(), 'Error code must be retry_later on 5xx' );

        $pending = get_option( 'rpcare_pipeline_pending_approval_cc-s02-batch' );
        $this->assertNotFalse( $pending, 'Pending approval option must be preserved after 5xx to allow retry' );
        $this->assertSame( 'cc-s02-batch', $pending['batch_id'] ?? '', 'Preserved pending option must contain the original approval data' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S03: send_approval_to_pc() 2xx+approval_id → true, pending option deleted
    // ─────────────────────────────────────────────────────────────────────────

    public function test_send_approval_to_pc_2xx_returns_true_and_deletes_pending(): void {
        $enc_token = $this->care_encrypt( 'test-pc-token-cc-s03' );
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED,    true );
        update_option( 'rpcare_hub_url',                         'https://hub.example.com' );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,       $enc_token );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'cc-s03-instance' );

        // Pre-set a stale pending record — it must be deleted on confirmed 2xx.
        update_option( 'rpcare_pipeline_pending_approval_cc-s03-batch', [ 'batch_id' => 'cc-s03-batch' ] );

        $GLOBALS['_wp_remote_post_mock'] = [ 'response' => [ 'code' => 200 ], 'body' => '{"approval_id":"appr-cc-s03-001"}' ];

        $approval_data = [ 'batch_id' => 'cc-s03-batch', 'decision' => 'approved', 'manifest_hash' => str_repeat( 'b', 64 ) ];
        $result = RP_Care_Pipeline_Client::send_approval_to_pc( $approval_data );

        $this->assertTrue( $result, '2xx response with approval_id must return true' );
        $this->assertFalse(
            get_option( 'rpcare_pipeline_pending_approval_cc-s03-batch' ),
            'Pending approval option must be deleted after confirmed 2xx + approval_id (no double-submit risk)'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S04: send_approval_to_pc() 4xx → WP_Error('rejected'), error option recorded
    // ─────────────────────────────────────────────────────────────────────────

    public function test_send_approval_to_pc_4xx_returns_rejected_and_records_error(): void {
        $enc_token = $this->care_encrypt( 'test-pc-token-cc-s04' );
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED,    true );
        update_option( 'rpcare_hub_url',                         'https://hub.example.com' );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,       $enc_token );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'cc-s04-instance' );

        $GLOBALS['_wp_remote_post_mock'] = [ 'response' => [ 'code' => 422 ], 'body' => '{"error":"manifest_hash_not_found"}' ];

        $approval_data = [ 'batch_id' => 'cc-s04-batch', 'decision' => 'approved', 'manifest_hash' => str_repeat( 'c', 64 ) ];
        $result = RP_Care_Pipeline_Client::send_approval_to_pc( $approval_data );

        $this->assertInstanceOf( WP_Error::class, $result, '4xx must return WP_Error' );
        $this->assertSame( 'rejected', $result->get_error_code(), 'Error code must be rejected on 4xx' );

        $err_record = get_option( 'rpcare_pipeline_approval_error_cc-s04-batch' );
        $this->assertNotFalse( $err_record, 'Error record option must be written so the operator can see the rejection reason' );
        $this->assertArrayHasKey( 'error', $err_record, 'Error record must include the HTTP error code' );
        $this->assertArrayHasKey( 'body',  $err_record, 'Error record must include the response body for diagnostics' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S05: create_pipeline_backup() → WP_Error('db_export_failed') when $wpdb unavailable
    // ─────────────────────────────────────────────────────────────────────────

    public function test_create_pipeline_backup_fails_with_db_export_failed_when_wpdb_unavailable(): void {
        // Ensure no $wpdb global — export_db_snapshot must return false, triggering db_export_failed.
        unset( $GLOBALS['wpdb'] );

        $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'create_pipeline_backup' );
        $method->setAccessible( true );

        $result = $method->invoke( null, 'cc-s05-batch' );

        $this->assertInstanceOf(
            WP_Error::class, $result,
            'create_pipeline_backup must return WP_Error when the database export fails'
        );
        $this->assertSame(
            'db_export_failed', $result->get_error_code(),
            'Error code must be db_export_failed — plugins snapshot succeeded (WP_PLUGIN_DIR exists), but DB export did not'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S06: compute_snapshot_hash() uses file content (not size) — same-size
    //         files with different content produce different hashes
    // ─────────────────────────────────────────────────────────────────────────

    public function test_compute_snapshot_hash_is_content_based_not_size_based(): void {
        $dir_a = sys_get_temp_dir() . '/cc-s06-snap-a-' . getmypid();
        $dir_b = sys_get_temp_dir() . '/cc-s06-snap-b-' . getmypid();

        foreach ( [ $dir_a, $dir_b ] as $d ) {
            if ( ! is_dir( $d ) ) {
                mkdir( $d, 0755, true );
            }
        }

        // Same size (16 bytes), different content.
        file_put_contents( $dir_a . '/data.bin', 'AAAAAAAAAAAAAAAA' );
        file_put_contents( $dir_b . '/data.bin', 'BBBBBBBBBBBBBBBB' );

        $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'compute_snapshot_hash' );
        $method->setAccessible( true );

        $hash_a = $method->invoke( null, $dir_a );
        $hash_b = $method->invoke( null, $dir_b );

        $this->assertNotEmpty( $hash_a, 'compute_snapshot_hash must return a non-empty hash for directory A' );
        $this->assertNotEmpty( $hash_b, 'compute_snapshot_hash must return a non-empty hash for directory B' );
        $this->assertNotSame(
            $hash_a, $hash_b,
            'Same-size files with different content must produce different snapshot hashes (content-based SHA-256, not mtime or size)'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S07: apply_production_batch() when batch lock held by another batch → batch_already_running
    // ─────────────────────────────────────────────────────────────────────────

    public function test_apply_production_batch_returns_batch_already_running_when_lock_held(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED, true );
        // OPT_ENVIRONMENT not set → get_option returns '' → not equal to 'staging' → production path.

        $backup_id = 'bkp-cc-s07';
        update_option( 'rpcare_prod_backup_done_cc-s07-batch', $backup_id );

        // Pre-lock with a DIFFERENT batch_id using update_option (bypasses INSERT IGNORE).
        // When apply_production_batch calls add_option(), it returns false (key exists),
        // and get_option('rpcare_batch_lock') = 'OTHER-BATCH-CC-S07' ≠ 'cc-s07-batch' → lock refused.
        update_option( 'rpcare_batch_lock', 'OTHER-BATCH-CC-S07' );

        $result = RP_Care_Task_Updates::apply_production_batch(
            'cc-s07-batch',
            str_repeat( 'a', 64 ),
            'appr-cc-s07',
            $backup_id
        );

        $this->assertIsArray( $result, 'apply_production_batch must return an array' );
        $this->assertFalse( $result['success'], 'Result must indicate failure when lock is held by another batch' );
        $this->assertSame(
            'batch_already_running', $result['error'],
            'Error must be batch_already_running when atomic lock acquisition fails'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S08: apply_production_batch() manifest_hash parameter mismatch vs fetched → manifest_hash_mismatch
    // ─────────────────────────────────────────────────────────────────────────

    public function test_apply_production_batch_returns_manifest_hash_mismatch_on_hash_discrepancy(): void {
        $enc_token = $this->care_encrypt( 'test-pc-token-cc-s08' );
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED,    true );
        // OPT_ENVIRONMENT not set → not staging → production path.
        update_option( 'rpcare_hub_url',                         'https://hub.example.com' );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,       $enc_token );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'cc-s08-instance' );

        $backup_id = 'bkp-cc-s08';
        update_option( 'rpcare_prod_backup_done_cc-s08-batch', $backup_id );

        // Build a canonical manifest and compute its hash the same way Care's compute_manifest_hash_local() does.
        // The wp_json_encode stub ignores extra flags, so wp_json_encode($data, flags) = json_encode($data).
        $manifest = [ 'artifact_set_hash' => '', 'artifacts' => [], 'items' => [] ]; // pre-sorted
        $real_manifest_hash = hash( 'sha256', (string) json_encode( $manifest ) );

        // Mock wp_remote_get: return the manifest with its real (internally consistent) hash.
        $GLOBALS['_wp_remote_get_mock'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [ 'manifest' => $manifest, 'manifest_hash' => $real_manifest_hash ] ),
        ];
        // Mock wp_remote_post: report_batch_to_pc on the failure path will POST to PC.
        $GLOBALS['_wp_remote_post_mock'] = [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];

        // Pass a manifest_hash that DIFFERS from the one PC returns — simulates a tampering attempt.
        $wrong_manifest_hash = str_repeat( 'f', 64 );

        $result = RP_Care_Task_Updates::apply_production_batch(
            'cc-s08-batch',
            $wrong_manifest_hash,
            'appr-cc-s08',
            $backup_id
        );

        $this->assertIsArray( $result, 'apply_production_batch must return an array' );
        $this->assertFalse( $result['success'], 'Result must indicate failure on manifest hash mismatch' );
        $this->assertSame(
            'manifest_hash_mismatch', $result['error'],
            'Error must be manifest_hash_mismatch when the approved hash differs from the fetched manifest hash'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S09: validate_manifest_artifacts passes with proper id field (not artifact_id)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_validate_manifest_artifacts_passes_with_id_field(): void {
        $manifest = [
            'proposed_updates' => [
                'plugins' => [
                    [ 'slug' => 'woocommerce', 'file' => 'woocommerce/woocommerce.php',
                      'from_version' => '8.0.0', 'to_version' => '8.1.0', 'id' => 'art-001', 'sha256' => str_repeat( 'a', 64 ) ],
                ],
                'themes'  => [],
            ],
            'artifacts' => [
                [
                    'id'      => 'art-001',
                    'type'    => 'plugin',
                    'slug'    => 'woocommerce',
                    'version' => '8.1.0',
                    'sha256'  => str_repeat( 'a', 64 ),
                    'size'    => 1024,
                ],
            ],
        ];

        $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'validate_manifest_artifacts' );
        $method->setAccessible( true );

        $result = $method->invoke( null, $manifest, 'cc-s09-batch' );

        $this->assertNull(
            $result,
            'validate_manifest_artifacts must return null (no error) when artifact uses id field and type:slug key matches'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S10: translations produce success=true, status='deferred' in apply_manifest_items
    // ─────────────────────────────────────────────────────────────────────────

    public function test_translations_are_deferred_in_apply_manifest_items(): void {
        $manifest = [
            'proposed_updates' => [
                'plugins'      => [],
                'themes'       => [],
                'core'         => [],
                'translations' => [
                    [ 'slug' => 'woocommerce', 'type' => 'translation', 'from_version' => '8.0.0', 'to_version' => '8.1.0' ],
                ],
            ],
            'artifacts' => [],
        ];

        $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'apply_manifest_items' );
        $method->setAccessible( true );

        $results = $method->invoke( null, $manifest, 'cc-s10-batch', 'staging' );

        $this->assertCount( 1, $results, 'apply_manifest_items must return one result for the translation item' );
        $translation = $results[0];

        $this->assertSame( 'woocommerce', $translation['slug'], 'Result slug must match the translation item slug' );
        $this->assertTrue( $translation['success'], 'Translation result must have success=true (deferred, not failed)' );
        $this->assertSame( 'deferred', $translation['status'] ?? '', 'Translation result must have status=deferred' );
        $this->assertArrayNotHasKey( 'error', $translation, 'Translation result must not have an error key when deferred' );
    }
}
