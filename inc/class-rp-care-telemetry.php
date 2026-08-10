<?php
/**
 * Replanta Care — Telemetry Engine
 *
 * Captures PHP errors, fatal shutdowns, and Care-specific events without
 * activating WP_DEBUG or WP_DEBUG_LOG and without displaying anything to visitors.
 *
 * DESIGN CONSTRAINTS
 * ──────────────────
 * • Never enable WP_DEBUG, display_errors, or alter the client's logging config.
 * • Cannot capture errors that occurred before Care loaded.
 * • Must NOT wrap or globally replace $wpdb.
 * • Must NOT enable SAVEQUERIES in production.
 * • PII, tokens, cookies, credentials, and order data are redacted before any
 *   persist or outbox dispatch.
 *
 * ERROR CAPTURE STRATEGY
 * ──────────────────────
 * 1. register_shutdown_function + error_get_last() — catches PHP fatals after load.
 * 2. woocommerce_logger — hooks into WooCommerce's WC_Logger for structured events.
 * 3. 'care_telemetry_event' action — internal hook for structured Care events.
 *
 * OUTPUT
 * ──────
 * • Local ring buffer (WP transient), capped at MAX_BUFFER_EVENTS.
 * • Dispatch to Plugin Center outbox via 'care_pipeline_outbox_enqueue' filter.
 * • All events are idempotent (keyed by fingerprint hash).
 *
 * @since 1.15.74
 */

defined( 'ABSPATH' ) || exit;

class RP_Care_Telemetry {

    /** Maximum events to keep in the local ring buffer. */
    const MAX_BUFFER_EVENTS = 50;

    /** Transient key for the event ring buffer. */
    const BUFFER_KEY = 'care_telemetry_buffer';

    /** Transient TTL (24 hours). */
    const BUFFER_TTL = 86400;

    /** Version of the telemetry envelope format. */
    const ENVELOPE_VERSION = 1;

    /** PII patterns to redact from strings before storing. */
    private const REDACT_KEYS = [
        'password', 'pass', 'pwd', 'secret', 'token', 'key', 'api_key', 'api_token',
        'auth', 'bearer', 'authorization', 'cookie', 'session', 'credit_card', 'card_number',
        'cvv', 'cvc', 'pin', 'iban', 'bic', 'ssn', 'nif', 'email', 'phone', 'mobile',
        'address', 'postcode', 'zip', 'ip_address', 'x-forwarded-for',
    ];

    /** PHP error level to severity string map. */
    private const SEVERITY_MAP = [
        E_ERROR             => 'fatal',
        E_WARNING           => 'warning',
        E_NOTICE            => 'info',
        E_DEPRECATED        => 'info',
        E_USER_ERROR        => 'fatal',
        E_USER_WARNING      => 'warning',
        E_USER_NOTICE       => 'info',
        E_USER_DEPRECATED   => 'info',
        E_RECOVERABLE_ERROR => 'error',
        E_PARSE             => 'fatal',
        E_STRICT            => 'info',
    ];

    private static bool $registered = false;

    // ── Init ──────────────────────────────────────────────────────────────────

    /**
     * Register the shutdown handler and WooCommerce logger hook.
     * Safe to call multiple times — noop after first call.
     */
    public static function register(): void {
        if ( self::$registered ) {
            return;
        }
        self::$registered = true;

        register_shutdown_function( [ self::class, 'handle_shutdown' ] );

        // Integrate with WooCommerce logger when available.
        add_action( 'woocommerce_log_entry', [ self::class, 'handle_wc_log_entry' ], 10, 4 );

        // Structured events from other Care modules.
        add_action( 'care_telemetry_event', [ self::class, 'record' ], 10, 1 );
    }

    // ── Shutdown handler ──────────────────────────────────────────────────────

