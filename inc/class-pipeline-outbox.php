<?php
/**
 * RP_Care_Pipeline_Outbox
 *
 * Durable event queue that guarantees at-least-once delivery of pipeline events
 * to Plugin Center. Each event is persisted to DB with a UUID event_id BEFORE
 * any network attempt, so a crash between enqueue and delivery is recoverable.
 *
 * PC uses PC_Event_Idempotency::begin_reserve() to de-duplicate replays.
 *
 * Delivery flow:
 *   1. enqueue()        — persists row with status='pending', returns event_id
 *   2. try_deliver_one() — immediate best-effort attempt after enqueue
 *   3. deliver_pending() — called by scheduler; FIFO per batch, atomic claim
 *   4. On 2xx or 409    — status='delivered', delivered_at set
 *   5. On 4xx (not 409) — status='dead_letter' (permanent failure)
 *   6. On 5xx/network   — attempts++, backoff, status back to 'pending'
 *   7. MAX_ATTEMPTS reached → status='dead_letter', operational alert
 *
 * Atomicity guarantee: two workers racing on deliver_pending() each issue
 *   UPDATE SET status='delivering' WHERE id=N AND status='pending'
 * Only one UPDATE returns rows_affected=1 — the other gets 0 and skips the row.
 *
 * FIFO guarantee: claim_next() selects only events where no earlier undelivered
 * sibling exists for the same batch, preventing out-of-order delivery.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RP_Care_Pipeline_Outbox {

    private const TABLE        = 'rpcare_pipeline_outbox';
    private const MAX_ATTEMPTS = 10;
    private const LEASE_SECONDS = 300; // 5 min lease; scheduler runs every ~1 min

    // ── Enqueue ────────────────────────────────────────────────────────────────

    /**
     * Persist a new event to the outbox (status='pending').
     * Returns the UUID event_id on success, or WP_Error on DB failure.
     *
     * MUST be called before any HTTP attempt so the event survives a crash.
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
                'status'           => 'pending',
                'attempts'         => 0,
                'next_attempt_at'  => $now,
                'created_at'       => $now,
                'last_error_code'  => null,
                'last_error_safe'  => null,
                'delivered_at'     => null,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return new \WP_Error( 'outbox_insert_failed', 'Failed to persist event to outbox: ' . ( $wpdb->last_error ?? '' ) );
        }

        return $event_id;
    }

    // ── Immediate delivery attempt ─────────────────────────────────────────────

    /**
     * Atomically claim and attempt delivery of a specific event_id.
     * Called immediately after enqueue() for best-effort same-request delivery.
     * The scheduler will retry on failure — this is fire-and-forget.
     *
     * @return true on delivered, WP_Error on failure (outbox will retry).
     */
    public static function try_deliver_one( string $event_id ): bool|\WP_Error {
        global $wpdb;
        $table         = $wpdb->prefix . self::TABLE;
        $worker        = self::worker_id();
        $lease_expires = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );

        $claimed = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET status='delivering', lease_owner=%s, lease_expires_at=%s
                 WHERE event_id=%s AND status='pending'",
                $worker, $lease_expires, $event_id
            )
        );

        if ( ! $claimed ) {
            return new \WP_Error( 'not_claimable', "Outbox event {$event_id} not in pending state." );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE event_id=%s LIMIT 1", $event_id ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new \WP_Error( 'not_found', "Outbox event {$event_id} not found after claim." );
        }

        return self::deliver_claimed( $row );
    }

    // ── Scheduler hook ─────────────────────────────────────────────────────────

    /**
     * Deliver pending events using atomic FIFO claiming.
     * Called by Action Scheduler on the 'rpcare_deliver_pipeline_outbox' hook.
     *
     * FIFO per batch: an event is only claimed when no earlier undelivered sibling exists
     * for the same batch_id, preventing out-of-order delivery.
     *
     * Atomic claim: UPDATE WHERE status='pending' — only one worker's UPDATE succeeds.
     */
    public static function deliver_pending(): void {
        $max_iterations = 50; // safety cap per scheduler run
        $i              = 0;
        while ( $i++ < $max_iterations ) {
            $row = self::claim_next();
            if ( ! $row ) {
                break;
            }
            self::deliver_claimed( $row );
        }
    }

    // ── Core delivery logic ────────────────────────────────────────────────────

    /**
     * Attempt HTTP delivery for a row that has already been atomically claimed
     * (status='delivering'). Handles success, 409 (treat as delivered), permanent
     * 4xx failure (dead_letter), and transient failure (backoff + pending).
     *
     * @param array $row Full DB row, ARRAY_A.
     * @return true on delivered, WP_Error on failure.
     */
    private static function deliver_claimed( array $row ): bool|\WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $id    = (int) $row['id'];

        $payload             = json_decode( $row['payload_json'] ?? '{}', true ) ?: [];
        $payload['event_id'] = $row['event_id'];
        $payload['batch_id'] = $row['batch_id'];
        $payload['event']    = $row['event'];

        $result = RP_Care_Pipeline_Client::send_batch_event(
            $row['batch_id'],
            $row['event'],
            $payload,
            $row['event_id']
        );

        $attempts = (int) $row['attempts'];

        if ( ! is_wp_error( $result ) ) {
            // 2xx — delivered.
            $wpdb->update(
                $table,
                [ 'status' => 'delivered', 'delivered_at' => gmdate( 'Y-m-d H:i:s' ), 'lease_owner' => null, 'lease_expires_at' => null ],
                [ 'id' => $id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return true;
        }

        $error_code = $result->get_error_code();

        // 409 — PC already has this event (duplicate delivery is OK).
        if ( $error_code === 'already_processed' || $error_code === 'conflict' ) {
            $wpdb->update(
                $table,
                [ 'status' => 'delivered', 'delivered_at' => gmdate( 'Y-m-d H:i:s' ), 'lease_owner' => null, 'lease_expires_at' => null ],
                [ 'id' => $id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return true;
        }

        // Permanent 4xx (not 409) — dead_letter immediately.
        if ( $error_code === 'permanent_failure' ) {
            self::dead_letter( $id, $attempts, $error_code, $result->get_error_message() );
            return $result;
        }

        // Transient failure (5xx, network) — backoff, or dead_letter if MAX_ATTEMPTS reached.
        self::record_failure( $id, $attempts, $error_code, $result->get_error_message() );
        return $result;
    }

    // ── Atomic FIFO claim ──────────────────────────────────────────────────────

    /**
     * Atomically claim the oldest eligible pending event, respecting FIFO per batch.
     *
     * An event is eligible when:
     *  - status = 'pending'
     *  - attempts < MAX_ATTEMPTS
     *  - next_attempt_at <= NOW (backoff expired)
     *  - No earlier undelivered sibling exists for the same batch_id
     *    (i.e. delivered_at IS NOT NULL for all older siblings)
     *
     * @return array|null Full row (ARRAY_A) if claimed, null if nothing to deliver.
     */
    private static function claim_next(): ?array {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $now    = gmdate( 'Y-m-d H:i:s' );
        $worker = self::worker_id();
        $lease  = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );

        // Find oldest eligible pending event with no blocking undelivered sibling.
        $candidate = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` t
                 WHERE t.status = 'pending'
                   AND t.attempts < %d
                   AND t.next_attempt_at <= %s
                   AND NOT EXISTS (
                       SELECT 1 FROM `{$table}` b
                       WHERE b.batch_id = t.batch_id
                         AND b.id < t.id
                         AND b.delivered_at IS NULL
                   )
                 ORDER BY t.batch_id ASC, t.created_at ASC
                 LIMIT 1",
                self::MAX_ATTEMPTS,
                $now
            ),
            ARRAY_A
        );

        if ( ! $candidate ) {
            return null;
        }

        // Atomic claim — only one worker's UPDATE succeeds.
        $claimed = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET status='delivering', lease_owner=%s, lease_expires_at=%s
                 WHERE id=%d AND status='pending'",
                $worker, $lease, (int) $candidate['id']
            )
        );

        if ( ! $claimed ) {
            return null; // Another worker claimed it first.
        }

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id=%d LIMIT 1", (int) $candidate['id'] ),
            ARRAY_A
        );
    }

    // ── Failure tracking ───────────────────────────────────────────────────────

    private static function record_failure( int $id, int $attempts, string $error_code, string $error_safe ): void {
        global $wpdb;
        $new_attempts    = $attempts + 1;
        $backoff_seconds = (int) min( 60 * ( 2 ** $new_attempts ), 3600 );
        $next_attempt    = gmdate( 'Y-m-d H:i:s', time() + $backoff_seconds );

        if ( $new_attempts >= self::MAX_ATTEMPTS ) {
            self::dead_letter( $id, $new_attempts, $error_code, $error_safe );
            return;
        }

        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [
                'status'          => 'pending',
                'attempts'        => $new_attempts,
                'next_attempt_at' => $next_attempt,
                'last_error_code' => substr( $error_code, 0, 64 ),
                'last_error_safe' => substr( $error_safe, 0, 500 ),
                'lease_owner'     => null,
                'lease_expires_at'=> null,
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    private static function dead_letter( int $id, int $attempts, string $error_code, string $error_safe ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [
                'status'          => 'dead_letter',
                'attempts'        => $attempts,
                'last_error_code' => substr( $error_code, 0, 64 ),
                'last_error_safe' => substr( $error_safe, 0, 500 ),
                'lease_owner'     => null,
                'lease_expires_at'=> null,
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( class_exists( 'RP_Care_Utils' ) ) {
            RP_Care_Utils::log(
                'pipeline', 'error',
                "Outbox event #{$id} moved to dead_letter after {$attempts} attempts",
                [ 'outbox_id' => $id, 'error_code' => $error_code ]
            );
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function generate_uuid(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }

    private static function worker_id(): string {
        static $id = null;
        if ( $id === null ) {
            $id = substr( md5( uniqid( (string) getmypid(), true ) ), 0, 16 );
        }
        return $id;
    }
}
