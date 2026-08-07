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
 *   1. enqueue()         — persists row with status='pending', returns event_id
 *   2. try_deliver_one() — immediate best-effort attempt after enqueue (FIFO-gated)
 *   3. deliver_pending() — called by scheduler; FIFO per batch, atomic claim
 *   4. On 2xx / idempotent 409 — status='delivered', delivered_at set
 *   5. On payload_conflict (409+body) — status='dead_letter', security alert logged
 *   6. On permanent_failure (4xx)    — status='dead_letter'
 *   7. On retryable failure (5xx/net/429) — attempts++, backoff, status 'pending'
 *   8. MAX_ATTEMPTS reached → status='dead_letter', operational alert
 *
 * FIFO guarantee: try_deliver_one() and claim_next() both refuse to claim an event
 * when an earlier undelivered sibling exists for the same batch_id.
 *
 * Atomicity: two workers racing on deliver_pending() each issue
 *   UPDATE SET status='delivering' WHERE id=N AND status='pending'
 * Only one UPDATE returns rows_affected=1 — the other gets 0 and skips the row.
 *
 * Lease recovery: before claiming, expire stale 'delivering' rows whose
 * lease_expires_at has passed; this prevents permanent lock-out on worker crash.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RP_Care_Pipeline_Outbox {

    private const TABLE         = 'rpcare_pipeline_outbox';
    private const MAX_ATTEMPTS  = 10;
    private const LEASE_SECONDS = 300; // 5-min lease; scheduler runs every ~1 min

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
     * FIFO-gated immediate delivery attempt for a specific event_id.
     *
     * Applies the same FIFO check as claim_next(): refuses to claim when an earlier
     * undelivered sibling exists for the same batch. Returns WP_Error('blocked_by_prior_event')
     * without altering the row — the scheduler handles the retry.
     *
     * @return true on delivered, WP_Error on failure or block.
     */
    public static function try_deliver_one( string $event_id ): bool|\WP_Error {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Recover expired leases before checking FIFO so stale siblings don't block forever.
        self::recover_expired_leases();

        // Look up the target row and evaluate its FIFO eligibility in one query.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, batch_id, status,
                        EXISTS(
                            SELECT 1 FROM `{$table}` b
                            WHERE b.batch_id = t.batch_id
                              AND b.id < t.id
                              AND b.delivered_at IS NULL
                        ) AS has_prior_undelivered
                 FROM `{$table}` t
                 WHERE t.event_id = %s
                 LIMIT 1",
                $event_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new \WP_Error( 'not_found', "Outbox event {$event_id} not found." );
        }

        if ( (int) $row['has_prior_undelivered'] ) {
            // Do NOT alter the row — scheduler will retry after the sibling is delivered.
            return new \WP_Error(
                'blocked_by_prior_event',
                "Event {$event_id} is blocked by an earlier undelivered event in batch {$row['batch_id']}."
            );
        }

        if ( $row['status'] !== 'pending' ) {
            return new \WP_Error( 'not_claimable', "Outbox event {$event_id} is in status '{$row['status']}', cannot claim." );
        }

        // Atomically claim the row — if another worker races us, their UPDATE wins.
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
            return new \WP_Error( 'claim_race', "Outbox event {$event_id} was claimed by another worker." );
        }

        $full_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE event_id=%s LIMIT 1", $event_id ),
            ARRAY_A
        );

        if ( ! $full_row ) {
            return new \WP_Error( 'not_found', "Outbox event {$event_id} not found after claim." );
        }

        return self::deliver_claimed( $full_row );
    }

    // ── Scheduler hook ─────────────────────────────────────────────────────────

    /**
     * Deliver pending events using atomic FIFO claiming.
     * Called by Action Scheduler on the 'rpcare_deliver_pipeline_outbox' hook.
     */
    public static function deliver_pending(): void {
        // Recover expired leases once per scheduler run before iterating.
        self::recover_expired_leases();

        $max_iterations = 50;
        $i              = 0;
        while ( $i++ < $max_iterations ) {
            $row = self::claim_next();
            if ( ! $row ) {
                break;
            }
            self::deliver_claimed( $row );
        }
    }

    // ── Expired lease recovery ─────────────────────────────────────────────────

    /**
     * Reclaim stale 'delivering' rows whose lease has expired.
     *
     * Two-worker safety: each worker issues its own per-row UPDATE with
     *   WHERE id=%d AND status='delivering' AND lease_expires_at < %s
     * Only one UPDATE returns rows_affected=1; the other's UPDATE silently finds 0 rows
     * because the first worker already flipped status to 'pending' or 'dead_letter'.
     */
    private static function recover_expired_leases(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = gmdate( 'Y-m-d H:i:s' );

        $expired = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, attempts FROM `{$table}`
                 WHERE status = 'delivering' AND lease_expires_at < %s
                 LIMIT 50",
                $now
            ),
            ARRAY_A
        );

        if ( empty( $expired ) ) {
            return;
        }

        foreach ( $expired as $row ) {
            $id           = (int) $row['id'];
            $new_attempts = (int) ($row['attempts'] ?? 0) + 1;

            if ( $new_attempts >= self::MAX_ATTEMPTS ) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE `{$table}` SET
                            status='dead_letter', attempts=%d,
                            last_error_code='lease_expired',
                            last_error_safe='Worker lease expired — max attempts reached',
                            lease_owner=NULL, lease_expires_at=NULL
                         WHERE id=%d AND status='delivering' AND lease_expires_at < %s",
                        $new_attempts, $id, $now
                    )
                );
                if ( class_exists( 'RP_Care_Utils' ) ) {
                    RP_Care_Utils::log( 'pipeline', 'error',
                        "Outbox event #{$id} moved to dead_letter — lease expired (max attempts)",
                        [ 'outbox_id' => $id ]
                    );
                }
            } else {
                $backoff   = (int) min( 60 * ( 2 ** $new_attempts ), 3600 );
                $next_at   = gmdate( 'Y-m-d H:i:s', time() + $backoff );

                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE `{$table}` SET
                            status='pending', attempts=%d,
                            next_attempt_at=%s,
                            last_error_code='lease_expired',
                            last_error_safe='Worker lease expired — reclaimed for retry',
                            lease_owner=NULL, lease_expires_at=NULL
                         WHERE id=%d AND status='delivering' AND lease_expires_at < %s",
                        $new_attempts, $next_at, $id, $now
                    )
                );
            }
        }
    }

    // ── Core delivery logic ────────────────────────────────────────────────────

    /**
     * Attempt HTTP delivery for a row already atomically claimed (status='delivering').
     *
     * Error code routing (typed contract):
     *   - true                  → delivered (2xx or idempotent 409)
     *   - 'payload_conflict'    → dead_letter + security alert (409 conflict_different_payload)
     *   - 'permanent_failure'   → dead_letter (4xx other than idempotent 409)
     *   - 'retryable' (or legacy 'retry_later') → backoff / dead_letter after MAX_ATTEMPTS
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
            // 2xx or idempotent 409 — delivered.
            $wpdb->update(
                $table,
                [
                    'status'           => 'delivered',
                    'delivered_at'     => gmdate( 'Y-m-d H:i:s' ),
                    'lease_owner'      => null,
                    'lease_expires_at' => null,
                ],
                [ 'id' => $id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return true;
        }

        $error_code = $result->get_error_code();
        $error_msg  = $result->get_error_message();

        // payload_conflict: 409 with conflict_different_payload → dead_letter + security alert.
        if ( $error_code === 'payload_conflict' ) {
            self::dead_letter( $id, $attempts, $error_code, $error_msg );
            if ( class_exists( 'RP_Care_Utils' ) ) {
                RP_Care_Utils::log(
                    'pipeline', 'error',
                    "SECURITY: Outbox event #{$id} payload_conflict — possible replay attack or payload mismatch",
                    [
                        'outbox_id' => $id,
                        'batch_id'  => $row['batch_id'] ?? '',
                        'event'     => $row['event']    ?? '',
                        'event_id'  => $row['event_id'] ?? '',
                    ]
                );
            }
            return $result;
        }

        // permanent_failure or legacy 'rejected' (any permanent 4xx) → dead_letter.
        if ( $error_code === 'permanent_failure' || $error_code === 'rejected' ) {
            self::dead_letter( $id, $attempts, $error_code, $error_msg );
            return $result;
        }

        // Transient failure (retryable, retry_later, network, 5xx/429) → backoff.
        self::record_failure( $id, $attempts, $error_code, $error_msg );
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
     *    (delivered_at IS NULL for all older siblings)
     *
     * @return array|null Full row (ARRAY_A) if claimed, null if nothing to deliver.
     */
    private static function claim_next(): ?array {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $now    = gmdate( 'Y-m-d H:i:s' );
        $worker = self::worker_id();
        $lease  = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );

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
                'status'           => 'pending',
                'attempts'         => $new_attempts,
                'next_attempt_at'  => $next_attempt,
                'last_error_code'  => substr( $error_code, 0, 64 ),
                'last_error_safe'  => substr( $error_safe, 0, 500 ),
                'lease_owner'      => null,
                'lease_expires_at' => null,
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
                'status'           => 'dead_letter',
                'attempts'         => $attempts,
                'last_error_code'  => substr( $error_code, 0, 64 ),
                'last_error_safe'  => substr( $error_safe, 0, 500 ),
                'lease_owner'      => null,
                'lease_expires_at' => null,
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
