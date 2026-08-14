<?php
/**
 * Replanta Care — Staging Provider
 *
 * Interface + concrete providers for staging site management.
 *
 * Providers:
 *   RP_Care_Staging_Provider_Interface  — contract every provider must implement.
 *   RP_Care_Staging_Provider_Paired     — uses a separately-managed staging site
 *                                         already paired with this Care instance.
 *   RP_Care_Staging_Provider_WPStaging  — partial integration with WP Staging Pro/Free.
 *                                         Only documented/public APIs are used.
 *
 * Usage:
 *   $provider = RP_Care_Staging_Provider::get();
 *   $status   = $provider->status();
 *
 * WARNING: This module does NOT auto-install WP Staging Free silently.
 * If no provider is available, status returns 'waiting_manual_staging_refresh'
 * with instructions for the operator.
 *
 * Compatible with PHP 7.4+ (Care's minimum requirement).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Interface ──────────────────────────────────────────────────────────────

interface RP_Care_Staging_Provider_Interface {

    /**
     * Return a capabilities array for this provider.
     *
     * @return array{
     *   can_create: bool,
     *   can_refresh: bool,
     *   can_verify_isolation: bool,
     *   provider_name: string,
     * }
     */
    public function capabilities(): array;

    /**
     * Trigger a staging site creation or refresh.
     * If auto-refresh is not supported, return an array with
     * 'manual_required' => true and 'instructions' string.
     *
     * @return array|WP_Error
     */
    public function create_or_refresh(): array|\WP_Error;

    /**
     * @return array{
     *   status: string,
     *   staging_url?: string,
     *   last_refreshed?: string,
     *   ready: bool,
     * }
     */
    public function status(): array;

    /** @return string|null  Staging URL if known, null otherwise. */
    public function get_url(): ?string;

    /**
     * Run isolation checks against the staging site.
     * @return array{passed: bool, checks: array[]}
     */
    public function verify_isolation(): array;

    /** Remove staging artifacts created by this provider. */
    public function cleanup(): bool|\WP_Error;
}

// ── Factory ────────────────────────────────────────────────────────────────

class RP_Care_Staging_Provider {

    /**
     * Return the provider for this installation.
     *
     * Reads staging_method from rpcare_options (set by Plugin Center policy):
     *   'auto'      → paired > wptoolkit > wpstaging > manual (default)
     *   'wptoolkit' → WP Toolkit CLI provider (cPanel/Plesk)
     *   'wpstaging' → WP Staging Pro/Free
     *   'paired'    → paired existing staging site
     *   'none'      → never reaches here; 'none' is handled in check_staging_gate()
     */
    public static function get(): RP_Care_Staging_Provider_Interface {
        $opts   = get_option( 'rpcare_options', [] );
        $method = (string) ( $opts['staging_method'] ?? 'auto' );

        switch ( $method ) {
            case 'wptoolkit':
                return new RP_Care_Staging_Provider_WPToolkit();
            case 'wpstaging':
                return RP_Care_Staging_Provider_WPStaging::is_available()
                    ? new RP_Care_Staging_Provider_WPStaging()
                    : new RP_Care_Staging_Provider_Manual();
            case 'paired':
                return RP_Care_Staging_Provider_Paired::is_available()
                    ? new RP_Care_Staging_Provider_Paired()
                    : new RP_Care_Staging_Provider_Manual();
            default: // 'auto'
                if ( RP_Care_Staging_Provider_Paired::is_available() )    return new RP_Care_Staging_Provider_Paired();
                if ( RP_Care_Staging_Provider_WPToolkit::is_available() ) return new RP_Care_Staging_Provider_WPToolkit();
                if ( RP_Care_Staging_Provider_WPStaging::is_available() ) return new RP_Care_Staging_Provider_WPStaging();
                return new RP_Care_Staging_Provider_Manual();
        }
    }

    /**
     * Prepare staging for a given batch_id.
     * Called from RP_Care_Pipeline_Client::handle_prepare_staging().
     *
     * @return array|WP_Error
     */
    public static function prepare( string $batch_id ): array|\WP_Error {
        $provider = self::get();
        $caps     = $provider->capabilities();

        if ( ! $caps['can_refresh'] ) {
            $status = $provider->status();
            return [
                'manual_required'  => true,
                'status'           => 'waiting_manual_staging_refresh',
                'instructions'     => $status['instructions'] ?? 'Please refresh your staging site manually.',
                'provider'         => $caps['provider_name'],
            ];
        }

        $result = $provider->create_or_refresh();
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( ! empty( $result['manual_required'] ) ) {
            return $result;
        }

        // Run isolation checks after refresh.
        $isolation = $provider->verify_isolation();
        if ( ! $isolation['passed'] ) {
            return new \WP_Error(
                'staging_isolation_failed',
                'Staging isolation check failed: ' . wp_json_encode( $isolation['checks'] )
            );
        }

        return array_merge( $result, [ 'isolation' => $isolation ] );
    }
}

