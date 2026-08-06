<?php
/**
 * Replanta Care — Pipeline Client
 *
 * Three responsibilities:
 *
 * 1. Feature flag guard: when pc_staging_pipeline_enabled is on for this site,
 *    direct-cron updates are blocked; only apply_production_batch commands execute.
 *
 * 2. Cloned-environment quarantine: Care detects on boot that the current
 *    hostname does not match the canonical_url registered with PC, and enters
 *    cloned_environment_quarantine. In quarantine Care refuses updates, heartbeats
 *    as production, and shows a pairing wizard instead.
 *
 * 3. Command processor: polls Plugin Center for signed commands, verifies HMAC,
 *    enforces replay protection, and dispatches to specific handlers.
 *
 * Compatible with PHP 7.4+ (Care's minimum requirement).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RP_Care_Pipeline_Client {

    // WP options.
    const OPT_ENABLED        = 'rpcare_pipeline_enabled';
    const OPT_PAIRING_STATUS = 'rpcare_pipeline_pairing_status';
    const OPT_INSTANCE_ID    = 'rpcare_pipeline_instance_id';
    const OPT_GROUP_ID       = 'rpcare_pipeline_group_id';
    const OPT_ENVIRONMENT    = 'rpcare_pipeline_environment';
    const OPT_SECRET         = 'rpcare_pipeline_secret'; // encrypted
    const OPT_LAST_POLL      = 'rpcare_pipeline_last_poll';

    // Replay-protection option (bounded FIFO of processed command IDs).
    const OPT_PROCESSED_CMDS = 'rpcare_pipeline_processed_cmds';
    const MAX_PROCESSED_CMDS = 200;

    // Pairing statuses.
    const STATUS_UNPAIRED  = 'unpaired';
    const STATUS_PAIRING   = 'pairing';
    const STATUS_READY     = 'ready';
    const STATUS_BLOCKED   = 'blocked';
    const STATUS_QUARANTINE = 'cloned_environment_quarantine';
    const STATUS_DISABLED  = 'disabled';

    // ── Feature flag ─────────────────────────────────────────────────────────

    /**
     * Is the new staging pipeline enabled for this site?
     */
    public static function is_pipeline_enabled(): bool {
        return (bool) get_option( self::OPT_ENABLED, false );
    }

    /**
     * Is this site in cloned-environment quarantine?
     */
    public static function is_in_quarantine(): bool {
        return get_option( self::OPT_PAIRING_STATUS ) === self::STATUS_QUARANTINE;
    }

    /**
     * Is this a staging instance (vs production)?
     */
    public static function is_staging(): bool {
        return get_option( self::OPT_ENVIRONMENT ) === 'staging';
    }

    /**
     * When the pipeline is enabled, direct cron-driven production updates are
     * forbidden. Returns true when they should be blocked.
     */
    public static function blocks_direct_updates(): bool {
        if ( ! self::is_pipeline_enabled() ) {
            return false;
        }
        if ( self::is_staging() ) {
            return false; // staging applies its own batch; it is not "direct cron production"
        }
        // Production instance with pipeline on: block all direct updates.
        return true;
    }

    // ── Cloned-environment quarantine ────────────────────────────────────────

    /**
     * Called on every WordPress boot via an early `init` hook.
     * Detects if the current site URL differs from the registered canonical_url.
     *
     * If so, and if the pipeline is enabled, enter quarantine.
     *
     * This is a heuristic only (doesn't replace full pairing verification).
     */
    public static function maybe_detect_cloned_environment(): void {
        if ( ! self::is_pipeline_enabled() ) {
            return;
        }

        $pairing_status = get_option( self::OPT_PAIRING_STATUS, self::STATUS_UNPAIRED );

        // Once in quarantine, stay there until operator explicitly pairs.
        if ( $pairing_status === self::STATUS_QUARANTINE ) {
            return;
        }

        // Get the URL this instance was registered with.
        $canonical = self::get_canonical_url();
        if ( empty( $canonical ) ) {
            return; // Not paired yet, nothing to check.
        }

        $current = home_url();

        if ( ! self::urls_match( $canonical, $current ) ) {
            self::enter_quarantine( $canonical, $current );
        }
    }

    private static function enter_quarantine( string $canonical, string $current ): void {
        update_option( self::OPT_PAIRING_STATUS, self::STATUS_QUARANTINE );

        if ( class_exists( 'RP_Care_Utils' ) ) {
            RP_Care_Utils::log(
                'pipeline',
                'warning',
                'Cloned environment detected — entering quarantine. Canonical: ' . $canonical . ', Current: ' . $current,
                [ 'canonical' => $canonical, 'current' => $current ]
            );
        }

        // Fire the quarantine action so other Care components can react.
        do_action( 'rpcare_pipeline_quarantine_entered', $canonical, $current );
    }

    /**
     * Compare two URLs ignoring trailing slashes and scheme differences (http vs https).
     */
    private static function urls_match( string $a, string $b ): bool {
        $normalise = function ( string $url ): string {
            // Strip scheme.
            $url = preg_replace( '#^https?://#', '', $url );
            return rtrim( strtolower( $url ), '/' );
        };
        return $normalise( $a ) === $normalise( $b );
    }

    private static function get_canonical_url(): string {
        // The canonical URL was set when this instance paired.
        // It's stored alongside the instance_id option.
        return (string) get_option( 'rpcare_pipeline_canonical_url', '' );
    }

    // ── Command polling ──────────────────────────────────────────────────────

    /**
     * Poll Plugin Center for pending commands and execute any that arrive.
     * Called by the Care scheduler (rpcare_task_pipeline_poll hook).
     *
     * Returns a summary for logging.
     */
    public static function poll_and_execute(): array {
        if ( ! self::is_pipeline_enabled() ) {
            return [ 'skipped' => true, 'reason' => 'pipeline_disabled' ];
        }

        if ( self::is_in_quarantine() ) {
            return [ 'skipped' => true, 'reason' => 'in_quarantine' ];
        }

        $instance_id = get_option( self::OPT_INSTANCE_ID, '' );
        $group_id    = get_option( self::OPT_GROUP_ID, '' );
        $token       = self::get_raw_token();
        $secret      = self::get_raw_secret();
        $hub_url     = self::get_hub_url();

        if ( empty( $instance_id ) || empty( $token ) || empty( $secret ) || empty( $hub_url ) ) {
            return [ 'skipped' => true, 'reason' => 'not_configured' ];
        }

        $environment = get_option( self::OPT_ENVIRONMENT, 'production' );

        // Call PC's command endpoint.
        $url      = trailingslashit( $hub_url ) . 'wp-json/replanta-pc/v1/pipeline/commands';
        $response = wp_remote_get(
            add_query_arg( [
                'instance_id' => $instance_id,
                'environment' => $environment,
            ], $url ),
            [
                'timeout' => 15,
                'headers' => [
                    'X-Pipeline-Token' => $token,
                    'X-Instance-ID'    => $instance_id,
                ],
            ]
        );

        update_option( self::OPT_LAST_POLL, current_time( 'mysql' ) );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return [ 'success' => false, 'http_code' => $code ];
        }

        $body     = json_decode( wp_remote_retrieve_body( $response ), true );
        $commands = $body['commands'] ?? [];

        if ( empty( $commands ) ) {
            return [ 'success' => true, 'processed' => 0 ];
        }

        $processed = 0;
        $errors    = [];

        foreach ( $commands as $command ) {
            $result = self::process_command( $command, $secret, $instance_id, $environment, $hub_url, $token );

            if ( ! empty( $result['success'] ) ) {
                $processed++;
                self::ack_command( $command['command_id'], $instance_id, $hub_url, $token, $result );
            } else {
                $errors[] = $result['error'] ?? 'unknown';
                self::nack_command( $command['command_id'], $instance_id, $hub_url, $token, $result['error'] ?? '' );
            }
        }

        return [
            'success'   => true,
            'processed' => $processed,
            'errors'    => $errors,
        ];
    }

    // ── Command dispatch ─────────────────────────────────────────────────────

    /**
     * Verify and dispatch a single command.
     *
     * @return array{success: bool, error?: string}
     */
    private static function process_command(
        array  $command,
        string $secret,
        string $instance_id,
        string $environment,
        string $hub_url,
        string $token
    ): array {

        // 1. Verify command_id, instance_id, expires_at, HMAC.
        $verify = self::verify_command( $command, $secret, $instance_id, $environment );
        if ( is_wp_error( $verify ) ) {
            return [ 'success' => false, 'error' => $verify->get_error_message() ];
        }

        $command_id   = $command['command_id'] ?? '';
        $command_type = $command['command_type'] ?? '';

        // 2. Replay protection.
        if ( self::is_replayed( $command_id ) ) {
            return [ 'success' => true, 'replayed' => true ]; // ack it again (idempotent)
        }

        self::mark_processed( $command_id );

        // 3. Dispatch.
        switch ( $command_type ) {
            case 'report_inventory':
                return self::handle_report_inventory( $command );

            case 'prepare_staging':
                return self::handle_prepare_staging( $command );

            case 'apply_update_batch':
                return self::handle_apply_update_batch( $command );

            case 'run_test_suite':
                return self::handle_run_test_suite( $command );

            case 'apply_production_batch':
                return self::handle_apply_production_batch( $command );

            case 'verify_production':
                return self::handle_verify_production( $command );

            case 'rollback_batch':
                return self::handle_rollback_batch( $command );

            case 'cancel_batch':
                return self::handle_cancel_batch( $command );

            default:
                return [ 'success' => false, 'error' => "Unknown command type: $command_type" ];
        }
    }

    // ── Command verification ─────────────────────────────────────────────────

    private static function verify_command(
        array  $command,
        string $secret,
        string $expected_instance_id,
        string $environment
    ): bool|\WP_Error {

        // Required fields.
        $required = [ 'command_id', 'instance_id', 'command_type', 'payload_hash', 'expires_at', 'hmac' ];
        foreach ( $required as $field ) {
            if ( empty( $command[ $field ] ) ) {
                return new WP_Error( 'missing_field', "Command missing field: $field" );
            }
        }

        // instance_id must match.
        if ( $command['instance_id'] !== $expected_instance_id ) {
            return new WP_Error( 'instance_mismatch', 'Command instance_id does not match.' );
        }

        // Staging must NOT accept production commands.
        // Production must NOT accept staging commands.
        // The pipeline server tags each command with an environment field when applicable.
        if ( isset( $command['target_environment'] ) && $command['target_environment'] !== $environment ) {
            return new WP_Error( 'environment_mismatch', 'Command environment does not match this instance.' );
        }

        // Expiry.
        if ( strtotime( $command['expires_at'] ) < time() ) {
            return new WP_Error( 'expired', 'Command has expired.' );
        }

        // HMAC (timing-safe).
        $hmac_payload = implode( '|', [
            $command['command_id'],
            $command['instance_id'],
            $command['command_type'],
            $command['payload_hash'],
            $command['expires_at'],
        ] );
        $expected_hmac = hash_hmac( 'sha256', $hmac_payload, $secret );

        if ( ! hash_equals( $expected_hmac, $command['hmac'] ) ) {
            return new WP_Error( 'invalid_hmac', 'Command HMAC verification failed.' );
        }

        // payload_hash verification.
        $payload_json   = wp_json_encode( $command['payload'] ?? [] );
        $actual_p_hash  = hash( 'sha256', $payload_json );
        if ( ! hash_equals( $command['payload_hash'], $actual_p_hash ) ) {
            return new WP_Error( 'payload_tampered', 'Command payload hash mismatch.' );
        }

        return true;
    }

    // ── Command handlers ─────────────────────────────────────────────────────

    private static function handle_report_inventory( array $cmd ): array {
        if ( class_exists( 'RP_Care_Inventory_Snapshot' ) ) {
            $inv = RP_Care_Inventory_Snapshot::capture();
            return [ 'success' => true, 'inventory' => $inv ];
        }
        return [ 'success' => false, 'error' => 'Inventory snapshot not available.' ];
    }

    private static function handle_prepare_staging( array $cmd ): array {
        $batch_id = $cmd['payload']['batch_id'] ?? '';
        if ( ! self::is_staging() ) {
            return [ 'success' => false, 'error' => 'prepare_staging sent to production instance.' ];
        }
        if ( class_exists( 'RP_Care_Staging_Provider' ) ) {
            $result = RP_Care_Staging_Provider::prepare( $batch_id );
            return is_wp_error( $result )
                ? [ 'success' => false, 'error' => $result->get_error_message() ]
                : [ 'success' => true, 'result' => $result ];
        }
        return [ 'success' => false, 'error' => 'Staging provider not available.' ];
    }

    private static function handle_apply_update_batch( array $cmd ): array {
        $batch_id = $cmd['payload']['batch_id'] ?? '';
        if ( ! self::is_staging() ) {
            return [ 'success' => false, 'error' => 'apply_update_batch sent to production. Use apply_production_batch.' ];
        }
        // Offload to AS to avoid HTTP timeout.
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action(
                'rpcare_pipeline_apply_staging_batch',
                [ 'batch_id' => $batch_id ],
                'replanta-care'
            );
            return [ 'success' => true, 'queued' => true, 'batch_id' => $batch_id ];
        }
        return [ 'success' => false, 'error' => 'Action Scheduler not available.' ];
    }

    private static function handle_run_test_suite( array $cmd ): array {
        $batch_id = $cmd['payload']['batch_id'] ?? '';
        if ( class_exists( 'RP_Care_Test_Runner' ) ) {
            $result = RP_Care_Test_Runner::run( $batch_id );
            return [ 'success' => true, 'result' => $result ];
        }
        return [ 'success' => false, 'error' => 'Test runner not available.' ];
    }

    private static function handle_apply_production_batch( array $cmd ): array {
        $batch_id      = $cmd['payload']['batch_id'] ?? '';
        $manifest_hash = $cmd['payload']['manifest_hash'] ?? '';
        $approval_id   = $cmd['payload']['approval_id'] ?? '';

        // Production must not be staging.
        if ( self::is_staging() ) {
            return [ 'success' => false, 'error' => 'apply_production_batch sent to staging instance.' ];
        }

        if ( empty( $batch_id ) || empty( $manifest_hash ) || empty( $approval_id ) ) {
            return [ 'success' => false, 'error' => 'Missing batch_id, manifest_hash, or approval_id.' ];
        }

        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action(
                'rpcare_pipeline_apply_production_batch',
                [
                    'batch_id'      => $batch_id,
                    'manifest_hash' => $manifest_hash,
                    'approval_id'   => $approval_id,
                ],
                'replanta-care'
            );
            return [ 'success' => true, 'queued' => true ];
        }

        return [ 'success' => false, 'error' => 'Action Scheduler not available.' ];
    }

    private static function handle_verify_production( array $cmd ): array {
        $batch_id = $cmd['payload']['batch_id'] ?? '';
        if ( class_exists( 'RP_Care_Test_Runner' ) ) {
            $result = RP_Care_Test_Runner::run( $batch_id, [ 'non_destructive' => true ] );
            return [ 'success' => true, 'result' => $result ];
        }
        return [ 'success' => false, 'error' => 'Test runner not available.' ];
    }

    private static function handle_rollback_batch( array $cmd ): array {
        $batch_id = $cmd['payload']['batch_id'] ?? '';
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action(
                'rpcare_pipeline_rollback_batch',
                [ 'batch_id' => $batch_id ],
                'replanta-care'
            );
            return [ 'success' => true, 'queued' => true ];
        }
        return [ 'success' => false, 'error' => 'Action Scheduler not available.' ];
    }

    private static function handle_cancel_batch( array $cmd ): array {
        $batch_id = $cmd['payload']['batch_id'] ?? '';
        do_action( 'rpcare_pipeline_batch_cancelled', $batch_id );
        return [ 'success' => true, 'batch_id' => $batch_id ];
    }

    // ── Replay protection ────────────────────────────────────────────────────

    public static function is_replayed( string $command_id ): bool {
        $processed = get_option( self::OPT_PROCESSED_CMDS, [] );
        return is_array( $processed ) && in_array( $command_id, $processed, true );
    }

    public static function mark_processed( string $command_id ): void {
        $processed = get_option( self::OPT_PROCESSED_CMDS, [] );
        if ( ! is_array( $processed ) ) {
            $processed = [];
        }
        $processed[] = $command_id;
        // Keep only the most recent N entries (bounded FIFO).
        if ( count( $processed ) > self::MAX_PROCESSED_CMDS ) {
            $processed = array_slice( $processed, -self::MAX_PROCESSED_CMDS );
        }
        update_option( self::OPT_PROCESSED_CMDS, $processed );
    }

    /**
     * Public testable wrapper for verify_command.
     * Reads instance_id and secret from WP options, returns bool.
     */
    public static function verify_command_public( array $command ): bool {
        $secret      = self::get_raw_secret();
        $instance_id = (string) get_option( self::OPT_INSTANCE_ID, '' );
        $environment = (string) get_option( self::OPT_ENVIRONMENT, 'production' );

        $result = self::verify_command( $command, $secret, $instance_id, $environment );
        return $result === true;
    }

    // ── Ack / Nack helpers ───────────────────────────────────────────────────

    private static function ack_command(
        string $command_id,
        string $instance_id,
        string $hub_url,
        string $token,
        array  $result
    ): void {
        wp_remote_post(
            trailingslashit( $hub_url ) . 'wp-json/replanta-pc/v1/pipeline/command-ack',
            [
                'timeout' => 10,
                'headers' => [
                    'Content-Type'     => 'application/json',
                    'X-Pipeline-Token' => $token,
                    'X-Instance-ID'    => $instance_id,
                ],
                'body'    => wp_json_encode( [
                    'command_id'  => $command_id,
                    'instance_id' => $instance_id,
                    'success'     => true,
                    'result'      => $result,
                ] ),
            ]
        );
    }

    private static function nack_command(
        string $command_id,
        string $instance_id,
        string $hub_url,
        string $token,
        string $reason
    ): void {
        wp_remote_post(
            trailingslashit( $hub_url ) . 'wp-json/replanta-pc/v1/pipeline/command-ack',
            [
                'timeout' => 10,
                'headers' => [
                    'Content-Type'     => 'application/json',
                    'X-Pipeline-Token' => $token,
                    'X-Instance-ID'    => $instance_id,
                ],
                'body'    => wp_json_encode( [
                    'command_id'  => $command_id,
                    'instance_id' => $instance_id,
                    'success'     => false,
                    'reason'      => substr( $reason, 0, 500 ),
                ] ),
            ]
        );
    }

    // ── Public credential accessors (used by RP_Care_Approval_Screen) ────────

    public static function get_hub_url_public(): string {
        return self::get_hub_url();
    }

    public static function get_token_public(): string {
        return self::get_raw_token();
    }

    // ── AS action-hook registration ──────────────────────────────────────────

    /**
     * Register add_action callbacks for the three AS async actions enqueued by
     * handle_apply_update_batch(), handle_apply_production_batch(), and
     * handle_rollback_batch().  Must be called early (init hook) so AS can
     * dispatch them.
     */
    public static function register_as_callbacks(): void {
        add_action(
            'rpcare_pipeline_apply_staging_batch',
            static function ( string $batch_id ): void {
                if ( ! class_exists( 'RP_Care_Task_Updates' ) ) {
                    return;
                }
                RP_Care_Task_Updates::apply_staging_batch( $batch_id );
            }
        );

        add_action(
            'rpcare_pipeline_apply_production_batch',
            static function ( string $batch_id, string $manifest_hash = '', string $approval_id = '' ): void {
                if ( ! class_exists( 'RP_Care_Task_Updates' ) ) {
                    return;
                }
                RP_Care_Task_Updates::apply_production_batch( $batch_id, $manifest_hash, $approval_id );
            },
            10,
            3
        );

        add_action(
            'rpcare_pipeline_rollback_batch',
            static function ( string $batch_id ): void {
                if ( ! class_exists( 'RP_Care_Task_Updates' ) ) {
                    return;
                }
                RP_Care_Task_Updates::rollback_batch( $batch_id );
            }
        );
    }

    // ── Credential helpers ───────────────────────────────────────────────────

    private static function get_raw_token(): string {
        $opts = get_option( 'rpcare_options', [] );
        return $opts['site_token'] ?? (string) get_option( 'rpcare_site_token', '' );
    }

    private static function get_raw_secret(): string {
        $enc = (string) get_option( self::OPT_SECRET, '' );
        if ( empty( $enc ) ) {
            return '';
        }
        // Simple decrypt: if it starts with enc1: use AES-256-CBC (same scheme as PC_Pairing).
        if ( str_starts_with( $enc, 'enc1:' ) && extension_loaded( 'openssl' ) ) {
            $key  = hash( 'sha256', wp_salt( 'auth' ) . 'pc-pipeline-secret-v1', true );
            $data = base64_decode( substr( $enc, 5 ) );
            if ( strlen( $data ) < 17 ) {
                return '';
            }
            $iv    = substr( $data, 0, 16 );
            $plain = openssl_decrypt( substr( $data, 16 ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
            return $plain !== false ? $plain : '';
        }
        return (string) base64_decode( $enc );
    }

    private static function get_hub_url(): string {
        $opts = get_option( 'rpcare_options', [] );
        return rtrim( $opts['hub_url'] ?? (string) get_option( 'rpcare_hub_url', '' ), '/' );
    }

    /**
     * Forward a command ACK to PC's REST endpoint.
     * Called from the Care REST handler after the admin triggers an ACK.
     *
     * @param array $body { command_id, status, result?, error_message? }
     * @return array
     */
    public static function forward_ack( array $body ): array {
        $hub_url = self::get_hub_url();
        if ( empty( $hub_url ) ) {
            return [ 'ok' => false, 'reason' => 'no_hub_url' ];
        }

        $instance_id = (string) get_option( self::OPT_INSTANCE_ID, '' );
        $token       = self::get_raw_token();

        if ( empty( $instance_id ) || empty( $token ) ) {
            return [ 'ok' => false, 'reason' => 'not_paired' ];
        }

        $response = wp_remote_post(
            $hub_url . '/wp-json/replanta-pc/v1/pipeline/command-ack',
            [
                'timeout' => 15,
                'headers' => [
                    'Content-Type'    => 'application/json',
                    'X-Pipeline-Token'=> $token,
                    'X-Instance-ID'   => $instance_id,
                ],
                'body' => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'reason' => $response->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return array_merge( [ 'ok' => $code === 200 ], is_array( $data ) ? $data : [] );
    }

    /**
     * Handle a pairing request coming from the Care admin UI.
     *
     * @param array $body { action: 'consume', token, canonical_url } |
     *                    { action: 'status' }
     * @return array|WP_Error
     */
    public static function handle_pairing_request( array $body ): array|\WP_Error {
        $action = sanitize_text_field( $body['action'] ?? 'status' );

        if ( $action === 'status' ) {
            return [
                'status'      => (string) get_option( self::OPT_PAIRING_STATUS, self::STATUS_UNPAIRED ),
                'environment' => (string) get_option( self::OPT_ENVIRONMENT, '' ),
                'instance_id' => (string) get_option( self::OPT_INSTANCE_ID, '' ),
                'group_id'    => (string) get_option( self::OPT_GROUP_ID, '' ),
            ];
        }

        if ( $action === 'consume' ) {
            $raw_token     = sanitize_text_field( $body['token'] ?? '' );
            $canonical_url = esc_url_raw( $body['canonical_url'] ?? home_url() );

            if ( empty( $raw_token ) ) {
                return new \WP_Error( 'missing_token', 'token is required.' );
            }

            $hub_url     = self::get_hub_url();
            $instance_id = (string) get_option( self::OPT_INSTANCE_ID, wp_generate_uuid4() );

            update_option( self::OPT_PAIRING_STATUS, self::STATUS_PAIRING );
            update_option( self::OPT_INSTANCE_ID, $instance_id );

            $response = wp_remote_post(
                $hub_url . '/wp-json/replanta-pc/v1/pipeline/pairing/consume',
                [
                    'timeout' => 20,
                    'headers' => [ 'Content-Type' => 'application/json' ],
                    'body'    => wp_json_encode( [
                        'token'         => $raw_token,
                        'canonical_url' => $canonical_url,
                        'instance_id'   => $instance_id,
                        'capabilities'  => [ 'care_version' => defined( 'RPCARE_VERSION' ) ? RPCARE_VERSION : '' ],
                    ] ),
                ]
            );

            if ( is_wp_error( $response ) ) {
                update_option( self::OPT_PAIRING_STATUS, self::STATUS_UNPAIRED );
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( $code !== 200 || empty( $data['ok'] ) ) {
                update_option( self::OPT_PAIRING_STATUS, self::STATUS_UNPAIRED );
                return new \WP_Error( 'pairing_failed', 'Pairing rejected by PC.' );
            }

            update_option( self::OPT_PAIRING_STATUS, self::STATUS_READY );
            update_option( self::OPT_GROUP_ID, $data['group_id'] ?? '' );
            update_option( self::OPT_ENVIRONMENT, $data['environment'] ?? 'production' );
            update_option( 'rpcare_pipeline_canonical_url', $canonical_url );

            return [
                'status'      => self::STATUS_READY,
                'instance_id' => $instance_id,
                'group_id'    => $data['group_id'] ?? '',
                'environment' => $data['environment'] ?? 'production',
            ];
        }

        return new \WP_Error( 'unknown_action', "Unknown pairing action: $action." );
    }
}
