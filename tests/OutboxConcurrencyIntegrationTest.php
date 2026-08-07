<?php
/**
 * OutboxConcurrencyIntegrationTest
 *
 * MariaDB integration tests for the RP_Care_Pipeline_Outbox atomic-claim and
 * FIFO guarantees.  These tests require a real MariaDB connection with the
 * rpcare_pipeline_outbox table already created (run the plugin's DB migrations
 * first).  They are skipped automatically when no DB is available.
 *
 * Run via:
 *   vendor/bin/phpunit tests/OutboxConcurrencyIntegrationTest.php
 *
 * Environment variables expected (or set defaults below):
 *   WPDB_HOST, WPDB_USER, WPDB_PASS, WPDB_NAME, WPDB_PREFIX
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

class OutboxConcurrencyIntegrationTest extends TestCase {

	private static bool $db_available = false;

	public static function setUpBeforeClass(): void {
		// Guard: skip unless a real DB is reachable.
		$host = getenv( 'WPDB_HOST' ) ?: '127.0.0.1';
		$user = getenv( 'WPDB_USER' ) ?: 'wp_test';
		$pass = getenv( 'WPDB_PASS' ) ?: '';
		$name = getenv( 'WPDB_NAME' ) ?: 'wp_test';

		$conn = @new mysqli( $host, $user, $pass, $name );
		if ( $conn->connect_errno ) {
			self::$db_available = false;
			return;
		}
		$conn->close();
		self::$db_available = true;
	}

	protected function setUp(): void {
		if ( ! self::$db_available ) {
			$this->markTestSkipped( 'MariaDB not available — set WPDB_HOST/USER/PASS/NAME to run.' );
		}
	}

	// ── Test stubs ─────────────────────────────────────────────────────────────

	/**
	 * Two simulated workers both call claim_next() for the same candidate row.
	 * Only one should succeed (rows_affected=1); the other gets 0.
	 *
	 * @todo Implement by spawning two PHP processes or using two DB connections
	 *       with an explicit SLEEP() to simulate the race.
	 */
	public function test_concurrent_claim_only_one_worker_wins(): void {
		$this->markTestSkipped( 'Integration stub — implement with two-connection race harness.' );
	}

	/**
	 * Two events for the same batch_id are enqueued.  Event A (id < Event B).
	 * deliver_pending() must deliver A before B.
	 *
	 * @todo Verify FIFO by asserting order in delivered_at timestamps.
	 */
	public function test_fifo_per_batch_is_enforced(): void {
		$this->markTestSkipped( 'Integration stub — requires real outbox table.' );
	}

	/**
	 * After MAX_ATTEMPTS failures, the row must have status='dead_letter' and
	 * RP_Care_Utils::log() must have been called with severity 'error'.
	 *
	 * @todo Mock HTTP to always return 500, run deliver_pending() MAX_ATTEMPTS times.
	 */
	public function test_dead_letter_after_max_attempts(): void {
		$this->markTestSkipped( 'Integration stub — requires real outbox table.' );
	}

	/**
	 * A row whose lease_expires_at is in the past must be re-claimable by a
	 * second worker even if status='delivering'.
	 *
	 * @todo Set a row to delivering with lease_expires_at = NOW() - 600s,
	 *       assert claim_next() returns it.
	 */
	public function test_expired_lease_row_is_reclaimable(): void {
		$this->markTestSkipped( 'Integration stub — requires real outbox table.' );
	}
}
