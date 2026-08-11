<?php
/**
 * Server environment detection.
 *
 * Identifies the hosting context so Care can adapt its behaviour:
 * - cyberpanel  → Cedro/CyberPanel (full server control)
 * - wptoolkit   → WP Toolkit Pro active on this site
 * - cpanel      → cPanel/WHM without WP Toolkit
 * - local       → localhost / dev environment
 * - external    → any other hosting
 *
 * WP Toolkit detection is INFORMATIONAL only.  It no longer implies
 * that Care must skip its own update task.  The update executor is a
 * separate concern controlled by rpcare_options['update_executor'].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RP_Care_Environment {

	const OPT_KEY = 'rpcare_server_type';

	/**
	 * Returns the detected server type. Result is cached in a WP option
	 * (not a transient) so it survives across requests without an HTTP call.
	 *
	 * Pass $fresh = true to force re-detection (e.g. after migrations).
	 */
	public static function detect( bool $fresh = false ): string {
		if ( ! $fresh ) {
			$cached = get_option( self::OPT_KEY, '' );
			if ( $cached !== '' ) {
				return $cached;
			}
		}

		$type = self::run_detection();
		update_option( self::OPT_KEY, $type, false );
		return $type;
	}

	/** Force re-detection and update the cached value. */
	public static function refresh(): string {
		return self::detect( true );
	}

	private static function run_detection(): string {
		if ( self::is_local() )       return 'local';
		if ( self::is_cyberpanel() )  return 'cyberpanel';
		if ( self::is_wptoolkit() )   return 'wptoolkit';
		if ( self::is_cpanel() )      return 'cpanel';
		return 'external';
	}

	// ── Individual detectors ─────────────────────────────────────────────

	public static function is_cyberpanel(): bool {
		static $result = null;
		if ( $result !== null ) return $result;

		$result = (
			file_exists( '/usr/local/CyberCP' )    ||
			file_exists( '/usr/local/CyberPanel' ) ||
			file_exists( '/etc/cyberpanel' )        ||
			getenv( 'CYBERPANEL_DOMAIN' ) !== false ||
			defined( 'CYBERPANEL_VERSION' )
		);
		return $result;
	}

	/**
	 * WP Toolkit Pro sets identifiable options when it manages a site.
	 * Detection is INFORMATIONAL only — it does not directly control update delegation.
	 */
	public static function is_wptoolkit(): bool {
		// Test seam: allow overriding detection result without WordPress.
		if ( defined( 'RPCARE_TESTING' ) && isset( $GLOBALS['_is_wptoolkit'] ) ) {
			return (bool) $GLOBALS['_is_wptoolkit'];
		}

		static $result = null;
		if ( $result !== null ) return $result;

		$result = (
			class_exists( 'Plesk_WP_Toolkit' )           ||
			defined( 'WPSTK_PLUGIN_VERSION' )             ||
			get_option( 'wpstk_last_scan' ) !== false     ||
			get_option( 'wpstk_settings' ) !== false
		);
		return $result;
	}

	public static function is_cpanel(): bool {
		static $result = null;
		if ( $result !== null ) return $result;

		$result = (
			defined( 'CPANEL_ENV' )                      ||
			function_exists( 'cpanel_api' )              ||
			file_exists( '/usr/local/cpanel' )           ||
			file_exists( '/var/cpanel' )                 ||
			getenv( 'CPANEL_USER' ) !== false
		);
		return $result;
	}

	private static function is_local(): bool {
		$host = $_SERVER['HTTP_HOST'] ?? '';
		return (
			str_contains( $host, 'localhost' ) ||
			str_ends_with( $host, '.local' )   ||
			str_ends_with( $host, '.test' )    ||
			$host === '127.0.0.1'
		);
	}

	// ── Helpers for consuming code ───────────────────────────────────────

	/**
	 * Resolved update executor for this site.
	 *
	 * Priority order:
	 *   1. Explicit rpcare_options['update_executor'] setting (operator override).
	 *   2. Legacy backwards-compat: WP Toolkit detected + no explicit setting
	 *      → 'external' (preserves prior auto-skip behaviour for existing sites).
	 *   3. Default: 'care_pipeline'.
	 *
	 * Valid values: 'care_pipeline' | 'external' | 'disabled'
	 */
	public static function get_update_executor(): string {
		$opts     = get_option( 'rpcare_options', [] );
		$explicit = $opts['update_executor'] ?? '';

		if ( $explicit !== '' ) {
			return in_array( $explicit, [ 'care_pipeline', 'external', 'disabled' ], true )
				? $explicit
				: 'care_pipeline';
		}

		// Backwards-compat: if WP Toolkit is detected but no setting was saved,
		// preserve previous behaviour (skip Care's own task).
		if ( self::is_wptoolkit() ) {
			return 'external';
		}

		return 'care_pipeline';
	}

	/**
	 * Whether an external tool manages updates for this site.
	 *
	 * Checks the explicit 'update_executor' option first; falls back to WP Toolkit
	 * detection for backwards compatibility with sites that never saved the option.
	 *
	 * @deprecated Use get_update_executor() for new code; this wrapper remains for
	 *             legacy callers and still has the correct semantic under the new model.
	 */
	public static function updates_externally_managed(): bool {
		return self::get_update_executor() === 'external';
	}

	/** Whether the native WordPress auto-updater should be disabled by Care. */
	public static function native_auto_updates_disabled(): bool {
		$opts = get_option( 'rpcare_options', [] );
		// Default: disabled (fail-closed).  Must be explicitly opted-in to enable.
		return ( $opts['native_auto_updates'] ?? 'disabled' ) !== 'enabled';
	}

	/**
	 * Structured executor/pipeline status report for Plugin Center drift detection.
	 *
	 * Returns a JSON-serialisable array describing the effective executor configuration
	 * on this Care instance.  Called by the /smart-updates/status REST endpoint.
	 *
	 * SECURITY: Never includes raw tokens, credentials, binary paths, or secrets.
	 */
	public static function get_status_report(): array {
		$opts     = get_option( 'rpcare_options', [] );
		$explicit = $opts['update_executor'] ?? '';

		if ( $explicit !== '' ) {
			$source = 'explicit';
		} elseif ( self::is_wptoolkit() ) {
			$source = 'wptoolkit_fallback';
		} else {
			$source = 'default';
		}

		$executor   = self::get_update_executor();
		$native_raw = $opts['native_auto_updates'] ?? 'disabled';
		$native     = in_array( $native_raw, [ 'disabled', 'enabled' ], true ) ? $native_raw : 'disabled';

		$pipeline = false;
		if ( class_exists( 'RP_Care_Pipeline_Client' ) ) {
			$pipeline = (bool) RP_Care_Pipeline_Client::is_pipeline_enabled();
		}

		return [
			'schema_version'         => 1,
			'generated_at'           => gmdate( 'c' ),
			'plugin_version'         => defined( 'RPCARE_VERSION' ) ? RPCARE_VERSION : '',
			'update_executor'        => $executor,
			'update_executor_source' => $source,
			'native_auto_updates'    => $native,
			'pipeline_enabled'       => $pipeline,
			'staging_role'           => $opts['staging_role'] ?? 'unset',
			'wp_toolkit_detected'    => self::is_wptoolkit(),
			'direct_updates_blocked' => self::native_auto_updates_disabled(),
		];
	}

	/** Human-readable label for UI display. */
	public static function label( ?string $type = null ): string {
		$type = $type ?? self::detect();
		return match ( $type ) {
			'cyberpanel' => 'CyberPanel',
			'wptoolkit'  => 'WP Toolkit',
			'cpanel'     => 'cPanel',
			'local'      => 'Local',
			default      => 'Externo',
		};
	}
}
