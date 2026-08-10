<?php
/**
 * Replanta Care — Integration Anomaly Detector
 *
 * Correlates errors from WooCommerce integrations to detect systemic anomalies
 * that transcend individual log entries. Designed around observable failure
 * families such as "Commands out of sync" in MySQL drivers, payment gateway
 * timeouts, webhook delivery failures, and Action Scheduler queue saturation.
 *
 * DESIGN PRINCIPLES
 * ─────────────────
 * • Never attribute cause to a specific plugin solely by temporal proximity.
 * • Detect patterns across multiple errors, not single events.
 * • Threshold-based: only escalate when a pattern exceeds a count within a window.
 * • Never auto-disable gateways, tracking, or integrations in production.
 * • All escalations route through plugin-center approval before any production change.
 * • Evidence is preserved in redacted form; no PII, no order data, no credentials.
 *
 * TRACKED ANOMALY FAMILIES
 * ────────────────────────
 * MYSQL_SYNC   "Commands out of sync" — MySQL protocol desync; usually indicates
 *              a PHP/MySQL driver bug or a stored procedure misuse.
 * GATEWAY_FAIL Payment gateway timeout or 5xx cluster (not isolated events).
 * CHECKOUT_ERR Checkout validation errors occurring at high frequency.
 * AS_SATURATION Action Scheduler queue growing without processing.
 * WEBHOOK_FAIL  Persistent webhook delivery failures.
 * REST_ERROR   WooCommerce REST API returning 5xx above a threshold.
 *
 * @since 1.15.74
 */

defined( 'ABSPATH' ) || exit;

class RP_Care_Integration_Detector {

    /** Transient prefix for anomaly counters (per family, per window). */
    const COUNTER_PREFIX = 'care_anomaly_';

    /** Default detection window in seconds (1 hour). */
    const DEFAULT_WINDOW_SECONDS = 3600;

    /** Option key for active anomaly records. */
    const ANOMALIES_KEY = 'care_active_anomalies';

    /**
     * Anomaly family definitions.
     *
     * threshold = occurrences within window_seconds to trigger escalation.
     */
    private const FAMILIES = [
        'mysql_sync' => [
            'threshold'      => 3,
            'window_seconds' => 3600,
            'escalate_at'    => 5,
            'patterns'       => [
                'commands out of sync',
                'mysql server has gone away',
                'lost connection to mysql',
                'deadlock found',
            ],
        ],
        'gateway_fail' => [
            'threshold'      => 5,
            'window_seconds' => 1800,
            'escalate_at'    => 10,
            'patterns'       => [
                'payment failed',
                'gateway error',
                'connection timed out',
                'curl error 28',
                'refused to connect',
            ],
        ],
        'checkout_err' => [
            'threshold'      => 20,
            'window_seconds' => 3600,
            'escalate_at'    => 50,
            'patterns'       => [
                'woocommerce_checkout_process',
                'checkout validation failed',
                'sorry, your session has expired',
                'invalid nonce',
            ],
        ],
        'as_saturation' => [
            'threshold'      => 1,     // any detection triggers this
            'window_seconds' => 3600,
            'escalate_at'    => 1,
            'patterns'       => [],    // detected by direct queue check, not patterns
        ],
        'webhook_fail' => [
            'threshold'      => 5,
            'window_seconds' => 3600,
            'escalate_at'    => 15,
            'patterns'       => [
                'webhook delivery failed',
                'webhook request failed',
                'http 4',
                'http 5',
            ],
        ],
        'rest_error' => [
            'threshold'      => 10,
            'window_seconds' => 1800,
            'escalate_at'    => 25,
            'patterns'       => [
                'rest_api_error',
                'rest_post_dispatch',
                'json error',
            ],
        ],
    ];

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Observe a raw error string. Classify it into known families, increment
     * counters, and return any newly detected anomalies.
     *
     * @param  string $message  Raw error or log message (will be redacted).
     * @param  array  $context  Optional structured context.
     * @return array[]          Newly detected anomalies (empty = no new detection).
     */
    public static function observe( string $message, array $context = [] ): array {
        $lower = strtolower( $message );
        $detected = [];

        foreach ( self::FAMILIES as $family => $def ) {
            if ( empty( $def['patterns'] ) ) {
                continue; // Pattern-less families are checked via direct methods.
            }

            foreach ( $def['patterns'] as $pattern ) {
                if ( str_contains( $lower, $pattern ) ) {
                    $count = self::increment_counter( $family, $def['window_seconds'] );
                    if ( $count >= $def['threshold'] ) {
                        $anomaly = self::build_anomaly( $family, $count, $def, $message, $context );
                        self::record_anomaly( $anomaly );
                        $detected[] = $anomaly;
                    }
                    break; // One match per family per observe() call.
                }
            }
        }

        return $detected;
    }

