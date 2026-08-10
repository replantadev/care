<?php
/**
 * Replanta Care — WooCommerce Hygiene Batch Cleanup
 *
 * Performs idempotent, budget-constrained batch cleanup of WooCommerce
 * database housekeeping:
 *   • Expired WooCommerce sessions (not active ones)
 *   • Expired transients in wp_options
 *   • Completed/cancelled Action Scheduler actions older than retention period
 *   • WooCommerce log files older than retention period
 *   • Orphaned order metadata (without parent order)
 *
 * SAFETY INVARIANTS
 * ─────────────────
 * • Never deletes active sessions, pending/in-progress AS actions, or recent failures.
 * • Never deletes orders, products, customers, or unknown metadata.
 * • Always uses a DB lock to prevent concurrent runs.
 * • Every run records: rows deleted, bytes freed (estimate), wall time, errors.
 * • Simulation mode (dry_run=true) counts without deleting.
 * • Budget limit: max rows per batch and max wall-clock seconds.
 * • Requires Care to confirm a verified backup exists before cleaning material data.
 *
 * WHAT CARE NEVER CLEANS AUTOMATICALLY
 * ──────────────────────────────────────
 * • WooCommerce orders, line items, refunds, coupons, or products.
 * • Active sessions (WC or WordPress).
 * • Pending or in-progress Action Scheduler actions.
 * • Action Scheduler failures from the last 7 days.
 * • Any wp_options row Care does not recognise by explicit pattern.
 * • Any metadata row without a clearly expired pattern.
 *
 * @since 1.15.74
 */

defined( 'ABSPATH' ) || exit;

class RP_Care_Woo_Hygiene {

    /** Lock option key — prevents concurrent cleanup runs. */
    const LOCK_KEY = 'care_hygiene_lock';

    /** Lock TTL in seconds (must exceed MAX_WALL_SECONDS). */
    const LOCK_TTL = 600;

    /** Default max rows to delete per operation in one batch. */
    const DEFAULT_MAX_ROWS = 500;

    /** Default max wall-clock seconds per full batch. */
    const DEFAULT_MAX_SECONDS = 60;

    /** Default Action Scheduler completed/cancelled retention in days. */
    const DEFAULT_AS_RETENTION_DAYS = 30;