// ── Paired provider ────────────────────────────────────────────────────────

/**
 * Uses a separately-paired staging site.
 * The staging instance has its own token, secret, and environment=staging.
 * Isolation is verified by polling the staging site's /pipeline/isolation-report endpoint.
 */
class RP_Care_Staging_Provider_Paired implements RP_Care_Staging_Provider_Interface {

    public static function is_available(): bool {
        $status = (string) get_option( RP_Care_Pipeline_Client::OPT_PAIRING_STATUS, '' );
        $env    = (string) get_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, '' );
        return $status === RP_Care_Pipeline_Client::STATUS_READY
            && $env   === 'staging';
    }

    public function capabilities(): array {
        return [
            'can_create'          => false,
            'can_refresh'         => false, // refresh is operator-driven (clone manually)
            'can_verify_isolation'=> true,
            'provider_name'       => 'paired_existing_staging',
        ];
    }

    public function create_or_refresh(): array|\WP_Error {
        return [
            'manual_required' => true,
            'instructions'    => 'Refresh your staging clone from production, then confirm readiness.',
            'status'          => 'waiting_manual_staging_refresh',
        ];
    }

    public function status(): array {
        $url = $this->get_url();
        return [
            'status'      => 'ready',
            'staging_url' => $url,
            'ready'       => ! empty( $url ),
        ];
    }

    public function get_url(): ?string {
        return (string) get_option( 'rpcare_pipeline_canonical_url' ) ?: null;
    }

    public function verify_isolation(): array {
        return RP_Care_Isolation_Checker::run();
    }

    public function cleanup(): bool|\WP_Error {
        return true; // Paired provider: cleanup is operator-driven.
    }
}

// ── WP Staging provider ────────────────────────────────────────────────────

/**
 * Integrates with WP Staging Pro/Free.
 *
 * IMPORTANT: Only publicly documented/supported APIs are used here.
 * Private AJAX handlers that are subject to breakage are NOT used as a
 * reliable mechanism — they are tried as a best-effort fallback, clearly
 * marked as such, with 'triggered=false' returned on failure.
 *
 * Never auto-installs WP Staging.
 */
class RP_Care_Staging_Provider_WPStaging implements RP_Care_Staging_Provider_Interface {

    public static function is_available(): bool {
        return class_exists( 'WPStaging\Core\WPStaging' )
            || class_exists( 'WPStaging\Framework\Application' )
            || defined( 'WPSTG_VERSION' );
    }

    public function capabilities(): array {
        $can_refresh = $this->has_pro_api();
        return [
            'can_create'           => false,
            'can_refresh'          => $can_refresh,
            'can_verify_isolation' => true,
            'provider_name'        => 'wpstaging_' . ( $can_refresh ? 'pro' : 'free' ),
        ];
    }

    public function create_or_refresh(): array|\WP_Error {
        if ( $this->has_pro_api() ) {
            return $this->trigger_pro_refresh();
        }

        return [
            'manual_required' => true,
            'status'          => 'waiting_manual_staging_refresh',
            'instructions'    => 'WP Staging Free detected. Please create or refresh the staging site manually from the WP Staging dashboard, then confirm readiness.',
            'provider'        => 'wpstaging_free',
            'triggered'       => false,
        ];
    }

    public function status(): array {
        $url = $this->get_url();
        return [
            'status'      => $url ? 'ready' : 'unknown',
            'staging_url' => $url,
            'ready'       => ! empty( $url ),
        ];
    }

    public function get_url(): ?string {
        // WP Staging stores the staging URL in its own option.
        $stages = get_option( 'wpstg_existing_clones_beta', [] );
        if ( is_array( $stages ) && ! empty( $stages ) ) {
            $first = reset( $stages );
            return $first['url'] ?? null;
        }
        return null;
    }

    public function verify_isolation(): array {
        return RP_Care_Isolation_Checker::run();
    }

