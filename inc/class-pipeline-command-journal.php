<?php
/**
 * RP_Care_Pipeline_Command_Journal
 *
 * Durable state machine for Care command dispatch.
 * Prevents duplicate Action Scheduler enqueues for the same command_id when
 * Plugin Center retries delivery of a command Care has already accepted.
 *
 * States: received → accepted → running → completed | failed_retryable | failed_permanent
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
    const STATE_ACCEPTED         = 'accepted';
    const STATE_RUNNING          = 'running';
    const STATE_COMPLETED        = 'completed';
    const STATE_FAILED_RETRYABLE = 'failed_retryable';
    const STATE_FAILED_PERMANENT = 'failed_permanent';

    const TERMINAL_STATES = [ 'completed', 'failed_permanent' ];

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
     * Transition to 'accepted' and record the AS action_id.
     * Only succeeds when the current state is 'received'.
     *
     * @return bool true if the row was updated.
     */
    public static function mark_accepted( string $command_id, int $action_id = 0 ): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $rows = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET state='accepted', action_id=%d, accepted_at=%s
                 WHERE command_id=%s AND state='received'",
                $action_id,
                gmdate( 'Y-m-d H:i:s' ),
                $command_id
            )
        );

        return $rows > 0;
    }

    /**
     * Transition to 'running'. Accepts rows in 'received' or 'accepted' state.
     */
    public static function mark_running( string $command_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET state='running', running_at=%s
                 WHERE command_id=%s AND state IN ('received','accepted')",
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
                "UPDATE `{$table}` SET state=%s, error_message=%s, completed_at=%s
                 WHERE command_id=%s AND state NOT IN ('completed','failed_permanent')",
                $state,
                substr( $error, 0, 500 ),
                gmdate( 'Y-m-d H:i:s' ),
                $command_id
            )
        );
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
}