    /**
     * Check Action Scheduler queue health directly.
     *
     * Returns an anomaly if the pending queue is too large or not processing.
     *
     * @param  int $threshold_pending  Pending actions above this → anomaly. Default 100.
     * @return array|null  Anomaly or null.
     */
    public static function check_as_saturation( int $threshold_pending = 100 ): ?array {
        global $wpdb;
        if ( ! $wpdb ) {
            return null;
        }

        $table = $wpdb->prefix . 'actionscheduler_actions';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $table_exists ) {
            return null;
        }

        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE `status` = 'pending'"
        );

        if ( $pending_count < $threshold_pending ) {
            return null;
        }

        $count   = self::increment_counter( 'as_saturation', self::FAMILIES['as_saturation']['window_seconds'] );
        $anomaly = self::build_anomaly(
            'as_saturation',
            $count,
            self::FAMILIES['as_saturation'],
            "Action Scheduler pending queue size: {$pending_count}",
            [ 'pending_count' => $pending_count ]
        );
        self::record_anomaly( $anomaly );

        return $anomaly;
    }

    /**
     * Return all currently active anomaly records.
     *
     * @return array[]
     */
    public static function get_active_anomalies(): array {
        $data = get_option( self::ANOMALIES_KEY, [] );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Return the current counter for a family (without incrementing).
     */
    public static function get_counter( string $family ): int {
        return (int) get_transient( self::COUNTER_PREFIX . $family );
    }

    /**
     * Reset all anomaly counters and active records (used by tests and after resolution).
     */
    public static function reset(): void {
        foreach ( array_keys( self::FAMILIES ) as $family ) {
            delete_transient( self::COUNTER_PREFIX . $family );
        }
        delete_option( self::ANOMALIES_KEY );
    }

    /**
     * Mark an anomaly as resolved (by its family).
     */
    public static function resolve( string $family ): void {
        $anomalies = self::get_active_anomalies();
        $anomalies = array_values( array_filter( $anomalies, fn( $a ) => ( $a['family'] ?? '' ) !== $family ) );
        update_option( self::ANOMALIES_KEY, $anomalies, false );
        delete_transient( self::COUNTER_PREFIX . $family );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function increment_counter( string $family, int $window_seconds ): int {
        $key     = self::COUNTER_PREFIX . $family;
        $current = (int) get_transient( $key );
        $next    = $current + 1;
        set_transient( $key, $next, $window_seconds );
        return $next;
    }

    private static function build_anomaly( string $family, int $count, array $def, string $message, array $context ): array {
        return [
            'family'        => $family,
            'count'         => $count,
            'threshold'     => $def['threshold'],
            'escalate_at'   => $def['escalate_at'],
            'escalated'     => $count >= $def['escalate_at'],
            'detected_at'   => gmdate( 'c' ),
            'message_hash'  => hash( 'sha256', $message ),
            'context_keys'  => array_keys( $context ),
        ];
    }

    private static function record_anomaly( array $anomaly ): void {
        $anomalies = self::get_active_anomalies();

        // Upsert by family.
        $found = false;
        foreach ( $anomalies as &$existing ) {
            if ( ( $existing['family'] ?? '' ) === $anomaly['family'] ) {
                $existing            = $anomaly; // Update count/escalation.
                $found               = true;
                break;
            }
        }
        unset( $existing );

        if ( ! $found ) {
            $anomalies[] = $anomaly;
        }

        update_option( self::ANOMALIES_KEY, $anomalies, false );
    }
}