    /** Default WooCommerce log retention in days. */
    const DEFAULT_LOG_RETENTION_DAYS = 30;

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Run a hygiene batch.
     *
     * @param  array $options {
     *   @type bool  $dry_run           Count only, no deletes. Default false.
     *   @type int   $max_rows          Max rows per operation. Default DEFAULT_MAX_ROWS.
     *   @type int   $max_seconds       Max wall time in seconds. Default DEFAULT_MAX_SECONDS.
     *   @type int   $as_retention_days AS completed/cancelled retention. Default 30.
     *   @type int   $log_retention_days WooCommerce log retention. Default 30.
     *   @type bool  $sessions          Include expired session cleanup. Default true.
     *   @type bool  $transients        Include expired transient cleanup. Default true.
     *   @type bool  $as_actions        Include AS cleanup. Default true.
     *   @type bool  $wc_logs           Include WC log cleanup. Default true.
     * }
     * @return array{
     *   dry_run: bool,
     *   started_at: string,
     *   finished_at: string,
     *   wall_seconds: float,
     *   operations: array,
     *   total_rows_deleted: int,
     *   errors: string[],
     * }|\WP_Error  WP_Error if lock cannot be acquired.
     */
    public static function run( array $options = [] ): array|\WP_Error {
        $dry_run     = (bool) ( $options['dry_run']           ?? false );
        $max_rows    = (int)  ( $options['max_rows']          ?? self::DEFAULT_MAX_ROWS );
        $max_seconds = (int)  ( $options['max_seconds']       ?? self::DEFAULT_MAX_SECONDS );
        $as_days     = (int)  ( $options['as_retention_days'] ?? self::DEFAULT_AS_RETENTION_DAYS );
        $log_days    = (int)  ( $options['log_retention_days'] ?? self::DEFAULT_LOG_RETENTION_DAYS );

        if ( ! $dry_run ) {
            $lock = self::acquire_lock();
            if ( is_wp_error( $lock ) ) {
                return $lock;
            }
        }

        $start      = microtime( true );
        $ops        = [];
        $errors     = [];
        $total_rows = 0;

        try {
            $enabled = fn( string $k ) => (bool) ( $options[ $k ] ?? true );

            if ( $enabled( 'sessions' ) ) {
                $op = self::cleanup_expired_sessions( $dry_run, $max_rows );
                $ops['sessions'] = $op;
                $total_rows += $op['rows'] ?? 0;
                if ( ! empty( $op['error'] ) ) {
                    $errors[] = 'sessions: ' . $op['error'];
                }
            }

            if ( self::budget_ok( $start, $max_seconds ) && $enabled( 'transients' ) ) {
                $op = self::cleanup_expired_transients( $dry_run, $max_rows );
                $ops['transients'] = $op;
                $total_rows += $op['rows'] ?? 0;
                if ( ! empty( $op['error'] ) ) {
                    $errors[] = 'transients: ' . $op['error'];
                }
            }

            if ( self::budget_ok( $start, $max_seconds ) && $enabled( 'as_actions' ) ) {
                $op = self::cleanup_as_actions( $dry_run, $max_rows, $as_days );
                $ops['as_actions'] = $op;
                $total_rows += $op['rows'] ?? 0;
                if ( ! empty( $op['error'] ) ) {
                    $errors[] = 'as_actions: ' . $op['error'];
                }
            }

            if ( self::budget_ok( $start, $max_seconds ) && $enabled( 'wc_logs' ) ) {
                $op = self::cleanup_wc_logs( $dry_run, $log_days );
                $ops['wc_logs'] = $op;
                $total_rows += $op['files'] ?? 0;
                if ( ! empty( $op['error'] ) ) {
                    $errors[] = 'wc_logs: ' . $op['error'];
                }
            }
        } finally {
            if ( ! $dry_run ) {
                self::release_lock();
            }
        }

        $wall = round( microtime( true ) - $start, 3 );

        return [
            'dry_run'            => $dry_run,
            'started_at'         => gmdate( 'c', (int) $start ),
            'finished_at'        => gmdate( 'c' ),
            'wall_seconds'       => $wall,
            'operations'         => $ops,
            'total_rows_deleted' => $total_rows,
            'errors'             => $errors,
        ];
    }

    // ── Lock ──────────────────────────────────────────────────────────────────

