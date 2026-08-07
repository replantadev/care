<?php
/**
 * RP_Care_Pipeline_Outbox
 *
 * Durable event queue that guarantees at-least-once delivery of pipeline events
 * to Plugin Center. Each event is persisted to DB with a UUID event_id before
 * any network attempt, so a crash between enqueue and delivery is recoverable.
 *
 * PC uses PC_Event_Idempotency to de-duplicate replays, so at-least-once is safe.
 *
 * Delivery flow:
 *   1. enqueue()        — writes row, returns event_id
 *   2. deliver_pending() — called by scheduler; delivers FIFO per batch
 *   3. On 2xx           — marks delivered_at
 *   4. On failure       — increments attempts, schedules next_attempt_at (backoff)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RP_Care_Pipeline_Outbox {

    private const TABLE    = 'rpcare_pipeline_outbox';
    private const MAX_ATTEMPTS = 10;

    // ── Write ──────────────────────────────────────────────────────────────────

    /**
     * Persist a new event to the outbox.
     * Returns the UUID event_id on success, or WP_Error on DB failure.
     *
     * @param string $batch_id  The pipeline batch this event belongs to.
     * @param string $event     Event name (e.g. 'staging_ready', 'tests_complete').
     * @param array  $payload   Arbitrary data to include in the POST body.
     * @return string|\WP_Error  event_id UUID on success.
     */
    public static function enqueue( string $batch_id, string $event, array $payload = [] ): string|\WP_Error {
        global $wpdb;

        $event_id = self::generate_uuid();
        $table    = $wpdb->prefix . self::TABLE;
        $now      = gmdate( 'Y-m-d H:i:s' );

        $inserted = $wpdb->insert(
            $table,
            [
                'event_id'         => $event_id,
                'batch_id'         => $batch_id,
                'event'            => $event,
                'payload_json'     => wp_json_encode( $payload ),
                'attempts'         => 0,
                'next_attempt_at'  => $now,
                'created_at'       => $now,
                'last_error'       => null,
                'delivered_at'     => null,
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return new \WP_Error( 'outbox_insert_failed', 'Failed to persist event to outbox.' );
        }

        return $event_id;
    }

    // ── Deliver ────────────────────────────────────────────────────────────────

    /**
     * Deliver a single event row by its DB id.
     * Returns true on 2xx, WP_Error on network or server error.
     */
    public static function deliver( int $id ): bool|\WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND delivered_at IS NULL LIMIT 1", $id ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new \WP_Error( 'not_found', "Outbox row $id not found or already delivered." );
        }

        $payload = json_decode( $row['payload_json'] ?? '{}', true ) ?: [];
        $payload['event_id'] = $row['event_id'];  // PC uses this for idempotency.
        $payload['batch_id'] = $row['batch_id'];
        $payload['event']    = $row['event'];

        $result = RP_Care_Pipeline_Client::send_batch_event(
            $row['batch_id'],
            $row['event'],
            $payload,
            $row['event_id']
        );

        if ( is_wp_error( $result ) ) {
            self::record_failure( $id, (int) $row['attempts'], $result->get_error_message() );
            return $result;
        }

        $wpdb->update(
            $table,
            [ 'delivered_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );

        return true;
    }

    // ── Scheduler hook ─────────────────────────────────────────────────────────

    /**
     * Deliver all pending events that are due.
     * Called by Action Scheduler on the 'rpcare_deliver_pipeline_outbox' hook.
     * Processes FIFO per batch: within a batch, events are delivered in creation order.
     */
    public static function deliver_pending(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = gmdate( 'Y-m-d H:i:s' );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM `{$table}`
                 WHERE delivered_at IS NULL
                   AND attempts < %d
                   AND next_attempt_at <= %s
                 ORDER BY batch_id ASC, created_at ASC
                 LIMIT 50",
                self::MAX_ATTEMPTS,
                $now
            ),
            ARRAY_A
        );

        foreach ( $rows as $row ) {
            self::deliver( (int) $row['id'] );
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function record_failure( int $id, int $attempts, string $error ): void {
        global $wpdb;
        $new_attempts   = $attempts + 1;
        $backoff_seconds = min( 60 * ( 2 ** $new_attempts ), 3600 ); // cap at 1h
        $next_attempt   = gmdate( 'Y-m-d H:i:s', time() + $backoff_seconds );

        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [
                'attempts'       => $new_attempts,
                'next_attempt_at'=> $next_attempt,
                'last_error'     => substr( $error, 0, 500 ),
            ],
            [ 'id' => $id ],
            [ '%d', '%s', '%s' ],
            [ '%d' ]
        );
    }

    private static function generate_uuid(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }
        // Fallback for test environments.
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
