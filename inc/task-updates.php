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

        $plan       = RP_Care_Plan::get_current();
        $exclusions = RP_Care_Tasks::get_exclusions();

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

        self::check_critical_updates($results);

        return $results;
    }
    
    private static function update_core() {
        if (!function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
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

        $plugin_updates = get_plugin_updates();
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
            $snapshot = self::snapshot_plugin($plugin_file);

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

                // Health check — rollback on failure
                if (!self::health_check()) {
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
            if (in_array($plugin_file, $critical_plugins) && !$result['updated'] && isset($result['error'])) {
                $critical_alerts[] = 'Critical plugin update failed: ' . $result['name'] . ' - ' . $result['error'];
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
     * Returns true when the site responds with a non-5xx status and the body
     * contains no PHP fatal-error markers.
     */
    private static function health_check(int $timeout = 15): bool {
        $result = wp_remote_get(home_url('/'), [
            'timeout'    => $timeout,
            'user-agent' => 'ReplantaCare/HealthCheck',
            'sslverify'  => false, // staging/local may use self-signed certs
        ]);

        if (is_wp_error($result)) {
            RP_Care_Utils::log('updates', 'error', 'Health check request failed: ' . $result->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($result);
        if ($code >= 500) {
            RP_Care_Utils::log('updates', 'error', "Health check returned HTTP $code");
            return false;
        }

        $body    = wp_remote_retrieve_body($result);
        $markers = ['Fatal error', 'Parse error', 'Call to undefined function', 'Call to undefined method'];
        foreach ($markers as $marker) {
            if (stripos($body, $marker) !== false) {
                RP_Care_Utils::log('updates', 'error', "Health check detected PHP error marker in response: '$marker'");
                return false;
            }
        }

        return true;
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
            $plugin_updates = get_plugin_updates();
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
}