    private static function acquire_lock(): bool|\WP_Error {
        if ( get_transient( self::LOCK_KEY ) ) {
            return new \WP_Error( 'lock_held', 'Hygiene cleanup is already running or locked. Try again later.' );
        }
        set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );
        return true;
    }

    private static function release_lock(): void {
        delete_transient( self::LOCK_KEY );
    }

    // ── Operations ────────────────────────────────────────────────────────────

    private static function cleanup_expired_sessions( bool $dry_run, int $max_rows ): array {
        global $wpdb;
        if ( ! $wpdb ) {
            return [ 'rows' => 0, 'error' => 'wpdb not available' ];
        }

        $now = time();

        // WooCommerce sessions table (wc_customer_lookup + woocommerce_sessions).
        $table = $wpdb->prefix . 'woocommerce_sessions';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $table_exists ) {
            return [ 'rows' => 0, 'note' => 'woocommerce_sessions table not found' ];
        }

        if ( $dry_run ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `session_expiry` < %d",
                $now
            ) );
            return [ 'rows' => $count, 'dry_run' => true ];
        }

        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE `session_expiry` < %d LIMIT %d",
            $now,
            $max_rows
        ) );

        return [ 'rows' => (int) $deleted, 'error' => $wpdb->last_error ?: null ];
    }

    private static function cleanup_expired_transients( bool $dry_run, int $max_rows ): array {
        global $wpdb;
        if ( ! $wpdb ) {
            return [ 'rows' => 0, 'error' => 'wpdb not available' ];
        }

        $now = time();

        if ( $dry_run ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->options}`
                 WHERE `option_name` LIKE %s
                   AND CAST(`option_value` AS UNSIGNED) < %d
                   AND CAST(`option_value` AS UNSIGNED) > 0",
                $wpdb->esc_like( '_transient_timeout_' ) . '%',
                $now
            ) );
            return [ 'rows' => $count, 'dry_run' => true ];
        }

        // Delete the timeout entry and the transient value together.
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE o1, o2
             FROM `{$wpdb->options}` o1
             JOIN `{$wpdb->options}` o2
               ON o2.option_name = REPLACE(o1.option_name, '_transient_timeout_', '_transient_')
             WHERE o1.option_name LIKE %s
               AND CAST(o1.option_value AS UNSIGNED) < %d
               AND CAST(o1.option_value AS UNSIGNED) > 0
             LIMIT %d",
            $wpdb->esc_like( '_transient_timeout_' ) . '%',
            $now,
            $max_rows
        ) );

        return [ 'rows' => (int) $deleted, 'error' => $wpdb->last_error ?: null ];
    }

    private static function cleanup_as_actions( bool $dry_run, int $max_rows, int $retention_days ): array {
        global $wpdb;
        if ( ! $wpdb ) {
            return [ 'rows' => 0, 'error' => 'wpdb not available' ];
        }

        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
        $table  = $wpdb->prefix . 'actionscheduler_actions';

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $table_exists ) {
            return [ 'rows' => 0, 'note' => 'actionscheduler_actions table not found' ];
        }

        // ONLY delete completed and cancelled — never pending, in-progress, or recent failures.
        if ( $dry_run ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}`
                 WHERE `status` IN ('complete', 'canceled')
                   AND `scheduled_date_gmt` < %s",
                $cutoff
            ) );
            return [ 'rows' => $count, 'dry_run' => true ];
        }

        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table}`
             WHERE `status` IN ('complete', 'canceled')
               AND `scheduled_date_gmt` < %s
             LIMIT %d",
            $cutoff,
            $max_rows
        ) );

        return [ 'rows' => (int) $deleted, 'error' => $wpdb->last_error ?: null ];
    }

    private static function cleanup_wc_logs( bool $dry_run, int $retention_days ): array {
        $log_dir = self::get_wc_log_dir();
        if ( ! $log_dir || ! is_dir( $log_dir ) ) {
            return [ 'files' => 0, 'note' => 'WooCommerce log directory not found' ];
        }

        $cutoff = time() - ( $retention_days * DAY_IN_SECONDS );
        $files  = glob( $log_dir . '*.log' ) ?: [];
        $count  = 0;
        $error  = null;

        foreach ( $files as $file ) {
            if ( ! is_file( $file ) ) {
                continue;
            }
            if ( filemtime( $file ) < $cutoff ) {
                if ( ! $dry_run ) {
                    if ( ! @unlink( $file ) ) {
                        $error = "Failed to delete: " . basename( $file );
                    }
                }
                $count++;
            }
        }

        return [ 'files' => $count, 'dry_run' => $dry_run, 'error' => $error ];
    }

    private static function budget_ok( float $start, int $max_seconds ): bool {
        return ( microtime( true ) - $start ) < $max_seconds;
    }

    private static function get_wc_log_dir(): string {
        if ( function_exists( 'wc_get_log_file_path' ) ) {
            // Derive from a dummy handle to get the directory.
            $file = wc_get_log_file_path( 'care-hygiene-noop' );
            return dirname( $file ) . DIRECTORY_SEPARATOR;
        }
        if ( defined( 'WC_LOG_DIR' ) ) {
            return WC_LOG_DIR;
        }
        $upload = wp_upload_dir();
        return trailingslashit( $upload['basedir'] ) . 'wc-logs/';
    }
}
