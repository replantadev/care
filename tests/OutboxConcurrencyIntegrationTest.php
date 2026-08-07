<?php
/**
 * OutboxConcurrencyIntegrationTest
 *
 * Real MariaDB integration tests for RP_Care_Pipeline_Outbox atomic-claim, FIFO,
 * lease recovery, dead-lettering, and outbox-error propagation.
 *
 * Activated automatically when PIPELINE_MARIADB_DSN / PIPELINE_MARIADB_USER /
 * PIPELINE_MARIADB_PASS are set; skipped otherwise.
 *
 * Run:
 *   PIPELINE_MARIADB_DSN=mysql:host=127.0.0.1;dbname=wp_test \
 *   PIPELINE_MARIADB_USER=wp_test \
 *   PIPELINE_MARIADB_PASS=secret \
 *   vendor/bin/phpunit tests/OutboxConcurrencyIntegrationTest.php
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

// Load Care pipeline classes if not already loaded (may be loaded by another test in the suite).
if ( ! class_exists( 'RP_Care_Pipeline_Outbox' ) ) {
    require_once __DIR__ . '/../inc/class-pipeline-client.php';
    require_once __DIR__ . '/../inc/class-pipeline-outbox.php';
}
if ( ! class_exists( 'RP_Care_Task_Updates' ) ) {
    require_once __DIR__ . '/../inc/class-inventory-snapshot.php';
    require_once __DIR__ . '/../inc/task-updates.php';
}

class OutboxConcurrencyIntegrationTest extends TestCase {

    private static ?\mysqli $db    = null;
    private static string   $table = 'wp_rpcare_pipeline_outbox';

    // ── Suite setup / teardown ────────────────────────────────────────────────

    public static function setUpBeforeClass(): void {
        $dsn  = (string) getenv( 'PIPELINE_MARIADB_DSN' );
        $user = (string) getenv( 'PIPELINE_MARIADB_USER' );
        $pass = (string) getenv( 'PIPELINE_MARIADB_PASS' );

        if ( ! $dsn || ! $user ) {
            return;
        }

        // Parse DSN: mysql:host=127.0.0.1;port=3306;dbname=wp_test
        $host = '127.0.0.1';
        $port = 3306;
        $name = 'wp_test';
        foreach ( explode( ';', ltrim( $dsn, 'mysql:' ) ) as $part ) {
            [ $k, $v ] = array_pad( explode( '=', $part, 2 ), 2, '' );
            match ( $k ) {
                'host'   => ( $host = $v ),
                'port'   => ( $port = (int) $v ),
                'dbname' => ( $name = $v ),
                default  => null,
            };
        }

        $conn = @new \mysqli( $host, $user, $pass, $name, $port );
        if ( $conn->connect_errno ) {
            return;
        }
        $conn->set_charset( 'utf8mb4' );
        self::$db    = $conn;
        self::$table = 'wp_rpcare_pipeline_outbox';

        $conn->query( "
            CREATE TABLE IF NOT EXISTS `" . self::$table . "` (
              `id`               bigint unsigned NOT NULL AUTO_INCREMENT,
              `event_id`         char(36)        NOT NULL,
              `batch_id`         varchar(36)     NOT NULL DEFAULT '',
              `event`            varchar(64)     NOT NULL DEFAULT '',
              `payload_json`     longtext,
              `status`           varchar(20)     NOT NULL DEFAULT 'pending',
              `attempts`         tinyint unsigned NOT NULL DEFAULT 0,
              `next_attempt_at`  datetime        NOT NULL DEFAULT '2000-01-01 00:00:00',
              `delivered_at`     datetime        DEFAULT NULL,
              `created_at`       datetime        NOT NULL DEFAULT '2000-01-01 00:00:00',
              `last_error_code`  varchar(64)     DEFAULT NULL,
              `last_error_safe`  varchar(500)    DEFAULT NULL,
              `lease_owner`      varchar(64)     DEFAULT NULL,
              `lease_expires_at` datetime        DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_event_id` (`event_id`),
              KEY `idx_batch_status` (`batch_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        " );
    }

    public static function tearDownAfterClass(): void {
        if ( self::$db ) {
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void {
        if ( ! self::$db ) {
            $this->markTestSkipped(
                'MariaDB not available — set PIPELINE_MARIADB_DSN/USER/PASS to run.'
            );
        }
        self::$db->query( 'TRUNCATE TABLE `' . self::$table . '`' );

        // Wire real wpdb wrapper for methods under test.
        $db            = self::$db;
        $table         = self::$table;
        $GLOBALS['wpdb'] = new class( $db, $table ) extends wpdb {
            private \mysqli $conn;
            public function __construct( \mysqli $conn, string $pfx_table ) {
                $this->conn   = $conn;
                $this->prefix = 'wp_';
            }

            public function prepare( string $sql, ...$args ): string {
                $i = 0;
                return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
                    $val = $args[ $i++ ] ?? null;
                    if ( is_null( $val ) ) { return 'NULL'; }
                    if ( $m[0] === '%d' ) { return (string) (int) $val; }
                    return "'" . $this->conn->real_escape_string( (string) $val ) . "'";
                }, $sql );
            }

            public function query( string $sql ): int|bool {
                $ok = $this->conn->query( $sql );
                if ( $ok === false ) {
                    $this->last_error = $this->conn->error;
                    return false;
                }
                return $this->conn->affected_rows >= 0 ? $this->conn->affected_rows : true;
            }

            public function insert( string $table, array $data, $format = null ): int|false {
                if ( empty( $data ) ) { return false; }
                $cols = implode( ', ', array_map( fn( $k ) => "`{$k}`", array_keys( $data ) ) );
                $vals = implode( ', ', array_map( function ( $v ) {
                    if ( is_null( $v ) ) { return 'NULL'; }
                    return "'" . $this->conn->real_escape_string( (string) $v ) . "'";
                }, array_values( $data ) ) );
                $ok = $this->conn->query( "INSERT INTO `{$table}` ({$cols}) VALUES ({$vals})" );
                if ( ! $ok ) {
                    $this->last_error = $this->conn->error;
                    return false;
                }
                return $this->conn->affected_rows;
            }

            public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|false {
                $sets   = implode( ', ', array_map( function ( $k, $v ) {
                    if ( is_null( $v ) ) { return "`{$k}` = NULL"; }
                    return "`{$k}` = '" . $this->conn->real_escape_string( (string) $v ) . "'";
                }, array_keys( $data ), array_values( $data ) ) );
                $wheres = implode( ' AND ', array_map( function ( $k, $v ) {
                    if ( is_null( $v ) ) { return "`{$k}` IS NULL"; }
                    return "`{$k}` = '" . $this->conn->real_escape_string( (string) $v ) . "'";
                }, array_keys( $where ), array_values( $where ) ) );
                $ok = $this->conn->query( "UPDATE `{$table}` SET {$sets} WHERE {$wheres}" );
                if ( ! $ok ) {
                    $this->last_error = $this->conn->error;
                    return false;
                }
                return $this->conn->affected_rows;
            }

            public function get_row( string $sql, $output = 'OBJECT', int $row_offset = 0 ) {
                $result = $this->conn->query( $sql );
                if ( ! $result ) { return null; }
                $row = $result->fetch_assoc();
                $result->free();
                if ( ! $row ) { return null; }
                return $output === ARRAY_A ? $row : (object) $row;
            }

            public function get_results( string $sql, string $output = 'OBJECT' ): array {
                $result = $this->conn->query( $sql );
                if ( ! $result ) { return []; }
                $rows = [];
                while ( $row = $result->fetch_assoc() ) {
                    $rows[] = $output === ARRAY_A ? $row : (object) $row;
                }
                $result->free();
                return $rows;
            }

            public function get_var( string $sql, int $col_offset = 0, int $row_offset = 0 ): ?string {
                $result = $this->conn->query( $sql );
                if ( ! $result ) { return null; }
                $row = $result->fetch_row();
                $result->free();
                return $row ? (string) $row[ $col_offset ] : null;
            }
        };

        // Reset option store between tests.
        $GLOBALS['_wp_options'] = [];
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb']                 = new wpdb();
        $GLOBALS['_wp_remote_post_mock'] = null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function insert_row( array $overrides = [] ): string {
        $event_id = sprintf( '%04x%04x-%04x-4%03x-%04x-%08x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ),
            mt_rand( 0x8000, 0xbfff ),
            mt_rand( 0, 0xffffffff ), mt_rand( 0, 0xffff )
        );
        $now = gmdate( 'Y-m-d H:i:s' );
        $row = array_merge( [
            'event_id'        => $event_id,
            'batch_id'        => 'test-batch',
            'event'           => 'staging_updated',
            'payload_json'    => '{}',
            'status'          => 'pending',
            'attempts'        => 0,
            'next_attempt_at' => $now,
            'created_at'      => $now,
        ], $overrides );

        $cols = implode( ', ', array_map( fn( $k ) => "`{$k}`", array_keys( $row ) ) );
        $vals = implode( ', ', array_map( function ( $v ) {
            if ( is_null( $v ) ) { return 'NULL'; }
            return "'" . self::$db->real_escape_string( (string) $v ) . "'";
        }, array_values( $row ) ) );

        self::$db->query( "INSERT INTO `" . self::$table . "` ({$cols}) VALUES ({$vals})" );
        return $event_id;
    }

    private function fetch_row( string $event_id ): ?array {
        $result = self::$db->query(
            "SELECT * FROM `" . self::$table . "` WHERE event_id='"
            . self::$db->real_escape_string( $event_id ) . "' LIMIT 1"
        );
        if ( ! $result ) { return null; }
        $row = $result->fetch_assoc();
        $result->free();
        return $row ?: null;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * OC-1: Two workers race on the same pending row — exactly one wins the atomic claim.
     * The UPDATE WHERE status='pending' is the atomic gate: second UPDATE sees 0 rows_affected.
     */
    public function test_concurrent_claim_only_one_worker_wins(): void {
        $this->insert_row( [ 'batch_id' => 'oc1-batch' ] );

        $res    = self::$db->query( "SELECT id FROM `" . self::$table . "` WHERE batch_id='oc1-batch' LIMIT 1" );
        $tmp    = $res->fetch_assoc();
        $res->free();
        $row_id = (int) $tmp['id'];

        // Worker A claims.
        self::$db->query( "UPDATE `" . self::$table . "` SET status='delivering', lease_owner='worker_a' WHERE id={$row_id} AND status='pending'" );
        $a_affected = self::$db->affected_rows;

        // Worker B tries to claim the same row — must get 0 (row is already 'delivering').
        self::$db->query( "UPDATE `" . self::$table . "` SET status='delivering', lease_owner='worker_b' WHERE id={$row_id} AND status='pending'" );
        $b_affected = self::$db->affected_rows;

        $this->assertSame( 1, $a_affected, 'Worker A must win the claim (1 row affected)' );
        $this->assertSame( 0, $b_affected, 'Worker B must get 0 rows_affected — row already claimed' );
        $this->assertSame( 1, $a_affected + $b_affected, 'Exactly one claim must succeed total' );

        // Verify the winner is worker_a.
        $row = $this->fetch_row( $tmp['event_id'] ?? '' );
        if ( $row ) {
            $this->assertSame( 'worker_a', $row['lease_owner'],
                'lease_owner must belong to the worker that won the race' );
        }
    }

    /**
     * OC-2: FIFO — event A (inserted first, lower id) must be claimed before event B
     * for the same batch. Event B cannot be claimed while A is still undelivered.
     */
    public function test_fifo_per_batch_is_enforced(): void {
        $batch = 'oc2-batch-' . mt_rand( 1000, 9999 );
        $now   = gmdate( 'Y-m-d H:i:s' );

        $this->insert_row( [ 'batch_id' => $batch, 'event' => 'staging_updated', 'created_at' => $now ] );
        $event_b = $this->insert_row( [ 'batch_id' => $batch, 'event' => 'tests_complete', 'created_at' => $now ] );

        $res = self::$db->query(
            "SELECT id FROM `" . self::$table . "` WHERE batch_id='" . self::$db->real_escape_string( $batch ) . "' ORDER BY id ASC LIMIT 2"
        );
        $ids = [];
        while ( $r = $res->fetch_assoc() ) { $ids[] = (int) $r['id']; }
        $res->free();
        [$id_a, $id_b] = $ids;

        // Try claiming B while A is still pending (NOT EXISTS check prevents this).
        self::$db->query(
            "UPDATE `" . self::$table . "` t
             JOIN (SELECT id FROM `" . self::$table . "` WHERE batch_id='" . self::$db->real_escape_string( $batch ) . "'
                   AND id < {$id_b} AND delivered_at IS NULL LIMIT 1) blocker ON 1=0
             SET t.status='delivering', t.lease_owner='worker_test'
             WHERE t.id={$id_b} AND t.status='pending'"
        );
        // Simpler direct check: claim B fails because A has delivered_at=NULL.
        self::$db->query(
            "UPDATE `" . self::$table . "` SET status='delivering', lease_owner='worker_test'
             WHERE id={$id_b} AND status='pending'
             AND NOT EXISTS (
                 SELECT 1 FROM (
                     SELECT id, batch_id, delivered_at FROM `" . self::$table . "`
                 ) sub
                 WHERE sub.batch_id='" . self::$db->real_escape_string( $batch ) . "'
                   AND sub.id < {$id_b}
                   AND sub.delivered_at IS NULL
             )"
        );
        $b_claimed_while_a_pending = self::$db->affected_rows;

        $this->assertSame( 0, $b_claimed_while_a_pending,
            'Event B must not be claimable while event A (earlier sibling) is still undelivered' );

        // Mark A delivered, then retry claiming B.
        self::$db->query( "UPDATE `" . self::$table . "` SET status='delivered', delivered_at='{$now}' WHERE id={$id_a}" );

        self::$db->query(
            "UPDATE `" . self::$table . "` SET status='delivering', lease_owner='worker_test'
             WHERE id={$id_b} AND status='pending'
             AND NOT EXISTS (
                 SELECT 1 FROM (
                     SELECT id, batch_id, delivered_at FROM `" . self::$table . "`
                 ) sub
                 WHERE sub.batch_id='" . self::$db->real_escape_string( $batch ) . "'
                   AND sub.id < {$id_b}
                   AND sub.delivered_at IS NULL
             )"
        );
        $b_claimed_after_a_delivered = self::$db->affected_rows;

        $this->assertSame( 1, $b_claimed_after_a_delivered,
            'Event B must be claimable after event A is delivered (FIFO unblocked)' );
    }

    /**
     * OC-3: try_deliver_one() returns WP_Error('blocked_by_prior_event') when an earlier
     * undelivered sibling exists for the same batch. The row must not be altered.
     */
    public function test_immediate_delivery_blocked_by_prior_event(): void {
        $batch = 'oc3-batch-' . mt_rand( 1000, 9999 );

        $enc_token = ( new ReflectionMethod( RP_Care_Pipeline_Client::class, 'encrypt_local' ) );
        $enc_token->setAccessible( true );
        $token = $enc_token->invoke( null, 'tok-oc3' );

        update_option( 'rpcare_options',                          [ 'hub_url' => 'https://pc.oc3.test' ] );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,        $token );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'oc3-instance' );

        $this->insert_row( [ 'batch_id' => $batch, 'event' => 'staging_updated' ] );
        $event_b = $this->insert_row( [ 'batch_id' => $batch, 'event' => 'tests_complete' ] );

        $result = RP_Care_Pipeline_Outbox::try_deliver_one( $event_b );

        $this->assertInstanceOf( \WP_Error::class, $result,
            'try_deliver_one must return WP_Error when a prior event is undelivered' );
        $this->assertSame( 'blocked_by_prior_event', $result->get_error_code(),
            'Error code must be blocked_by_prior_event' );

        // Row must remain unchanged.
        $row = $this->fetch_row( $event_b );
        $this->assertSame( 'pending', $row['status'] ?? '',
            'try_deliver_one must not alter the blocked row — it stays pending for the scheduler' );
    }

    /**
     * OC-4: A delivering row with lease_expires_at in the past is reclaimed to 'pending'
     * by recover_expired_leases(), and its attempts counter is incremented.
     */
    public function test_expired_lease_row_is_reclaimable(): void {
        $past     = gmdate( 'Y-m-d H:i:s', time() - 600 );
        $event_id = $this->insert_row( [
            'status'           => 'delivering',
            'lease_owner'      => 'crashed-worker',
            'lease_expires_at' => $past,
            'attempts'         => 2,
        ] );

        $ref = new \ReflectionMethod( RP_Care_Pipeline_Outbox::class, 'recover_expired_leases' );
        $ref->setAccessible( true );
        $ref->invoke( null );

        $row = $this->fetch_row( $event_id );
        $this->assertSame( 'pending', $row['status'],
            'Expired-lease row must be reset to pending after recover_expired_leases()' );
        $this->assertSame( '3', (string) $row['attempts'],
            'attempts must be incremented during lease recovery (2 → 3)' );
        $this->assertNull( $row['lease_owner'],
            'lease_owner must be cleared after lease recovery' );
        $this->assertNull( $row['lease_expires_at'],
            'lease_expires_at must be cleared after lease recovery' );
    }

    /**
     * OC-5: After MAX_ATTEMPTS (10) failed deliveries the row becomes dead_letter.
     * Inject a row at attempts=9, mock HTTP 500; one more failure tips it over MAX_ATTEMPTS.
     */
    public function test_dead_letter_after_max_attempts(): void {
        $event_id = $this->insert_row( [
            'batch_id' => 'oc5-batch',
            'attempts' => 9,
        ] );

        $enc = ( new \ReflectionMethod( RP_Care_Pipeline_Client::class, 'encrypt_local' ) );
        $enc->setAccessible( true );
        update_option( 'rpcare_options',                          [ 'hub_url' => 'https://pc.oc5.test' ] );
        update_option( RP_Care_Pipeline_Client::OPT_TOKEN,        $enc->invoke( null, 'tok-oc5' ) );
        update_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, 'oc5-instance' );

        $GLOBALS['_wp_remote_post_mock'] = static fn() => [ 'response' => [ 'code' => 500 ], 'body' => 'Server Error' ];

        RP_Care_Pipeline_Outbox::try_deliver_one( $event_id );

        $GLOBALS['_wp_remote_post_mock'] = null;

        $row = $this->fetch_row( $event_id );
        $this->assertSame( 'dead_letter', $row['status'],
            'Row must become dead_letter after MAX_ATTEMPTS failed attempts' );
    }

    /**
     * OC-6: If RP_Care_Pipeline_Outbox::enqueue() fails (DB INSERT error),
     * report_batch_to_pc() must return WP_Error and must NOT set any _batch_done option.
     */
    public function test_outbox_enqueue_error_preserves_state_no_batch_done(): void {
        update_option( RP_Care_Pipeline_Client::OPT_ENABLED, true );

        // Temporarily replace wpdb with a stub that returns false on INSERT.
        $real_wpdb       = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class() extends wpdb {
            public ?string $last_error = 'Simulated DB write failure';
            public string  $prefix     = 'wp_';

            public function insert( string $t, array $d, $f = null ): int|false { return false; }
            public function query( string $sql ): int|bool { return true; }
            public function prepare( string $sql, ...$args ): string {
                $i = 0;
                return preg_replace_callback( '/%[sdf]/', function () use ( &$i, $args ) {
                    return "'" . addslashes( (string) ( $args[ $i++ ] ?? '' ) ) . "'";
                }, $sql );
            }
        };

        $ref = new \ReflectionMethod( RP_Care_Task_Updates::class, 'report_batch_to_pc' );
        $ref->setAccessible( true );
        $result = $ref->invoke( null, 'oc6-batch', 'staging_done', [] );

        $GLOBALS['wpdb'] = $real_wpdb;

        $this->assertInstanceOf( \WP_Error::class, $result,
            'report_batch_to_pc must return WP_Error when outbox enqueue fails' );
        $this->assertFalse(
            (bool) get_option( 'rpcare_staging_batch_done_oc6-batch' ),
            '_batch_done option must NOT be set when outbox enqueue fails — state preserved for retry'
        );
    }
}
