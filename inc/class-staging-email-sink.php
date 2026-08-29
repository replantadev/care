<?php
/**
 * Replanta Care — Staging Email Sink
 *
 * When a site is identified as a staging environment (OPT_ENVIRONMENT = 'staging'
 * or RPCARE_STAGING_SUPPRESS_EMAIL is defined), this class intercepts wp_mail()
 * calls and either:
 *
 *  a) Redirects them to a configurable sink address (RPCARE_STAGING_EMAIL_SINK
 *     constant or rpcare_staging_email_sink option), or
 *  b) Suppresses them by redirecting to a non-deliverable RFC 2606 address.
 *
 * This prevents staging sites from sending emails to real users.
 *
 * PHP 8.0+ / WP 5.0+.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RP_Care_Staging_Email_Sink {

	const OPT_SINK_ADDRESS = 'rpcare_staging_email_sink';

	public static function is_active(): bool {
		if ( defined( 'RPCARE_STAGING_SUPPRESS_EMAIL' ) && RPCARE_STAGING_SUPPRESS_EMAIL ) {
			return true;
		}
		$env = (string) get_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, '' );
		return $env === 'staging';
	}

	public static function register(): void {
		if ( ! self::is_active() ) {
			return;
		}
		add_filter( 'wp_mail', [ self::class, 'intercept' ], 99 );
		add_action( 'send_headers', [ self::class, 'send_noindex_header' ], 99 );
	}

	/** Defense in depth: every staging response discourages indexing. */
	public static function send_noindex_header(): void {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		}
	}

	/**
	 * Rewrites outgoing mail destinations.
	 *
	 * @param array $args wp_mail() arguments {to, subject, message, headers, attachments}
	 * @return array
	 */
	public static function intercept( array $args ): array {
		$sink = self::get_sink_address();

		if ( $sink !== null ) {
			$args['to']      = $sink;
			$args['subject'] = '[STAGING] ' . ( $args['subject'] ?? '' );
			// Strip CC/BCC from headers to avoid leaking to other addresses.
			$args['headers'] = self::strip_address_headers( $args['headers'] ?? [] );
		} else {
			// No sink configured: redirect to a non-deliverable address.
			// .invalid is IANA-reserved (RFC 2606) and guaranteed never to resolve.
			$args['to']      = 'staging-suppressed@staging.invalid';
			$args['subject'] = '[STAGING SUPPRESSED] ' . ( $args['subject'] ?? '' );
			$args['headers'] = [];
		}

		return $args;
	}

	/**
	 * Returns the configured sink address, or null if none is set.
	 */
	public static function get_sink_address(): ?string {
		if ( defined( 'RPCARE_STAGING_EMAIL_SINK' ) ) {
			$val = constant( 'RPCARE_STAGING_EMAIL_SINK' );
			return is_string( $val ) && $val !== '' ? $val : null;
		}
		$opt = (string) get_option( self::OPT_SINK_ADDRESS, '' );
		return $opt !== '' ? $opt : null;
	}

	/**
	 * Removes CC: and BCC: lines from wp_mail() headers.
	 *
	 * @param string|string[] $headers
	 * @return string[]
	 */
	private static function strip_address_headers( $headers ): array {
		if ( is_string( $headers ) ) {
			$headers = array_filter( array_map( 'trim', explode( "\n", $headers ) ) );
		}
		return array_values( array_filter(
			(array) $headers,
			static fn( string $h ) => ! preg_match( '/^(cc|bcc)\s*:/i', $h )
		) );
	}
}
