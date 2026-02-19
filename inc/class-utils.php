<?php
/**
 * Utility functions class
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Utils {
    
    public static function log($task_type, $status, $message, $data = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rpcare_logs';
        
        $result = $wpdb->insert(
            $table_name,
            [
                'task_type' => sanitize_text_field($task_type),
                'status' => sanitize_text_field($status),
                'message' => sanitize_textarea_field($message),
                'data' => $data ? wp_json_encode($data) : null,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
        
        // Also log to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[Replanta Care - $task_type] $status: $message");
        }
        
        return $result !== false;
    }
    
    public static function get_logs($limit = 50, $task_type = null, $status = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rpcare_logs';
        $where_clauses = [];
        $values = [];
        
        if ($task_type) {
            $where_clauses[] = 'task_type = %s';
            $values[] = $task_type;
        }
        
        if ($status) {
            $where_clauses[] = 'status = %s';
            $values[] = $status;
        }
        
        $where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);
        $values[] = (int) $limit;
        
        $sql = "SELECT * FROM $table_name $where_sql ORDER BY created_at DESC LIMIT %d";
        
        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }
    
    public static function clean_old_logs($days = 30) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rpcare_logs';
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE created_at < %s",
                $cutoff_date
            )
        );
        
        self::log('maintenance', 'info', "Cleaned $deleted old log entries older than $days days");
        
        return $deleted;
    }

    /**
     * Run all cleanup/maintenance tasks.
     * Hooked to rpcare_task_maintenance (daily) and rpcare_daily_check.
     *
     * @param mixed $args Ignored – present so it can be used as filter callback.
     * @return array Summary of cleanup actions performed.
     */
    public static function cleanup_all($args = []) {
        $results = [];

        // 1. Clean rpcare_logs table (older than 30 days)
        $results['logs_deleted'] = self::clean_old_logs(30);

        // 2. Clean rpcare_404_logs – remove entries older than 90 days
        $results['404_logs_deleted'] = self::clean_old_404_logs(90);

        // 3. Clean expired rpcare transients from wp_options
        $results['transients_deleted'] = self::clean_expired_transients();

        // 4. Rotate / trim the PHP debug.log if it exceeds 50 MB
        $results['debug_log_rotated'] = self::rotate_debug_log(50);

        // 5. Remove old backup directories (older than 30 days)
        $results['old_backups_deleted'] = self::clean_old_backups(30);

        self::log('maintenance', 'success', 'Daily cleanup completed', $results);

        return $results;
    }

    /**
     * Delete rows from rpcare_404_logs older than $days days.
     */
    public static function clean_old_404_logs($days = 90) {
        global $wpdb;

        $table = $wpdb->prefix . 'rpcare_404_logs';

        // Bail if table doesn't exist yet
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return 0;
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE last_seen < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));

        return $deleted !== false ? $deleted : 0;
    }

    /**
     * Remove expired transients that belong to this plugin.
     */
    public static function clean_expired_transients() {
        global $wpdb;

        $now = time();
        $count = 0;

        // Find transient timeout keys that have our prefix and are expired
        $expired = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_value < %d",
            $wpdb->esc_like('_transient_timeout_rpcare_') . '%',
            $now
        ));

        foreach ($expired as $timeout_key) {
            // _transient_timeout_rpcare_xxx → rpcare_xxx
            $transient_name = str_replace('_transient_timeout_', '', $timeout_key);
            delete_transient($transient_name);
            $count++;
        }

        // Also clean any orphaned rpcare_ transients that lost their timeout row
        $orphaned = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_name NOT LIKE %s",
            $wpdb->esc_like('_transient_rpcare_') . '%',
            $wpdb->esc_like('_transient_timeout_rpcare_') . '%'
        ));

        foreach ($orphaned as $opt) {
            $transient_name = str_replace('_transient_', '', $opt);
            // Only delete if there is no corresponding timeout key
            $timeout_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
                '_transient_timeout_' . $transient_name
            ));
            if (!$timeout_exists) {
                delete_transient($transient_name);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Rotate the PHP debug.log when it exceeds $max_mb megabytes.
     * Keeps one .old backup and truncates the current file.
     *
     * @param int $max_mb Maximum size in megabytes before rotation.
     * @return bool Whether a rotation was performed.
     */
    public static function rotate_debug_log($max_mb = 50) {
        $log_file = WP_CONTENT_DIR . '/debug.log';

        if (!file_exists($log_file)) {
            return false;
        }

        $size_mb = filesize($log_file) / (1024 * 1024);
        if ($size_mb < $max_mb) {
            return false;
        }

        // Rotate: rename current to .old (overwrites previous .old)
        $old_file = WP_CONTENT_DIR . '/debug.old.log';
        @rename($log_file, $old_file);

        // Create a fresh empty file so PHP can keep logging
        @file_put_contents($log_file, '');

        return true;
    }

    /**
     * Remove rpcare backup directories older than $days days.
     *
     * @param int $days Maximum age in days.
     * @return int Number of backup directories removed.
     */
    public static function clean_old_backups($days = 30) {
        if (!class_exists('RP_Care_Task_Backup')) {
            return 0;
        }

        // Reconstruct the base dir the same way integrations-backup.php does.
        $secret = get_option('rpcare_backup_dir_secret');
        if (!$secret) {
            return 0;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'] . '/rpcare-backups-' . $secret;

        if (!is_dir($base_dir)) {
            return 0;
        }

        $cutoff  = time() - ($days * DAY_IN_SECONDS);
        $removed = 0;

        foreach (new DirectoryIterator($base_dir) as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            // Each sub-directory is named Y-m-d_H-i-s
            if ($item->getMTime() < $cutoff) {
                self::delete_directory($item->getPathname());
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Recursively delete a directory and its contents.
     */
    private static function delete_directory($dir) {
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
    
    public static function send_notification($type, $subject, $message, $data = null) {
        $hub_url = get_option('rpcare_hub_url', 'https://sitios.replanta.dev');
        $token = get_option('rpcare_token', '');
        $site_url = get_site_url();
        
        if (empty($token)) {
            return false;
        }
        
        $payload = [
            'type' => $type,
            'subject' => $subject,
            'message' => $message,
            'site_url' => $site_url,
            'timestamp' => time(),
            'data' => $data
        ];
        
        $response = wp_remote_post($hub_url . '/api/v1/notifications', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 15
        ]);
        
        if (is_wp_error($response)) {
            self::log('notification', 'error', 'Failed to send notification: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            self::log('notification', 'error', "Notification failed with status $status_code");
            return false;
        }
        
        return true;
    }
    
    public static function get_site_metrics() {
        $metrics = [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'disk_usage' => self::get_disk_usage(),
            'plugin_count' => count(get_option('active_plugins', [])),
            'theme' => get_template(),
            'multisite' => is_multisite(),
            'ssl_enabled' => is_ssl(),
            'debug_enabled' => defined('WP_DEBUG') && WP_DEBUG,
            'caching_detected' => self::detect_caching_plugins(),
            'seo_plugin' => self::detect_seo_plugin(),
            'backup_plugin' => self::detect_backup_plugin(),
            'last_update' => get_option('rpcare_last_update', ''),
            'uptime' => self::get_uptime_status()
        ];
        
        return $metrics;
    }
    
    public static function get_disk_usage() {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'];
        
        if (!is_dir($path)) {
            return 0;
        }
        
        try {
            $size = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
            
            return $size;
        } catch (Exception $e) {
            return 0;
        }
    }
    
    public static function detect_caching_plugins() {
        $caching_plugins = [
            'LiteSpeed Cache' => 'litespeed-cache/litespeed-cache.php',
            'WP Rocket' => 'wp-rocket/wp-rocket.php',
            'W3 Total Cache' => 'w3-total-cache/w3-total-cache.php',
            'WP Super Cache' => 'wp-super-cache/wp-cache.php',
            'Autoptimize' => 'autoptimize/autoptimize.php',
            'WP Fastest Cache' => 'wp-fastest-cache/wpFastestCache.php'
        ];
        
        $active_plugins = get_option('active_plugins', []);
        $detected = [];
        
        foreach ($caching_plugins as $name => $plugin_file) {
            if (in_array($plugin_file, $active_plugins)) {
                $detected[] = $name;
            }
        }
        
        return $detected;
    }
    
    public static function detect_seo_plugin() {
        $seo_plugins = [
            'Yoast SEO' => 'wordpress-seo/wp-seo.php',
            'Rank Math' => 'seo-by-rank-math/rank-math.php',
            'All in One SEO' => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
            'SEOPress' => 'wp-seopress/seopress.php'
        ];
        
        $active_plugins = get_option('active_plugins', []);
        
        foreach ($seo_plugins as $name => $plugin_file) {
            if (in_array($plugin_file, $active_plugins)) {
                return $name;
            }
        }
        
        return 'None';
    }
    
    public static function detect_backup_plugin() {
        $backup_plugins = [
            'UpdraftPlus' => 'updraftplus/updraftplus.php',
            'BackupBuddy' => 'backupbuddy/backupbuddy.php',
            'Duplicator' => 'duplicator/duplicator.php',
            'BackWPup' => 'backwpup/backwpup.php',
            'WP Backups' => 'wp-backups/wp-backups.php'
        ];
        
        $active_plugins = get_option('active_plugins', []);
        
        foreach ($backup_plugins as $name => $plugin_file) {
            if (in_array($plugin_file, $active_plugins)) {
                return $name;
            }
        }
        
        return 'None';
    }
    
    public static function get_uptime_status() {
        $last_check = get_option('rpcare_last_uptime_check', 0);
        $status = get_option('rpcare_uptime_status', 'unknown');
        
        // If it's been more than 5 minutes since last check, consider it as "up" since we're running
        if (time() - $last_check > 300) {
            update_option('rpcare_last_uptime_check', time());
            update_option('rpcare_uptime_status', 'up');
            return 'up';
        }
        
        return $status;
    }
    
    public static function format_bytes($size, $precision = 2) {
        $base = log($size, 1024);
        $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }
    
    public static function is_plugin_active($plugin_file) {
        return in_array($plugin_file, get_option('active_plugins', []));
    }
    
    public static function get_wp_config_value($constant) {
        return defined($constant) ? constant($constant) : null;
    }
    
    public static function sanitize_filename($filename) {
        // Remove special characters and spaces
        $filename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $filename);
        return trim($filename, '.-_');
    }
    
    public static function validate_url($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    public static function validate_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    public static function get_client_ip() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    public static function schedule_single_event($hook, $args = [], $delay = 0) {
        $timestamp = time() + $delay;
        return wp_schedule_single_event($timestamp, $hook, $args);
    }
    
    public static function get_performance_score() {
        // Simple performance heuristic based on various factors
        $score = 100;
        
        // PHP version penalty
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            $score -= 10;
        }
        
        // Memory limit check
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit && intval($memory_limit) < 256) {
            $score -= 5;
        }
        
        // Plugin count penalty (more than 30 plugins)
        $plugin_count = count(get_option('active_plugins', []));
        if ($plugin_count > 30) {
            $score -= ($plugin_count - 30);
        }
        
        // No caching plugin penalty
        $caching = self::detect_caching_plugins();
        if (empty($caching)) {
            $score -= 15;
        }
        
        return max(0, $score);
    }
    
    /**
     * Calculate overall health score based on multiple factors
     */
    public static function calculate_health_score() {
        $scores = [];
        
        // Performance score (25%)
        $scores['performance'] = self::get_performance_score() * 0.25;
        
        // Security score (30%)
        $security_score = 100;
        if (!is_ssl()) $security_score -= 20;
        if (!self::check_wp_version()) $security_score -= 15;
        if (self::count_vulnerable_plugins() > 0) $security_score -= 25;
        $scores['security'] = max(0, $security_score) * 0.30;
        
        // Updates score (20%)
        $update_score = 100;
        $pending_updates = self::get_pending_updates_count();
        $update_score -= min($pending_updates * 10, 50);
        $scores['updates'] = max(0, $update_score) * 0.20;
        
        // Backup score (15%)
        $backup_score = 100;
        $last_backup = get_option('rpcare_last_backup', '');
        if (empty($last_backup)) {
            $backup_score = 0;
        } else {
            $days_since_backup = (time() - strtotime($last_backup)) / DAY_IN_SECONDS;
            if ($days_since_backup > 7) $backup_score -= 30;
            if ($days_since_backup > 30) $backup_score -= 50;
        }
        $scores['backup'] = max(0, $backup_score) * 0.15;
        
        // SEO score (10%)
        $seo_score = 100;
        if (!self::has_sitemap()) $seo_score -= 25;
        if (!self::has_robots_txt()) $seo_score -= 15;
        if (!self::has_analytics()) $seo_score -= 20;
        $scores['seo'] = max(0, $seo_score) * 0.10;
        
        $total_score = array_sum($scores);
        return round($total_score, 1);
    }
    
    private static function check_wp_version() {
        global $wp_version;
        $latest_version = get_transient('rpcare_latest_wp_version');
        
        if (!$latest_version) {
            $response = wp_remote_get('https://api.wordpress.org/core/version-check/1.7/');
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($body['offers'][0]['version'])) {
                    $latest_version = $body['offers'][0]['version'];
                    set_transient('rpcare_latest_wp_version', $latest_version, HOUR_IN_SECONDS);
                }
            }
        }
        
        return $latest_version ? version_compare($wp_version, $latest_version, '>=') : true;
    }
    
    private static function count_vulnerable_plugins() {
        // This would integrate with vulnerability databases
        // For now, return 0 as a placeholder
        return 0;
    }
    
    private static function get_pending_updates_count() {
        $core_updates = get_core_updates();
        $plugin_updates = get_plugin_updates();
        $theme_updates = get_theme_updates();
        
        return count($core_updates) + count($plugin_updates) + count($theme_updates);
    }
    
    private static function has_sitemap() {
        $sitemap_urls = [
            home_url('/sitemap.xml'),
            home_url('/sitemap_index.xml'),
            home_url('/wp-sitemap.xml')
        ];
        
        foreach ($sitemap_urls as $url) {
            $response = wp_remote_head($url, ['timeout' => 10]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                return true;
            }
        }
        
        return false;
    }
    
    private static function has_robots_txt() {
        $robots_url = home_url('/robots.txt');
        $response = wp_remote_head($robots_url, ['timeout' => 10]);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    private static function has_analytics() {
        $analytics_plugins = [
            'google-analytics-for-wordpress/googleanalytics.php',
            'ga-google-analytics/ga-google-analytics.php',
            'google-analytics-dashboard-for-wp/gadwp.php'
        ];
        
        foreach ($analytics_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return true;
            }
        }
        
        return false;
    }
}
