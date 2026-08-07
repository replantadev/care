<?php
/**
 * RP_Care_Pipeline_Command_Journal
 *
 * Durable state machine for Care command dispatch.
 * Prevents duplicate Action Scheduler enqueues for the same command_id when
 * Plugin Center retries delivery of a command Care has already accepted.
 *
 * States: received → dispatching → accepted → running → completed | failed_retryable | failed_permanent
 *
 * Atomicity: claim_for_dispatch() uses a CAS UPDATE:
 *   UPDATE SET state='dispatching' WHERE command_id=X AND state IN ('received','failed_retryable')
 * Only the worker whose UPDATE returns rows_affected=1 may proceed to enqueue an AS action.
 * Expired dispatching rows (lease_expires_at < NOW) can be claimed by a new worker.
 *
 * Identity binding: the first claim records identity_hash (SHA-256 of
 *   instance_id|environment|command_type|batch_id|payload_hash). A second claim
 *   with a different identity_hash is treated as a permanent failure with alert.
 *
 * Backed by the rpcare_pipeline_command_journal table created in replanta-care.php.
 * When the table does not exist (unit tests) every query returns a no-op default.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RP_Care_Pipeline_Command_Journal {

    const TABLE = 'rpcare_pipeline_command_journal';

    const STATE_RECEIVED         = 'received';
    const STATE_DISPATCHING      = 'dispatching';
    const STATE_ACCEPTED         = 'accepted';
    const STATE_RUNNING          = 'running';
    const STATE_COMPLETED        = 'completed';
    const STATE_FAILED_RETRYABLE = 'failed_retryable';
    const STATE_FAILED_PERMANENT = 'failed_permanent';

    const TERMINAL_STATES = [ 'completed', 'failed_permanent' ];

    const DISPATCH_LEASE_SECONDS = 30; // dispatching lease: enough to enqueue AS and mark_accepted

    // ── Write operations ───────────────────────────────────────────────────────

    /**
     * Record a command as received. INSERT IGNORE — safe to call on replays.
     *
     * @return bool true if this is the first insert, false if the row already existed.
     */
    public static function insert_received(
        string $command_id,
        string $instance_id,
        string $environment,
        string $command_type,
        string $batch_id
    ): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $rows = (int) $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$table}`
                 (command_id, instance_id, environment, command_type, batch_id, state, received_at)
                 VALUES (%s, %s, %s, %s, %s, 'received', %s)",
                $command_id,
                $instance_id,
                $environment,
                $command_type,
                $batch_id,
                gmdate( 'Y-m-d H:i:s' )
            )
        );

        return $rows === 1;
    }

    /**
     * Atomic CAS claim: transition received/failed_retryable → dispatching.
     *
     * Only the worker whose UPDATE returns rows_affected=1 may enqueue an AS action.
     * Expired dispatching rows (lease_expires_at < NOW) can be re-claimed by a new worker.
     *
     * @param string $command_id
     * @param string $identity_hash  SHA-256 of instance_id|environment|command_type|batch_id|payload_hash.
     * @param string $worker_id      Opaque worker identifier (e.g. md5(getmypid()+microtime)).
     * @param string $lease_expires_at  Datetime when this dispatching claim expires.
     *
     * @return string
     *   'claimed'            — this worker owns the dispatching slot; proceed to enqueue.
     *   'not_found'          — command_id not in journal.
     *   'already_terminal'   — state is completed or failed_permanent; skip.
     *   'accepted_or_running'— AS action already in flight; ack without re-dispatch.
     *   'lease_valid'        — another worker holds an active dispatching lease; retry.
     *   'identity_conflict'  — same command_id, different identity → permanent failure + alert.
     */
    public static function claim_for_dispatch(
        string $command_id,
        string $identity_hash,
        string $worker_id,
        string $lease_expires_at
    ): string {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = gmdate( 'Y-m-d H:i:s' );

        // ── Attempt CAS for received or failed_retryable → dispatching ──────────
        $rows = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                 SET state='dispatching', identity_hash=%s, worker_id=%s,
                     lease_expires_at=%s, dispatching_at=%s
                 WHERE command_id=%s
                   AND state IN ('received','failed_retryable')",
                $identity_hash,
                $worker_id,
                $lease_expires_at,
                $now,
                $command_id
            )
        );

        if ( $rows > 0 ) {
            return 'claimed';
        }

        // ── CAS failed — inspect current state ────────────────────────────────
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT state, identity_hash, lease_expires_at FROM `{$table}` WHERE command_id=%s LIMIT 1",
                $command_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return 'not_found';
        }

        $state = (string) ( $row['state'] ?? '' );

        if ( in_array( $state, self::TERMINAL_STATES, true ) ) {
            return 'already_terminal';
        }

        if ( in_array( $state, [ self::STATE_ACCEPTED, self::STATE_RUNNING ], true ) ) {
            return 'accepted_or_running';
        }

        // state = 'dispatching' — check lease and identity
        if ( $state === self::STATE_DISPATCHING ) {
            $stored_identity = (string) ( $row['identity_hash'] ?? '' );
            if ( $stored_identity !== '' && $stored_identity !== $identity_hash ) {
                // Same command_id, different identity → permanent failure + alert
                self::_mark_identity_conflict( $command_id, $identity_hash, $stored_identity );
                return 'identity_conflict';
            }

            $lease_expires = (string) ( $row['lease_expires_at'] ?? '' );
            if ( ! empty( $lease_expires ) && strtotime( $lease_expires ) > time() ) {
                return 'lease_valid'; // Another worker holds the lease; retry later.
            }

            // Expired lease — attempt atomic takeover
            $taken = (int) $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}`
                     SET state='dispatching', identity_hash=%s, worker_id=%s,
                         lease_expires_at=%s, dispatching_at=%s
                     WHERE command_id=%s
                       AND state='dispatching'
                       AND lease_expires_at=%s",
                    $identity_hash,
                    $worker_id,
                    $lease_expires_at,
                    $now,
                    $command_id,
                    $lease_expires
                )
            );

            return $taken > 0 ? 'claimed' : 'lease_valid';
        }

        // Any other state — treat as retry-later
        return 'lease_valid';
    }

    /**
     * Transition to 'accepted' and record the AS action_id.
     * Only succeeds when the current state is 'dispatching'.
     *
     * @return bool true if the row was updated.
     */
    public static function mark_accepted( string $command_id, int $action_id = 0 ): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $rows = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET state='accepted', action_id=%d, accepted_at=%s,
                 worker_id=NULL, lease_expires_at=NULL
                 WHERE command_id=%s AND state='dispatching'",
                $action_id,
                gmdate( 'Y-m-d H:i:s' ),
                $command_id
            )
        );

        return $rows > 0;
    }

    /**
     * Transition to 'running'. Accepts rows in 'dispatching', 'received', or 'accepted' state.
     */
    public static function mark_running( string $command_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET state='running', running_at=%s
                 WHERE command_id=%s AND state IN ('received','dispatching','accepted')",
                gmdate( 'Y-m-d H:i:s' ),
                $command_id
            )
        );
    }

    /**
     * Transition to 'completed'.
     *
     * @return bool true if the row was updated (was not already in a terminal state).
     */
    public static function mark_completed( string $command_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $rows = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET state='completed', completed_at=%s
                 WHERE command_id=%s AND state NOT IN ('completed','failed_permanent')",
                gmdate( 'Y-m-d H:i:s' ),
                $command_id
            )
        );

        return $rows > 0;
    }

    /**
     * Transition to a failed state.
     *
     * @param bool   $permanent  true = failed_permanent (no retry); false = failed_retryable.
     * @param string $error      Short sanitized error message (truncated to 500 chars).
     */
    public static function mark_failed( string $command_id, bool $permanent, string $error = '' ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $state = $permanent ? 'failed_permanent' : 'failed_retryable';

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET state=%s, error_message=%s, completed_at=%s,
                 worker_id=NULL, lease_expires_at=NULL
                 WHERE command_id=%s AND state NOT IN ('completed','failed_permanent')",
                $state,
                substr( $error, 0, 500 ),
                gmdate( 'Y-m-d H:i:s' ),
                $command_id
            )
        );
    }

    /**
     * Reconcile orphaned journal entries:
     *   - accepted rows with no AS action (action_id = 0 or NULL) → failed_retryable
     *   - dispatching rows with expired leases → failed_retryable
     *
     * Should be called periodically (e.g. from a scheduler).
     *
     * @return int Number of rows reconciled.
     */
    public static function reconcile_stale_entries(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = gmdate( 'Y-m-d H:i:s' );
        $count = 0;

        // Accepted rows with no AS action_id — orphaned (AS enqueue was lost).
        $orphaned = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT command_id FROM `{$table}`
                 WHERE state='accepted' AND (action_id IS NULL OR action_id = 0)
                 LIMIT 50"
            )
        );
        foreach ( $orphaned as $cid ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}` SET state='failed_retryable',
                     error_message='orphaned_accepted_no_action_id', completed_at=%s
                     WHERE command_id=%s AND state='accepted' AND (action_id IS NULL OR action_id = 0)",
                    $now, $cid
                )
            );
            $count++;
        }

        // Dispatching rows with expired lease and no transition to accepted.
        $expired_dispatching = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT command_id FROM `{$table}`
                 WHERE state='dispatching' AND lease_expires_at < %s
                 LIMIT 50",
                $now
            )
        );
        foreach ( $expired_dispatching as $cid ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}` SET state='failed_retryable',
                     error_message='dispatching_lease_expired_unrecovered', completed_at=%s,
                     worker_id=NULL, lease_expires_at=NULL
                     WHERE command_id=%s AND state='dispatching' AND lease_expires_at < %s",
                    $now, $cid, $now
                )
            );
            $count++;
        }

        return $count;
    }

    // ── Read operations ────────────────────────────────────────────────────────

    /**
     * Get the full journal row, or null if not found.
     *
     * @return array<string,mixed>|null
     */
    public static function get( string $command_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE command_id=%s LIMIT 1",
                $command_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Return the current state string, or null if the command is not in the journal.
     */
    public static function get_state( string $command_id ): ?string {
        $row = self::get( $command_id );
        return $row ? $row['state'] : null;
    }

    /**
     * True when the command has reached a terminal state (completed or failed_permanent).
     */
    public static function is_terminal( string $command_id ): bool {
        $state = self::get_state( $command_id );
        return $state !== null && in_array( $state, self::TERMINAL_STATES, true );
    }

    /**
     * Return the stored AS action_id, or null if not recorded.
     */
    public static function get_action_id( string $command_id ): ?int {
        $row = self::get( $command_id );
        if ( ! $row || empty( $row['action_id'] ) ) {
            return null;
        }
        return (int) $row['action_id'];
    }

    // ── Internal helpers ───────────────────────────────────────────────────────

    /**
     * Mark a command as permanently failed due to identity conflict and log an alert.
     */
    private static function _mark_identity_conflict(
        string $command_id,
        string $new_identity,
        string $stored_identity
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET state='failed_permanent',
                 error_message='identity_conflict', completed_at=%s
                 WHERE command_id=%s AND state NOT IN ('completed','failed_permanent')",
                gmdate( 'Y-m-d H:i:s' ),
                $command_id
            )
        );

        if ( class_exists( 'RP_Care_Utils' ) ) {
            RP_Care_Utils::log(
                'pipeline', 'error',
                "SECURITY: command_id '{$command_id}' received with mismatched identity_hash — possible replay attack",
                [
                    'command_id'       => $command_id,
                    'stored_identity'  => substr( $stored_identity, 0, 16 ) . '…',
                    'new_identity'     => substr( $new_identity, 0, 16 ) . '…',
                ]
            );
        }
    }
}
