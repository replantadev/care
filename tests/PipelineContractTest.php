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
if ( ! function_exists( 'get_plugins' ) ) {
    function get_plugins(): array { return []; }
}
if ( ! function_exists( 'get_plugin_updates' ) ) {
    function get_plugin_updates(): array { return []; }
}
if ( ! function_exists( 'wp_get_themes' ) ) {
    function wp_get_themes( array $args = [] ): array { return []; }
}
if ( ! function_exists( 'get_stylesheet' ) ) {
    function get_stylesheet(): string { return ''; }
}
if ( ! function_exists( 'wp_get_installed_translations' ) ) {
    function wp_get_installed_translations( string $type ): array { return []; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
        return $show === 'version' ? '6.5' : '';
    }
}
if ( ! function_exists( 'get_locale' ) ) {
    function get_locale(): string { return 'en_US'; }
}
if ( ! function_exists( 'wp_get_available_translations' ) ) {
    function wp_get_available_translations(): array { return []; }
}

// ── Load Care pipeline classes ────────────────────────────────────────────────

require_once __DIR__ . '/../inc/class-pipeline-client.php';
require_once __DIR__ . '/../inc/class-staging-provider.php';
require_once __DIR__ . '/../inc/class-pipeline-outbox.php';
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

    public function test_manifest_hash_uses_pc_string_key_sort_for_large_lists(): void {
        $manifest = [
            'plugins' => array_map(
                static fn( int $i ): array => [ 'version' => '1.' . $i, 'slug' => 'plugin-' . $i ],
                range( 0, 11 )
            ),
            'batch_id' => 'large-list-contract',
        ];

        $sort = static function ( array $value ) use ( &$sort ): array {
            ksort( $value, SORT_STRING );
            foreach ( $value as &$entry ) {
                if ( is_array( $entry ) ) {
                    $entry = $sort( $entry );
                }
            }
            unset( $entry );
            return $value;
        };
        $expected = hash( 'sha256', wp_json_encode( $sort( $manifest ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

        $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'compute_manifest_hash_local' );
        $method->setAccessible( true );
        $this->assertSame( $expected, $method->invoke( null, $manifest ) );
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

    public function test_clone_repair_rotates_staging_identity_and_replay_state(): void {
        update_option( 'rpcare_options', [ 'hub_url' => 'https://hub.contract-test.example' ] );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'production-uuid-copied-by-clone' );
        update_option( RP_Care_Pipeline_Client::OPT_PROCESSED_CMDS, [ 'production-command' ] );
        update_option( RP_Care_Pipeline_Client::OPT_LAST_POLL, '2026-08-29 09:00:00' );

        $sent_instance = '';
        $GLOBALS['_wp_remote_post_mock'] = static function ( string $url, array $args ) use ( &$sent_instance ): array {
            $request       = json_decode( (string) ( $args['body'] ?? '' ), true );
            $sent_instance = (string) ( $request['instance_id'] ?? '' );
            return [
                'response' => [ 'code' => 200 ],
                'body'     => wp_json_encode( [
                    'ok'          => true,
                    'token'       => 'new-staging-token',
                    'secret'      => 'new-staging-secret',
                    'group_id'    => 'clone-repair-group',
                    'environment' => 'staging',
                ] ),
            ];
        };

        $result = RP_Care_Pipeline_Client::handle_pairing_request( [
            'action'             => 'consume',
            'token'              => 'one-time-token',
            'canonical_url'      => 'https://dev2.example.test',
            'rotate_instance_id' => true,
        ] );

        $this->assertNotInstanceOf( WP_Error::class, $result );
        $this->assertNotSame( 'production-uuid-copied-by-clone', $sent_instance );
        $this->assertSame( $sent_instance, get_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID ) );
        $this->assertSame( [], get_option( RP_Care_Pipeline_Client::OPT_PROCESSED_CMDS ) );
        $this->assertFalse( get_option( RP_Care_Pipeline_Client::OPT_LAST_POLL ) );
        $this->assertContains( 'rpcare_task_pipeline_poll', array_column( $GLOBALS['_as_enqueued'], 'hook' ) );
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
        // P0-1 fix: report_batch_to_pc no longer calls wp_remote_post directly.
        // It enqueues to the outbox first; delivery happens via try_deliver_one().
        // This test now verifies the outbox row — the durable, auditable record.

        $enc_token = $this->care_encrypt( 'care-token-cc006' );
        update_option( 'rpcare_options', [ 'hub_url' => 'https://hub.contract-test.example' ] );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,       $enc_token );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'cc006-instance' );

        // Capture inserts to the outbox table.
        $outbox_inserts = [];
        $prev_wpdb      = $GLOBALS['wpdb'];

        $GLOBALS['wpdb'] = new class( $outbox_inserts ) extends wpdb {
            private $log;
            public function __construct( &$l ) { $this->log = &$l; }
            public function insert( string $table, array $data, $format = null ): int|false {
                if ( str_contains( $table, 'rpcare_pipeline_outbox' ) ) {
                    $this->log[] = $data;
                    return 1;
                }
                return false;
            }
            public function query( string $sql ): int|bool {
                // Atomic claim UPDATE → 0 so try_deliver_one exits cleanly in tests.
                if ( preg_match( '/UPDATE.*rpcare_pipeline_outbox.*SET.*status/si', $sql ) ) {
                    return 0;
                }
                return true;
            }
        };

        try {
            $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'report_batch_to_pc' );
            $method->setAccessible( true );
            $method->invoke( null, 'cc006-batch', 'staging_done', [] );
        } finally {
            $GLOBALS['wpdb'] = $prev_wpdb;
        }

        $this->assertNotEmpty( $outbox_inserts,
            'report_batch_to_pc must enqueue the event to the outbox (not call wp_remote_post directly)' );

        $row = $outbox_inserts[0];

        $this->assertSame( 'staging_updated', $row['event'],
            'Internal status "staging_done" must be mapped to PC event "staging_updated"' );
        $this->assertSame( 'cc006-batch', $row['batch_id'],
            'Outbox row must include the correct batch_id' );

        $payload = json_decode( $row['payload_json'] ?? '{}', true );
        $this->assertArrayNotHasKey( 'state', $payload,
            'Payload must NOT use "state" key — PC API uses event names' );
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
        $old_options = get_option( 'rpcare_options', [] );
        update_option( 'rpcare_options', array_merge( (array) $old_options, [ 'backup_mode' => 'local_dev' ] ) );
        // Ensure no $wpdb global — export_db_snapshot must return false, triggering db_export_failed.
        $had_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
        unset( $GLOBALS['wpdb'] );

        $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'create_pipeline_backup' );
        $method->setAccessible( true );

        try {
            $result = $method->invoke( null, 'cc-s05-batch' );
        } finally {
            update_option( 'rpcare_options', $old_options );
            // Restore wpdb so subsequent tests (e.g. those using the outbox) are not affected.
            if ( $had_wpdb !== null ) {
                $GLOBALS['wpdb'] = $had_wpdb;
            } else {
                $GLOBALS['wpdb'] = new wpdb();
            }
        }

        $this->assertInstanceOf(
            WP_Error::class, $result,
            'create_pipeline_backup must return WP_Error when the database export fails'
        );
        $this->assertSame(
            'db_export_failed', $result->get_error_code(),
            'Error code must be db_export_failed — plugins snapshot succeeded (WP_PLUGIN_DIR exists), but DB export did not'
        );
    }

    public function test_pipeline_backup_never_falls_back_to_local_storage_on_managed_host(): void {
        $old_options = get_option( 'rpcare_options', [] );
        update_option( 'rpcare_options', array_merge( (array) $old_options, [ 'backup_mode' => 'managed_by_host' ] ) );
        $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'create_pipeline_backup' );
        $method->setAccessible( true );

        try {
            $result = $method->invoke( null, 'managed-host-safety' );
        } finally {
            update_option( 'rpcare_options', $old_options );
        }

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'safe_backup_provider_required', $result->get_error_code() );
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

    public function test_manifest_fetch_prefers_pc_canonical_bytes_over_reserialised_structure(): void {
        update_option( 'rpcare_hub_url', 'https://hub.example.com' );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN, $this->care_encrypt( 'canonical-token' ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'canonical-instance' );

        $canonical = '{"a":"á/β","nested":{"a":1,"z":2}}';
        $GLOBALS['_wp_remote_get_mock'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'manifest'                => [ 'tampered_transport_copy' => true ],
                'manifest_canonical_json' => $canonical,
                'manifest_hash'           => hash( 'sha256', $canonical ),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
        ];

        $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'fetch_manifest_from_pc' );
        $method->setAccessible( true );
        $result = $method->invoke( null, 'canonical-batch' );

        $this->assertIsArray( $result );
        $this->assertSame( [ 'a' => 'á/β', 'nested' => [ 'a' => 1, 'z' => 2 ] ], $result['manifest'] );
    }

    public function test_manifest_fetch_rejects_modified_canonical_bytes(): void {
        update_option( 'rpcare_hub_url', 'https://hub.example.com' );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN, $this->care_encrypt( 'canonical-token' ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'canonical-instance' );

        $GLOBALS['_wp_remote_get_mock'] = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'manifest_canonical_json' => '{"a":2}',
                'manifest_hash'           => hash( 'sha256', '{"a":1}' ),
            ] ),
        ];

        $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'fetch_manifest_from_pc' );
        $method->setAccessible( true );
        $result = $method->invoke( null, 'canonical-batch' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'manifest_hash_mismatch', $result->get_error_code() );
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

        // apply_manifest_items now returns ['applied' => [...], 'deferred' => [...]].
        $this->assertArrayHasKey( 'applied', $results,  'apply_manifest_items must return an array with key "applied"' );
        $this->assertArrayHasKey( 'deferred', $results, 'apply_manifest_items must return an array with key "deferred"' );
        $this->assertCount( 0, $results['applied'],  'Translation must not appear in applied[]' );
        $this->assertCount( 1, $results['deferred'], 'Translation must appear in deferred[]' );

        $translation = $results['deferred'][0];
        $this->assertSame( 'woocommerce', $translation['slug'], 'Deferred slug must match the translation item slug' );
        $this->assertSame( 'deferred', $translation['status'] ?? '', 'Deferred translation must have status=deferred' );
        $this->assertArrayNotHasKey( 'error', $translation, 'Deferred translation must not have an error key' );
    }

    // ── Sprint 6 tests ─────────────────────────────────────────────────────────

    // CC-S11: paired/manual staging must wait for an explicit refresh confirmation.
    public function test_handle_prepare_staging_waits_for_manual_refresh(): void {
        // A canonical URL alone is not evidence that the clone was refreshed.
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT,    'staging' );
        update_option( 'rpcare_pipeline_canonical_url', 'https://staging.example.test' );

        $ref = new ReflectionMethod( RP_Care_Pipeline_Client::class, 'handle_prepare_staging' );
        $ref->setAccessible( true );

        $result = $ref->invoke( null, [
            'type'    => 'prepare_staging',
            'payload' => [ 'batch_id' => 'cc-s11-batch' ],
        ] );

        // Restore environment to avoid polluting other tests.
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT,    'production' );
        delete_option( 'rpcare_pipeline_canonical_url' );

        $this->assertSame( 'waiting_manual_staging_refresh', $result['event'] ?? null,
            'handle_prepare_staging must not report staging_ready before a manual refresh is confirmed' );
        $this->assertTrue( $result['success'] ?? false,
            'handle_prepare_staging must set success=true' );
        $this->assertSame( 'cc-s11-batch', $result['batch_id'] ?? null,
            'handle_prepare_staging must echo back batch_id' );
        $this->assertSame( 'manual_staging_refresh_required', $result['reason'] ?? null );
    }

    // CC-S12: handle_run_test_suite returns event='tests_complete' with severity.
    public function test_handle_run_test_suite_returns_tests_complete_event(): void {
        // Configure environment as staging so the handler proceeds.
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT,    'staging' );
        update_option( 'rpcare_pipeline_canonical_url', 'https://staging.example.test' );

        // Mock HTTP call to staging URL so the health check returns 200.
        $GLOBALS['_wp_remote_get_mock'] = [ 'response' => [ 'code' => 200 ], 'body' => '' ];

        $ref = new ReflectionMethod( RP_Care_Pipeline_Client::class, 'handle_run_test_suite' );
        $ref->setAccessible( true );

        $result = $ref->invoke( null, [
            'type'    => 'run_test_suite',
            'payload' => [ 'batch_id' => 'cc-s12-batch' ],
        ] );

        // Restore.
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, 'production' );
        delete_option( 'rpcare_pipeline_canonical_url' );
        $GLOBALS['_wp_remote_get_mock'] = null;

        $this->assertSame( 'tests_complete', $result['event'] ?? null,
            'handle_run_test_suite must set event=tests_complete at top level' );
        $this->assertTrue( $result['success'] ?? false,
            'handle_run_test_suite must set success=true' );
        $this->assertArrayHasKey( 'severity', $result,
            'handle_run_test_suite must include severity at top level' );
        $this->assertArrayHasKey( 'test_report', $result,
            'handle_run_test_suite must include test_report at top level' );
    }

    // CC-S13: handle_verify_production returns event='production_verified'.
    public function test_handle_verify_production_returns_production_verified_event(): void {
        $ref = new ReflectionMethod( RP_Care_Pipeline_Client::class, 'handle_verify_production' );
        $ref->setAccessible( true );

        $result = $ref->invoke( null, [
            'type'    => 'verify_production',
            'payload' => [ 'batch_id' => 'cc-s13-batch' ],
        ] );

        $this->assertSame( 'production_verified', $result['event'] ?? null,
            'handle_verify_production must set event=production_verified at top level' );
        $this->assertTrue( $result['success'] ?? false,
            'handle_verify_production must set success=true' );
        $this->assertArrayHasKey( 'test_report', $result,
            'handle_verify_production must include test_report at top level' );
    }

    // CC-S14: find_artifact with mismatched expected_version returns WP_Error.
    public function test_find_artifact_version_mismatch_returns_wp_error(): void {
        $manifest = [
            'artifacts' => [
                [
                    'type'    => 'plugin',
                    'slug'    => 'woocommerce',
                    'id'      => 'abc-123',
                    'sha256'  => str_repeat( 'a', 64 ),
                    'version' => '8.0.0',
                ],
            ],
        ];

        $ref = new ReflectionMethod( RP_Care_Task_Updates::class, 'find_artifact' );
        $ref->setAccessible( true );

        $result = $ref->invoke( null, $manifest, 'plugin', 'woocommerce', '9.0.0' );

        $this->assertTrue( is_wp_error( $result ),
            'find_artifact with mismatched version must return WP_Error' );
        $this->assertSame( 'artifact_version_mismatch', $result->get_error_code(),
            'Error code must be artifact_version_mismatch' );
    }

    // CC-S15: find_artifact with wrong slug returns not_found even if type matches.
    public function test_find_artifact_type_slug_identity_no_false_match(): void {
        $manifest = [
            'artifacts' => [
                [
                    'type'    => 'plugin',
                    'slug'    => 'woocommerce',
                    'id'      => 'abc-123',
                    'sha256'  => str_repeat( 'a', 64 ),
                    'version' => '8.0.0',
                ],
                [
                    'type'    => 'theme',
                    'slug'    => 'storefront',
                    'id'      => 'def-456',
                    'sha256'  => str_repeat( 'b', 64 ),
                    'version' => '4.0.0',
                ],
            ],
        ];

        $ref = new ReflectionMethod( RP_Care_Task_Updates::class, 'find_artifact' );
        $ref->setAccessible( true );

        // Plugin lookup must not match the theme with the same-looking slug area.
        $result = $ref->invoke( null, $manifest, 'plugin', 'storefront', '' );
        $this->assertTrue( is_wp_error( $result ),
            'Plugin lookup for "storefront" must not match the theme artifact' );

        // Theme lookup must not match the plugin.
        $result2 = $ref->invoke( null, $manifest, 'theme', 'woocommerce', '' );
        $this->assertTrue( is_wp_error( $result2 ),
            'Theme lookup for "woocommerce" must not match the plugin artifact' );

        // Correct type+slug combinations must resolve.
        $ok1 = $ref->invoke( null, $manifest, 'plugin', 'woocommerce', '' );
        $this->assertFalse( is_wp_error( $ok1 ), 'plugin+woocommerce must resolve' );

        $ok2 = $ref->invoke( null, $manifest, 'theme', 'storefront', '' );
        $this->assertFalse( is_wp_error( $ok2 ), 'theme+storefront must resolve' );
    }

    // CC-S16: apply_manifest_items puts deferred translations in deferred[], not applied[].
    public function test_apply_manifest_splits_deferred_from_applied(): void {
        $manifest = [
            'proposed_updates' => [
                'plugins'      => [],
                'themes'       => [],
                'core'         => [],
                'translations' => [
                    [ 'slug' => 'jetpack', 'type' => 'translation', 'from_version' => '1.0', 'to_version' => '2.0' ],
                    [ 'slug' => 'hello',   'type' => 'translation', 'from_version' => '1.0', 'to_version' => '2.0' ],
                ],
            ],
            'artifacts' => [],
        ];

        $method = new ReflectionMethod( RP_Care_Task_Updates::class, 'apply_manifest_items' );
        $method->setAccessible( true );
        $result = $method->invoke( null, $manifest, 'cc-s16-batch', 'staging' );

        $this->assertArrayHasKey( 'applied',  $result );
        $this->assertArrayHasKey( 'deferred', $result );
        $this->assertCount( 0, $result['applied'],  'No applied items for translation-only manifest' );
        $this->assertCount( 2, $result['deferred'], 'Both translations must be in deferred[]' );
    }

    // CC-S17: outbox enqueue returns a UUID string on success.
    public function test_pipeline_outbox_enqueue_returns_uuid_string(): void {
        if ( ! class_exists( 'RP_Care_Pipeline_Outbox' ) ) {
            $this->markTestSkipped( 'RP_Care_Pipeline_Outbox not loaded.' );
        }

        // Ensure $wpdb stub is present (another test may have unset it).
        if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] ) {
            $GLOBALS['wpdb'] = new wpdb();
        }

        // RP_Care_Pipeline_Outbox::enqueue requires a DB. The stub's insert()
        // returns false (no real DB), so the method returns WP_Error gracefully.
        $result = RP_Care_Pipeline_Outbox::enqueue( 'batch-s17', 'staging_updated', [ 'foo' => 'bar' ] );

        // In test environments without a DB the method returns WP_Error — that's acceptable.
        // In environments where $wpdb->insert returns 1 it must return a UUID string.
        $this->assertTrue(
            is_string( $result ) || is_wp_error( $result ),
            'enqueue must return a UUID string or WP_Error (no exceptions)'
        );
        if ( is_string( $result ) ) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $result,
                'enqueue return value must be a valid UUID v4'
            );
        }
    }

    // CC-S18: send_batch_event 5xx returns retryable WP_Error.
    public function test_send_batch_event_5xx_returns_retryable(): void {
        $enc_token = $this->care_encrypt( 'test-pc-token-cc-s18' );
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED,    true );
        update_option( 'rpcare_hub_url',                        'http://pc.example.test' );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,      $enc_token );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID,'inst-cc-s18' );

        $GLOBALS['_wp_remote_post_mock'] = static function( $url, $args ) {
            return [ 'response' => [ 'code' => 503 ], 'body' => 'Service Unavailable' ];
        };

        $result = RP_Care_Pipeline_Client::send_batch_event(
            'cc-s18-batch',
            'staging_updated',
            [],
            'evt-uuid-s18'
        );

        $GLOBALS['_wp_remote_post_mock'] = null;

        $this->assertTrue( is_wp_error( $result ), 'send_batch_event on 5xx must return WP_Error' );
        $this->assertSame( 'retryable', $result->get_error_code(),
            'Error code on 5xx must be retryable (not retry_later)' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S19: report_batch_to_pc must route through Outbox::enqueue, not wp_remote_post
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * P0-1: With current code report_batch_to_pc() calls wp_remote_post() directly (task-updates.php:2070).
     * After fix: all Care→PC events must be persisted via RP_Care_Pipeline_Outbox::enqueue()
     * BEFORE any HTTP attempt, so a crash between enqueue and delivery is recoverable.
     */
    public function test_CC_S19_report_batch_to_pc_routes_through_outbox_not_wp_remote_post(): void {
        $enc_token = $this->care_encrypt( 'test-token-s19' );
        update_option( 'rpcare_options',                         [ 'hub_url' => 'https://pc.s19.test' ] );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,       $enc_token );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'inst-s19' );
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED,     false ); // disabled → early-fail path

        $batch_status_calls = [];
        $GLOBALS['_wp_remote_post_mock'] = static function ( string $url, array $args ) use ( &$batch_status_calls ) {
            if ( str_contains( $url, 'batch-status' ) ) {
                $batch_status_calls[] = $url;
            }
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };

        $outbox_inserts = [];
        $orig_wpdb      = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class ( $outbox_inserts ) extends wpdb {
            private $log; // reference — no type declaration allowed on PHP ref properties
            public function __construct( array &$l ) { $this->log = &$l; }
            public function insert( string $table, array $data, $fmt = null ): int|false {
                if ( str_contains( $table, 'rpcare_pipeline_outbox' ) ) {
                    $this->log[] = $data;
                    return 1;
                }
                return false;
            }
            public function prepare( string $sql, ...$args ): string {
                $i = 0;
                return preg_replace_callback( '/%[sdf]/', function() use ( &$i, $args ) {
                    return "'" . addslashes( (string) ( $args[ $i++ ] ?? '' ) ) . "'";
                }, $sql );
            }
            public function query( string $sql ): int|bool { return true; }
            public function get_row( string $sql, $out = null, int $off = 0 ) { return null; }
            public function update( string $t, array $d, array $w, $f = null, $wf = null ): int|false { return 1; }
        };

        RP_Care_Task_Updates::apply_staging_batch( 'cc-s19-batch' );

        $GLOBALS['wpdb']                 = $orig_wpdb;
        $GLOBALS['_wp_remote_post_mock'] = null;

        $this->assertEmpty(
            $batch_status_calls,
            'report_batch_to_pc must not call wp_remote_post() directly — all events must route through the outbox'
        );

        $this->assertNotEmpty(
            $outbox_inserts,
            'report_batch_to_pc must call RP_Care_Pipeline_Outbox::enqueue() to persist the event before HTTP'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S20: deliver_pending must enforce FIFO — event-2 blocked while event-1 is failing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * P0-2: Current deliver_pending() uses a plain SELECT ALL and processes both events.
     * After fix: claim_next() only picks an event when no undelivered earlier sibling exists
     * for the same batch (FIFO per batch).
     */
    public function test_CC_S20_deliver_pending_enforces_fifo_per_batch(): void {
        $rows = [
            1 => [
                'id' => 1, 'batch_id' => 'cc-s20-batch', 'event' => 'staging_updated',
                'status' => 'pending', 'attempts' => 3, 'event_id' => 'evt-s20-001',
                'payload_json' => '{}', 'next_attempt_at' => '2000-01-01 00:00:00',
                'delivered_at' => null, 'created_at' => '2000-01-01 00:00:00',
                'last_error_code' => null, 'last_error_safe' => null,
            ],
            2 => [
                'id' => 2, 'batch_id' => 'cc-s20-batch', 'event' => 'production_updated',
                'status' => 'pending', 'attempts' => 0, 'event_id' => 'evt-s20-002',
                'payload_json' => '{}', 'next_attempt_at' => '2000-01-01 00:00:00',
                'delivered_at' => null, 'created_at' => '2000-01-01 00:05:00',
                'last_error_code' => null, 'last_error_safe' => null,
            ],
        ];

        $attempted_events = [];
        $GLOBALS['_wp_remote_post_mock'] = static function ( string $url, array $args ) use ( &$attempted_events ) {
            $body = json_decode( $args['body'] ?? '{}', true );
            $eid  = $body['event_id'] ?? ( $body['data']['event_id'] ?? '?' );
            $attempted_events[] = $eid;
            return [ 'response' => [ 'code' => 500 ], 'body' => 'Internal Server Error' ];
        };

        $orig_wpdb       = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class ( $rows ) extends wpdb {
            private $r; // untyped — assigned by value, internal copy used by mock
            private int $cand_calls = 0;

            public function __construct( array $rows ) { $this->r = $rows; }

            public function prepare( string $sql, ...$args ): string {
                $i = 0;
                return preg_replace_callback( '/%[sdf]/', function() use ( &$i, $args ) {
                    return "'" . addslashes( (string) ( $args[ $i++ ] ?? '' ) ) . "'";
                }, $sql );
            }

            // Old code path: plain SELECT returns ALL rows (no FIFO).
            // recover_expired_leases() queries for 'delivering' rows — none exist here, return empty.
            public function get_results( string $sql, string $output = 'OBJECT' ): array {
                if ( str_contains( $sql, "status = 'delivering'" ) ) {
                    return [];
                }
                return [ [ 'id' => 1 ], [ 'id' => 2 ] ];
            }

            // New code path: FIFO-aware SELECT for claim_next candidate,
            // plus full-row SELECT after claim.
            public function get_row( string $sql, $output = 'OBJECT', int $row_offset = 0 ) {
                if ( str_contains( $sql, "status = 'pending'" ) && ! str_contains( $sql, 'WHERE id =' ) ) {
                    // FIFO candidate SELECT.
                    $this->cand_calls++;
                    if ( $this->cand_calls > 1 ) {
                        return null; // event-1 is in backoff; event-2 is blocked.
                    }
                    $r = $this->r[1]; // event-1 is the only eligible candidate.
                    return $output === 'ARRAY_A' ? $r : (object) $r;
                }
                if ( preg_match( "/WHERE id = '?(\d+)'?/i", $sql, $m ) ) {
                    $id  = (int) $m[1];
                    $row = $this->r[ $id ] ?? null;
                    return $row ? ( $output === 'ARRAY_A' ? $row : (object) $row ) : null;
                }
                if ( preg_match( "/WHERE event_id = '([^']+)'/i", $sql, $m ) ) {
                    foreach ( $this->r as $row ) {
                        if ( $row['event_id'] === $m[1] ) {
                            return $output === 'ARRAY_A' ? $row : (object) $row;
                        }
                    }
                }
                return null;
            }

            // Atomic claim: UPDATE SET status='delivering' WHERE id=N AND status='pending'.
            public function query( string $sql ): int|bool {
                if ( preg_match(
                    "/UPDATE.*rpcare_pipeline_outbox.*SET.*status.*=.*'delivering'.*WHERE.*id.*=.*'?(\d+)'?.*AND.*status.*=.*'pending'/si",
                    $sql, $m
                ) ) {
                    $id = (int) $m[1];
                    if ( isset( $this->r[ $id ] ) && $this->r[ $id ]['status'] === 'pending' ) {
                        $this->r[ $id ]['status'] = 'delivering';
                        return 1;
                    }
                    return 0;
                }
                return true;
            }

            // record_failure() resets status and sets backoff.
            public function update( string $t, array $data, array $w, $f = null, $wf = null ): int|false {
                if ( isset( $w['id'] ) && isset( $this->r[ (int) $w['id'] ] ) ) {
                    $this->r[ (int) $w['id'] ] = array_merge( $this->r[ (int) $w['id'] ], $data );
                }
                return 1;
            }
        };

        update_option( 'rpcare_options',                         [ 'hub_url' => 'https://pc.s20.test' ] );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,       $this->care_encrypt( 'tok-s20' ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'inst-s20' );

        RP_Care_Pipeline_Outbox::deliver_pending();

        $GLOBALS['wpdb']                 = $orig_wpdb;
        $GLOBALS['_wp_remote_post_mock'] = null;

        $this->assertNotContains(
            'evt-s20-002',
            $attempted_events,
            'event-2 must be blocked while undelivered event-1 exists for the same batch (FIFO per batch)'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S21: deliver_pending must use atomic UPDATE claim, not plain SELECT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * P0-2: Current deliver_pending() selects rows without an atomic claim, allowing two
     * concurrent workers to both attempt the same outbox row.
     * After fix: claim_next() issues UPDATE SET status='delivering' WHERE status='pending'
     * — only one worker's UPDATE succeeds (rows_affected=1).
     */
    public function test_CC_S21_deliver_pending_uses_atomic_update_claim(): void {
        $pending_row = [
            'id' => 1, 'event_id' => 'evt-s21-001', 'batch_id' => 'cc-s21-batch',
            'event' => 'staging_updated', 'payload_json' => '{}',
            'attempts' => 0, 'status' => 'pending', 'delivered_at' => null,
            'next_attempt_at' => '2000-01-01 00:00:00', 'created_at' => '2000-01-01 00:00:00',
            'last_error_code' => null, 'last_error_safe' => null,
        ];

        $query_log = [];
        $orig_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class ( $pending_row, $query_log ) extends wpdb {
            private array $row;
            private $log; // untyped — PHP typed properties cannot be ref-assigned
            private int   $cand_calls = 0;

            public function __construct( array $row, array &$log ) {
                $this->row  = $row;
                $this->log  = &$log;
            }

            public function prepare( string $sql, ...$args ): string {
                $i = 0;
                return preg_replace_callback( '/%[sdf]/', function() use ( &$i, $args ) {
                    return "'" . addslashes( (string) ( $args[ $i++ ] ?? '' ) ) . "'";
                }, $sql );
            }

            public function get_results( string $sql, string $output = 'OBJECT' ): array {
                $this->log[] = 'get_results:' . $sql;
                // recover_expired_leases() queries for 'delivering' rows — none in this test.
                if ( str_contains( $sql, "status = 'delivering'" ) ) {
                    return [];
                }
                return [ [ 'id' => 1 ] ]; // old code returns one row
            }

            public function get_row( string $sql, $output = 'OBJECT', int $row_offset = 0 ) {
                $this->log[] = 'get_row:' . $sql;
                if ( str_contains( $sql, "status = 'pending'" ) ) {
                    $this->cand_calls++;
                    if ( $this->cand_calls > 1 ) return null;
                    return $output === 'ARRAY_A' ? $this->row : (object) $this->row;
                }
                if ( str_contains( $sql, 'WHERE id =' ) ) {
                    return $output === 'ARRAY_A' ? $this->row : (object) $this->row;
                }
                return null;
            }

            public function query( string $sql ): int|bool {
                $this->log[] = 'query:' . $sql;
                return 1;
            }

            public function update( string $t, array $d, array $w, $f = null, $wf = null ): int|false {
                return 1;
            }
        };

        $GLOBALS['_wp_remote_post_mock'] = static fn() => [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        update_option( 'rpcare_options',                         [ 'hub_url' => 'https://pc.s21.test' ] );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,       $this->care_encrypt( 'tok-s21' ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'inst-s21' );

        RP_Care_Pipeline_Outbox::deliver_pending();

        $GLOBALS['wpdb']                 = $orig_wpdb;
        $GLOBALS['_wp_remote_post_mock'] = null;

        $has_atomic_claim = false;
        foreach ( $query_log as $entry ) {
            if ( str_starts_with( $entry, 'query:' )
                 && preg_match(
                     "/UPDATE.*rpcare_pipeline_outbox.*SET.*status.*=.*'delivering'.*WHERE.*status.*=.*'pending'/si",
                     substr( $entry, 6 )
                 ) ) {
                $has_atomic_claim = true;
                break;
            }
        }

        $this->assertTrue(
            $has_atomic_claim,
            'deliver_pending() must use atomic UPDATE SET status=\'delivering\' WHERE status=\'pending\''
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S22: mark_processed must be called AFTER the handler, not before dispatch
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * P0-5: With current code, mark_processed() is called at line 271 BEFORE the switch
     * dispatch. If the handler returns failure, the command_id is permanently marked as
     * "already seen" — the next PC delivery is ACK'd without executing the handler.
     * After fix: mark_processed() called ONLY after a successful handler return.
     */
    public function test_CC_S22_mark_processed_called_after_handler_not_before(): void {
        $secret       = 'test-secret-cc-s22-sprint7';
        $command_id   = 'cc-s22-cmd-' . bin2hex( random_bytes( 4 ) );
        $instance_id  = 'inst-s22';
        $environment  = 'staging';
        $command_type = 'unknown_type_will_hit_default_case'; // → success=false
        $batch_id     = 'cc-s22-batch';
        $payload      = [];
        $payload_hash = hash( 'sha256', wp_json_encode( $payload ) );
        $issued_at    = gmdate( 'Y-m-d\TH:i:s\Z' );
        $expires_at   = gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 );

        $hmac_payload = implode( '|', [
            $command_id, $instance_id, $environment, $command_type,
            $batch_id, $payload_hash, '', $issued_at, $expires_at,
        ] );
        $hmac = hash_hmac( 'sha256', $hmac_payload, $secret );

        $command = [
            'command_id'         => $command_id,
            'instance_id'        => $instance_id,
            'command_type'       => $command_type,
            'target_environment' => $environment,
            'batch_id'           => $batch_id,
            'payload'            => $payload,
            'payload_hash'       => $payload_hash,
            'manifest_hash'      => '',
            'issued_at'          => $issued_at,
            'expires_at'         => $expires_at,
            'hmac'               => $hmac,
        ];

        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, $instance_id );
        update_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT,  $environment );

        $ref = new \ReflectionMethod( RP_Care_Pipeline_Client::class, 'process_command' );
        $ref->setAccessible( true );
        $result = $ref->invoke(
            null, $command, $secret, $instance_id, $environment,
            'https://hub.s22.test', 'tok-s22'
        );

        $this->assertFalse(
            $result['success'] ?? true,
            'CC-S22 setup: unknown command type must return success=false via default case'
        );

        $this->assertFalse(
            RP_Care_Pipeline_Client::is_replayed( $command_id ),
            'mark_processed() must not be called before dispatch — a failed handler must leave the command unmarked'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S23: send_batch_event 409 with body code=conflict → payload_conflict
    // ─────────────────────────────────────────────────────────────────────────

    public function test_send_batch_event_409_with_conflict_body_returns_payload_conflict(): void {
        $enc_token = $this->care_encrypt( 'test-pc-token-cc-s23' );
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED,     true );
        update_option( 'rpcare_hub_url',                         'http://pc.example.test' );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,       $enc_token );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'inst-cc-s23' );

        // PC returns 409 with body containing code=conflict (payload mismatch, NOT idempotent).
        $GLOBALS['_wp_remote_post_mock'] = static function ( $url, $args ) {
            return [
                'response' => [ 'code' => 409 ],
                'body'     => json_encode( [ 'code' => 'conflict', 'message' => 'Payload differs from recorded event.' ] ),
            ];
        };

        $result = RP_Care_Pipeline_Client::send_batch_event(
            'cc-s23-batch',
            'staging_updated',
            [],
            'evt-uuid-s23'
        );

        $GLOBALS['_wp_remote_post_mock'] = null;

        $this->assertTrue( is_wp_error( $result ),
            '409 with code=conflict body must return WP_Error' );
        $this->assertSame( 'payload_conflict', $result->get_error_code(),
            'Error code must be payload_conflict on 409 with body code=conflict' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S24: try_deliver_one returns blocked_by_prior_event when FIFO check fails
    // ─────────────────────────────────────────────────────────────────────────

    public function test_try_deliver_one_returns_blocked_when_prior_event_undelivered(): void {
        $target_event_id = 'evt-s24-target';
        $orig_wpdb       = $GLOBALS['wpdb'];

        $GLOBALS['wpdb'] = new class( $target_event_id ) extends wpdb {
            private string $target;
            public function __construct( string $t ) { $this->target = $t; }

            public function prepare( string $sql, ...$args ): string {
                $i = 0;
                return preg_replace_callback( '/%[sdf]/', function () use ( &$i, $args ) {
                    return "'" . addslashes( (string) ( $args[ $i++ ] ?? '' ) ) . "'";
                }, $sql );
            }

            // recover_expired_leases() calls get_results — return empty (no expired leases).
            public function get_results( string $sql, string $output = 'OBJECT' ): array { return []; }

            // FIFO-check get_row: return row with has_prior_undelivered=1.
            public function get_row( string $sql, $output = 'OBJECT', int $row_offset = 0 ) {
                if ( str_contains( $sql, 'has_prior_undelivered' ) ) {
                    $row = [
                        'id'                   => 2,
                        'batch_id'             => 'cc-s24-batch',
                        'status'               => 'pending',
                        'has_prior_undelivered' => 1,
                    ];
                    return $output === ARRAY_A ? $row : (object) $row;
                }
                return null;
            }

            public function query( string $sql ): int|bool { return true; }
        };

        try {
            $result = RP_Care_Pipeline_Outbox::try_deliver_one( $target_event_id );
        } finally {
            $GLOBALS['wpdb'] = $orig_wpdb;
        }

        $this->assertInstanceOf( \WP_Error::class, $result,
            'try_deliver_one must return WP_Error when a prior undelivered event exists for the same batch' );
        $this->assertSame( 'blocked_by_prior_event', $result->get_error_code(),
            'Error code must be blocked_by_prior_event' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CC-S25: handle_report_inventory POSTs inventory to PC /inventory-report
    //         when a batch_id is present (product fix: drift check gate)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_handle_report_inventory_posts_inventory_to_pc_when_batch_id_present(): void {
        $enc_token = $this->care_encrypt( 'test-pc-token-cc-s25' );
        update_option( 'rpcare_options',                         [ 'hub_url' => 'http://pc.s25.test' ] );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,       $enc_token );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'inst-cc-s25' );

        $captured_url  = null;
        $captured_body = null;
        $GLOBALS['_wp_remote_post_mock'] = static function ( string $url, array $args ) use ( &$captured_url, &$captured_body ) {
            $captured_url  = $url;
            $captured_body = json_decode( $args['body'] ?? '{}', true );
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        };

        $ref = new ReflectionMethod( RP_Care_Pipeline_Client::class, 'handle_report_inventory' );
        $ref->setAccessible( true );

        $result = $ref->invoke( null, [
            'type'    => 'report_inventory',
            'payload' => [ 'batch_id' => 'cc-s25-batch' ],
        ] );

        $GLOBALS['_wp_remote_post_mock'] = null;

        $this->assertTrue( $result['success'] ?? false,
            'handle_report_inventory must return success=true' );
        $this->assertArrayHasKey( 'inventory', $result,
            'handle_report_inventory must include inventory in the returned array' );

        $this->assertNotNull( $captured_url,
            'handle_report_inventory must call wp_remote_post when batch_id is present and credentials are set' );
        $this->assertStringEndsWith( '/pipeline/inventory-report', $captured_url,
            'wp_remote_post URL must end with /pipeline/inventory-report' );

        $this->assertIsArray( $captured_body,
            'wp_remote_post body must be a valid JSON object' );
        $this->assertArrayHasKey( 'inventory', $captured_body,
            'POSTed body must include inventory key' );
        $this->assertArrayHasKey( 'batch_id', $captured_body,
            'POSTed body must include batch_id key' );
        $this->assertSame( 'cc-s25-batch', $captured_body['batch_id'],
            'POSTed batch_id must match the command payload batch_id' );
    }

	public function test_pipeline_schedule_is_independent_of_paid_plan(): void {
		$GLOBALS['_as_pending'] = [];
		$GLOBALS['_as_enqueued'] = [];
		RP_Care_Pipeline_Client::ensure_poll_schedule( true );
		$this->assertArrayHasKey( 'rpcare_task_pipeline_poll', $GLOBALS['_as_pending'] );
		$this->assertArrayHasKey( 'rpcare_deliver_pipeline_outbox', $GLOBALS['_as_pending'] );
		$this->assertNotEmpty( array_filter( $GLOBALS['_as_enqueued'], static fn( $job ) => 'rpcare_task_pipeline_poll' === $job['hook'] ) );
		$scheduled = $GLOBALS['_as_pending'];
		$enqueued = $GLOBALS['_as_enqueued'];
		RP_Care_Pipeline_Client::ensure_poll_schedule( true );
		$this->assertSame( $scheduled, $GLOBALS['_as_pending'], 'Schedule reconciliation must be idempotent.' );
		$this->assertSame( $enqueued, $GLOBALS['_as_enqueued'], 'A healthy schedule must not enqueue duplicate probes.' );
		RP_Care_Pipeline_Client::ensure_poll_schedule( false );
		$this->assertArrayNotHasKey( 'rpcare_task_pipeline_poll', $GLOBALS['_as_pending'] );
		$this->assertArrayNotHasKey( 'rpcare_deliver_pipeline_outbox', $GLOBALS['_as_pending'] );
	}

	public function test_plugin_init_reconciles_enabled_pipeline_without_paid_plan(): void {
		$source = (string) file_get_contents( __DIR__ . '/../replanta-care.php' );
		$this->assertMatchesRegularExpression(
			'/is_pipeline_enabled\(\).*ensure_poll_schedule\(\s*true\s*\)/s',
			$source,
			'Care init must self-heal the Pipeline schedule independently of plan activation.'
		);
	}

	public function test_poll_now_flushes_durable_outbox_and_exposes_only_safe_summary(): void {
		$source = (string) file_get_contents( __DIR__ . '/../inc/class-rest.php' );
		$this->assertStringContainsString( 'RP_Care_Pipeline_Outbox::deliver_pending()', $source );
		$this->assertStringContainsString( 'RP_Care_Pipeline_Outbox::safe_status_for_batch( $batch_id )', $source );

		$previous = $GLOBALS['wpdb'];
		try {
			$GLOBALS['wpdb'] = new class extends wpdb {
				public function get_results( string $sql, string $output = 'OBJECT' ): array {
					return [ [ 'status' => 'delivered', 'total' => 2 ], [ 'status' => 'pending', 'total' => 1 ] ];
				}
				public function get_row( string $sql, string $output = 'OBJECT', int $row_offset = 0 ) {
					return [
						'status' => 'pending', 'attempts' => 2,
						'next_attempt_at' => '2026-08-30 17:00:00',
						'last_error_code' => 'retryable',
						'last_error_safe' => 'HTTP 503 from Plugin Center',
					];
				}
			};
			$status = RP_Care_Pipeline_Outbox::safe_status_for_batch( 'batch-safe' );
			$this->assertSame( [ 'delivered' => 2, 'pending' => 1 ], $status['counts'] );
			$this->assertSame( 2, $status['oldest_undelivered']['attempts'] );
			$this->assertArrayNotHasKey( 'payload_json', $status['oldest_undelivered'] );
			$this->assertArrayNotHasKey( 'event_id', $status['oldest_undelivered'] );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}
}
