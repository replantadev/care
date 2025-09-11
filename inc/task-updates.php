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
        $plan = RP_Care_Plan::get_current();
        $exclusions = RP_Care_Tasks::get_exclusions();
        
        $results = [
            'core' => null,
            'plugins' => [],
            'themes' => [],
            'translations' => []
        ];
        
        // Force refresh update transients
        wp_clean_update_cache();
        
        // Update WordPress core
        if (!$exclusions['core']) {
            $results['core'] = self::update_core();
        }
        
        // Update plugins
        $results['plugins'] = self::update_plugins($exclusions['plugins']);
        
        // Update themes
        $results['themes'] = self::update_themes($exclusions['themes']);
        
        // Update translations
        $results['translations'] = self::update_translations();
        
        // Log results
        $updated_count = count(array_filter($results['plugins'], function($r) { return $r['updated']; })) +
                        count(array_filter($results['themes'], function($r) { return $r['updated']; })) +
                        ($results['core']['updated'] ? 1 : 0);
        
        RP_Care_Utils::log('updates', 'success', "Updates completed: $updated_count items updated", $results);
        
        // Store last update check
        update_option('rpcare_last_update_check', current_time('mysql'));
        
        // Send critical alerts if needed
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
        
        // Check if it's a major version update and if auto-updates are disabled for major versions
        $current_version = get_bloginfo('version');
        $is_major = version_compare($current_version, $update->current, '<') && 
                   (int)$current_version !== (int)$update->current;
        
        if ($is_major && !get_option('rpcare_allow_major_updates', false)) {
            RP_Care_Utils::log('updates', 'info', "Major WordPress update available ({$update->current}) but auto-update disabled");
            return ['updated' => false, 'message' => 'Major update available but auto-update disabled', 'version' => $update->current];
        }
        
        // Perform backup before core update if plan allows
        if (RP_Care_Plan::can_access_feature('backups')) {
            RP_Care_Task_Backup::run(['reason' => 'pre_core_update']);
        }
        
        try {
            if (!class_exists('Core_Upgrader')) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }
            
            $upgrader = new Core_Upgrader(new WP_Upgrader_Skin());
            $result = $upgrader->upgrade($update);
            
            if (is_wp_error($result)) {
                return ['updated' => false, 'error' => $result->get_error_message()];
            }
            
            return ['updated' => true, 'version' => $update->current];
            
        } catch (Exception $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }
    }
    
    private static function update_plugins($exclusions = []) {
        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        
        $plugin_updates = get_plugin_updates();
        $results = [];
        
        if (empty($plugin_updates)) {
            return $results;
        }
        
        foreach ($plugin_updates as $plugin_file => $plugin_data) {
            // Skip excluded plugins
            if (in_array($plugin_file, $exclusions)) {
                $results[$plugin_file] = [
                    'updated' => false,
                    'message' => 'Excluded from auto-updates',
                    'name' => $plugin_data->Name ?? $plugin_file
                ];
                continue;
            }
            
            // Skip plugins that don't support auto-updates
            if (isset($plugin_data->auto_update) && !$plugin_data->auto_update) {
                $results[$plugin_file] = [
                    'updated' => false,
                    'message' => 'Auto-updates disabled by plugin',
                    'name' => $plugin_data->Name ?? $plugin_file
                ];
                continue;
            }
            
            try {
                if (!class_exists('Plugin_Upgrader')) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                }
                
                $upgrader = new Plugin_Upgrader(new WP_Upgrader_Skin());
                $result = $upgrader->upgrade($plugin_file);
                
                if (is_wp_error($result)) {
                    $results[$plugin_file] = [
                        'updated' => false,
                        'error' => $result->get_error_message(),
                        'name' => $plugin_data->Name ?? $plugin_file
                    ];
                } elseif ($result === true) {
                    $results[$plugin_file] = [
                        'updated' => true,
                        'name' => $plugin_data->Name ?? $plugin_file,
                        'new_version' => $plugin_data->update->new_version ?? 'unknown'
                    ];
                } else {
                    $results[$plugin_file] = [
                        'updated' => false,
                        'message' => 'Update failed',
                        'name' => $plugin_data->Name ?? $plugin_file
                    ];
                }
                
            } catch (Exception $e) {
                $results[$plugin_file] = [
                    'updated' => false,
                    'error' => $e->getMessage(),
                    'name' => $plugin_data->Name ?? $plugin_file
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
        $results = [];
        
        if (empty($theme_updates)) {
            return $results;
        }
        
        $current_theme = get_stylesheet();
        
        foreach ($theme_updates as $theme_slug => $theme_data) {
            // Skip excluded themes
            if (in_array($theme_slug, $exclusions)) {
                $results[$theme_slug] = [
                    'updated' => false,
                    'message' => 'Excluded from auto-updates',
                    'name' => $theme_data->get('Name')
                ];
                continue;
            }
            
            // Be extra careful with active theme
            if ($theme_slug === $current_theme) {
                // Create backup before updating active theme
                if (RP_Care_Plan::can_access_feature('backups')) {
                    RP_Care_Task_Backup::run(['reason' => 'pre_theme_update']);
                }
            }
            
            try {
                if (!class_exists('Theme_Upgrader')) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                }
                
                $upgrader = new Theme_Upgrader(new WP_Upgrader_Skin());
                $result = $upgrader->upgrade($theme_slug);
                
                if (is_wp_error($result)) {
                    $results[$theme_slug] = [
                        'updated' => false,
                        'error' => $result->get_error_message(),
                        'name' => $theme_data->get('Name')
                    ];
                } elseif ($result === true) {
                    $results[$theme_slug] = [
                        'updated' => true,
                        'name' => $theme_data->get('Name'),
                        'new_version' => $theme_data->update['new_version'] ?? 'unknown'
                    ];
                } else {
                    $results[$theme_slug] = [
                        'updated' => false,
                        'message' => 'Update failed',
                        'name' => $theme_data->get('Name')
                    ];
                }
                
            } catch (Exception $e) {
                $results[$theme_slug] = [
                    'updated' => false,
                    'error' => $e->getMessage(),
                    'name' => $theme_data->get('Name')
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
            
            $upgrader = new Language_Pack_Upgrader(new WP_Upgrader_Skin());
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
    
    public static function get_available_updates() {
        wp_clean_update_cache();
        
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