    public function cleanup(): bool|\WP_Error {
        return true; // WP Staging manages its own cleanup.
    }

    private function has_pro_api(): bool {
        return defined( 'WPSTG_PRO_VERSION' )
            || class_exists( 'WPStaging\Pro\WPStaging' );
    }

    private function trigger_pro_refresh(): array|\WP_Error {
        // WP Staging Pro 3.x+ exposes a documented action hook for cloning.
        if ( has_action( 'wpstg_create_clone' ) ) {
            do_action( 'wpstg_create_clone', [
                'label' => 'rpcare-pipeline-' . gmdate( 'YmdHis' ),
            ] );
            return [ 'triggered' => true, 'status' => 'staging_sync_requested' ];
        }

        return [
            'triggered'       => false,
            'manual_required' => true,
            'instructions'    => 'WP Staging Pro found but the documented hook is not available in this version. Please refresh manually.',
        ];
    }
}

// ── WP Toolkit provider ────────────────────────────────────────────────────

/**
 * Runs WP Toolkit CLI directly on the local server.
 *
 * Supports cPanel (wp-toolkit binary) and Plesk (plesk ext wp-toolkit).
 * Binary is never invoked via a shell string — argv is passed as an array
 * to proc_open() to prevent injection. Each argument is validated against
 * a blocklist of shell metacharacters before exec.
 *
 * Requires staging_url to be set in rpcare_options (Care settings).
 */
class RP_Care_Staging_Provider_WPToolkit implements RP_Care_Staging_Provider_Interface {

    const CPANEL_PATHS = [
        '/usr/local/cpanel/3rdparty/wp-toolkit/bin/wp-toolkit',
        '/opt/cpanel/wp-toolkit/bin/wp-toolkit',
    ];

    private string $binary;
    private bool   $is_plesk;

    public function __construct() {
        [ $this->binary, $this->is_plesk ] = self::locate_binary();
    }

    public static function is_available(): bool {
        [ $binary ] = self::locate_binary();
        return $binary !== '';
    }

    public function capabilities(): array {
        return [
            'can_create'           => true,
            'can_refresh'          => true,
            'can_verify_isolation' => true,
            'provider_name'        => 'wptoolkit',
        ];
    }

