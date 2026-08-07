<?php
/**
 * WordPress Updates Task
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Updates {
    
    public static function run($args = []) {
        // When the Staging-First Pipeline is managing updates, block direct Care updates.
        if ( class_exists( 'RP_Care_Pipeline_Client' ) && RP_Care_Pipeline_Client::blocks_direct_updates() ) {
            $msg = 'Pipeline de staging activo — actualizaciones directas bloqueadas hasta aprobación humana.';
            RP_Care_Utils::log( 'updates', 'info', $msg );
            return [ 'success' => true, 'pipeline_blocked' => true, 'message' => $msg, 'skipped' => true ];
        }

        // When Hub/WP Toolkit Pro manages updates for this site, skip Care's own task
        if (get_option('rpcare_update_managed', false)) {
            $msg = 'Actualizaciones gestionadas por Replanta Hub vía WP Toolkit Pro';
            RP_Care_Utils::log('updates', 'info', $msg);
            return [
                'success'         => true,
                'managed_by_hub'  => true,
                'message'         => $msg,
                'skipped'         => true,
            ];
        }

        // Bug #5: Auto-detect WP Toolkit even when Hub never pushed rpcare_update_managed.
        // Prevents Care running its own update loop alongside WP Toolkit on StablePoint sites.
        if ( class_exists( 'RP_Care_Environment' ) && RP_Care_Environment::updates_externally_managed() ) {
            $msg = 'Actualizaciones gestionadas externamente (WP Toolkit detectado automáticamente)';
            RP_Care_Utils::log( 'updates', 'info', $msg );
            return [ 'success' => true, 'managed_externally' => true, 'message' => $msg, 'skipped' => true ];
        }

        $plan       = RP_Care_Plan::get_current();
        $exclusions = RP_Care_Tasks::get_exclusions();

        // Bug #7: Staging gate cooldown — prevent infinite loop when staging is required but
        // validation never arrives. Gate fires once per 6h; next run just skips silently.
        if ( get_transient( 'rpcare_staging_gate_cooldown' ) ) {
            return [ 'success' => false, 'skipped' => true, 'message' => 'Staging gate cooldown activo — esperando validación del clon.' ];
        }

        $staging_gate = self::check_staging_gate($plan, $args);
        if (empty($staging_gate['success'])) {
            set_transient( 'rpcare_staging_gate_cooldown', 1, 6 * HOUR_IN_SECONDS );
            RP_Care_Utils::log('updates', 'warning', $staging_gate['message'], $staging_gate);
            RP_Care_Utils::send_notification(
                'updates_waiting_for_staging',
                'Actualizaciones pendientes de staging',
                $staging_gate['message']
            );
            return $staging_gate;
        }

        // Si un fatal interrumpe un upgrade, WP dejaría el sitio bloqueado
        // en modo mantenimiento (.maintenance). Lo retiramos al apagar.
        // Bug #6: también limpiamos snapshots/upgrade dirs huérfanos del proceso actual.
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $file = ABSPATH . '.maintenance';
                if (file_exists($file)) {
                    @unlink($file);
                    RP_Care_Utils::log('updates', 'error', 'Fatal durante actualización — modo mantenimiento retirado', ['error' => $error['message']]);
                }
                // Clean orphaned rpcare upgrade work dirs
                $rm = function ( string $dir ) use ( &$rm ): void {
                    foreach ( glob( $dir . '/*' ) ?: [] as $f ) {
                        is_dir( $f ) ? $rm( $f ) : @unlink( $f );
                    }
                    @rmdir( $dir );
                };
                foreach ( glob( WP_CONTENT_DIR . '/upgrade/rpcare-*' ) ?: [] as $d ) {
                    if ( is_dir( $d ) ) $rm( $d );
                }
                // Clean snapshot dirs created in the last hour
                $upload_dir = wp_upload_dir();
                $snap_base  = $upload_dir['basedir'] . '/rpcare-snapshots';
                foreach ( glob( $snap_base . '/*' ) ?: [] as $d ) {
                    if ( is_dir( $d ) && filemtime( $d ) >= time() - HOUR_IN_SECONDS ) $rm( $d );
                }
            }
        });

        $results = [
            'backup'       => null,
            'core'         => null,
            'plugins'      => [],
            'themes'       => [],
            'translations' => [],
        ];

        // Use WordPress's existing update transients (populated by WP's built-in cron).
        // Clearing + forcing a re-check here would make synchronous HTTP calls to
        // api.wordpress.org that risk a PHP timeout on shared hosting.
        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        // -----------------------------------------------------------------
        // 1. Full backup BEFORE any updates. Even if the plan doesn't
        //    normally include backups the backup class picks the best
        //    available method. If it fails we log a warning and continue
        //    — per-item filesystem snapshots still protect individual items.
        // -----------------------------------------------------------------
        $backup_result = class_exists('RP_Care_Task_Backup')
            ? RP_Care_Task_Backup::run(['reason' => 'pre_update_run'])
            : ['success' => false, 'message' => 'Módulo de backup no disponible'];
        $results['backup'] = $backup_result;

        if ($backup_result['success']) {
            RP_Care_Utils::log('updates', 'info', 'Pre-update backup completed successfully');
        } else {
            RP_Care_Utils::log('updates', 'warning', 'Pre-update backup failed — proceeding with filesystem snapshots only', $backup_result);
        }

        // 2. Update WordPress core
        if (!$exclusions['core']) {
            $results['core'] = self::update_core();
        }

        // 3. Update plugins
        $results['plugins'] = self::update_plugins($exclusions['plugins']);

        // 4. Update themes
        $results['themes'] = self::update_themes($exclusions['themes']);

        // 5. Update translations
        $results['translations'] = self::update_translations();

        // Summary
        $updated_count = count(array_filter($results['plugins'], function($r) { return $r['updated']; })) +
                         count(array_filter($results['themes'],  function($r) { return $r['updated']; })) +
                         ($results['core']['updated'] ? 1 : 0);

        $rolled_back = count(array_filter($results['plugins'], function($r) { return !empty($r['rolled_back']); })) +
                       count(array_filter($results['themes'],  function($r) { return !empty($r['rolled_back']); }));

        RP_Care_Utils::log(
            'updates',
            $rolled_back > 0 ? 'warning' : 'success',
            "Updates: {$updated_count} updated, {$rolled_back} rolled back",
            $results
        );

        update_option('rpcare_last_update_check', current_time('mysql'));
        update_option('rpcare_last_update', current_time('mysql'));

        self::check_critical_updates($results);

        $updated_plugin_names = [];
        foreach ($results['plugins'] as $file => $r) {
            if (!empty($r['updated'])) {
                $updated_plugin_names[] = ($r['name'] ?? $file) . (!empty($r['new_version']) ? ' → v' . $r['new_version'] : '');
            }
        }
        foreach ($results['themes'] as $slug => $r) {
            if (!empty($r['updated'])) {
                $updated_plugin_names[] = ($r['name'] ?? $slug) . (!empty($r['new_version']) ? ' (theme) → v' . $r['new_version'] : '');
            }
        }

        return array_merge($results, [
            'success'         => true,
            'updated_plugins' => $updated_plugin_names,
            'wp_updated'      => !empty($results['core']['updated']),
        ]);
    }
    
    private static function check_staging_gate($plan, $args = []) {
        if (!self::requires_staging($plan)) {
            return ['success' => true, 'required' => false];
        }

        if (!empty($args['staging_validated'])) {
            return ['success' => true, 'required' => true, 'validated' => true];
        }

        if (!class_exists('RP_Care_Task_Staging')) {
            return [
                'success' => false,
                'skipped' => true,
                'staging_required' => true,
                'message' => 'El plan requiere staging antes de actualizar, pero el modulo staging no esta disponible.',
            ];
        }

        $clone = RP_Care_Task_Staging::create_clone('pre-update-' . gmdate('Ymd-His'));
        $triggered = is_array($clone) && !empty($clone['triggered']);

        if ($triggered && class_exists('RP_Care_Task_StagingEval')) {
            $options     = get_option('rpcare_options', []);
            $staging_url = $options['staging_url'] ?? '';

            // Capture prod snapshot in a separate async action to avoid HTTP loopback
            // deadlock on single-worker PHP-FPM pools (this task already holds a worker).
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action('rpcare_task_capture_staging_snapshot', [], 'replanta-care');
            } else {
                RP_Care_Task_StagingEval::capture_production_snapshot();
            }

            RP_Care_Utils::send_notification('staging_clone_triggered', 'Clon de staging iniciado', 'Se ha creado un clon de staging. Evalua el entorno y aprueba desde Plugin Center para aplicar actualizaciones.', [
                'staging_url'       => $staging_url,
                'clone_data'        => is_array($clone) ? array_intersect_key($clone, array_flip(['name', 'url', 'id'])) : [],
            ]);
        }

        return [
            'success' => false,
            'skipped' => true,
            'staging_required' => true,
            'staging_clone' => $clone,
            'message' => $triggered
                ? 'Staging solicitado. Valida el clon y vuelve a lanzar updates con args.staging_validated=true.'
                : 'El plan requiere staging antes de actualizar, pero no se pudo crear un clon automatico.',
        ];
    }

    private static function requires_staging($plan) {
        $plan = RP_Care_Plan::normalize_plan($plan);
        if ($plan === RP_Care_Plan::PLAN_ECOSISTEMA) {
            return true;
        }

        if (class_exists('RP_Care_Addon_Manager')) {
            $addons = RP_Care_Addon_Manager::get();
            $ecom_cfg = $addons->get_config('ecommerce');
            return $addons->is_active('ecommerce') && !empty($ecom_cfg['staging_required']);
        }

        return false;
    }

    private static function update_core() {
        if (!function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        // Bug #2: only force-refresh if transient is stale (> 15 min) to avoid sync HTTP
        // call on shared hosting with a tight PHP execution limit.
        $cached_core = get_site_transient( 'update_core' );
        if ( ! $cached_core || empty( $cached_core->last_checked ) || ( time() - $cached_core->last_checked ) > 15 * MINUTE_IN_SECONDS ) {
            delete_site_transient( 'update_core' );
            wp_version_check();
        }

        $updates = get_core_updates();

        if (empty($updates) || !isset($updates[0]) || $updates[0]->response !== 'upgrade') {
            return ['updated' => false, 'message' => 'No core updates available'];
        }

        $update = $updates[0];

        // Block major version updates unless explicitly allowed
        $current_version = get_bloginfo('version');
        $is_major        = version_compare($current_version, $update->current, '<') &&
                           (int) $current_version !== (int) $update->current;

        if ($is_major && !get_option('rpcare_allow_major_updates', false)) {
            RP_Care_Utils::log('updates', 'info', "Major WordPress update available ({$update->current}) but auto-update disabled");
            return ['updated' => false, 'message' => 'Major update available but auto-update disabled', 'version' => $update->current];
        }

        try {
            if (!class_exists('Core_Upgrader')) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }

            $skin     = class_exists('WP_Ajax_Upgrader_Skin') ? new WP_Ajax_Upgrader_Skin() : new WP_Upgrader_Skin();
            $upgrader = new Core_Upgrader($skin);
            $result   = $upgrader->upgrade($update);

            if (is_wp_error($result)) {
                return ['updated' => false, 'error' => $result->get_error_message()];
            }

            // Health check — core rollback is not done automatically (too risky),
            // but we alert immediately so the team can act.
            if (!self::health_check()) {
                $msg = "Site health check FAILED after WordPress core update to {$update->current}. Manual rollback required.";
                RP_Care_Utils::log('updates', 'error', $msg);
                RP_Care_Utils::send_notification(
                    'core_update_health_failed',
                    'CRITICAL: Site Down After WP Core Update',
                    $msg
                );
                return [
                    'updated'      => true,
                    'version'      => $update->current,
                    'health_check' => false,
                    'rolled_back'  => false,
                    'message'      => 'Updated but health check failed — manual intervention required',
                ];
            }

            return ['updated' => true, 'version' => $update->current, 'health_check' => true];

        } catch (Exception $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }
    }
    
    private static function update_plugins($exclusions = []) {
        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        // Bug #2: same time-gate as core — only refresh if transient is stale (> 15 min).
        $cached_plugins = get_site_transient( 'update_plugins' );
        if ( ! $cached_plugins || empty( $cached_plugins->last_checked ) || ( time() - $cached_plugins->last_checked ) > 15 * MINUTE_IN_SECONDS ) {
            delete_site_transient( 'update_plugins' );
            wp_update_plugins();
        }

        RP_Care_Update_Control::$bypass_for_task = true;
        $plugin_updates = get_plugin_updates();
        RP_Care_Update_Control::$bypass_for_task = false;
        $results        = [];

        if (empty($plugin_updates)) {
            return $results;
        }

        foreach ($plugin_updates as $plugin_file => $plugin_data) {
            $plugin_name = $plugin_data->Name    ?? $plugin_file;
            $old_version = $plugin_data->Version ?? 'unknown';
            $new_version = $plugin_data->update->new_version ?? 'unknown';

            if (in_array($plugin_file, $exclusions)) {
                $results[$plugin_file] = [
                    'updated' => false,
                    'message' => 'Excluded from auto-updates',
                    'name'    => $plugin_name,
                ];
                continue;
            }

            if (isset($plugin_data->auto_update) && !$plugin_data->auto_update) {
                $results[$plugin_file] = [
                    'updated' => false,
                    'message' => 'Auto-updates disabled by plugin',
                    'name'    => $plugin_name,
                ];
                continue;
            }

            // Snapshot the plugin directory so we can roll back if needed
            $snapshot       = self::snapshot_plugin($plugin_file);
            $is_pagebuilder = self::is_pagebuilder_plugin($plugin_file);

            try {
                if (!class_exists('Plugin_Upgrader')) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                }

                $skin     = class_exists('WP_Ajax_Upgrader_Skin') ? new WP_Ajax_Upgrader_Skin() : new WP_Upgrader_Skin();
                $upgrader = new Plugin_Upgrader($skin);
                $result   = $upgrader->upgrade($plugin_file);

                if (is_wp_error($result)) {
                    self::cleanup_snapshots([$snapshot]);
                    $results[$plugin_file] = [
                        'updated' => false,
                        'error'   => $result->get_error_message(),
                        'name'    => $plugin_name,
                    ];
                    continue;
                }

                if ($result !== true) {
                    self::cleanup_snapshots([$snapshot]);
                    $results[$plugin_file] = [
                        'updated' => false,
                        'message' => 'Update failed',
                        'name'    => $plugin_name,
                    ];
                    continue;
                }

                // Extended health check for page builders (checks 3 URLs including WC shop)
                if (!self::health_check(12, $is_pagebuilder)) {
                    $rolled_back = $snapshot ? self::restore_plugin_snapshot($plugin_file, $snapshot) : false;
                    self::cleanup_snapshots([$snapshot]);

                    RP_Care_Utils::log('updates', 'error',
                        "Rolled back plugin '$plugin_name' from $new_version to $old_version after health check failure"
                    );
                    RP_Care_Utils::send_notification(
                        'plugin_update_rolled_back',
                        "Plugin Rolled Back: $plugin_name",
                        "Plugin '$plugin_name' was updated to $new_version but the site health check failed. " .
                        ($rolled_back
                            ? "Automatically rolled back to $old_version."
                            : 'Auto-rollback also failed — manual intervention required.')
                    );

                    $results[$plugin_file] = [
                        'updated'      => true,
                        'rolled_back'  => $rolled_back,
                        'name'         => $plugin_name,
                        'old_version'  => $old_version,
                        'new_version'  => $new_version,
                        'health_check' => false,
                        'message'      => $rolled_back
                            ? "Rolled back to $old_version after health check failure"
                            : 'Health check failed and rollback failed',
                    ];
                } else {
                    self::cleanup_snapshots([$snapshot]);
                    $results[$plugin_file] = [
                        'updated'      => true,
                        'rolled_back'  => false,
                        'name'         => $plugin_name,
                        'new_version'  => $new_version,
                        'health_check' => true,
                    ];
                }

            } catch (Exception $e) {
                self::cleanup_snapshots([$snapshot]);
                $results[$plugin_file] = [
                    'updated' => false,
                    'error'   => $e->getMessage(),
                    'name'    => $plugin_name,
                ];
            }
        }

        return $results;
    }
    
    private static function update_themes($exclusions = []) {
        if (!function_exists('get_theme_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $theme_updates = get_theme_updates();
        $results       = [];

        if (empty($theme_updates)) {
            return $results;
        }

        $current_theme = get_stylesheet();

        foreach ($theme_updates as $theme_slug => $theme_data) {
            $theme_name  = $theme_data->get('Name');
            $old_version = $theme_data->get('Version');
            $new_version = $theme_data->update['new_version'] ?? 'unknown';

            if (in_array($theme_slug, $exclusions)) {
                $results[$theme_slug] = [
                    'updated' => false,
                    'message' => 'Excluded from auto-updates',
                    'name'    => $theme_name,
                ];
                continue;
            }

            // Bug #3: respect explicit auto-update opt-outs (admin toggle or theme filter).
            if ( ! apply_filters( 'auto_update_theme', true, $theme_slug ) ) {
                $results[$theme_slug] = [
                    'updated' => false,
                    'message' => 'Auto-updates disabled',
                    'name'    => $theme_name,
                ];
                continue;
            }

            // Snapshot the theme directory before updating
            $snapshot = self::snapshot_theme($theme_slug);

            try {
                if (!class_exists('Theme_Upgrader')) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                }

                $skin     = class_exists('WP_Ajax_Upgrader_Skin') ? new WP_Ajax_Upgrader_Skin() : new WP_Upgrader_Skin();
                $upgrader = new Theme_Upgrader($skin);
                $result   = $upgrader->upgrade($theme_slug);

                if (is_wp_error($result)) {
                    self::cleanup_snapshots([$snapshot]);
                    $results[$theme_slug] = [
                        'updated' => false,
                        'error'   => $result->get_error_message(),
                        'name'    => $theme_name,
                    ];
                    continue;
                }

                if ($result !== true) {
                    self::cleanup_snapshots([$snapshot]);
                    $results[$theme_slug] = [
                        'updated' => false,
                        'message' => 'Update failed',
                        'name'    => $theme_name,
                    ];
                    continue;
                }

                // Health check — extra critical for the active theme
                if (!self::health_check()) {
                    $rolled_back = $snapshot ? self::restore_theme_snapshot($theme_slug, $snapshot) : false;
                    self::cleanup_snapshots([$snapshot]);

                    RP_Care_Utils::log('updates', 'error',
                        "Rolled back theme '$theme_name' from $new_version to $old_version after health check failure"
                    );
                    RP_Care_Utils::send_notification(
                        'theme_update_rolled_back',
                        "Theme Rolled Back: $theme_name",
                        "Theme '$theme_name' was updated to $new_version but the site health check failed. " .
                        ($rolled_back
                            ? "Automatically rolled back to $old_version."
                            : 'Auto-rollback also failed — manual intervention required.') .
                        ($theme_slug === $current_theme ? ' (This is the active theme.)' : '')
                    );

                    $results[$theme_slug] = [
                        'updated'      => true,
                        'rolled_back'  => $rolled_back,
                        'name'         => $theme_name,
                        'old_version'  => $old_version,
                        'new_version'  => $new_version,
                        'health_check' => false,
                        'message'      => $rolled_back
                            ? "Rolled back to $old_version after health check failure"
                            : 'Health check failed and rollback failed',
                    ];
                } else {
                    self::cleanup_snapshots([$snapshot]);
                    $results[$theme_slug] = [
                        'updated'      => true,
                        'rolled_back'  => false,
                        'name'         => $theme_name,
                        'new_version'  => $new_version,
                        'health_check' => true,
                    ];
                }

            } catch (Exception $e) {
                self::cleanup_snapshots([$snapshot]);
                $results[$theme_slug] = [
                    'updated' => false,
                    'error'   => $e->getMessage(),
                    'name'    => $theme_name,
                ];
            }
        }

        return $results;
    }
    
    private static function update_translations() {
        if (!function_exists('wp_get_translation_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        
        $translation_updates = wp_get_translation_updates();
        $results = [];
        
        if (empty($translation_updates)) {
            return $results;
        }
        
        try {
            if (!class_exists('Language_Pack_Upgrader')) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }
            
            $skin     = class_exists('WP_Ajax_Upgrader_Skin') ? new WP_Ajax_Upgrader_Skin() : new WP_Upgrader_Skin();
            $upgrader = new Language_Pack_Upgrader($skin);
            $result = $upgrader->bulk_upgrade($translation_updates);
            
            if (is_array($result)) {
                foreach ($result as $update => $success) {
                    $results[] = [
                        'updated' => !is_wp_error($success),
                        'language' => $update,
                        'error' => is_wp_error($success) ? $success->get_error_message() : null
                    ];
                }
            }
            
        } catch (Exception $e) {
            $results[] = [
                'updated' => false,
                'error' => $e->getMessage()
            ];
        }
        
        return $results;
    }
    
    private static function check_critical_updates($results) {
        $critical_alerts = [];
        
        // Check for failed core updates
        if ($results['core'] && !$results['core']['updated'] && isset($results['core']['error'])) {
            $critical_alerts[] = 'WordPress core update failed: ' . $results['core']['error'];
        }
        
        // Check for failed critical plugin updates
        $critical_plugins = get_option('rpcare_critical_plugins', [
            'wordpress-seo/wp-seo.php',
            'woocommerce/woocommerce.php',
            'elementor/elementor.php'
        ]);
        
        foreach ($results['plugins'] as $plugin_file => $result) {
            // Bug #4: fire alert for any failed critical plugin update, not only when
            // 'error' key is set — "Update failed" without error key was silently dropped.
            $intentional_skip = in_array( $result['message'] ?? '', [
                'Excluded from auto-updates',
                'Auto-updates disabled by plugin',
            ] );
            if (in_array($plugin_file, $critical_plugins) && !$result['updated'] && !$intentional_skip) {
                $detail = $result['error'] ?? $result['message'] ?? 'fallo sin detalles';
                $critical_alerts[] = 'Critical plugin update failed: ' . $result['name'] . ' — ' . $detail;
            }
        }
        
        // Send alerts if any
        if (!empty($critical_alerts)) {
            foreach ($critical_alerts as $alert) {
                RP_Care_Utils::send_notification('critical_update_failed', 'Critical Update Failed', $alert);
            }
        }
    }
    
    // =========================================================================
    // Health check + filesystem snapshot / rollback helpers
    // =========================================================================

    /**
     * HTTP health check against the site homepage.
     * Checks: HTTP status (non-5xx), PHP fatal markers, TTFB threshold, and minimal HTML.
     * When $extended=true (page-builder updates), also checks an inner page and WC shop.
     */
    private static function health_check(int $timeout = 12, bool $extended = false): bool {
        $urls = [home_url('/')];

        if ($extended) {
            $pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'modified']);
            if (!empty($pages)) {
                $urls[] = get_permalink($pages[0]->ID);
            }
            if (function_exists('wc_get_page_id')) {
                $shop_id = wc_get_page_id('shop');
                if ($shop_id > 0) {
                    $urls[] = get_permalink($shop_id);
                }
            }
        }

        foreach ($urls as $url) {
            if (!self::check_single_url($url, $timeout)) {
                return false;
            }
        }
        return true;
    }

    private static function check_single_url(string $url, int $timeout): bool {
        $start  = microtime(true);
        $result = wp_remote_get($url, [
            'timeout'    => $timeout,
            'user-agent' => 'ReplantaCare/HealthCheck',
            'sslverify'  => false,
        ]);
        $elapsed = microtime(true) - $start;

        if (is_wp_error($result)) {
            RP_Care_Utils::log('updates', 'error', 'Health check failed for ' . $url . ': ' . $result->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($result);
        if ($code >= 500) {
            RP_Care_Utils::log('updates', 'error', "Health check returned HTTP $code for $url");
            return false;
        }

        // Bug #1: threshold must always be below the HTTP timeout to avoid false rollbacks.
        // A response arriving in 9s with a 12s timeout was failing this check and triggering rollback.
        $slow_threshold = max( 6.0, $timeout - 2 );
        if ($elapsed > $slow_threshold) {
            RP_Care_Utils::log('updates', 'warning', sprintf('Health check: slow response %.2fs (threshold %.1fs) for %s', $elapsed, $slow_threshold, $url));
            return false;
        }

        $body = wp_remote_retrieve_body($result);

        $markers = [
            'Fatal error',
            'Parse error',
            'Call to undefined function',
            'Call to undefined method',
            'Uncaught Error',
            'Uncaught Exception',
            'WordPress database error',
            'Elementor detected some incompatible',
        ];
        foreach ($markers as $marker) {
            if (stripos($body, $marker) !== false) {
                RP_Care_Utils::log('updates', 'error', "Health check: error marker '$marker' in $url");
                return false;
            }
        }

        if (strlen($body) < 200 || stripos($body, '<html') === false) {
            RP_Care_Utils::log('updates', 'warning', "Health check: response too short or missing HTML for $url");
            return false;
        }

        return true;
    }

    private static function is_pagebuilder_plugin(string $plugin_file): bool {
        static $slugs = [
            'elementor/elementor.php',
            'elementor-pro/elementor-pro.php',
            'divi-builder/divi-builder.php',
            'bb-plugin/fl-builder.php',
            'oxygen/functions.php',
            'bricks/bricks.php',
            'wpbakery/js_composer.php',
            'js_composer/js_composer.php',
        ];
        return in_array($plugin_file, $slugs, true);
    }

    /**
     * Copy a plugin directory to a temporary snapshot location.
     * Returns the snapshot path on success, false on failure.
     *
     * @return string|false
     */
    private static function snapshot_plugin(string $plugin_file) {
        $plugin_dir    = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
        if (!is_dir($plugin_dir)) {
            return false;
        }

        $snapshot_path = self::get_snapshot_dir() . '/plugin-' . sanitize_file_name(dirname($plugin_file)) . '-' . time();
        if (!wp_mkdir_p($snapshot_path)) {
            return false;
        }

        return self::copy_directory($plugin_dir, $snapshot_path) ? $snapshot_path : false;
    }

    /**
     * Copy a theme directory to a temporary snapshot location.
     * Returns the snapshot path on success, false on failure.
     *
     * @return string|false
     */
    private static function snapshot_theme(string $theme_slug) {
        $theme_dir = get_theme_root() . '/' . $theme_slug;
        if (!is_dir($theme_dir)) {
            return false;
        }

        $snapshot_path = self::get_snapshot_dir() . '/theme-' . sanitize_file_name($theme_slug) . '-' . time();
        if (!wp_mkdir_p($snapshot_path)) {
            return false;
        }

        return self::copy_directory($theme_dir, $snapshot_path) ? $snapshot_path : false;
    }

    /**
     * Restore a plugin from a previously saved filesystem snapshot.
     */
    private static function restore_plugin_snapshot(string $plugin_file, string $snapshot_dir): bool {
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
        self::remove_dir_recursive($plugin_dir);
        return self::copy_directory($snapshot_dir, $plugin_dir);
    }

    /**
     * Restore a theme from a previously saved filesystem snapshot.
     */
    private static function restore_theme_snapshot(string $theme_slug, string $snapshot_dir): bool {
        $theme_dir = get_theme_root() . '/' . $theme_slug;
        self::remove_dir_recursive($theme_dir);
        return self::copy_directory($snapshot_dir, $theme_dir);
    }

    /**
     * Return the base directory used for temporary snapshots.
     * Protected from direct web access via .htaccess.
     */
    private static function get_snapshot_dir(): string {
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/rpcare-snapshots';
        wp_mkdir_p($dir);

        if (!file_exists($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
        }
        if (!file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden\n");
        }

        return $dir;
    }

    /**
     * Recursively copy a directory.
     */
    private static function copy_directory(string $source, string $destination): bool {
        if (!is_dir($source)) {
            return false;
        }

        wp_mkdir_p($destination);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen(rtrim($source, '/\\')) + 1);
            $target   = $destination . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                wp_mkdir_p($target);
            } else {
                wp_mkdir_p(dirname($target));
                copy($item->getPathname(), $target);
            }
        }

        return true;
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private static function remove_dir_recursive(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * Remove a set of snapshot directories (called after rollback or after
     * a successful update to free disk space).
     */
    private static function cleanup_snapshots(array $dirs): void {
        foreach ($dirs as $dir) {
            if ($dir && is_dir($dir)) {
                self::remove_dir_recursive($dir);
            }
        }
    }

    public static function get_available_updates() {
        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $updates = [
            'core' => [],
            'plugins' => [],
            'themes' => [],
            'translations' => []
        ];
        
        // Core updates
        if (function_exists('get_core_updates')) {
            $core_updates = get_core_updates();
            if (!empty($core_updates) && $core_updates[0]->response === 'upgrade') {
                $updates['core'] = [
                    'current' => get_bloginfo('version'),
                    'new' => $core_updates[0]->current,
                    'auto_update' => $core_updates[0]->autoupdate ?? false
                ];
            }
        }
        
        // Plugin updates
        if (function_exists('get_plugin_updates')) {
            RP_Care_Update_Control::$bypass_for_task = true;
            $plugin_updates = get_plugin_updates();
            RP_Care_Update_Control::$bypass_for_task = false;
            foreach ($plugin_updates as $plugin_file => $plugin_data) {
                $updates['plugins'][$plugin_file] = [
                    'name' => $plugin_data->Name ?? $plugin_file,
                    'current' => $plugin_data->Version ?? 'unknown',
                    'new' => $plugin_data->update->new_version ?? 'unknown',
                    'auto_update' => $plugin_data->auto_update ?? true
                ];
            }
        }
        
        // Theme updates
        if (function_exists('get_theme_updates')) {
            $theme_updates = get_theme_updates();
            foreach ($theme_updates as $theme_slug => $theme_data) {
                $updates['themes'][$theme_slug] = [
                    'name' => $theme_data->get('Name'),
                    'current' => $theme_data->get('Version'),
                    'new' => $theme_data->update['new_version'] ?? 'unknown'
                ];
            }
        }
        
        // Translation updates
        if (function_exists('wp_get_translation_updates')) {
            $translation_updates = wp_get_translation_updates();
            foreach ($translation_updates as $update) {
                $updates['translations'][] = [
                    'language' => $update->language,
                    'type' => $update->type,
                    'slug' => $update->slug
                ];
            }
        }
        
        return $updates;
    }

    // =========================================================================
    // Staging-first pipeline batch apply / rollback
    // =========================================================================

    /**
     * AS callback for 'rpcare_create_production_backup'.
     * Scheduled by handle_prepare_production(). Creates exactly one backup per batch,
     * verifies integrity, and reports production_backup_complete to PC.
     * Idempotent: re-running for the same batch_id reports the already-created backup.
     */
    public static function do_create_production_backup( string $batch_id ): void {
        if ( empty( $batch_id ) ) {
            return;
        }

        if ( ! class_exists( 'RP_Care_Pipeline_Client' ) || ! RP_Care_Pipeline_Client::is_pipeline_enabled() ) {
            self::report_batch_to_pc( $batch_id, 'production_failed', [ 'error' => 'pipeline_disabled' ] );
            return;
        }

        if ( RP_Care_Pipeline_Client::is_staging() ) {
            self::report_batch_to_pc( $batch_id, 'production_failed', [ 'error' => 'not_production' ] );
            return;
        }

        if ( RP_Care_Pipeline_Client::is_in_quarantine() ) {
            self::report_batch_to_pc( $batch_id, 'production_failed', [ 'error' => 'in_quarantine' ] );
            return;
        }

        // Idempotency: only create one backup per batch.
        $existing_id = (string) get_option( 'rpcare_prod_backup_done_' . $batch_id, '' );
        if ( ! empty( $existing_id ) ) {
            self::report_batch_to_pc( $batch_id, 'backup_complete', [
                'backup_id'       => $existing_id,
                'backup_verified' => true,
            ] );
            return;
        }

        $backup_result = self::create_pipeline_backup( $batch_id );
        if ( is_wp_error( $backup_result ) ) {
            self::report_batch_to_pc( $batch_id, 'production_failed', [ 'error' => 'backup_failed: ' . $backup_result->get_error_message() ] );
            return;
        }

        // Persist so apply_production_batch can verify the backup_id from the command.
        update_option( 'rpcare_prod_backup_done_' . $batch_id, $backup_result );

        self::report_batch_to_pc( $batch_id, 'backup_complete', [
            'backup_id'       => $backup_result,
            'backup_verified' => true,
        ] );
    }

    /**
     * Apply an update batch on the staging environment.
     * Called by the rpcare_pipeline_apply_staging_batch AS action.
     */
    public static function apply_staging_batch( string $batch_id ): array {
        if ( ! class_exists( 'RP_Care_Pipeline_Client' ) || ! RP_Care_Pipeline_Client::is_pipeline_enabled() ) {
            self::report_batch_to_pc( $batch_id, 'staging_failed', [] );
            return [ 'success' => false, 'error' => 'pipeline_disabled' ];
        }

        if ( ! RP_Care_Pipeline_Client::is_staging() ) {
            self::report_batch_to_pc( $batch_id, 'staging_failed', [] );
            return [ 'success' => false, 'error' => 'not_staging_instance' ];
        }

        if ( RP_Care_Pipeline_Client::is_in_quarantine() ) {
            self::report_batch_to_pc( $batch_id, 'staging_failed', [] );
            return [ 'success' => false, 'error' => 'in_quarantine' ];
        }

        if ( get_option( 'rpcare_staging_batch_done_' . $batch_id ) ) {
            return [ 'success' => true, 'idempotent' => true, 'batch_id' => $batch_id ];
        }

        if ( ! self::acquire_batch_lock( $batch_id ) ) {
            return [ 'success' => false, 'error' => 'batch_already_running' ];
        }

        $item_results = [];
        try {
            $manifest_data = self::fetch_manifest_from_pc( $batch_id );
            if ( is_wp_error( $manifest_data ) ) {
                self::report_batch_to_pc( $batch_id, 'staging_failed', [] );
                self::release_batch_lock( $batch_id );
                return [ 'success' => false, 'error' => $manifest_data->get_error_message() ];
            }

            // Pre-flight: every manifest item must have a pre-downloaded artifact.
            $artifact_check = self::validate_manifest_artifacts( $manifest_data['manifest'], $batch_id );
            if ( $artifact_check instanceof \WP_Error ) {
                self::report_batch_to_pc( $batch_id, 'staging_failed', [ [ 'error' => $artifact_check->get_error_message() ] ] );
                self::release_batch_lock( $batch_id );
                return [ 'success' => false, 'error' => $artifact_check->get_error_message() ];
            }

            $batch_items  = self::apply_manifest_items( $manifest_data['manifest'], $batch_id, 'staging' );
            $applied      = $batch_items['applied'];
            $deferred     = $batch_items['deferred'];
            $success      = empty( array_filter( $applied, static function ( $r ) { return empty( $r['success'] ); } ) );

            self::report_batch_to_pc( $batch_id, $success ? 'staging_done' : 'staging_failed', $applied, $deferred );

            if ( $success ) {
                update_option( 'rpcare_staging_batch_done_' . $batch_id, time() );
            }

            self::release_batch_lock( $batch_id );
            return [ 'success' => $success, 'batch_id' => $batch_id, 'item_results' => $applied, 'deferred_items' => $deferred ];

        } catch ( \Throwable $e ) {
            if ( class_exists( 'RP_Care_Utils' ) ) {
                RP_Care_Utils::log( 'pipeline', 'error', 'apply_staging_batch exception: ' . $e->getMessage(), [ 'batch_id' => $batch_id ] );
            }
            self::report_batch_to_pc( $batch_id, 'staging_failed', $item_results );
            self::release_batch_lock( $batch_id );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Apply an approved update batch to the production environment.
     * Called by the rpcare_pipeline_apply_production_batch AS action.
     * $backup_id must match the backup created by do_create_production_backup().
     */
    public static function apply_production_batch( string $batch_id, string $manifest_hash, string $approval_id, string $backup_id = '' ): array {
        if ( ! class_exists( 'RP_Care_Pipeline_Client' ) || ! RP_Care_Pipeline_Client::is_pipeline_enabled() ) {
            self::report_batch_to_pc( $batch_id, 'production_failed', [] );
            return [ 'success' => false, 'error' => 'pipeline_disabled' ];
        }

        if ( RP_Care_Pipeline_Client::is_staging() ) {
            self::report_batch_to_pc( $batch_id, 'production_failed', [] );
            return [ 'success' => false, 'error' => 'not_production_instance' ];
        }

        if ( RP_Care_Pipeline_Client::is_in_quarantine() ) {
            self::report_batch_to_pc( $batch_id, 'production_failed', [] );
            return [ 'success' => false, 'error' => 'in_quarantine' ];
        }

        if ( empty( $batch_id ) || empty( $manifest_hash ) || empty( $approval_id ) ) {
            return [ 'success' => false, 'error' => 'missing_required_params' ];
        }

        // backup_id is required — it proves the backup callback ran before this command was sent.
        if ( empty( $backup_id ) ) {
            self::report_batch_to_pc( $batch_id, 'production_failed', [ [ 'error' => 'missing_backup_id' ] ] );
            return [ 'success' => false, 'error' => 'missing_backup_id_in_command' ];
        }

        // Verify the backup_id from the command matches what we recorded locally.
        $stored_backup_id = (string) get_option( 'rpcare_prod_backup_done_' . $batch_id, '' );
        if ( empty( $stored_backup_id ) || ! hash_equals( $stored_backup_id, $backup_id ) ) {
            self::report_batch_to_pc( $batch_id, 'production_failed', [ [ 'error' => 'backup_id_mismatch' ] ] );
            return [ 'success' => false, 'error' => 'backup_id_mismatch' ];
        }

        if ( get_option( 'rpcare_prod_batch_done_' . $batch_id ) ) {
            return [ 'success' => true, 'idempotent' => true, 'batch_id' => $batch_id ];
        }

        if ( ! self::acquire_batch_lock( $batch_id ) ) {
            return [ 'success' => false, 'error' => 'batch_already_running' ];
        }

        $item_results = [];
        try {
            $manifest_data = self::fetch_manifest_from_pc( $batch_id );
            if ( is_wp_error( $manifest_data ) ) {
                self::report_batch_to_pc( $batch_id, 'production_failed', [] );
                self::release_batch_lock( $batch_id );
                return [ 'success' => false, 'error' => $manifest_data->get_error_message() ];
            }

            // CRITICAL: verify manifest hash matches what was approved.
            if ( ! hash_equals( $manifest_hash, $manifest_data['manifest_hash'] ) ) {
                self::report_batch_to_pc( $batch_id, 'production_failed', [ [ 'error' => 'manifest_hash_mismatch' ] ] );
                self::release_batch_lock( $batch_id );
                return [ 'success' => false, 'error' => 'manifest_hash_mismatch' ];
            }

            // Pre-flight: every manifest item must have a pre-downloaded artifact.
            $artifact_check = self::validate_manifest_artifacts( $manifest_data['manifest'], $batch_id );
            if ( $artifact_check instanceof \WP_Error ) {
                self::report_batch_to_pc( $batch_id, 'production_failed', [ [ 'error' => $artifact_check->get_error_message() ] ] );
                self::release_batch_lock( $batch_id );
                return [ 'success' => false, 'error' => $artifact_check->get_error_message() ];
            }

            // Record backup_id for rollback_batch to use.
            update_option( 'rpcare_prod_batch_backup_' . $batch_id, $backup_id );

            $batch_items  = self::apply_manifest_items( $manifest_data['manifest'], $batch_id, 'production' );
            $applied      = $batch_items['applied'];
            $deferred     = $batch_items['deferred'];
            $success      = empty( array_filter( $applied, static function ( $r ) { return empty( $r['success'] ); } ) );

            self::report_batch_to_pc( $batch_id, $success ? 'production_done' : 'production_failed', $applied, $deferred );

            if ( $success ) {
                update_option( 'rpcare_prod_batch_done_' . $batch_id, time() );
            }

            self::release_batch_lock( $batch_id );
            return [ 'success' => $success, 'batch_id' => $batch_id, 'item_results' => $applied, 'deferred_items' => $deferred ];

        } catch ( \Throwable $e ) {
            if ( class_exists( 'RP_Care_Utils' ) ) {
                RP_Care_Utils::log( 'pipeline', 'error', 'apply_production_batch exception: ' . $e->getMessage(), [ 'batch_id' => $batch_id ] );
            }
            self::report_batch_to_pc( $batch_id, 'production_failed', $item_results );
            self::release_batch_lock( $batch_id );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Roll back a production batch to its pre-update state via the stored backup.
     */
    public static function rollback_batch( string $batch_id ): array {
        if ( ! class_exists( 'RP_Care_Pipeline_Client' ) || ! RP_Care_Pipeline_Client::is_pipeline_enabled() ) {
            self::report_batch_to_pc( $batch_id, 'rollback_failed', [] );
            return [ 'success' => false, 'error' => 'pipeline_disabled' ];
        }

        if ( RP_Care_Pipeline_Client::is_staging() ) {
            self::report_batch_to_pc( $batch_id, 'rollback_failed', [] );
            return [ 'success' => false, 'error' => 'not_applicable_on_staging' ];
        }

        $backup_id = get_option( 'rpcare_prod_batch_backup_' . $batch_id );
        if ( empty( $backup_id ) ) {
            self::report_batch_to_pc( $batch_id, 'rollback_failed', [ [ 'error' => 'no_backup_found' ] ] );
            return [ 'success' => false, 'error' => 'no_backup_found_for_batch' ];
        }

        $restore_result = self::restore_pipeline_backup( (string) $backup_id, $batch_id );
        if ( is_wp_error( $restore_result ) ) {
            self::report_batch_to_pc( $batch_id, 'rollback_failed', [ [ 'error' => $restore_result->get_error_message() ] ] );
            return [ 'success' => false, 'error' => $restore_result->get_error_message() ];
        }

        delete_option( 'rpcare_prod_batch_done_' . $batch_id );
        self::report_batch_to_pc( $batch_id, 'rolled_back', [] );

        return [ 'success' => true, 'batch_id' => $batch_id, 'backup_id' => $backup_id ];
    }

    // ── Pipeline manifest helpers ─────────────────────────────────────────────

    private static function fetch_manifest_from_pc( string $batch_id ): array|\WP_Error {
        if ( ! class_exists( 'RP_Care_Pipeline_Client' ) ) {
            return new \WP_Error( 'pipeline_client_missing', 'RP_Care_Pipeline_Client not available.' );
        }

        $hub_url     = RP_Care_Pipeline_Client::get_hub_url_public();
        $token       = RP_Care_Pipeline_Client::get_token_public();
        $instance_id = (string) get_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, '' );

        if ( empty( $hub_url ) || empty( $token ) || empty( $instance_id ) ) {
            return new \WP_Error( 'not_configured', 'Pipeline client not fully configured.' );
        }

        $response = wp_remote_get(
            trailingslashit( $hub_url ) . 'wp-json/replanta-pc/v1/pipeline/manifest/' . rawurlencode( $batch_id ),
            [
                'timeout' => 20,
                'headers' => [
                    'X-Pipeline-Token' => $token,
                    'X-Instance-ID'    => $instance_id,
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new \WP_Error( 'http_error', "Manifest fetch returned HTTP $code." );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || ! isset( $body['manifest'] ) || ! isset( $body['manifest_hash'] ) ) {
            return new \WP_Error( 'invalid_manifest', 'Manifest response missing required keys.' );
        }

        $manifest = is_array( $body['manifest'] ) ? $body['manifest'] : json_decode( (string) $body['manifest'], true );
        if ( ! is_array( $manifest ) ) {
            return new \WP_Error( 'invalid_manifest', 'Manifest could not be decoded.' );
        }

        // Locally recalculate hash to verify PC is not sending a tampered manifest.
        $recalculated = self::compute_manifest_hash_local( $manifest );
        if ( ! hash_equals( $recalculated, (string) $body['manifest_hash'] ) ) {
            return new \WP_Error( 'manifest_hash_mismatch', 'Locally recalculated manifest hash does not match the value returned by PC.' );
        }

        // Verify artifact_set_hash — fail closed on any inconsistency.
        $artifacts        = $manifest['artifacts'] ?? [];
        $stored_aset_hash = $manifest['artifact_set_hash'] ?? '';
        $has_updates      = ! empty( $manifest['proposed_updates']['plugins'] )
                         || ! empty( $manifest['proposed_updates']['themes'] );

        if ( $has_updates && empty( $artifacts ) ) {
            return new \WP_Error( 'missing_artifacts', 'Manifest has plugin/theme updates but no artifacts array — failing closed.' );
        }

        if ( ! empty( $artifacts ) ) {
            if ( empty( $stored_aset_hash ) ) {
                return new \WP_Error( 'missing_artifact_set_hash', 'Manifest has artifacts but no artifact_set_hash.' );
            }
            $computed_aset_hash = self::compute_artifact_set_hash_local( $artifacts );
            if ( ! hash_equals( $stored_aset_hash, $computed_aset_hash ) ) {
                return new \WP_Error( 'artifact_set_hash_mismatch', 'artifact_set_hash in manifest does not match locally computed value.' );
            }
        }

        return [
            'manifest'      => $manifest,
            'manifest_hash' => (string) $body['manifest_hash'],
        ];
    }

    /**
     * Locally reproduce the canonical manifest hash that PC_Manifest::hash() would compute.
     * Uses ksort-recursive + JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES to match PC exactly.
     */
    private static function compute_manifest_hash_local( array $manifest ): string {
        $canonical = self::sort_keys_recursive_local( $manifest );
        $json      = wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return hash( 'sha256', (string) $json );
    }

    private static function sort_keys_recursive_local( array $arr ): array {
        ksort( $arr );
        foreach ( $arr as &$v ) {
            if ( is_array( $v ) ) {
                $v = self::sort_keys_recursive_local( $v );
            }
        }
        unset( $v );
        return $arr;
    }

    /**
     * Reproduce PC_Manifest::compute_artifact_set_hash() locally.
     * Sorts tuples id:sha256 and hashes the pipe-separated list.
     */
    private static function compute_artifact_set_hash_local( array $artifacts ): string {
        $tuples = [];
        foreach ( $artifacts as $a ) {
            $id     = $a['id'] ?? '';
            $sha256 = $a['sha256'] ?? '';
            if ( $id && $sha256 ) {
                $tuples[] = "$id:$sha256";
            }
        }
        sort( $tuples );
        return hash( 'sha256', implode( '|', $tuples ) );
    }

    /**
     * Apply all manifest items.
     * Returns ['applied' => [...], 'deferred' => [...]].
     * Translations and core items without a frozen artifact go into 'deferred'.
     * Only items in 'applied' are considered for success/failure.
     */
    private static function apply_manifest_items( array $manifest, string $batch_id, string $env ): array {
        $applied  = [];
        $deferred = [];
        $proposed = $manifest['proposed_updates'] ?? [];

        foreach ( $proposed['plugins'] ?? [] as $item ) {
            $applied[] = self::apply_pipeline_plugin( $item, $manifest, $batch_id, $env );
        }

        foreach ( $proposed['themes'] ?? [] as $item ) {
            $applied[] = self::apply_pipeline_theme( $item, $manifest, $batch_id, $env );
        }

        if ( ! empty( $proposed['core'] ) ) {
            $core_item  = isset( $proposed['core'][0] ) ? $proposed['core'][0] : $proposed['core'];
            $core_result = self::apply_pipeline_core( (array) $core_item, $manifest, $batch_id );
            // Core without a frozen artifact is deferred, not a failure.
            if ( ( $core_result['status'] ?? '' ) === 'deferred' ) {
                $deferred[] = $core_result;
            } else {
                $applied[] = $core_result;
            }
        }

        foreach ( $proposed['translations'] ?? [] as $item ) {
            $deferred[] = [
                'slug'   => $item['slug'] ?? '',
                'type'   => 'translation',
                'status' => 'deferred',
            ];
        }

        return [ 'applied' => $applied, 'deferred' => $deferred ];
    }

    /**
     * Verify that every plugin/theme item in the manifest has a pre-downloaded artifact
     * with a non-empty id, sha256, and matching version. Returns WP_Error on first failure.
     */
    private static function validate_manifest_artifacts( array $manifest, string $batch_id ): ?\WP_Error {
        $proposed  = $manifest['proposed_updates'] ?? [];
        $artifacts = $manifest['artifacts'] ?? [];

        $artifact_index = [];
        foreach ( $artifacts as $a ) {
            $a_id   = $a['id'] ?? '';
            $slug   = $a['slug'] ?? '';
            $type   = $a['type'] ?? '';
            $sha256 = $a['sha256'] ?? '';
            $ver    = $a['version'] ?? '';
            if ( $a_id !== '' && $slug !== '' && $type !== '' && $sha256 !== '' && $ver !== '' ) {
                $artifact_index[ $type . ':' . $slug ] = $a;
            }
        }

        $type_map = [ 'plugins' => 'plugin', 'themes' => 'theme' ];

        foreach ( $type_map as $item_type => $artifact_type ) {
            foreach ( $proposed[ $item_type ] ?? [] as $item ) {
                $slug = $item['slug'] ?? '';
                if ( $slug === '' ) {
                    continue;
                }
                $key = $artifact_type . ':' . $slug;
                if ( ! isset( $artifact_index[ $key ] ) ) {
                    return new \WP_Error(
                        'missing_artifact',
                        "Manifest item '$slug' ($item_type) has no pre-downloaded artifact with id, sha256, and version. " .
                        'All updates must be pre-downloaded by Plugin Center before deployment.'
                    );
                }
                $to_version       = $item['to_version'] ?? '';
                $artifact_version = $artifact_index[ $key ]['version'] ?? '';
                if ( $to_version !== '' && $artifact_version !== $to_version ) {
                    return new \WP_Error(
                        'artifact_version_mismatch',
                        "Artifact for '$slug' ($item_type) has version '$artifact_version' but manifest expects '$to_version'."
                    );
                }
            }
        }

        return null;
    }

    /**
     * Locate a single artifact by full identity: type, slug, and expected version.
     * Fails closed — returns WP_Error if any field is missing or the version doesn't match.
     * Use this everywhere instead of inline slug loops.
     */
    private static function find_artifact( array $manifest, string $type, string $slug, string $expected_version ): array|\WP_Error {
        foreach ( $manifest['artifacts'] ?? [] as $a ) {
            if ( ( $a['type'] ?? '' ) !== $type ) { continue; }
            if ( ( $a['slug'] ?? '' ) !== $slug )  { continue; }
            $a_id = $a['id']     ?? '';
            $sha  = $a['sha256'] ?? '';
            $ver  = $a['version'] ?? '';
            if ( $a_id === '' || $sha === '' || $ver === '' ) {
                return new \WP_Error( 'artifact_incomplete', "Artifact for $type '$slug' is missing id, sha256, or version." );
            }
            if ( $expected_version !== '' && $ver !== $expected_version ) {
                return new \WP_Error(
                    'artifact_version_mismatch',
                    "Artifact for $type '$slug' has version '$ver' but '$expected_version' is expected."
                );
            }
            return $a;
        }
        return new \WP_Error( 'artifact_not_found', "No artifact found for $type '$slug'." );
    }

    /**
     * Read the installed version of a plugin, theme, or WordPress core.
     * Used for post-install verification.
     */
    private static function get_installed_version( string $type, string $slug, ?string $file ): string {
        switch ( $type ) {
            case 'plugin':
                $plugin_file = $file ?: "$slug/$slug.php";
                if ( ! function_exists( 'get_plugin_data' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
                return $data['Version'] ?? '';
            case 'theme':
                $theme = wp_get_theme( $slug );
                return $theme->exists() ? ( $theme->get( 'Version' ) ?: '' ) : '';
            case 'core':
                return get_bloginfo( 'version' );
            default:
                return '';
        }
    }

    private static function apply_pipeline_plugin( array $item, array $manifest, string $batch_id, string $env ): array {
        $slug         = $item['slug'] ?? '';
        $file         = $item['file'] ?? "$slug/$slug.php";
        $from_version = $item['from_version'] ?? '';
        $to_version   = $item['to_version'] ?? '';
        $start_ts     = microtime( true );
        $result_base  = [
            'slug'              => $slug,
            'type'              => 'plugin',
            'success'           => false,
            'from_version'      => $from_version,
            'requested_version' => $to_version,
        ];

        if ( empty( $slug ) ) {
            $result_base['error'] = 'missing_slug';
            return $result_base;
        }

        // Locate artifact by full identity: type + slug + expected version.
        $artifact = self::find_artifact( $manifest, 'plugin', $slug, $to_version );
        if ( is_wp_error( $artifact ) ) {
            $result_base['error'] = $artifact->get_error_message();
            return $result_base;
        }

        $hub_url     = RP_Care_Pipeline_Client::get_hub_url_public();
        $token       = RP_Care_Pipeline_Client::get_token_public();
        $instance_id = (string) get_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, '' );

        $tmp_file = self::download_artifact_package( $artifact, $hub_url, $token, $instance_id );
        if ( is_wp_error( $tmp_file ) ) {
            $result_base['error'] = $tmp_file->get_error_message();
            return $result_base;
        }

        // Preserve activation state before upgrade.
        $was_active = function_exists( 'is_plugin_active' ) && is_plugin_active( $file );

        try {
            if ( ! class_exists( 'Plugin_Upgrader' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }

            $skin     = class_exists( 'WP_Ajax_Upgrader_Skin' ) ? new \WP_Ajax_Upgrader_Skin() : new \WP_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader( $skin );
            $result   = $upgrader->install( $tmp_file, [ 'overwrite_package' => true ] );

            @unlink( $tmp_file );

            if ( is_wp_error( $result ) ) {
                $result_base['error'] = $result->get_error_message();
                return $result_base;
            }

            // Restore activation state.
            if ( $was_active && function_exists( 'is_plugin_active' ) && ! is_plugin_active( $file ) ) {
                activate_plugin( $file, '', false, true );
            }

            // Post-install: verify installed version matches requested.
            $installed = self::get_installed_version( 'plugin', $slug, $file );
            if ( $to_version !== '' && $installed !== $to_version ) {
                $result_base['error']             = 'version_mismatch_after_install';
                $result_base['installed_version'] = $installed;
                return $result_base;
            }

            $result_base['success']           = true;
            $result_base['installed_version'] = $installed;
            $result_base['artifact_id']       = $artifact['id'];
            $result_base['sha256']            = $artifact['sha256'];
            $result_base['duration_ms']       = (int) ( ( microtime( true ) - $start_ts ) * 1000 );
            return $result_base;

        } catch ( \Throwable $e ) {
            @unlink( $tmp_file );
            $result_base['error'] = $e->getMessage();
            return $result_base;
        }
    }

    private static function apply_pipeline_theme( array $item, array $manifest, string $batch_id, string $env ): array {
        $slug         = $item['slug'] ?? '';
        $from_version = $item['from_version'] ?? '';
        $to_version   = $item['to_version'] ?? '';
        $start_ts     = microtime( true );
        $result_base  = [
            'slug'              => $slug,
            'type'              => 'theme',
            'success'           => false,
            'from_version'      => $from_version,
            'requested_version' => $to_version,
        ];

        if ( empty( $slug ) ) {
            $result_base['error'] = 'missing_slug';
            return $result_base;
        }

        // Locate artifact by full identity: type + slug + expected version.
        $artifact = self::find_artifact( $manifest, 'theme', $slug, $to_version );
        if ( is_wp_error( $artifact ) ) {
            $result_base['error'] = $artifact->get_error_message();
            return $result_base;
        }

        $hub_url     = RP_Care_Pipeline_Client::get_hub_url_public();
        $token       = RP_Care_Pipeline_Client::get_token_public();
        $instance_id = (string) get_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, '' );

        $tmp_file = self::download_artifact_package( $artifact, $hub_url, $token, $instance_id );
        if ( is_wp_error( $tmp_file ) ) {
            $result_base['error'] = $tmp_file->get_error_message();
            return $result_base;
        }

        try {
            if ( ! class_exists( 'Theme_Upgrader' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }

            $skin     = class_exists( 'WP_Ajax_Upgrader_Skin' ) ? new \WP_Ajax_Upgrader_Skin() : new \WP_Upgrader_Skin();
            $upgrader = new \Theme_Upgrader( $skin );
            $result   = $upgrader->install( $tmp_file, [ 'overwrite_package' => true ] );

            @unlink( $tmp_file );

            if ( is_wp_error( $result ) ) {
                $result_base['error'] = $result->get_error_message();
                return $result_base;
            }

            // Post-install: verify installed version matches requested.
            $installed = self::get_installed_version( 'theme', $slug, null );
            if ( $to_version !== '' && $installed !== $to_version ) {
                $result_base['error']             = 'version_mismatch_after_install';
                $result_base['installed_version'] = $installed;
                return $result_base;
            }

            $result_base['success']           = true;
            $result_base['installed_version'] = $installed;
            $result_base['artifact_id']       = $artifact['id'];
            $result_base['sha256']            = $artifact['sha256'];
            $result_base['duration_ms']       = (int) ( ( microtime( true ) - $start_ts ) * 1000 );
            return $result_base;

        } catch ( \Throwable $e ) {
            @unlink( $tmp_file );
            $result_base['error'] = $e->getMessage();
            return $result_base;
        }
    }

    private static function apply_pipeline_core( array $item, array $manifest, string $batch_id ): array {
        $from_version = $item['from_version'] ?? '';
        $to_version   = $item['to_version'] ?? '';
        $start_ts     = microtime( true );
        $result_base  = [
            'slug'              => 'wordpress',
            'type'              => 'core',
            'success'           => false,
            'from_version'      => $from_version,
            'requested_version' => $to_version,
        ];

        // Core must use a frozen artifact — no live WordPress.org downloads in pipeline.
        $artifact = self::find_artifact( $manifest, 'core', 'wordpress', $to_version );
        if ( is_wp_error( $artifact ) ) {
            // No pre-downloaded core artifact: defer to manual — do NOT pretend it was tested.
            return [
                'slug'    => 'wordpress',
                'type'    => 'core',
                'success' => true,
                'status'  => 'deferred',
                'note'    => 'Core updates require a pre-downloaded artifact in the manifest. Excluded from automated pipeline — apply manually.',
            ];
        }

        try {
            if ( ! class_exists( 'Core_Upgrader' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }

            $hub_url     = RP_Care_Pipeline_Client::get_hub_url_public();
            $token       = RP_Care_Pipeline_Client::get_token_public();
            $instance_id = (string) get_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, '' );

            $tmp_file = self::download_artifact_package( $artifact, $hub_url, $token, $instance_id );
            if ( is_wp_error( $tmp_file ) ) {
                $result_base['error'] = $tmp_file->get_error_message();
                return $result_base;
            }

            // Build a minimal update object using the local temp file.
            // WP_Upgrader::download_package() returns local paths unchanged, so no live WP.org request.
            $update_obj           = new \stdClass();
            $update_obj->response = 'upgrade';
            $update_obj->current  = $to_version;
            $update_obj->download = $tmp_file;
            $update_obj->packages = (object) [ 'full' => $tmp_file, 'no_content' => $tmp_file ];
            $update_obj->locale   = get_locale();

            $skin     = class_exists( 'WP_Ajax_Upgrader_Skin' ) ? new \WP_Ajax_Upgrader_Skin() : new \WP_Upgrader_Skin();
            $upgrader = new \Core_Upgrader( $skin );
            $result   = $upgrader->upgrade( $update_obj, [ 'allow_relaxed_file_ownership' => true ] );

            @unlink( $tmp_file );

            if ( is_wp_error( $result ) ) {
                $result_base['error'] = $result->get_error_message();
                return $result_base;
            }

            // Post-install: verify WP version matches requested.
            $installed = get_bloginfo( 'version' );
            if ( $to_version !== '' && $installed !== $to_version ) {
                $result_base['error']             = 'version_mismatch_after_install';
                $result_base['installed_version'] = $installed;
                return $result_base;
            }

            $result_base['success']           = true;
            $result_base['installed_version'] = $installed;
            $result_base['artifact_id']       = $artifact['id'];
            $result_base['sha256']            = $artifact['sha256'];
            $result_base['duration_ms']       = (int) ( ( microtime( true ) - $start_ts ) * 1000 );
            return $result_base;

        } catch ( \Throwable $e ) {
            $result_base['error'] = $e->getMessage();
            return $result_base;
        }
    }

    /**
     * Fetch a signed download URL from PC, download the artifact to a temp file,
     * and verify SHA-256. Returns the local temp path or WP_Error.
     */
    private static function download_artifact_package( array $artifact, string $hub_url, string $token, string $instance_id ): string|\WP_Error {
        $artifact_id = $artifact['id'] ?? '';
        if ( empty( $artifact_id ) ) {
            return new \WP_Error( 'missing_artifact_id', 'Artifact ID is missing.' );
        }

        $url_response = wp_remote_get(
            trailingslashit( $hub_url ) . 'wp-json/replanta-pc/v1/pipeline/artifact-url/' . rawurlencode( $artifact_id ),
            [
                'timeout' => 15,
                'headers' => [
                    'X-Pipeline-Token' => $token,
                    'X-Instance-ID'    => $instance_id,
                ],
            ]
        );

        if ( is_wp_error( $url_response ) ) {
            return $url_response;
        }

        $code = wp_remote_retrieve_response_code( $url_response );
        if ( $code !== 200 ) {
            return new \WP_Error( 'artifact_url_http_error', "Artifact URL fetch returned HTTP $code." );
        }

        $url_data = json_decode( wp_remote_retrieve_body( $url_response ), true );
        if ( ! is_array( $url_data ) || empty( $url_data['download_url'] ) ) {
            return new \WP_Error( 'artifact_url_invalid', 'Artifact URL response missing download_url.' );
        }

        $download_url    = $url_data['download_url'];
        $expected_sha256 = $url_data['sha256'] ?? ( $artifact['sha256'] ?? '' );

        $tmp_file = wp_tempnam( 'rpcare-artifact-' );
        if ( ! $tmp_file ) {
            return new \WP_Error( 'tmp_file_failed', 'Could not create temporary file for artifact download.' );
        }

        $dl_response = wp_remote_get( $download_url, [
            'timeout'  => 120,
            'stream'   => true,
            'filename' => $tmp_file,
        ] );

        if ( is_wp_error( $dl_response ) ) {
            @unlink( $tmp_file );
            return $dl_response;
        }

        $dl_code = wp_remote_retrieve_response_code( $dl_response );
        if ( $dl_code !== 200 ) {
            @unlink( $tmp_file );
            return new \WP_Error( 'artifact_download_http_error', "Artifact download returned HTTP $dl_code." );
        }

        if ( ! file_exists( $tmp_file ) || filesize( $tmp_file ) === 0 ) {
            @unlink( $tmp_file );
            return new \WP_Error( 'artifact_empty', 'Downloaded artifact file is empty.' );
        }

        // SHA-256 verification — fail closed on mismatch
        if ( ! empty( $expected_sha256 ) ) {
            $actual_sha256 = hash_file( 'sha256', $tmp_file );
            if ( ! hash_equals( $expected_sha256, (string) $actual_sha256 ) ) {
                @unlink( $tmp_file );
                return new \WP_Error( 'sha256_mismatch', 'Artifact SHA-256 verification failed.' );
            }
        }

        return $tmp_file;
    }

    /**
     * Create a synchronous, verifiable pre-update backup.
     *
     * UpdraftPlus is intentionally NOT used here — boot_backup() is asynchronous
     * and cannot be verified before proceeding. Returns a backup_id only after
     * integrity verification passes.
     *
     * Returns a backup_id string or WP_Error.
     */
    private static function create_pipeline_backup( string $batch_id ): string|\WP_Error {
        $backup_id = 'pipeline_' . sanitize_file_name( $batch_id ) . '_' . gmdate( 'YmdHis' );

        // 1. Try B2 backup (synchronous, independently verified).
        if ( class_exists( 'RP_Care_Task_Backup' ) && RP_Care_Task_Backup::is_b2_configured_public() ) {
            $b2_result = RP_Care_Task_Backup::create_b2_backup( [
                'reason' => 'pipeline_pre_update',
                'scopes' => [ 'database', 'plugins', 'themes' ],
            ] );
            if ( ! empty( $b2_result['success'] ) ) {
                $backup_id = $b2_result['backup_id'] ?? $backup_id;
                update_option( 'rpcare_pipeline_backup_' . $batch_id, [
                    'backup_id' => $backup_id,
                    'method'    => 'b2',
                    'timestamp' => time(),
                    'batch_id'  => $batch_id,
                    'prefix'    => $b2_result['prefix'] ?? '',
                    'verified'  => true,
                ] );
                return $backup_id;
            }
        }

        // 2. Fallback: synchronous filesystem snapshot (plugins + themes + DB export).
        $snapshot_dir = self::get_snapshot_dir() . '/pipeline-batch-' . sanitize_file_name( $batch_id ) . '-' . time();
        if ( ! wp_mkdir_p( $snapshot_dir ) ) {
            return new \WP_Error( 'snapshot_dir_failed', 'Could not create pipeline backup snapshot directory.' );
        }

        $plugins_ok = self::copy_directory( WP_PLUGIN_DIR, $snapshot_dir . '/plugins' );
        $themes_ok  = self::copy_directory( get_theme_root(), $snapshot_dir . '/themes' );
        $db_ok      = self::export_db_snapshot( $snapshot_dir );

        if ( ! $plugins_ok && ! $themes_ok ) {
            self::remove_dir_recursive( $snapshot_dir );
            return new \WP_Error( 'snapshot_failed', 'Filesystem snapshot failed for both plugins and themes.' );
        }

        // A backup without a DB export is incomplete and must not be accepted for production.
        if ( ! $db_ok ) {
            self::remove_dir_recursive( $snapshot_dir );
            return new \WP_Error( 'db_export_failed', 'Database export failed — filesystem snapshot without DB is not accepted for production backup.' );
        }

        $snapshot_hash = self::compute_snapshot_hash( $snapshot_dir );

        update_option( 'rpcare_pipeline_backup_' . $batch_id, [
            'backup_id'     => $backup_id,
            'method'        => 'filesystem_snapshot',
            'snapshot_dir'  => $snapshot_dir,
            'snapshot_hash' => $snapshot_hash,
            'db_included'   => $db_ok,
            'timestamp'     => time(),
            'batch_id'      => $batch_id,
        ] );

        if ( ! self::verify_backup_integrity( $backup_id, $batch_id ) ) {
            self::remove_dir_recursive( $snapshot_dir );
            delete_option( 'rpcare_pipeline_backup_' . $batch_id );
            return new \WP_Error( 'snapshot_verify_failed', 'Backup integrity check failed after creation.' );
        }

        return $backup_id;
    }

    /**
     * Export all database tables to a SQL file inside the snapshot directory.
     * Returns true on success, false if DB is unavailable.
     */
    private static function export_db_snapshot( string $snapshot_dir ): bool {
        global $wpdb;

        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
            return false;
        }

        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( empty( $tables ) ) {
            return false;
        }

        $sql = "-- Pipeline DB snapshot\n-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . "\n\n";
        foreach ( $tables as $table ) {
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( $create ) {
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $create[1] . ";\n\n";
            }
            $rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
            foreach ( (array) $rows as $row ) {
                $values = [];
                foreach ( $row as $v ) {
                    $values[] = $v === null ? 'NULL' : ( "'" . esc_sql( $v ) . "'" );
                }
                $sql .= 'INSERT INTO `' . $table . '` VALUES (' . implode( ', ', $values ) . ");\n";
            }
            $sql .= "\n";
        }

        return (bool) file_put_contents( $snapshot_dir . '/db.sql', $sql );
    }

    /**
     * Compute a deterministic hash of snapshot directory contents using SHA-256 of file contents.
     * A file rename-only or size-preserving content change both invalidate the hash.
     */
    private static function compute_snapshot_hash( string $snapshot_dir ): string {
        $manifest = [];
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $snapshot_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $it as $file ) {
                if ( $file->isFile() ) {
                    $rel              = str_replace( $snapshot_dir, '', $file->getPathname() );
                    $manifest[ $rel ] = hash_file( 'sha256', $file->getPathname() ) ?: '0';
                }
            }
        } catch ( \Throwable $e ) {
            return '';
        }
        ksort( $manifest );
        return hash( 'sha256', (string) wp_json_encode( $manifest ) );
    }

    /**
     * Verify that a previously created backup is still intact and readable.
     */
    private static function verify_backup_integrity( string $backup_id, string $batch_id ): bool {
        $record = get_option( 'rpcare_pipeline_backup_' . $batch_id );
        if ( ! is_array( $record ) || ( $record['backup_id'] ?? '' ) !== $backup_id ) {
            return false;
        }

        $method = $record['method'] ?? '';

        if ( $method === 'b2' ) {
            return ! empty( $record['verified'] );
        }

        if ( $method === 'filesystem_snapshot' ) {
            $snapshot_dir  = $record['snapshot_dir'] ?? '';
            $expected_hash = $record['snapshot_hash'] ?? '';

            if ( empty( $snapshot_dir ) || ! is_dir( $snapshot_dir ) || empty( $expected_hash ) ) {
                return false;
            }

            $actual_hash = self::compute_snapshot_hash( $snapshot_dir );
            return ! empty( $actual_hash ) && hash_equals( $expected_hash, $actual_hash );
        }

        return false;
    }

    /**
     * Restore a backup created by create_pipeline_backup().
     * Returns true on success, WP_Error on failure.
     */
    private static function restore_pipeline_backup( string $backup_id, string $batch_id ): true|\WP_Error {
        $record = get_option( 'rpcare_pipeline_backup_' . $batch_id );
        if ( ! is_array( $record ) ) {
            return new \WP_Error( 'backup_record_missing', 'No backup record found for batch.' );
        }

        $method = $record['method'] ?? '';

        if ( $method === 'filesystem_snapshot' ) {
            $snapshot_dir = $record['snapshot_dir'] ?? '';
            if ( empty( $snapshot_dir ) || ! is_dir( $snapshot_dir ) ) {
                return new \WP_Error( 'snapshot_dir_missing', 'Snapshot directory not found.' );
            }

            $plugins_snap = $snapshot_dir . '/plugins';
            $themes_snap  = $snapshot_dir . '/themes';

            if ( is_dir( $plugins_snap ) ) {
                self::remove_dir_recursive( WP_PLUGIN_DIR );
                if ( ! self::copy_directory( $plugins_snap, WP_PLUGIN_DIR ) ) {
                    return new \WP_Error( 'plugins_restore_failed', 'Failed to restore plugins from snapshot.' );
                }
            }

            if ( is_dir( $themes_snap ) ) {
                self::remove_dir_recursive( get_theme_root() );
                if ( ! self::copy_directory( $themes_snap, get_theme_root() ) ) {
                    return new \WP_Error( 'themes_restore_failed', 'Failed to restore themes from snapshot.' );
                }
            }

            // Restore DB from SQL dump — required for a complete rollback.
            $db_sql_file = $snapshot_dir . '/db.sql';
            if ( ( $record['db_included'] ?? false ) && is_file( $db_sql_file ) ) {
                $db_result = self::restore_db_from_snapshot( $db_sql_file );
                if ( is_wp_error( $db_result ) ) {
                    return $db_result;
                }
            }

            self::remove_dir_recursive( $snapshot_dir );
            return true;
        }

        if ( $method === 'b2' ) {
            if ( ! class_exists( 'RP_Care_Task_Backup' ) ) {
                return new \WP_Error( 'backup_class_missing', 'RP_Care_Task_Backup not available.' );
            }
            $restore = RP_Care_Task_Backup::restore_b2_backup( $backup_id, [ 'database', 'plugins', 'themes' ] );
            if ( is_wp_error( $restore ) ) {
                return $restore;
            }
            if ( empty( $restore['success'] ) ) {
                return new \WP_Error( 'b2_restore_failed', 'B2 restore reported failure.' );
            }
            return true;
        }

        if ( $method === 'updraftplus' ) {
            if ( ! class_exists( 'RP_Care_Task_Backup' ) ) {
                return new \WP_Error( 'backup_class_missing', 'RP_Care_Task_Backup not available.' );
            }
            $restore = RP_Care_Task_Backup::restore_updraftplus_backup( $backup_id, [ 'database', 'plugins', 'themes' ] );
            if ( is_wp_error( $restore ) ) {
                return $restore;
            }
            if ( empty( $restore['success'] ) ) {
                return new \WP_Error( 'udp_restore_failed', 'UpdraftPlus restore reported failure.' );
            }
            return true;
        }

        return new \WP_Error( 'unknown_backup_method', "Unknown backup method: $method" );
    }

    /**
     * POST a batch status update to Plugin Center. Non-throwing.
     */
    /**
     * Persist a batch status event to the outbox and attempt immediate delivery.
     * Uses RP_Care_Pipeline_Outbox::enqueue() so the event is durable before any HTTP
     * attempt — a crash between enqueue and delivery is recoverable by the scheduler.
     *
     * Returns the event_id UUID on success, or WP_Error if the outbox INSERT failed.
     * Callers may ignore the return value; the outbox scheduler handles retries.
     */
    private static function report_batch_to_pc( string $batch_id, string $status, array $item_results, array $deferred_items = [] ): string|\WP_Error {
        if ( ! class_exists( 'RP_Care_Pipeline_Client' ) || ! class_exists( 'RP_Care_Pipeline_Outbox' ) ) {
            return new \WP_Error( 'outbox_unavailable', 'Pipeline outbox not available.' );
        }

        $hub_url     = RP_Care_Pipeline_Client::get_hub_url_public();
        $token       = RP_Care_Pipeline_Client::get_token_public();
        $instance_id = (string) get_option( RP_Care_Pipeline_Client::OPT_INSTANCE_ID, '' );

        if ( empty( $hub_url ) || empty( $token ) || empty( $instance_id ) ) {
            return new \WP_Error( 'pipeline_not_configured', 'Hub URL, token, or instance_id missing.' );
        }

        // Map internal status strings to PC event names.
        switch ( $status ) {
            case 'staging_done':
                $event_name = 'staging_updated';
                $extra_data = [];
                break;
            case 'staging_failed':
                $event_name = 'operation_failed';
                $extra_data = [ 'reason' => 'staging_failed' ];
                break;
            case 'production_done':
                $event_name = 'production_updated';
                $extra_data = [];
                break;
            case 'production_failed':
                $event_name = 'operation_failed';
                $extra_data = [ 'reason' => 'production_failed' ];
                break;
            case 'rolled_back':
                $event_name = 'rollback_complete';
                $extra_data = [];
                break;
            case 'rollback_failed':
                $event_name = 'operation_failed';
                $extra_data = [ 'reason' => 'rollback_failed' ];
                break;
            case 'backup_complete':
                $event_name   = 'production_backup_complete';
                // backup_id and backup_verified must be top-level in data (PC reads $data['backup_id']).
                $extra_data   = $item_results;
                $item_results = [];
                break;
            default:
                $event_name = 'operation_failed';
                $extra_data = [ 'reason' => $status ];
                break;
        }

        $payload = [
            'data' => array_merge(
                [ 'item_results' => $item_results ],
                ! empty( $deferred_items ) ? [ 'deferred_items' => $deferred_items ] : [],
                $extra_data
            ),
            'instance_id' => $instance_id,
        ];

        // Persist to outbox FIRST — event is durable before any HTTP attempt.
        $event_id = RP_Care_Pipeline_Outbox::enqueue( $batch_id, $event_name, $payload );

        if ( is_wp_error( $event_id ) ) {
            if ( class_exists( 'RP_Care_Utils' ) ) {
                RP_Care_Utils::log( 'pipeline', 'error', 'report_batch_to_pc: outbox enqueue failed: ' . $event_id->get_error_message(), [
                    'batch_id' => $batch_id,
                    'status'   => $status,
                ] );
            }
            return $event_id;
        }

        // Best-effort immediate delivery; outbox scheduler retries on failure.
        RP_Care_Pipeline_Outbox::try_deliver_one( $event_id );

        return $event_id;
    }

    /**
     * Release the global batch concurrency lock, but only if it belongs to $batch_id.
     */
    private static function release_batch_lock( string $batch_id ): void {
        $current = (string) get_option( 'rpcare_batch_lock', '' );
        if ( $current === $batch_id ) {
            delete_option( 'rpcare_batch_lock' );
        }
    }

    /**
     * Try to acquire the global batch concurrency lock atomically.
     * Uses add_option() (INSERT-on-no-conflict) to prevent TOCTOU races.
     * Returns true if the lock was acquired (or is already held by this batch).
     */
    private static function acquire_batch_lock( string $batch_id ): bool {
        // add_option() maps to INSERT IGNORE — fails atomically if key already exists.
        if ( add_option( 'rpcare_batch_lock', $batch_id, '', 'no' ) ) {
            return true;
        }
        $current = (string) get_option( 'rpcare_batch_lock', '' );
        return $current === $batch_id; // already held by this batch (idempotent)
    }

    /**
     * Register Action Scheduler callbacks for the pipeline.
     * Called once from the plugin init hook (replanta-care.php).
     */
    public static function register_pipeline_hooks(): void {
        add_action( 'rpcare_create_production_backup', [ self::class, 'do_create_production_backup' ] );
    }

    /**
     * Restore a DB snapshot from a SQL dump file via wpdb.
     * Returns true on success, WP_Error on failure.
     */
    private static function restore_db_from_snapshot( string $sql_file ): true|\WP_Error {
        global $wpdb;

        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
            return new \WP_Error( 'db_unavailable', 'wpdb is not available for DB restore.' );
        }

        $sql = file_get_contents( $sql_file );
        if ( $sql === false ) {
            return new \WP_Error( 'db_restore_failed', 'Could not read DB snapshot SQL file.' );
        }

        // Execute each statement (split on ";\n" which matches our export format).
        $statements = explode( ";\n", $sql );
        foreach ( $statements as $stmt ) {
            $stmt = trim( $stmt );
            if ( empty( $stmt ) || str_starts_with( $stmt, '--' ) ) {
                continue;
            }
            $result = $wpdb->query( $stmt );
            if ( $result === false && ! empty( $wpdb->last_error ) ) {
                return new \WP_Error( 'db_restore_query_failed', 'DB restore failed: ' . $wpdb->last_error );
            }
        }

        return true;
    }
}