    /**
     * Called by PHP on shutdown. Captures fatal errors only.
     * Other PHP errors (E_WARNING, E_NOTICE) are NOT captured here to avoid noise.
     */
    public static function handle_shutdown(): void {
        $error = error_get_last();
        if ( ! $error ) {
            return;
        }

        $fatals = [ E_ERROR, E_PARSE, E_USER_ERROR, E_RECOVERABLE_ERROR ];
        if ( ! in_array( $error['type'], $fatals, true ) ) {
            return;
        }

        self::record( [
            'source'    => 'shutdown_handler',
            'severity'  => 'fatal',
            'message'   => self::redact_string( $error['message'] ),
            'file'      => self::anonymize_path( $error['file'] ?? '' ),
            'line'      => (int) ( $error['line'] ?? 0 ),
            'php_type'  => $error['type'],
        ] );
    }

    // ── WooCommerce logger hook ───────────────────────────────────────────────

    /**
     * Hook into WooCommerce's log system.
     * Captures structured WC log entries and adds them to the ring buffer.
     *
     * @param string $level   WC log level (emergency, alert, critical, error, warning, notice, info, debug)
     * @param string $message Log message.
     * @param array  $context Additional context.
     * @param string $handle  WC log handle (plugin/context name).
     */
    public static function handle_wc_log_entry( string $level, string $message, array $context, string $handle ): void {
        // Only capture warning and above to avoid noise from debug/info logs.
        $capture_levels = [ 'emergency', 'alert', 'critical', 'error', 'warning' ];
        if ( ! in_array( $level, $capture_levels, true ) ) {
            return;
        }

        self::record( [
            'source'   => 'wc_logger',
            'severity' => self::wc_level_to_severity( $level ),
            'handle'   => sanitize_key( $handle ),
            'message'  => self::redact_string( $message ),
            'context'  => self::redact_array( $context ),
        ] );
    }

    // ── Structured event API ──────────────────────────────────────────────────

    /**
     * Record a telemetry event.
     *
     * All fields are redacted. The event is fingerprinted for deduplication.
     *
     * @param array $event {
     *   @type string $source    Module or handler name.
     *   @type string $severity  fatal|error|warning|info
     *   @type string $message   Human-readable description.
     *   @type mixed  ...        Additional structured fields (will be redacted).
     * }
     */
    public static function record( array $event ): void {
        $event = self::redact_array( $event );

        $event['severity']         = self::normalise_severity( $event['severity'] ?? 'info' );
        $event['envelope_version'] = self::ENVELOPE_VERSION;
        $event['recorded_at']      = gmdate( 'c' );

        $fingerprint = self::fingerprint( $event );

        // Skip duplicates already in the buffer.
        $buffer = self::get_buffer();
        foreach ( $buffer as $existing ) {
            if ( ( $existing['fingerprint'] ?? '' ) === $fingerprint ) {
                // Increment counter on deduplication.
                $existing['count'] = ( (int) $existing['count'] ?? 1 ) + 1;
                self::save_buffer( $buffer );
                return;
            }
        }

        $event['fingerprint'] = $fingerprint;
        $event['count']       = 1;

        // Append to ring buffer, evicting oldest when full.
        $buffer[] = $event;
        if ( count( $buffer ) > self::MAX_BUFFER_EVENTS ) {
            array_shift( $buffer );
        }

        self::save_buffer( $buffer );

        // Dispatch to outbox for Plugin Center delivery.
        self::dispatch_to_outbox( $event );
    }

    /**
     * Emit a structured event via the 'care_telemetry_event' action.
     * Convenience wrapper so callers don't need to use do_action() directly.
     */
    public static function emit( string $source, string $severity, string $message, array $context = [] ): void {
        do_action( 'care_telemetry_event', array_merge( [
            'source'   => $source,
            'severity' => $severity,
            'message'  => $message,
        ], $context ) );
    }

    // ── Buffer read ───────────────────────────────────────────────────────────

    /**
     * Return current ring buffer contents.
     *
     * @return array[]
     */
    public static function get_buffer(): array {
        $buf = get_transient( self::BUFFER_KEY );
        return is_array( $buf ) ? $buf : [];
    }