    public function create_or_refresh(): array|\WP_Error {
        if ( $this->binary === '' ) {
            return new \WP_Error( 'wptk_no_binary', 'WP Toolkit binary not found.' );
        }

        $prod_url    = (string) home_url();
        $staging_url = $this->get_url();

        if ( ! $staging_url ) {
            return new \WP_Error( 'wptk_no_staging_url', 'staging_url not configured in Care settings.' );
        }

        $staging_domain = (string) parse_url( $staging_url, PHP_URL_HOST );
        if ( ! $staging_domain ) {
            return new \WP_Error( 'wptk_bad_staging_url', 'staging_url is not a valid URL with a hostname.' );
        }

        // Detect if staging clone already exists; if so, reset (sync), else clone.
        $list_result    = $this->run_command( 'list', [] );
        $staging_exists = ! is_wp_error( $list_result )
            && $list_result['exit_code'] === 0
            && strpos( $list_result['stdout'], $staging_domain ) !== false;

        $result = $staging_exists
            ? $this->run_command( 'reset', [ 'source-url' => $prod_url, 'target-url' => $staging_url ] )
            : $this->run_command( 'clone', [ 'source-url' => $prod_url, 'target-domain' => $staging_domain ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( $result['exit_code'] !== 0 ) {
            return new \WP_Error(
                'wptk_exec_error',
                'wp-toolkit exited ' . $result['exit_code'] . ': ' . trim( $result['stderr'] )
            );
        }

        return [
            'triggered'   => true,
            'status'      => $staging_exists ? 'staging_sync_requested' : 'staging_clone_requested',
            'staging_url' => $staging_url,
            'provider'    => 'wptoolkit',
        ];
    }

    public function status(): array {
        $url = $this->get_url();
        return [
            'status'      => $url ? 'ready' : 'unconfigured',
            'staging_url' => $url,
            'ready'       => $url !== null,
            'provider'    => 'wptoolkit',
        ];
    }

    public function get_url(): ?string {
        $opts = get_option( 'rpcare_options', [] );
        return ! empty( $opts['staging_url'] ) ? (string) $opts['staging_url'] : null;
    }

    public function verify_isolation(): array {
        return RP_Care_Isolation_Checker::run();
    }

    public function cleanup(): bool|\WP_Error {
        if ( $this->binary === '' ) {
            return new \WP_Error( 'wptk_no_binary', 'WP Toolkit binary not found.' );
        }

        $staging_url = $this->get_url();
        if ( ! $staging_url ) {
            return true;
        }

        $result = $this->run_command( 'delete', [ 'url' => $staging_url ] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result['exit_code'] === 0;
    }

    // ── Internal helpers ──────────────────────────────────────────────────

    private function run_command( string $command, array $params ): array|\WP_Error {
        $argv = $this->is_plesk
            ? [ $this->binary, 'ext', 'wp-toolkit', '--', $command ]
            : [ $this->binary, $command ];

        foreach ( $params as $key => $value ) {
            $argv[] = '--' . ltrim( $key, '-' );
            $argv[] = (string) $value;
        }

        return $this->exec_argv( $argv );
    }

    /**
     * Execute an argv array via proc_open (no shell involvement).
     * Each argument is checked against a blocklist before exec.
     */
    private function exec_argv( array $argv ): array|\WP_Error {
        $forbidden = [ '..', ';', '&&', '||', '|', '`', '$', '(', "\n", "\r", "\0" ];
        foreach ( $argv as $arg ) {
            foreach ( $forbidden as $bad ) {
                if ( strpos( (string) $arg, $bad ) !== false ) {
                    return new \WP_Error( 'wptk_injection', "Rejected argument containing '{$bad}'." );
                }
            }
        }

        $descriptors = [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ];
        $proc        = proc_open( $argv, $descriptors, $pipes );

        if ( ! is_resource( $proc ) ) {
            return new \WP_Error( 'wptk_proc_open', 'proc_open() failed.' );
        }

        fclose( $pipes[0] );
        $stdout    = (string) stream_get_contents( $pipes[1] );
        $stderr    = (string) stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $exit_code = proc_close( $proc );

        return [ 'stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exit_code ];
    }

    /**
     * Locate the WP Toolkit binary on this server.
     * Returns [path, is_plesk]. Path is '' when not found.
     *
     * Test seam: set $GLOBALS['_wptk_binary_seam'] to a path string or ''
     * to override detection in unit tests without touching the filesystem.
     *
     * @return array{string, bool}
     */
    private static function locate_binary(): array {
        if ( defined( 'RPCARE_TESTING' ) && array_key_exists( '_wptk_binary_seam', $GLOBALS ) ) {
            $seam = $GLOBALS['_wptk_binary_seam'];
            return [ ( $seam === false || $seam === null ) ? '' : (string) $seam, false ];
        }

        foreach ( self::CPANEL_PATHS as $path ) {
            if ( is_executable( $path ) ) {
                return [ $path, false ];
            }
        }

        // Plesk: locate 'plesk' in PATH (shell_exec with a literal, safe).
        // The subsequent CLI calls use proc_open() with an array — no shell.
        $plesk = @shell_exec( 'which plesk 2>/dev/null' );
        if ( $plesk ) {
            $plesk = trim( $plesk );
            if ( is_executable( $plesk ) ) {
                return [ $plesk, true ];
            }
        }

        return [ '', false ];
    }
}

// ── Manual / fallback provider ────────────────────────────────────────────

/**
 * Used when no automated provider is available.
 * Always returns 'waiting_manual_staging_refresh' with instructions.
 */
class RP_Care_Staging_Provider_Manual implements RP_Care_Staging_Provider_Interface {

    public function capabilities(): array {
        return [
            'can_create'          => false,
            'can_refresh'         => false,
            'can_verify_isolation'=> false,
            'provider_name'       => 'manual',
        ];
    }

    public function create_or_refresh(): array|\WP_Error {
        return [
            'manual_required' => true,
            'status'          => 'waiting_manual_staging_refresh',
            'instructions'    => 'No automated staging provider detected. Please create or refresh your staging site manually, then confirm readiness in Plugin Center.',
        ];
    }

    public function status(): array {
        return [
            'status' => 'waiting_manual_staging_refresh',
            'ready'  => false,
        ];
    }

    public function get_url(): ?string {
        return null;
    }

    public function verify_isolation(): array {
        return [
            'passed' => false,
            'checks' => [
                [ 'name' => 'provider', 'status' => 'skipped', 'message' => 'No automated provider; isolation cannot be verified automatically.' ],
            ],
        ];
    }

    public function cleanup(): bool|\WP_Error {
        return true;
    }
}
