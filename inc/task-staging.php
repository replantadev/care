<?php
/**
 * Staging integration.
 *
 * Detects WP Staging or WP STAGING free plugin, and offers a unified API for the
 * scheduler to spin up a clone before a risky update window.
 * If no plugin is available we fall back to documenting the missing capability
 * in the report so the user can act.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Staging {

    const OPT_LAST = 'rpcare_staging_last_clone';

    public static function detect() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        $candidates = [
            'wp-staging/wp-staging.php',
            'wp-staging-pro/wp-staging-pro.php',
            'wp-stagecoach/wp-stagecoach.php',
            'duplicator/duplicator.php',
            'wp-time-capsule/wp-time-capsule.php',
        ];
        foreach ($candidates as $slug) {
            if (isset($plugins[$slug])) {
                return [
                    'detected' => true,
                    'plugin'   => $slug,
                    'active'   => is_plugin_active($slug),
                ];
            }
        }
        return ['detected' => false];
    }

    public static function create_clone($label = null) {
        if (!RP_Care_Plan::can_access_feature('staging')) {
            return ['skipped' => 'plan_excluded'];
        }

        $detection = self::detect();
        if (empty($detection['detected'])) {
            return ['error' => 'no_staging_plugin', 'message' => 'Instala WP Staging (gratis) para clones automáticos'];
        }
        if (empty($detection['active'])) {
            return ['error' => 'plugin_inactive', 'plugin' => $detection['plugin']];
        }

        $label = $label ?: 'rpcare-' . date('Ymd-His');
        $result = ['plugin' => $detection['plugin'], 'label' => $label];

        if (strpos($detection['plugin'], 'wp-staging') === 0 && class_exists('\WPStaging\Backend\Modules\Jobs\Cloning')) {
            try {
                do_action('wpstg_cloning_start', $label);
                $result['triggered'] = true;
            } catch (\Throwable $e) {
                $result['error'] = $e->getMessage();
            }
        } else {
            $result['triggered'] = false;
            $result['note'] = 'Plugin detectado pero sin hook automático; abre el plugin manualmente';
        }

        update_option(self::OPT_LAST, [
            'when'   => current_time('mysql'),
            'result' => $result,
        ], false);

        if (class_exists('RP_Care_Utils')) {
            RP_Care_Utils::log('staging_clone', 'success', 'Staging clone solicitado', $result);
        }

        return $result;
    }

    public static function status() {
        return [
            'detection' => self::detect(),
            'last_clone'=> get_option(self::OPT_LAST, null),
        ];
    }
}
