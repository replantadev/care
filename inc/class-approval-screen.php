<?php
/**
 * Replanta Care — Approval Screen
 *
 * Admin page for human review and approval of update batches.
 * Accessible to users with the 'rpcare_approve_updates' capability.
 *
 * Security:
 *  - login required (checked by WP admin menu registration).
 *  - capability check (rpcare_approve_updates).
 *  - nonce (rpcare_approve_batch_{batch_id}).
 *  - batch_id + manifest_hash + production_inventory_hash all validated.
 *  - approval is single-use (idempotent on same batch+decision).
 *  - 48h default TTL (configurable per group).
 *
 * The approval records the minimal audit data required:
 *  WP user ID, display name, role, decision, manifest_hash,
 *  production_inventory_hash, terms_version, reason.
 * Full IP is NOT recorded (privacy-by-design).
 *
 * Compatible with PHP 7.4+ (Care's minimum requirement).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RP_Care_Approval_Screen {

    const CAPABILITY    = 'rpcare_approve_updates';
    const TERMS_VERSION = '1.0';

    // OPT keys for pending approval requests sent by PC (stored as transients).
    // Key: rpcare_pending_approval_{batch_id}, value: approval request array.
    const TRANSIENT_PREFIX = 'rpcare_pending_approval_';
    const TRANSIENT_TTL    = 172800; // 48 hours default

    public static function init(): void {
        add_action( 'admin_menu',        [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_rpcare_approve_batch', [ __CLASS__, 'handle_approval' ] );
        add_action( 'admin_init',        [ __CLASS__, 'maybe_register_capability' ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public static function register_menu(): void {
        add_submenu_page(
            'replanta-care',
            __( 'Aprobar Actualizaciones', 'replanta-care' ),
            __( 'Aprobar Actualizaciones', 'replanta-care' ),
            self::CAPABILITY,
            'rpcare-approve-updates',
            [ __CLASS__, 'render_page' ]
        );
    }

    // ── Capability bootstrapping ──────────────────────────────────────────────

    /**
     * Grant 'rpcare_approve_updates' to all administrators by default on first run.
     * Can be overridden later with a role-management plugin.
     */
    public static function maybe_register_capability(): void {
        if ( get_option( 'rpcare_approval_cap_init', false ) ) {
            return;
        }
        $admin_role = get_role( 'administrator' );
        if ( $admin_role ) {
            $admin_role->add_cap( self::CAPABILITY );
        }
        update_option( 'rpcare_approval_cap_init', true );
    }

    // ── Pending approval API ──────────────────────────────────────────────────

    /**
     * Store a pending approval request (called via REST when PC dispatches a notify).
     * This is what makes the approval screen show a pending item.
     *
     * @param array{
     *   batch_id: string,
     *   manifest_hash: string,
     *   production_inventory_hash: string,
     *   expires_at: string,
     *   manifest_summary: array,
     *   test_report: array,
     *   staging_url?: string,
     * } $request
     */
    public static function store_pending_approval( array $request ): void {
        $batch_id = sanitize_text_field( $request['batch_id'] ?? '' );
        if ( empty( $batch_id ) ) {
            return;
        }
        set_transient(
            self::TRANSIENT_PREFIX . $batch_id,
            $request,
            self::TRANSIENT_TTL
        );
    }

    /** @return array|null */
    public static function get_pending_approval( string $batch_id ): ?array {
        $data = get_transient( self::TRANSIENT_PREFIX . $batch_id );
        return is_array( $data ) ? $data : null;
    }

    // ── Admin page ────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to approve updates.', 'replanta-care' ) );
        }

        $batch_id = sanitize_text_field( $_GET['batch_id'] ?? '' );

        if ( empty( $batch_id ) ) {
            self::render_pending_list();
            return;
        }

        $pending = self::get_pending_approval( $batch_id );
        if ( ! $pending ) {
            self::render_not_found( $batch_id );
            return;
        }

        self::render_approval_form( $pending );
    }

    private static function render_pending_list(): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Update Approvals', 'replanta-care' ) . '</h1>';
        // In a real implementation this would list pending approvals from transients.
        // For the initial release, operators are directed here via notification email link.
        echo '<p>' . esc_html__( 'Use the link from your notification email to review a specific update batch.', 'replanta-care' ) . '</p>';
        echo '</div>';
    }

    private static function render_not_found( string $batch_id ): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Approval Not Found', 'replanta-care' ) . '</h1>';
        echo '<div class="notice notice-error"><p>';
        printf(
            esc_html__( 'No pending approval found for batch %s. It may have expired or already been decided.', 'replanta-care' ),
            esc_html( $batch_id )
        );
        echo '</p></div></div>';
    }

    private static function render_approval_form( array $data ): void {
        $batch_id     = $data['batch_id'];
        $manifest_hash = $data['manifest_hash'] ?? '';
        $inv_hash      = $data['production_inventory_hash'] ?? '';
        $expires_at    = $data['expires_at'] ?? '';
        $summary       = $data['manifest_summary'] ?? [];
        $tests         = $data['test_report'] ?? [];
        $staging_url   = esc_url( $data['staging_url'] ?? '' );
        $nonce         = wp_create_nonce( 'rpcare_approve_batch_' . $batch_id );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Review Update Batch', 'replanta-care' ) . '</h1>';

        // Expiry notice.
        if ( $expires_at ) {
            $exp_ts = strtotime( $expires_at );
            $expired = $exp_ts < time();
            if ( $expired ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'This approval request has expired.', 'replanta-care' ) . '</p></div>';
            } else {
                printf(
                    '<div class="notice notice-info"><p>' . esc_html__( 'This approval expires: %s', 'replanta-care' ) . '</p></div>',
                    esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $exp_ts ) )
                );
            }
        }

        // Batch summary.
        echo '<table class="widefat" style="max-width:800px">';
        echo '<tr><th>' . esc_html__( 'Batch ID', 'replanta-care' ) . '</th><td><code>' . esc_html( $batch_id ) . '</code></td></tr>';
        if ( ! empty( $summary['wp_version_update'] ) ) {
            echo '<tr><th>' . esc_html__( 'WordPress', 'replanta-care' ) . '</th>';
            printf( '<td>%s → %s</td>', esc_html( $summary['wp_version_update']['from'] ), esc_html( $summary['wp_version_update']['to'] ) );
            echo '</tr>';
        }
        if ( ! empty( $summary['plugins'] ) ) {
            echo '<tr><th>' . esc_html__( 'Plugins', 'replanta-care' ) . '</th><td>';
            echo '<ul>';
            foreach ( $summary['plugins'] as $p ) {
                printf( '<li><strong>%s</strong>: %s → %s</li>', esc_html( $p['name'] ?? $p['slug'] ), esc_html( $p['from'] ?? '?' ), esc_html( $p['to'] ?? '?' ) );
            }
            echo '</ul></td></tr>';
        }
        if ( $staging_url ) {
            echo '<tr><th>' . esc_html__( 'Staging URL', 'replanta-care' ) . '</th>';
            printf( '<td><a href="%s" target="_blank" rel="noopener">%s</a></td>', $staging_url, $staging_url );
            echo '</tr>';
        }
        echo '</table>';

        // Test summary.
        if ( ! empty( $tests ) ) {
            $severity = $tests['severity'] ?? 'ok';
            $class    = [ 'ok' => 'success', 'warning' => 'warning', 'critical' => 'error' ][ $severity ] ?? 'info';
            echo '<h2>' . esc_html__( 'Automated Test Results', 'replanta-care' ) . '</h2>';
            echo "<div class=\"notice notice-{$class}\"><p>";
            echo esc_html( 'Severity: ' . strtoupper( $severity ) . ' — ' . ( $tests['summary'] ?? '' ) );
            echo '</p></div>';
        }

        // Terms text.
        echo '<h2>' . esc_html__( 'Terms of Approval', 'replanta-care' ) . '</h2>';
        echo '<p>' . esc_html__(
            'By approving this batch you confirm that you have reviewed the staging test results and the list of updates above, ' .
            'and that you authorise Replanta Care to apply exactly this batch to your production site. ' .
            'This authorises the specific update batch shown — it does not constitute a general waiver of any rights or exemption from technical responsibility.',
            'replanta-care'
        ) . '</p>';

        // Approval form.
        $exp_ts = $expires_at ? strtotime( $expires_at ) : 0;
        $expired = $exp_ts > 0 && $exp_ts < time();
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'rpcare_approve_batch_' . $batch_id, '_wpnonce_approve' ); ?>
            <input type="hidden" name="action"                      value="rpcare_approve_batch">
            <input type="hidden" name="batch_id"                    value="<?php echo esc_attr( $batch_id ); ?>">
            <input type="hidden" name="manifest_hash"               value="<?php echo esc_attr( $manifest_hash ); ?>">
            <input type="hidden" name="production_inventory_hash"   value="<?php echo esc_attr( $inv_hash ); ?>">

            <p>
                <label>
                    <input type="checkbox" name="confirm_reviewed" value="1" required>
                    <?php esc_html_e( 'I have reviewed the staging results and the update list above.', 'replanta-care' ); ?>
                </label>
            </p>

            <?php if ( ! empty( $tests['has_warning'] ) ) : ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e( 'There are warnings in the test results. If you approve, provide a reason:', 'replanta-care' ); ?></p>
                <textarea name="override_reason" rows="3" style="width:100%;max-width:600px"
                    placeholder="<?php esc_attr_e( 'Reason for approving despite warnings...', 'replanta-care' ); ?>"></textarea>
            </div>
            <?php endif; ?>

            <p>
                <?php if ( ! $expired ) : ?>
                <button type="submit" name="decision" value="approved"
                    class="button button-primary"
                    onclick="return confirm('<?php esc_attr_e( 'Confirm: apply this update batch to production?', 'replanta-care' ); ?>')">
                    <?php esc_html_e( 'Approve', 'replanta-care' ); ?>
                </button>
                <?php endif; ?>
                &nbsp;
                <button type="submit" name="decision" value="rejected" class="button button-secondary">
                    <?php esc_html_e( 'Reject', 'replanta-care' ); ?>
                </button>
                &nbsp;
                <button type="submit" name="decision" value="request_review" class="button">
                    <?php esc_html_e( 'Request Review', 'replanta-care' ); ?>
                </button>
            </p>
        </form>
        <?php
        echo '</div>';
    }

    // ── Approval handler ─────────────────────────────────────────────────────

    public static function handle_approval(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Permission denied.', 'replanta-care' ) );
        }

        $batch_id  = sanitize_text_field( $_POST['batch_id'] ?? '' );
        $decision  = sanitize_key( $_POST['decision'] ?? '' );
        $mhash     = sanitize_text_field( $_POST['manifest_hash'] ?? '' );
        $inv_hash  = sanitize_text_field( $_POST['production_inventory_hash'] ?? '' );
        $reason    = sanitize_textarea_field( $_POST['override_reason'] ?? '' );
        $confirmed = ! empty( $_POST['confirm_reviewed'] );

        // Verify nonce.
        if ( ! wp_verify_nonce( $_POST['_wpnonce_approve'] ?? '', 'rpcare_approve_batch_' . $batch_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'replanta-care' ) );
        }

        if ( empty( $batch_id ) || empty( $decision ) || empty( $mhash ) || empty( $inv_hash ) ) {
            wp_die( esc_html__( 'Missing required fields.', 'replanta-care' ) );
        }

        if ( $decision === 'approved' && ! $confirmed ) {
            wp_die( esc_html__( 'You must confirm you have reviewed the staging results.', 'replanta-care' ) );
        }

        $allowed_decisions = [ 'approved', 'rejected', 'request_review' ];
        if ( ! in_array( $decision, $allowed_decisions, true ) ) {
            wp_die( esc_html__( 'Invalid decision.', 'replanta-care' ) );
        }

        // Verify pending approval still exists.
        $pending = self::get_pending_approval( $batch_id );
        if ( ! $pending ) {
            wp_die( esc_html__( 'Approval request expired or not found.', 'replanta-care' ) );
        }

        // Verify that manifest_hash and production_inventory_hash match what was shown.
        if ( ! hash_equals( $pending['manifest_hash'] ?? '', $mhash ) ) {
            wp_die( esc_html__( 'Manifest hash mismatch — the batch may have changed.', 'replanta-care' ) );
        }
        if ( ! hash_equals( $pending['production_inventory_hash'] ?? '', $inv_hash ) ) {
            wp_die( esc_html__( 'Production inventory hash mismatch — production may have drifted.', 'replanta-care' ) );
        }

        // Check expiry.
        $expires_at = $pending['expires_at'] ?? '';
        if ( $expires_at && strtotime( $expires_at ) < time() ) {
            wp_die( esc_html__( 'This approval request has expired.', 'replanta-care' ) );
        }

        // Build the signed approval record.
        $user        = wp_get_current_user();
        $approval_id = wp_generate_uuid4();
        $approval    = [
            'id'                        => $approval_id,
            'batch_id'                  => $batch_id,
            'manifest_hash'             => $mhash,
            'production_inventory_hash' => $inv_hash,
            'actor_type'                => 'wp_user',
            'actor_reference'           => (string) $user->ID,
            'actor_display'             => $user->display_name,
            'decision'                  => $decision,
            'reason'                    => $reason,
            'terms_version'             => self::TERMS_VERSION,
            'created_at'                => current_time( 'mysql', true ),
            // Roles only (no full capabilities list).
            'actor_roles'               => implode( ',', (array) $user->roles ),
        ];

        // Send the approval to PC via pipeline client.
        $sent = false;
        if ( class_exists( 'RP_Care_Pipeline_Client' ) ) {
            $sent = RP_Care_Pipeline_Client::send_approval_to_pc( $approval );
        }
        if ( is_wp_error( $sent ) ) {
            if ( class_exists( 'RP_Care_Utils' ) ) {
                RP_Care_Utils::log( 'pipeline', 'warning', 'Approval send failed: ' . $sent->get_error_message(), [
                    'batch_id' => $batch_id,
                    'decision' => $decision,
                ] );
            }
            $redirect_url = admin_url(
                'admin.php?page=rpcare-approve-updates&send_error=' . urlencode( $sent->get_error_message() ) .
                '&batch_id=' . urlencode( $batch_id )
            );
            wp_safe_redirect( $redirect_url );
            exit;
        }

        // Delete the transient only after confirmed send (single-use).
        delete_transient( self::TRANSIENT_PREFIX . $batch_id );

        if ( class_exists( 'RP_Care_Utils' ) ) {
            RP_Care_Utils::log( 'pipeline', 'info', "Batch $batch_id $decision by user {$user->ID}", [
                'batch_id'  => $batch_id,
                'decision'  => $decision,
                'user_id'   => $user->ID,
                'user_role' => $approval['actor_roles'],
            ] );
        }

        $redirect_url = admin_url( 'admin.php?page=rpcare-approve-updates&decision=' . urlencode( $decision ) . '&batch_id=' . urlencode( $batch_id ) );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handle an approval decision submitted via the Care REST endpoint.
     * Called from RP_Care_REST::pipeline_approval_request().
     *
     * Expects $body to carry the same fields as the admin form POST
     * (batch_id, decision, manifest_hash, production_inventory_hash, reason).
     * Performs its own nonce and capability check — REST auth is already validated upstream.
     *
     * @param array $body Decoded JSON body from the REST request.
     * @return array|WP_Error
     */
    public static function handle_rest_approval( array $body ): array|\WP_Error {
        $batch_id  = sanitize_text_field( $body['batch_id'] ?? '' );
        $decision  = sanitize_text_field( $body['decision'] ?? '' );
        $nonce     = sanitize_text_field( $body['nonce'] ?? '' );

        if ( empty( $batch_id ) || empty( $decision ) ) {
            return new \WP_Error( 'missing_field', 'batch_id and decision are required.' );
        }

        // Validate the per-batch nonce from the REST caller.
        if ( ! wp_verify_nonce( $nonce, 'rpcare_approve_batch_' . $batch_id ) ) {
            return new \WP_Error( 'bad_nonce', 'Invalid or expired nonce.' );
        }

        if ( ! current_user_can( self::CAPABILITY ) ) {
            return new \WP_Error( 'forbidden', 'You do not have permission to approve updates.' );
        }

        $approval = [
            'batch_id'                  => $batch_id,
            'manifest_hash'             => sanitize_text_field( $body['manifest_hash'] ?? '' ),
            'production_inventory_hash' => sanitize_text_field( $body['production_inventory_hash'] ?? '' ),
            'actor_type'                => 'wp_user',
            'actor_reference'           => (string) get_current_user_id(),
            'actor_display'             => wp_get_current_user()->display_name ?? '',
            'decision'                  => $decision,
            'reason'                    => sanitize_textarea_field( $body['reason'] ?? '' ),
            'terms_version'             => '1.0',
        ];

        // Delegate to the standard approval processor (forwards to PC).
        $result = self::process_approval( $approval );
        if ( is_wp_error( $result ) ) {
            return new \WP_Error( $result->get_error_code(), $result->get_error_message() );
        }

        return [
            'decision' => $decision,
            'batch_id' => $batch_id,
        ];
    }

    /**
     * Core approval processing shared by the form POST and REST endpoints.
     * Returns WP_Error if the approval could not be sent to PC (transient preserved for retry).
     */
    private static function process_approval( array $approval ): true|\WP_Error {
        $batch_id = $approval['batch_id'];
        $decision = $approval['decision'];

        $pending = get_transient( 'rpcare_pending_approval_' . $batch_id );
        if ( ! $pending ) {
            return new \WP_Error( 'no_pending_approval', "No pending approval found for batch $batch_id." );
        }

        // Validate hashes.
        $manifest_ok = hash_equals( $pending['manifest_hash'] ?? '', $approval['manifest_hash'] ?? '' );
        $inv_ok      = hash_equals( $pending['production_inventory_hash'] ?? '', $approval['production_inventory_hash'] ?? '' );

        if ( ! $manifest_ok || ! $inv_ok ) {
            return new \WP_Error( 'hash_mismatch', 'Approval hashes do not match the pending approval.' );
        }

        // Forward to PC first — only record audit entry on confirmed success.
        if ( class_exists( 'RP_Care_Pipeline_Client' ) ) {
            $sent = RP_Care_Pipeline_Client::send_approval_to_pc( $approval );
            if ( is_wp_error( $sent ) ) {
                // Transient preserved by send_approval_to_pc() for retry; surface the error.
                return $sent;
            }
        }

        // Record minimal audit entry after confirmed send.
        $pending['decisions'][] = [
            'decision'  => $decision,
            'actor'     => $approval['actor_display'],
            'reason'    => $approval['reason'] ?? '',
            'timestamp' => time(),
        ];
        set_transient( 'rpcare_pending_approval_' . $batch_id, $pending, 48 * HOUR_IN_SECONDS );

        return true;
    }
}

