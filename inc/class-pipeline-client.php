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

    const MAX_ARTIFACT_BYTES = 33554432; // Must match PC_Artifact_Store.

    /** @var callable|null Test seams; never configured in production. */
    public static $artifact_downloader = null;
    public static $artifact_uploader   = null;

    // WP options.
    const OPT_ENABLED        = 'rpcare_pipeline_enabled';
    const OPT_PAIRING_STATUS = 'rpcare_pipeline_pairing_status';
    const OPT_INSTANCE_ID    = 'rpcare_pipeline_instance_id';
    const OPT_GROUP_ID       = 'rpcare_pipeline_group_id';
    const OPT_ENVIRONMENT    = 'rpcare_pipeline_environment';
    const OPT_TOKEN          = 'rpcare_pipeline_token';  // encrypted with Care's local key
    const OPT_SECRET         = 'rpcare_pipeline_secret'; // encrypted with Care's local key
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

	/** Ensure the pull channel is scheduled independently of plan activation. */
	public static function ensure_poll_schedule( bool $enabled = true ): void {
		$hooks = [ 'rpcare_task_pipeline_poll', 'rpcare_deliver_pipeline_outbox' ];
		$poll_created = false;
		foreach ( $hooks as $hook ) {
			if ( ! $enabled ) {
				if ( function_exists( 'as_unschedule_all_actions' ) ) as_unschedule_all_actions( $hook, [], 'replanta-care' );
				if ( function_exists( 'wp_clear_scheduled_hook' ) ) wp_clear_scheduled_hook( $hook );
				continue;
			}
			if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
				if ( ! as_next_scheduled_action( $hook, [], 'replanta-care' ) ) {
					as_schedule_recurring_action( time() + 30, 5 * MINUTE_IN_SECONDS, $hook, [], 'replanta-care', true );
					if ( 'rpcare_task_pipeline_poll' === $hook ) $poll_created = true;
				}
			} elseif ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + 30, 'five_minutes', $hook );
			}
		}
		if ( $enabled && $poll_created && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'rpcare_task_pipeline_poll', [], 'replanta-care', true );
		}
	}

	/** Prove the outbound authenticated channel without leasing commands. */
	public static function send_heartbeat(): array {
		$instance_id = (string) get_option( self::OPT_INSTANCE_ID, '' );
		$token       = self::get_raw_token();
		$hub_url     = self::get_hub_url();
		$environment = (string) get_option( self::OPT_ENVIRONMENT, 'production' );
		if ( '' === $instance_id || '' === $token || '' === $hub_url ) {
			return [ 'success' => false, 'error' => 'not_configured' ];
		}
		$url = trailingslashit( $hub_url ) . 'wp-json/replanta-pc/v1/pipeline/commands';
		$response = wp_remote_get( add_query_arg( [
			'instance_id' => $instance_id, 'environment' => $environment, 'heartbeat_only' => 1,
		], $url ), [
			'timeout' => 15,
			'headers' => [
				'X-Pipeline-Token' => $token, 'X-Instance-ID' => $instance_id,
				'X-Care-Version' => defined( 'RPCARE_VERSION' ) ? RPCARE_VERSION : '',
			],
		] );
		if ( is_wp_error( $response ) ) return [ 'success' => false, 'error' => $response->get_error_message() ];
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) return [ 'success' => false, 'http_code' => $code ];
		update_option( self::OPT_LAST_POLL, current_time( 'mysql' ) );
		return [ 'success' => true ];
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
					'X-Care-Version'   => defined( 'RPCARE_VERSION' ) ? RPCARE_VERSION : '',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return [ 'success' => false, 'http_code' => $code ];
        }

		update_option( self::OPT_LAST_POLL, current_time( 'mysql' ) );

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
        $batch_id_cmd = $command['payload']['batch_id'] ?? '';

        // 2. Replay protection + atomic dispatch claim (journal-based).
        if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
            // INSERT IGNORE records the first receipt; subsequent calls are no-ops.
            RP_Care_Pipeline_Command_Journal::insert_received(
                $command_id, $instance_id, $environment, $command_type, $batch_id_cmd
            );

            // Build identity hash — binds command_id to its exact invocation identity.
            $identity_hash = hash( 'sha256', implode( '|', [
                $instance_id,
                $environment,
                $command_type,
                $batch_id_cmd,
                $command['payload_hash'] ?? '',
            ] ) );

            $worker_id = substr( md5( uniqid( (string) getmypid(), true ) ), 0, 16 );
            $lease_at  = gmdate(
                'Y-m-d H:i:s',
                time() + RP_Care_Pipeline_Command_Journal::DISPATCH_LEASE_SECONDS
            );

            $claim = RP_Care_Pipeline_Command_Journal::claim_for_dispatch(
                $command_id, $identity_hash, $worker_id, $lease_at
            );

            switch ( $claim ) {
                case 'already_terminal':
                    // completed → replay success; failed_permanent → report failure.
                    $final_state = RP_Care_Pipeline_Command_Journal::get_state( $command_id );
                    if ( $final_state === RP_Care_Pipeline_Command_Journal::STATE_COMPLETED ) {
                        return [ 'success' => true, 'replayed' => true ];
                    }
                    return [ 'success' => false, 'error' => 'command_permanently_failed' ];

                case 'accepted_or_running':
                    // AS action already in flight; ack without re-dispatch.
                    return [ 'success' => true, 'replayed' => true ];

                case 'lease_valid':
                    // Another worker holds the dispatching lease; PC should retry later.
                    return [ 'success' => false, 'error' => 'dispatch_lease_held_by_other_worker' ];

                case 'identity_conflict':
                    return [ 'success' => false, 'error' => 'command_identity_conflict' ];

                case 'not_found':
                    // Should not happen (insert_received ran); treat as retryable.
                    return [ 'success' => false, 'error' => 'journal_row_not_found' ];

                case 'claimed':
                default:
                    // This worker owns the slot — fall through to dispatch.
                    break;
            }
        } elseif ( self::is_replayed( $command_id ) ) {
            return [ 'success' => true, 'replayed' => true ];
        }

        // 3. Dispatch — journal / mark_processed() only after successful handler return.
        switch ( $command_type ) {
            case 'report_inventory':
                $result = self::handle_report_inventory( $command );
                break;

            case 'export_update_artifact':
                $result = self::handle_export_update_artifact( $command, $hub_url, $token, $instance_id );
                break;

            case 'prepare_staging':
                $result = self::handle_prepare_staging( $command );
                break;

            case 'apply_update_batch':
                $result = self::handle_apply_update_batch( $command );
                break;

            case 'run_test_suite':
                $result = self::handle_run_test_suite( $command );
                break;

            case 'apply_production_batch':
                $result = self::handle_apply_production_batch( $command );
                break;

            case 'verify_production':
                $result = self::handle_verify_production( $command );
                break;

            case 'rollback_batch':
                $result = self::handle_rollback_batch( $command );
                break;

            case 'cancel_batch':
                $result = self::handle_cancel_batch( $command );
                break;

            case 'approval_required':
                $result = self::handle_approval_required( $command );
                break;

            case 'prepare_production':
                $result = self::handle_prepare_production( $command );
                break;

            default:
                return [ 'success' => false, 'error' => "Unknown command type: $command_type" ];
        }

        // Mark as processed only when the handler succeeded.
        if ( ! empty( $result['success'] ) ) {
            if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
                // Async handlers advance journal inside handle_* (dispatching→accepted);
                // sync handlers get marked completed here.
                $state_after = RP_Care_Pipeline_Command_Journal::get_state( $command_id );
                if ( ! in_array( $state_after, [
                    RP_Care_Pipeline_Command_Journal::STATE_DISPATCHING,
                    RP_Care_Pipeline_Command_Journal::STATE_ACCEPTED,
                    RP_Care_Pipeline_Command_Journal::STATE_RUNNING,
                    RP_Care_Pipeline_Command_Journal::STATE_COMPLETED,
                    RP_Care_Pipeline_Command_Journal::STATE_FAILED_PERMANENT,
                    RP_Care_Pipeline_Command_Journal::STATE_FAILED_RETRYABLE,
                ], true ) ) {
                    RP_Care_Pipeline_Command_Journal::mark_completed( $command_id );
                }
            } else {
                self::mark_processed( $command_id );
            }
        } elseif ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
            // Handler failed and hasn't already marked the state — mark retryable.
            $state_after = RP_Care_Pipeline_Command_Journal::get_state( $command_id );
            if ( ! in_array( $state_after, [
                RP_Care_Pipeline_Command_Journal::STATE_FAILED_RETRYABLE,
                RP_Care_Pipeline_Command_Journal::STATE_FAILED_PERMANENT,
                RP_Care_Pipeline_Command_Journal::STATE_COMPLETED,
            ], true ) ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, $result['error'] ?? 'handler_failed' );
            }
        }

        return $result;
    }

    // ── Command verification ─────────────────────────────────────────────────

    private static function verify_command(
        array  $command,
        string $secret,
        string $expected_instance_id,
        string $environment
    ): bool|\WP_Error {

        // Required fields.
        $required = [ 'command_id', 'instance_id', 'command_type', 'payload_hash', 'expires_at', 'hmac', 'issued_at' ];
        foreach ( $required as $field ) {
            if ( empty( $command[ $field ] ) ) {
                return new WP_Error( 'missing_field', "Command missing field: $field" );
            }
        }

        // target_environment key must be present (value may be empty for legacy, but key must exist).
        if ( ! array_key_exists( 'target_environment', $command ) ) {
            return new WP_Error( 'missing_field', 'Command missing field: target_environment' );
        }

        // batch_id key must be present (value may be null/empty, but key must exist).
        if ( ! array_key_exists( 'batch_id', $command ) ) {
            return new WP_Error( 'missing_field', 'Command missing field: batch_id' );
        }

        // manifest_hash key must be present (value may be empty for commands without a batch).
        if ( ! array_key_exists( 'manifest_hash', $command ) ) {
            return new WP_Error( 'missing_field', 'Command missing field: manifest_hash' );
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

        // HMAC (timing-safe). All fields bound so neither staging nor production
        // can accept a command signed for the other environment.
        $hmac_payload = implode( '|', [
            $command['command_id'],
            $command['instance_id'],
            $command['target_environment'] ?? '',
            $command['command_type'],
            $command['batch_id'] ?? '',
            $command['payload_hash'],
            $command['manifest_hash'] ?? '',
            $command['issued_at'] ?? '',
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
        if ( ! class_exists( 'RP_Care_Inventory_Snapshot' ) ) {
            return [ 'success' => false, 'error' => 'Inventory snapshot not available.' ];
        }

        $inv      = RP_Care_Inventory_Snapshot::capture();
        $batch_id = $cmd['payload']['batch_id'] ?? '';

        // POST inventory to PC's drift-check endpoint when a batch_id is present.
        // The command ACK only carries scalar event data; inventory must reach PC via
        // the dedicated inventory-report route so the drift check can fire atomically.
        if ( ! empty( $batch_id ) ) {
            $hub_url     = self::get_hub_url();
            $token       = self::get_raw_token();
            $instance_id = (string) get_option( self::OPT_INSTANCE_ID, '' );

            if ( ! empty( $hub_url ) && ! empty( $token ) && ! empty( $instance_id ) ) {
                wp_remote_post(
                    trailingslashit( $hub_url ) . 'wp-json/replanta-pc/v1/pipeline/inventory-report',
                    [
                        'timeout' => 20,
                        'headers' => [
                            'Content-Type'     => 'application/json',
                            'X-Pipeline-Token' => $token,
                            'X-Instance-ID'    => $instance_id,
                        ],
                        'body' => wp_json_encode( [
                            'inventory' => $inv,
                            'batch_id'  => $batch_id,
                        ] ),
                    ]
                );
            }
        }

        return [ 'success' => true, 'inventory' => $inv ];
    }

    /** Download a licensed package locally and upload only its bytes to PC. */
    private static function handle_export_update_artifact(
        array $cmd,
        string $hub_url,
        string $token,
        string $instance_id
    ): array {
        if ( self::is_staging() ) {
            return [ 'success' => false, 'error' => 'artifact_export_requires_production' ];
        }
        if ( ! class_exists( 'ZipArchive' ) && null === self::$artifact_downloader ) {
            return [ 'success' => false, 'error' => 'ziparchive_unavailable' ];
        }

        $plugin_file    = (string) ( $cmd['payload']['plugin_file'] ?? '' );
        $target_version = (string) ( $cmd['payload']['target_version'] ?? '' );
        $command_id     = (string) ( $cmd['command_id'] ?? '' );
        if ( ! preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._/-]+\.php$#', $plugin_file )
            || false !== strpos( $plugin_file, '..' )
            || '' === $target_version || '' === $command_id ) {
            return [ 'success' => false, 'error' => 'artifact_request_invalid' ];
        }

        $transient = get_site_transient( 'update_plugins' );
        $candidate = is_object( $transient ) && isset( $transient->response[ $plugin_file ] )
            ? $transient->response[ $plugin_file ]
            : null;
        $new_version = is_object( $candidate ) ? (string) ( $candidate->new_version ?? '' ) : '';
        $package_url = is_object( $candidate ) ? (string) ( $candidate->package ?? '' ) : '';
        if ( $new_version !== $target_version || '' === $package_url ) {
            return [ 'success' => false, 'error' => 'artifact_not_in_current_inventory' ];
        }
        $parts = wp_parse_url( $package_url );
        if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
            || empty( $parts['host'] ) || isset( $parts['user'] ) ) {
            return [ 'success' => false, 'error' => 'artifact_source_not_https' ];
        }

        if ( null !== self::$artifact_downloader ) {
            $tmp = call_user_func( self::$artifact_downloader, $package_url );
        } else {
            if ( ! function_exists( 'download_url' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $tmp = download_url( $package_url, 60 );
        }
        if ( is_wp_error( $tmp ) || ! is_string( $tmp ) || ! is_file( $tmp ) ) {
            return [ 'success' => false, 'error' => 'artifact_download_failed' ];
        }

        $size = (int) filesize( $tmp );
        if ( $size <= 0 || $size > self::MAX_ARTIFACT_BYTES ) {
            @unlink( $tmp );
            return [ 'success' => false, 'error' => 'artifact_size_invalid' ];
        }
        if ( null === self::$artifact_downloader ) {
            $zip = new ZipArchive();
            if ( true !== $zip->open( $tmp ) ) {
                @unlink( $tmp );
                return [ 'success' => false, 'error' => 'artifact_zip_invalid' ];
            }
            $zip->close();
        }

        $sha256 = hash_file( 'sha256', $tmp );
        $bytes  = file_get_contents( $tmp );
        @unlink( $tmp );
        if ( false === $sha256 || false === $bytes ) {
            return [ 'success' => false, 'error' => 'artifact_read_failed' ];
        }

        $url  = trailingslashit( $hub_url ) . 'wp-json/replanta-pc/v1/pipeline/artifact-ingest/' . rawurlencode( $command_id );
        $args = [
            'timeout'   => 90,
            'sslverify' => true,
            'headers'   => [
                'Content-Type'      => 'application/zip',
                'X-Pipeline-Token'  => $token,
                'X-Instance-ID'     => $instance_id,
                'X-Artifact-SHA256' => $sha256,
            ],
            'body' => $bytes,
        ];
        $response = null !== self::$artifact_uploader
            ? call_user_func( self::$artifact_uploader, $url, $args )
            : wp_remote_post( $url, $args );
        unset( $bytes );
        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => 'artifact_upload_failed' ];
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $code || empty( $data['artifact']['id'] )
            || ! hash_equals( $sha256, (string) ( $data['artifact']['sha256'] ?? '' ) ) ) {
            return [ 'success' => false, 'error' => 'artifact_upload_rejected' ];
        }
        return [
            'success'     => true,
            'artifact_id' => (string) $data['artifact']['id'],
            'sha256'      => $sha256,
            'size'        => (int) ( $data['artifact']['size'] ?? $size ),
            'plugin_file' => $plugin_file,
            'target_version' => $target_version,
        ];
    }

    private static function handle_prepare_production( array $cmd ): array {
        $batch_id   = $cmd['payload']['batch_id'] ?? '';
        $command_id = $cmd['command_id'] ?? '';
        if ( empty( $batch_id ) ) {
            return [ 'success' => false, 'error' => 'missing_batch_id' ];
        }
        if ( self::is_staging() ) {
            return [ 'success' => false, 'error' => 'not_applicable_on_staging' ];
        }
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'action_scheduler_not_available' );
            }
            return [ 'success' => false, 'error' => 'action_scheduler_not_available' ];
        }
        $action_id = (int) as_enqueue_async_action(
            'rpcare_create_production_backup',
            [ $batch_id, $command_id ],
            'pipeline',
            true // unique per hook+args to prevent double-enqueue on retry
        );
        if ( $action_id <= 0 ) {
            if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'as_enqueue_failed' );
            }
            return [ 'success' => false, 'error' => 'as_enqueue_failed' ];
        }
        $accepted = false;
        if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
            $accepted = RP_Care_Pipeline_Command_Journal::mark_accepted( $command_id, $action_id );
            if ( ! $accepted ) {
                // Lost the dispatching slot (race) — fail retryable so PC retries.
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'mark_accepted_failed' );
                return [ 'success' => false, 'error' => 'mark_accepted_failed' ];
            }
        }
        return [ 'success' => true, 'batch_id' => $batch_id, 'event' => 'prepare_production_scheduled' ];
    }

    private static function handle_prepare_staging( array $cmd ): array {
        $batch_id = $cmd['payload']['batch_id'] ?? '';
        if ( empty( $batch_id ) ) {
            return [ 'success' => false, 'error' => 'missing_batch_id' ];
        }
        if ( ! self::is_staging() ) {
            return [ 'success' => false, 'error' => 'prepare_staging sent to production instance.' ];
        }

        // Verify isolation: staging canonical URL must differ from production.
        $staging_url = self::get_canonical_url();
        if ( empty( $staging_url ) ) {
            return [
                'success'  => true,
                'batch_id' => $batch_id,
                'event'    => 'waiting_manual_staging_refresh',
                'reason'   => 'staging_canonical_url_not_configured',
            ];
        }

        // Optional: delegate to a staging provider if available.
        if ( class_exists( 'RP_Care_Staging_Provider' ) ) {
            $provider_result = RP_Care_Staging_Provider::prepare( $batch_id );
            if ( is_wp_error( $provider_result ) ) {
                return [
                    'success'  => true,
                    'batch_id' => $batch_id,
                    'event'    => 'operation_failed',
                    'phase'    => 'staging',
                    'reason'   => $provider_result->get_error_message(),
                ];
            }
            if (
                ! empty( $provider_result['manual_required'] )
                || 'waiting_manual_staging_refresh' === ( $provider_result['status'] ?? '' )
            ) {
                return [
                    'success'      => true,
                    'batch_id'     => $batch_id,
                    'event'        => 'waiting_manual_staging_refresh',
                    'reason'       => 'manual_staging_refresh_required',
                    'provider'     => $provider_result['provider'] ?? 'manual',
                    'instructions' => $provider_result['instructions'] ?? 'Refresh staging from production and confirm readiness.',
                ];
            }
        }

        return [
            'success'          => true,
            'batch_id'         => $batch_id,
            'event'            => 'staging_ready',
            'staging_metadata' => [
                'url'         => $staging_url,
                'prepared_at' => gmdate( 'c' ),
                'environment' => 'staging',
            ],
        ];
    }

    private static function handle_apply_update_batch( array $cmd ): array {
        $batch_id   = $cmd['payload']['batch_id'] ?? '';
        $command_id = $cmd['command_id'] ?? '';
        if ( ! self::is_staging() ) {
            return [ 'success' => false, 'error' => 'apply_update_batch sent to production. Use apply_production_batch.' ];
        }
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'action_scheduler_not_available' );
            }
            return [ 'success' => false, 'error' => 'Action Scheduler not available.' ];
        }
        $action_id = (int) as_enqueue_async_action(
            'rpcare_pipeline_apply_staging_batch',
            [ 'batch_id' => $batch_id, 'command_id' => $command_id ],
            'replanta-care',
            true // unique per hook+args
        );
        if ( $action_id <= 0 ) {
            if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'as_enqueue_failed' );
            }
            return [ 'success' => false, 'error' => 'as_enqueue_failed' ];
        }
        if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
            $accepted = RP_Care_Pipeline_Command_Journal::mark_accepted( $command_id, $action_id );
            if ( ! $accepted ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'mark_accepted_failed' );
                return [ 'success' => false, 'error' => 'mark_accepted_failed' ];
            }
        }
        return [ 'success' => true, 'queued' => true, 'batch_id' => $batch_id ];
    }

    private static function handle_run_test_suite( array $cmd ): array {
        $batch_id = $cmd['payload']['batch_id'] ?? '';
        if ( empty( $batch_id ) ) {
            return [ 'success' => false, 'error' => 'missing_batch_id' ];
        }
        if ( ! self::is_staging() ) {
            return [ 'success' => false, 'error' => 'run_test_suite sent to production instance.' ];
        }

        // Delegate to test runner if available; otherwise run built-in health checks.
        if ( class_exists( 'RP_Care_Test_Runner' ) ) {
            $runner_result = RP_Care_Test_Runner::run( $batch_id );
            if ( is_wp_error( $runner_result ) ) {
                return [ 'success' => false, 'error' => $runner_result->get_error_message() ];
            }
            $severity    = $runner_result['severity'] ?? 'none';
            $test_report = $runner_result['report']   ?? $runner_result;
        } else {
            // Built-in: HTTP health check of staging canonical URL.
            $staging_url = self::get_canonical_url();
            $severity    = 'none';
            $test_report = [ 'checks' => [], 'note' => 'built_in_health_check' ];
            if ( ! empty( $staging_url ) ) {
                $resp = wp_remote_get( $staging_url, [ 'timeout' => 15, 'sslverify' => true ] );
                $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
                if ( $code === 0 || $code >= 500 ) {
                    $severity = 'critical';
                }
                $test_report['checks'][] = [ 'url' => $staging_url, 'status_code' => $code ];
            }
        }

        return [
            'success'     => true,
            'batch_id'    => $batch_id,
            'event'       => 'tests_complete',
            'severity'    => $severity,
            'test_report' => $test_report,
        ];
    }

    private static function handle_apply_production_batch( array $cmd ): array {
        $batch_id      = $cmd['payload']['batch_id'] ?? '';
        $manifest_hash = $cmd['payload']['manifest_hash'] ?? '';
        $approval_id   = $cmd['payload']['approval_id'] ?? '';
        $backup_id     = $cmd['payload']['backup_id'] ?? '';
        $command_id    = $cmd['command_id'] ?? '';

        if ( self::is_staging() ) {
            return [ 'success' => false, 'error' => 'apply_production_batch sent to staging instance.' ];
        }

        if ( empty( $batch_id ) || empty( $manifest_hash ) || empty( $approval_id ) || empty( $backup_id ) ) {
            return [ 'success' => false, 'error' => 'Missing batch_id, manifest_hash, approval_id, or backup_id.' ];
        }

        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'action_scheduler_not_available' );
            }
            return [ 'success' => false, 'error' => 'Action Scheduler not available.' ];
        }

        $action_id = (int) as_enqueue_async_action(
            'rpcare_pipeline_apply_production_batch',
            [
                'batch_id'      => $batch_id,
                'manifest_hash' => $manifest_hash,
                'approval_id'   => $approval_id,
                'backup_id'     => $backup_id,
                'command_id'    => $command_id,
            ],
            'replanta-care',
            true // unique per hook+args
        );
        if ( $action_id <= 0 ) {
            if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'as_enqueue_failed' );
            }
            return [ 'success' => false, 'error' => 'as_enqueue_failed' ];
        }
        if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
            $accepted = RP_Care_Pipeline_Command_Journal::mark_accepted( $command_id, $action_id );
            if ( ! $accepted ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'mark_accepted_failed' );
                return [ 'success' => false, 'error' => 'mark_accepted_failed' ];
            }
        }
        return [ 'success' => true, 'queued' => true ];
    }

    /**
     * Send a human approval decision to Plugin Center.
     *
     * Returns true on success. Returns WP_Error on any failure.
     * - On timeout / 429 / 5xx: preserves the pending approval for retry (idempotent).
     * - On 4xx: preserves an error record visible to the operator.
     * - Only deletes the pending option after HTTP 2xx with a valid approval_id in the body.
     *
     * @param array $approval_data  Must include 'batch_id' and the full approval payload.
     * @return true|\WP_Error
     */
    public static function send_approval_to_pc( array $approval_data ): bool|\WP_Error {
        if ( ! self::is_pipeline_enabled() ) {
            return new \WP_Error( 'pipeline_disabled', 'Pipeline is not enabled.' );
        }

        $hub_url     = self::get_hub_url_public();
        $token       = self::get_token_public();
        $instance_id = (string) get_option( self::OPT_INSTANCE_ID, '' );

        if ( empty( $hub_url ) || empty( $token ) || empty( $instance_id ) ) {
            return new \WP_Error( 'not_configured', 'Pipeline client not fully configured.' );
        }

        $batch_id = (string) ( $approval_data['batch_id'] ?? '' );
        if ( empty( $batch_id ) ) {
            return new \WP_Error( 'missing_batch_id', 'approval_data must include batch_id.' );
        }

        $response = wp_remote_post(
            trailingslashit( $hub_url ) . 'wp-json/replanta-pc/v1/pipeline/batch-approval',
            [
                'timeout' => 15,
                'headers' => [
                    'Content-Type'     => 'application/json',
                    'X-Pipeline-Token' => $token,
                    'X-Instance-ID'    => $instance_id,
                ],
                'body' => wp_json_encode( $approval_data ),
            ]
        );

        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

        // On timeout, network error, 429, or 5xx — preserve pending approval for retry.
        if ( is_wp_error( $response ) || $code === 0 || $code === 429 || $code >= 500 ) {
            $err = is_wp_error( $response ) ? $response->get_error_message() : "HTTP $code";
            update_option( 'rpcare_pipeline_pending_approval_' . $batch_id, $approval_data );
            return new \WP_Error( 'retry_later', "Approval submission failed (retryable): $err" );
        }

        // On 4xx — preserve an error record visible to the operator; do NOT fake success.
        if ( $code >= 400 ) {
            $err_body = wp_remote_retrieve_body( $response );
            update_option( 'rpcare_pipeline_approval_error_' . $batch_id, [
                'error'    => "HTTP $code",
                'body'     => substr( $err_body, 0, 500 ),
                'time'     => time(),
                'approval' => $approval_data,
            ] );
            return new \WP_Error( 'rejected', "PC rejected approval (HTTP $code)." );
        }

        // 2xx — verify approval_id returned by PC.
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['approval_id'] ) ) {
            // Keep pending — we need the approval_id to continue.
            update_option( 'rpcare_pipeline_pending_approval_' . $batch_id, $approval_data );
            return new \WP_Error( 'missing_approval_id', 'PC returned 2xx but did not include approval_id.' );
        }

        // Only delete the pending option after confirmed 2xx with valid approval_id.
        delete_option( 'rpcare_pipeline_pending_approval_' . $batch_id );
        return true;
    }

    /**
     * POST a pipeline event to PC's /pipeline/batch-status endpoint.
     * Used by RP_Care_Pipeline_Outbox to deliver durable events with idempotency.
     *
     * @param string $batch_id  Batch the event belongs to.
     * @param string $event     Event name (e.g. 'staging_updated', 'tests_complete').
     * @param array  $payload   Full POST body (must include event_id).
     * @param string $event_id  UUID from the outbox row — included for PC-side dedup.
     * @return true|\WP_Error
     */
    public static function send_batch_event(
        string $batch_id,
        string $event,
        array  $payload,
        string $event_id = ''
    ): bool|\WP_Error {
        if ( ! self::is_pipeline_enabled() ) {
            return new \WP_Error( 'pipeline_disabled', 'Pipeline is not enabled.' );
        }

        $hub_url     = self::get_hub_url_public();
        $token       = self::get_token_public();
        $instance_id = (string) get_option( self::OPT_INSTANCE_ID, '' );

        if ( empty( $hub_url ) || empty( $token ) || empty( $instance_id ) ) {
            return new \WP_Error( 'not_configured', 'Pipeline client not fully configured.' );
        }

        $body = array_merge( $payload, [
            'batch_id' => $batch_id,
            'event'    => $event,
        ] );
        if ( ! empty( $event_id ) ) {
            $body['event_id'] = $event_id;
        }

        $response = wp_remote_post(
            trailingslashit( $hub_url ) . 'wp-json/replanta-pc/v1/pipeline/batch-status',
            [
                'timeout' => 15,
                'headers' => [
                    'Content-Type'     => 'application/json',
                    'X-Pipeline-Token' => $token,
                    'X-Instance-ID'    => $instance_id,
                ],
                'body' => wp_json_encode( $body ),
            ]
        );

        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

        // Transient failures: network error, timeout, 429, 5xx.
        if ( is_wp_error( $response ) || $code === 0 || $code === 429 || $code >= 500 ) {
            $err = is_wp_error( $response ) ? $response->get_error_message() : "HTTP $code";
            return new \WP_Error( 'retryable', "Batch event delivery failed (retryable): $err", [ 'http_code' => $code ] );
        }

        // 409 — distinguish payload conflict (security event) from idempotent duplicate.
        if ( $code === 409 ) {
            $body_data  = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_code = is_array( $body_data ) ? ( $body_data['code'] ?? '' ) : '';
            if ( $error_code === 'conflict' ) {
                // PC reports a different payload for the same event_id — possible replay attack.
                return new \WP_Error(
                    'payload_conflict',
                    "PC reports payload conflict for event '{$event}' (HTTP 409 conflict).",
                    [ 'http_code' => 409, 'error_code' => 'conflict' ]
                );
            }
            // Other 409 → duplicate_ok / already processed → treat as delivered.
            return true;
        }

        // Permanent 4xx failures (400, 401, 403, 404, 410, 422, …).
        if ( $code >= 400 ) {
            return new \WP_Error( 'permanent_failure', "PC rejected batch event '{$event}' permanently (HTTP $code).", [ 'http_code' => $code ] );
        }

        // 2xx → delivered.
        return true;
    }

    private static function handle_verify_production( array $cmd ): array {
        $batch_id = $cmd['payload']['batch_id'] ?? '';
        if ( empty( $batch_id ) ) {
            return [ 'success' => false, 'error' => 'missing_batch_id' ];
        }
        if ( self::is_staging() ) {
            return [ 'success' => false, 'error' => 'verify_production sent to staging instance.' ];
        }

        // Delegate to test runner if available; otherwise run built-in health checks.
        if ( class_exists( 'RP_Care_Test_Runner' ) ) {
            $runner_result = RP_Care_Test_Runner::run( $batch_id, [ 'non_destructive' => true ] );
            if ( is_wp_error( $runner_result ) ) {
                return [
                    'success'  => true,
                    'batch_id' => $batch_id,
                    'event'    => 'operation_failed',
                    'phase'    => 'production_verification',
                    'reason'   => $runner_result->get_error_message(),
                ];
            }
            $severity    = $runner_result['severity'] ?? 'none';
            $test_report = $runner_result['report']   ?? $runner_result;
        } else {
            // Built-in: HTTP health check of production site URL.
            $prod_url    = get_site_url();
            $severity    = 'none';
            $test_report = [ 'checks' => [], 'note' => 'built_in_health_check' ];
            if ( ! empty( $prod_url ) ) {
                $resp = wp_remote_get( $prod_url, [ 'timeout' => 15, 'sslverify' => true ] );
                $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
                if ( $code === 0 || $code >= 500 ) {
                    $severity = 'critical';
                }
                $test_report['checks'][] = [ 'url' => $prod_url, 'status_code' => $code ];
            }
        }

        if ( $severity === 'critical' ) {
            return [
                'success'     => true,
                'batch_id'    => $batch_id,
                'event'       => 'operation_failed',
                'phase'       => 'production_verification',
                'reason'      => 'critical_test_failure',
                'test_report' => $test_report,
            ];
        }

        return [
            'success'     => true,
            'batch_id'    => $batch_id,
            'event'       => 'production_verified',
            'test_report' => $test_report,
        ];
    }

    private static function handle_rollback_batch( array $cmd ): array {
        $batch_id   = $cmd['payload']['batch_id'] ?? '';
        $command_id = $cmd['command_id'] ?? '';
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'action_scheduler_not_available' );
            }
            return [ 'success' => false, 'error' => 'Action Scheduler not available.' ];
        }
        $action_id = (int) as_enqueue_async_action(
            'rpcare_pipeline_rollback_batch',
            [ 'batch_id' => $batch_id, 'command_id' => $command_id ],
            'replanta-care',
            true // unique per hook+args
        );
        if ( $action_id <= 0 ) {
            if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'as_enqueue_failed' );
            }
            return [ 'success' => false, 'error' => 'as_enqueue_failed' ];
        }
        if ( class_exists( 'RP_Care_Pipeline_Command_Journal' ) ) {
            $accepted = RP_Care_Pipeline_Command_Journal::mark_accepted( $command_id, $action_id );
            if ( ! $accepted ) {
                RP_Care_Pipeline_Command_Journal::mark_failed( $command_id, false, 'mark_accepted_failed' );
                return [ 'success' => false, 'error' => 'mark_accepted_failed' ];
            }
        }
        return [ 'success' => true, 'queued' => true ];
    }

    private static function handle_cancel_batch( array $cmd ): array {
        $batch_id = $cmd['payload']['batch_id'] ?? '';
        do_action( 'rpcare_pipeline_batch_cancelled', $batch_id );
        return [ 'success' => true, 'batch_id' => $batch_id ];
    }

    private static function handle_approval_required( array $cmd ): array {
        $batch_id                  = $cmd['payload']['batch_id'] ?? '';
        $manifest_hash             = $cmd['payload']['manifest_hash'] ?? '';
        $production_inventory_hash = $cmd['payload']['production_inventory_hash'] ?? '';
        $test_severity             = $cmd['payload']['test_severity'] ?? '';

        if ( empty( $batch_id ) || empty( $manifest_hash ) || empty( $production_inventory_hash ) ) {
            return [ 'success' => false, 'error' => 'approval_required payload missing required fields' ];
        }

        $approval_data = [
            'batch_id'                  => $batch_id,
            'manifest_hash'             => $manifest_hash,
            'production_inventory_hash' => $production_inventory_hash,
            'test_severity'             => $test_severity,
            'expires_at'                => gmdate( 'c', time() + 48 * 3600 ),
        ];

        // Always persist in a WP option — authoritative store for retries if the ACK to PC fails.
        update_option( 'rpcare_pipeline_pending_approval_' . $batch_id, $approval_data );

        // Also notify the approval screen UI if the module is available.
        if ( class_exists( 'RP_Care_Approval_Screen' ) ) {
            RP_Care_Approval_Screen::store_pending_approval( $approval_data );
        }

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
                    'status'      => 'completed',
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
                    'command_id'    => $command_id,
                    'instance_id'   => $instance_id,
                    'status'        => 'failed',
                    'error_message' => substr( $reason, 0, 500 ),
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
            static function ( string $batch_id, string $command_id = '' ): void {
                if ( ! class_exists( 'RP_Care_Task_Updates' ) ) {
                    return;
                }
                RP_Care_Task_Updates::apply_staging_batch( $batch_id, $command_id );
            },
            10,
            2
        );

        add_action(
            'rpcare_pipeline_apply_production_batch',
            static function (
                string $batch_id,
                string $manifest_hash = '',
                string $approval_id   = '',
                string $backup_id     = '',
                string $command_id    = ''
            ): void {
                if ( ! class_exists( 'RP_Care_Task_Updates' ) ) {
                    return;
                }
                RP_Care_Task_Updates::apply_production_batch( $batch_id, $manifest_hash, $approval_id, $backup_id, $command_id );
            },
            10,
            5
        );

        add_action(
            'rpcare_pipeline_rollback_batch',
            static function ( string $batch_id, string $command_id = '' ): void {
                if ( ! class_exists( 'RP_Care_Task_Updates' ) ) {
                    return;
                }
                RP_Care_Task_Updates::rollback_batch( $batch_id, $command_id );
            },
            10,
            2
        );
    }

    // ── Credential helpers ───────────────────────────────────────────────────

    private static function get_raw_token(): string {
        $enc = (string) get_option( self::OPT_TOKEN, '' );
        return self::decrypt_local( $enc );
    }

    private static function get_raw_secret(): string {
        $enc = (string) get_option( self::OPT_SECRET, '' );
        if ( empty( $enc ) ) {
            return '';
        }
        // Decrypt using Care's local key (care1: prefix). Does NOT accept enc1: or plain base64.
        return self::decrypt_local( $enc );
    }

    /**
     * Encrypt a plaintext string with Care's local key.
     * Stored value is prefixed with 'care1:' to distinguish from PC's 'enc1:' scheme.
     *
     * @param string $plaintext
     * @return string Encrypted value, or '' if openssl unavailable or plaintext is empty.
     */
    private static function encrypt_local( string $plaintext ): string {
        if ( ! extension_loaded( 'openssl' ) || $plaintext === '' ) {
            return '';
        }
        $key = hash( 'sha256', wp_salt( 'auth' ) . 'care-pipeline-local-v1', true );
        $iv  = openssl_random_pseudo_bytes( 16 );
        $enc = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        if ( $enc === false ) {
            return '';
        }
        return 'care1:' . base64_encode( $iv . $enc );
    }

    /**
     * Decrypt a 'care1:' prefixed value encrypted by encrypt_local().
     * Fail-closed: returns '' for any value not starting with 'care1:'.
     *
     * @param string $encrypted
     * @return string Decrypted plaintext, or '' on any failure.
     */
    private static function decrypt_local( string $encrypted ): string {
        if ( ! str_starts_with( $encrypted, 'care1:' ) ) {
            return ''; // fail closed; does not accept enc1: or plain base64
        }
        if ( ! extension_loaded( 'openssl' ) ) {
            return '';
        }
        $key  = hash( 'sha256', wp_salt( 'auth' ) . 'care-pipeline-local-v1', true );
        $data = base64_decode( substr( $encrypted, 6 ) );
        if ( strlen( $data ) < 17 ) {
            return '';
        }
        $iv    = substr( $data, 0, 16 );
        $plain = openssl_decrypt( substr( $data, 16 ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return $plain !== false ? $plain : '';
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

            // Validate that the response includes credentials.
            $api_token  = $data['token'] ?? '';
            $api_secret = $data['secret'] ?? '';

            if ( empty( $api_token ) || empty( $api_secret ) ) {
                update_option( self::OPT_PAIRING_STATUS, self::STATUS_UNPAIRED );
                return new \WP_Error( 'missing_credentials', 'Pairing response missing token or secret.' );
            }

            // Persist credentials encrypted with Care's local key BEFORE marking pairing ready.
            $token_saved  = update_option( self::OPT_TOKEN, self::encrypt_local( $api_token ) );
            $secret_saved = update_option( self::OPT_SECRET, self::encrypt_local( $api_secret ) );

            if ( ! $token_saved || ! $secret_saved ) {
                update_option( self::OPT_PAIRING_STATUS, self::STATUS_UNPAIRED );
                return new \WP_Error( 'credential_save_failed', 'Failed to persist pipeline credentials.' );
            }

            update_option( self::OPT_PAIRING_STATUS, self::STATUS_READY );
            update_option( self::OPT_GROUP_ID, $data['group_id'] ?? '' );
            update_option( self::OPT_ENVIRONMENT, $data['environment'] ?? 'production' );
            update_option( 'rpcare_pipeline_canonical_url', $canonical_url );
			update_option( self::OPT_ENABLED, true );
			self::ensure_poll_schedule( true );

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
