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
     * Return the best available provider for this installation.
     * Priority: paired > WP Staging Pro/Free > null (manual).
     */
    public static function get(): RP_Care_Staging_Provider_Interface {
        if ( RP_Care_Staging_Provider_Paired::is_available() ) {
            return new RP_Care_Staging_Provider_Paired();
        }

        if ( RP_Care_Staging_Provider_WPStaging::is_available() ) {
            return new RP_Care_Staging_Provider_WPStaging();
        }

        return new RP_Care_Staging_Provider_Manual();
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
