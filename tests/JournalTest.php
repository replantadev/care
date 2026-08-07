<?php
/**
 * RP_Care_Pipeline_Command_Journal — Unit Tests
 *
 * Covers every state-machine branch of claim_for_dispatch(), mark_accepted(),
 * and reconcile_stale_entries() via an in-memory wpdb mock.
 * No WP install, no DB, no HTTP required.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/class-pipeline-command-journal.php';

// ── Configurable in-memory wpdb stub ─────────────────────────────────────────

if ( ! class_exists( 'Journal_Mock_WPDB' ) ) {
    class Journal_Mock_WPDB {
        public string  $prefix     = 'wp_';
        public ?string $last_error = null;

        private array $query_returns   = [];
        private array $get_row_returns = [];
        private array $get_col_returns = [];

        public function set_query_returns( array $r ): void   { $this->query_returns   = $r; }
        public function set_get_row_returns( array $r ): void { $this->get_row_returns  = $r; }
        public function set_get_col_returns( array $r ): void { $this->get_col_returns  = $r; }

        public function prepare( string $sql, ...$args ): string {
            $idx = 0;
            return preg_replace_callback( '/%[sdf]/', function () use ( &$idx, $args ) {
                $v = $args[ $idx++ ] ?? '';
                return "'" . addslashes( (string) $v ) . "'";
            }, $sql );
        }

        public function query( string $sql ): int|bool {
            $v = array_shift( $this->query_returns );
            return $v ?? 0;
        }

        public function get_row( string $sql, string $output = 'OBJECT', int $row = 0 ): mixed {
            return array_shift( $this->get_row_returns );
        }

        public function get_col( string $sql, int $col = 0 ): array {
            $v = array_shift( $this->get_col_returns );
            return is_array( $v ) ? $v : [];
        }

        public function get_var( string $sql, int $col = 0, int $row = 0 ): ?string { return null; }
        public function get_results( string $sql, string $output = 'OBJECT' ): array { return []; }
        public function update( string $t, array $d, array $w, $f = null, $wf = null ): int|false { return 1; }
        public function insert( string $t, array $d, $f = null ): int|false { return 1; }
    }
}

// ── Test class ────────────────────────────────────────────────────────────────

class JournalTest extends TestCase {

    private Journal_Mock_WPDB $mock;
    private mixed $saved_wpdb;

    protected function setUp(): void {
        $this->mock      = new Journal_Mock_WPDB();
        $this->saved_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $this->mock;
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->saved_wpdb;
    }

    private function future(): string { return gmdate( 'Y-m-d H:i:s', time() + 3600 ); }
    private function past(): string   { return gmdate( 'Y-m-d H:i:s', time() - 3600 ); }

    // ── claim_for_dispatch() ─────────────────────────────────────────────────

    /**
     * J-01: CAS UPDATE returns 1 → first worker is granted the dispatching slot.
     */
    public function test_J01_claim_returns_claimed_when_cas_update_succeeds(): void {
        $this->mock->set_query_returns( [1] );
        $result = RP_Care_Pipeline_Command_Journal::claim_for_dispatch(
            'cmd-j01', 'hash-abc', 'worker-1', $this->future()
        );
        $this->assertSame( 'claimed', $result, 'J-01: CAS UPDATE rows_affected=1 must yield claimed' );
    }

    /**
     * J-02: CAS UPDATE returns 0, no row exists → 'not_found'.
     */
    public function test_J02_claim_returns_not_found_when_row_absent(): void {
        $this->mock->set_query_returns( [0] );
        $this->mock->set_get_row_returns( [null] );
        $result = RP_Care_Pipeline_Command_Journal::claim_for_dispatch(
            'cmd-j02', 'hash-abc', 'worker-1', $this->future()
        );
        $this->assertSame( 'not_found', $result, 'J-02: no journal row must yield not_found' );
    }

    /**
     * J-03: State is 'completed' (terminal) → 'already_terminal'.
     */
    public function test_J03_claim_returns_already_terminal_for_completed_state(): void {
        $this->mock->set_query_returns( [0] );
        $this->mock->set_get_row_returns( [
            [ 'state' => 'completed', 'identity_hash' => 'any', 'lease_expires_at' => null ],
        ] );
        $result = RP_Care_Pipeline_Command_Journal::claim_for_dispatch(
            'cmd-j03', 'hash-abc', 'worker-1', $this->future()
        );
        $this->assertSame( 'already_terminal', $result, 'J-03: terminal state must yield already_terminal' );
    }

    /**
     * J-04: State is 'accepted' (AS action in flight) → 'accepted_or_running'.
     * Care must not re-enqueue; the original AS action is still pending.
     */
    public function test_J04_claim_returns_accepted_or_running_for_in_flight_command(): void {
        $this->mock->set_query_returns( [0] );
        $this->mock->set_get_row_returns( [
            [ 'state' => 'accepted', 'identity_hash' => 'any', 'lease_expires_at' => null ],
        ] );
        $result = RP_Care_Pipeline_Command_Journal::claim_for_dispatch(
            'cmd-j04', 'hash-abc', 'worker-1', $this->future()
        );
        $this->assertSame( 'accepted_or_running', $result, 'J-04: accepted state must yield accepted_or_running' );
    }

    /**
     * J-05: State is 'dispatching', same identity, lease still valid → 'lease_valid'.
     * The slot is held by another worker; this worker must back off.
     */
    public function test_J05_claim_returns_lease_valid_when_another_worker_holds_active_lease(): void {
        $this->mock->set_query_returns( [0] );
        $this->mock->set_get_row_returns( [
            [
                'state'            => 'dispatching',
                'identity_hash'    => 'hash-same',
                'lease_expires_at' => $this->future(),
            ],
        ] );
        $result = RP_Care_Pipeline_Command_Journal::claim_for_dispatch(
            'cmd-j05', 'hash-same', 'worker-2', $this->future()
        );
        $this->assertSame( 'lease_valid', $result, 'J-05: active lease held by another worker must yield lease_valid' );
    }

    /**
     * J-06: Same command_id, different identity_hash (replay with different payload) → 'identity_conflict'.
     * The journal must permanently fail the command and alert.
     */
    public function test_J06_claim_returns_identity_conflict_when_payload_differs(): void {
        // CAS UPDATE returns 0; then _mark_identity_conflict issues a second UPDATE.
        $this->mock->set_query_returns( [0, 1] );
        $this->mock->set_get_row_returns( [
            [
                'state'            => 'dispatching',
                'identity_hash'    => 'hash-original',
                'lease_expires_at' => $this->future(),
            ],
        ] );
        $result = RP_Care_Pipeline_Command_Journal::claim_for_dispatch(
            'cmd-j06', 'hash-DIFFERENT', 'worker-x', $this->future()
        );
        $this->assertSame( 'identity_conflict', $result, 'J-06: mismatched identity_hash must yield identity_conflict' );
    }

    /**
     * J-07: Expired dispatching lease → takeover succeeds → 'claimed'.
     * The second UPDATE (takeover) returns 1, so the new worker owns the slot.
     */
    public function test_J07_claim_reclaims_expired_dispatching_lease(): void {
        // CAS #1: fails (row is dispatching, not received/failed_retryable).
        // CAS #2 (takeover): succeeds.
        $this->mock->set_query_returns( [0, 1] );
        $this->mock->set_get_row_returns( [
            [
                'state'            => 'dispatching',
                'identity_hash'    => 'hash-same',
                'lease_expires_at' => $this->past(), // ← expired
            ],
        ] );
        $result = RP_Care_Pipeline_Command_Journal::claim_for_dispatch(
            'cmd-j07', 'hash-same', 'worker-3', $this->future()
        );
        $this->assertSame( 'claimed', $result, 'J-07: expired dispatching lease must be reclaimable' );
    }

    /**
     * J-08: Two concurrent workers for the same command_id.
     * The first CAS (rows_affected=1) wins; the second sees lease_valid.
     * This is the concurrency correctness guarantee: only one worker may enqueue.
     */
    public function test_J08_two_workers_same_command_id_only_first_gets_claimed(): void {
        // Worker 1: CAS succeeds immediately.
        $this->mock->set_query_returns( [1] );
        $w1 = RP_Care_Pipeline_Command_Journal::claim_for_dispatch(
            'cmd-j08', 'hash-abc', 'worker-1', $this->future()
        );
        $this->assertSame( 'claimed', $w1, 'J-08: first worker must claim the dispatching slot' );

        // Worker 2 arrives after worker 1 transitioned the row to 'dispatching'.
        $this->mock->set_query_returns( [0] );
        $this->mock->set_get_row_returns( [
            [
                'state'            => 'dispatching',
                'identity_hash'    => 'hash-abc',
                'lease_expires_at' => $this->future(),
            ],
        ] );
        $w2 = RP_Care_Pipeline_Command_Journal::claim_for_dispatch(
            'cmd-j08', 'hash-abc', 'worker-2', $this->future()
        );
        $this->assertSame( 'lease_valid', $w2, 'J-08: second worker must back off — only one may enqueue' );
    }

    // ── mark_accepted() ──────────────────────────────────────────────────────

    /**
     * J-09: mark_accepted returns true when the CAS UPDATE (WHERE state='dispatching') succeeds.
     * This enforces that only a dispatching→accepted transition is legal.
     */
    public function test_J09_mark_accepted_returns_true_when_state_is_dispatching(): void {
        $this->mock->set_query_returns( [1] );
        $ok = RP_Care_Pipeline_Command_Journal::mark_accepted( 'cmd-j09', 999 );
        $this->assertTrue( $ok, 'J-09: mark_accepted must return true when state=dispatching (rows_affected=1)' );
    }

    /**
     * J-10: mark_accepted returns false when the CAS UPDATE returns 0.
     * Caller must NOT treat this as success; it must transition to failed_retryable.
     */
    public function test_J10_mark_accepted_returns_false_when_not_dispatching(): void {
        $this->mock->set_query_returns( [0] );
        $ok = RP_Care_Pipeline_Command_Journal::mark_accepted( 'cmd-j10', 0 );
        $this->assertFalse( $ok, 'J-10: mark_accepted must return false when state != dispatching' );
    }

    // ── reconcile_stale_entries() ────────────────────────────────────────────

    /**
     * J-11: reconcile_stale_entries finds orphaned accepted rows (no AS action_id)
     * and transitions them to failed_retryable.
     * Simulates 2 orphaned accepted rows and 0 expired dispatching rows.
     */
    public function test_J11_reconcile_stale_entries_reconciles_orphaned_accepted(): void {
        // First get_col → orphaned accepted commands.
        // Second get_col → expired dispatching (none).
        $this->mock->set_get_col_returns( [
            [ 'cmd-orphan-a', 'cmd-orphan-b' ],
            [],
        ] );
        // One UPDATE query per orphaned row → 2 queries.
        $this->mock->set_query_returns( [1, 1] );

        $count = RP_Care_Pipeline_Command_Journal::reconcile_stale_entries();

        $this->assertSame( 2, $count, 'J-11: reconcile_stale_entries must count all reconciled rows' );
    }

    /**
     * J-12: reconcile_stale_entries finds and transitions expired dispatching rows.
     * Simulates 0 orphaned accepted rows and 1 expired dispatching row.
     */
    public function test_J12_reconcile_stale_entries_reconciles_expired_dispatching(): void {
        $this->mock->set_get_col_returns( [
            [],                // orphaned accepted — none
            [ 'cmd-exp-d' ],   // expired dispatching — one
        ] );
        $this->mock->set_query_returns( [1] );

        $count = RP_Care_Pipeline_Command_Journal::reconcile_stale_entries();

        $this->assertSame( 1, $count, 'J-12: reconcile_stale_entries must count expired dispatching rows' );
    }
}
