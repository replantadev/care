<?php
/**
 * Staging integration.
 *
 * Detects WP Staging (free/pro) and offers a unified clone API.
 * When no staging plugin is found, auto-installs WP Staging Free from WordPress.org.
 * Falls back gracefully when WP Staging cannot be triggered automatically.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Staging {

    const OPT_LAST = 'rpcare_staging_last_clone';

    private static function is_allowed(): bool {
        if (RP_Care_Plan::can_access_feature('staging')) {
            return true;
        }

        if (class_exists('RP_Care_Addon_Manager')) {
            $addons   = RP_Care_Addon_Manager::get();
            $ecom_cfg = $addons->get_config('ecommerce');
            return $addons->is_active('ecommerce') && !empty($ecom_cfg['staging_required']);
        }

        return false;
    }

    public static function detect(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();

        // Priority: Pro first (has better API), then Free, then alternatives
        $candidates = [
            'wp-staging-pro/wp-staging-pro.php',
            'wp-staging/wp-staging.php',
            'duplicator/duplicator.php',
            'wp-stagecoach/wp-stagecoach.php',
            'wp-time-capsule/wp-time-capsule.php',
        ];

        foreach ($candidates as $slug) {
            if (isset($plugins[$slug])) {
                return [
                    'detected' => true,
                    'plugin'   => $slug,
                    'active'   => is_plugin_active($slug),
                    'name'     => $plugins[$slug]['Name'] ?? $slug,
                    'version'  => $plugins[$slug]['Version'] ?? '',
                ];
            }
        }
        return ['detected' => false];
    }

    public static function create_clone(?string $label = null): array {
        if (!self::is_allowed()) {
            return ['skipped' => 'plan_excluded'];
        }

        $label     = $label ?: 'rpcare-' . gmdate('Ymd-His');
        $detection = self::detect();

        // Auto-install WP Staging Free when nothing is present
        if (empty($detection['detected'])) {
            $install = self::auto_install_wpstaging();
            if (is_wp_error($install)) {
                $result = [
                    'triggered' => false,
                    'error'     => 'install_failed',
                    'message'   => 'WP Staging no encontrado. Auto-instalación falló: ' . $install->get_error_message(),
                ];
                self::_store_and_log($result);
                return $result;
            }
            $detection = self::detect();
            if (empty($detection['detected'])) {
                $result = ['triggered' => false, 'error' => 'detect_after_install_failed'];
                self::_store_and_log($result);
                return $result;
            }
        }

        // Activate plugin if installed but inactive
        if (empty($detection['active']) && !empty($detection['plugin'])) {
            $activated = activate_plugin($detection['plugin']);
            if (!is_wp_error($activated)) {
                $detection['active'] = true;
            }
        }

        $result = ['plugin' => $detection['plugin'] ?? 'none', 'label' => $label];

        if (!empty($detection['active'])) {
            $plugin = $detection['plugin'] ?? '';
            if (str_starts_with($plugin, 'wp-staging')) {
                $result = array_merge($result, self::trigger_wpstaging($label, $plugin));
            } else {
                $result['triggered'] = false;
                $result['note']      = 'Plugin ' . $plugin . ' detectado pero sin API automática; inicia el clon manualmente.';
            }
        } else {
            $result['triggered'] = false;
            $result['note']      = 'WP Staging instalado pero no activo; actívalo desde Plugins.';
        }

        self::_store_and_log($result);
        return $result;
    }

    /**
     * Attempt to trigger a WP Staging clone programmatically.
     *
     * WP Staging does not expose a stable public PHP API for clone creation.
     * This method tries the best available entry point for each edition:
     *  - Pro 3+: uses the wpstg_create_clone action if registered.
     *  - Free/Pro: fires the wp_ajax_wpstg_start_cloning hook in-process.
     *  - Fallback: returns triggered=false with a human-readable note so the gate degrades gracefully.
     */
    private static function trigger_wpstaging(string $label, string $plugin): array {
        $is_pro = str_contains($plugin, 'pro') || defined('WPSTGPRO_VERSION');

        // WP Staging Pro 3+ action hook
        if ($is_pro && has_action('wpstg_create_clone')) {
            try {
                do_action('wpstg_create_clone', ['cloneName' => $label]);
                return ['triggered' => true, 'method' => 'wpstg_create_clone_action', 'async' => true];
            } catch (\Throwable $e) {
                // Fall through to next method
            }
        }

        // Both editions: fire their WP-Admin AJAX handler directly.
        // Guards against output and wp_die via output buffering.
        if (has_action('wp_ajax_wpstg_start_cloning')) {
            $saved_post = $_POST;
            $_POST = [
                'cloneID'            => '',
                'cloneName'          => sanitize_title($label),
                'cloneDirectoryName' => sanitize_title($label),
                'directoryName'      => sanitize_title($label),
            ];

            $triggered = false;
            ob_start();
            try {
                // Neutralise wp_die() so the AJAX handler cannot kill our process
                add_filter('wp_die_ajax_handler', static function () {
                    return static function () {};
                }, 99);
                do_action('wp_ajax_wpstg_start_cloning');
                $triggered = true;
            } catch (\Throwable $e) {
                // ignore — output already captured
            } finally {
                ob_end_clean();
                $_POST = $saved_post;
            }

            if ($triggered) {
                return ['triggered' => true, 'method' => 'wp_ajax_wpstg_start_cloning', 'async' => true];
            }
        }

        // Graceful degradation: gate will fire, snapshot will be captured, but clone must be manual
        return [
            'triggered' => false,
            'note'      => 'WP Staging activo pero sin API automática disponible. ' .
                           'Crea manualmente un clon en WP Staging con el nombre: ' . $label,
        ];
    }

    /**
     * Install WP Staging Free from WordPress.org plugin repository.
     *
     * @return true|\WP_Error
     */
    private static function auto_install_wpstaging() {
        if (!current_user_can('install_plugins')) {
            return new \WP_Error('no_caps', 'Sin permisos para instalar plugins');
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $api = plugins_api('plugin_information', [
            'slug'   => 'wp-staging',
            'fields' => ['sections' => false, 'reviews' => false],
        ]);

        if (is_wp_error($api)) {
            return $api;
        }

        if (empty($api->download_link)) {
            return new \WP_Error('no_download', 'No se pudo obtener el enlace de descarga de WP Staging');
        }

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result   = $upgrader->install($api->download_link);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result === false || $result === null) {
            $errors = $skin->get_errors();
            $msg    = is_wp_error($errors) ? $errors->get_error_message() : 'Instalación falló sin mensaje de error';
            return new \WP_Error('install_failed', $msg);
        }

        RP_Care_Utils::log('staging', 'info', 'WP Staging Free auto-instalado correctamente');
        return true;
    }

    public static function status(): array {
        return [
            'detection'  => self::detect(),
            'last_clone' => get_option(self::OPT_LAST, null),
        ];
    }

    private static function _store_and_log(array $result): void {
        update_option(self::OPT_LAST, ['when' => current_time('mysql'), 'result' => $result], false);
        if (class_exists('RP_Care_Utils')) {
            $level = empty($result['error']) ? 'info' : 'error';
            RP_Care_Utils::log('staging_clone', $level, 'Staging clone solicitado', $result);
        }
    }
}