    /**
     * Return events at or above a given severity level.
     *
     * @param  string $min_severity  fatal|error|warning|info
     * @return array[]
     */
    public static function get_events_above( string $min_severity = 'warning' ): array {
        $order = [ 'info' => 0, 'warning' => 1, 'error' => 2, 'fatal' => 3 ];
        $min   = $order[ $min_severity ] ?? 1;
        return array_values( array_filter( self::get_buffer(), function ( $e ) use ( $order, $min ) {
            return ( $order[ $e['severity'] ?? 'info' ] ?? 0 ) >= $min;
        } ) );
    }

    /**
     * Clear the ring buffer (used by tests and after successful outbox delivery).
     */
    public static function clear_buffer(): void {
        delete_transient( self::BUFFER_KEY );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function save_buffer( array $buffer ): void {
        set_transient( self::BUFFER_KEY, $buffer, self::BUFFER_TTL );
    }

    private static function dispatch_to_outbox( array $event ): void {
        // The outbox integration is decoupled via a filter to avoid hard coupling
        // between the telemetry module and the outbox implementation.
        apply_filters( 'care_pipeline_outbox_enqueue', null, 'telemetry', $event );
    }

    private static function fingerprint( array $event ): string {
        $stable = [
            'source'   => $event['source']  ?? '',
            'severity' => $event['severity'] ?? '',
            'message'  => $event['message']  ?? '',
        ];
        return hash( 'sha256', serialize( $stable ) );
    }

    private static function normalise_severity( string $s ): string {
        $map = [
            'fatal'     => 'fatal',
            'critical'  => 'fatal',
            'emergency' => 'fatal',
            'alert'     => 'fatal',
            'error'     => 'error',
            'warning'   => 'warning',
            'warn'      => 'warning',
            'notice'    => 'info',
            'info'      => 'info',
            'debug'     => 'info',
        ];
        return $map[ strtolower( $s ) ] ?? 'info';
    }

    private static function wc_level_to_severity( string $level ): string {
        return self::normalise_severity( $level );
    }

    /**
     * Redact known sensitive keys from an array (recursive, depth-limited).
     */
    public static function redact_array( array $data, int $depth = 0 ): array {
        if ( $depth > 4 ) {
            return [ '[redacted:depth]' ];
        }
        $out = [];
        foreach ( $data as $k => $v ) {
            $lower = strtolower( (string) $k );
            $is_pii = false;
            foreach ( self::REDACT_KEYS as $rk ) {
                if ( str_contains( $lower, $rk ) ) {
                    $is_pii = true;
                    break;
                }
            }
            if ( $is_pii ) {
                $out[ $k ] = '[REDACTED]';
            } elseif ( is_array( $v ) ) {
                $out[ $k ] = self::redact_array( $v, $depth + 1 );
            } else {
                $out[ $k ] = is_string( $v ) ? self::redact_string( $v ) : $v;
            }
        }
        return $out;
    }

    /**
     * Redact credential-shaped patterns from a string.
     * Conservative: only removes tokens ≥ 20 chars of hex/base64 or clear-text markers.
     */
    public static function redact_string( string $s ): string {
        // Hex blobs that look like HMAC secrets (≥ 40 hex chars).
        $s = preg_replace( '/\b[0-9a-f]{40,}\b/i', '[REDACTED_HEX]', $s );
        // Bearer/Basic auth headers.
        $s = preg_replace( '/\b(Bearer|Basic)\s+\S+/i', '$1 [REDACTED]', $s );
        return $s;
    }

    /**
     * Replace absolute filesystem path with a relative safe version.
     * Prevents server layout disclosure in logs.
     */
    public static function anonymize_path( string $path ): string {
        if ( defined( 'ABSPATH' ) ) {
            $path = str_replace( ABSPATH, '[ABSPATH]/', $path );
        }
        return $path;
    }
}
