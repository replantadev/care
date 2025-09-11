<?php
/**
 * WPO (Web Performance Optimization) Task
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_WPO {
    
    public static function run($args = []) {
        $plan = RP_Care_Plan::get_current();
        $wpo_level = RP_Care_Plan::get_wpo_level($plan);
        
        $results = [
            'cache_purged' => false,
            'database_optimized' => false,
            'transients_cleaned' => false,
            'autoload_optimized' => false,
            'images_checked' => false,
            'webp_conversion' => false,
            'advanced_optimizations' => []
        ];
        
        // Basic WPO (all plans)
        $results['cache_purged'] = self::purge_cache();
        $results['transients_cleaned'] = self::clean_transients();
        $results['database_optimized'] = self::optimize_database();
        
        // Advanced WPO (Raíz and Ecosistema)
        if (in_array($wpo_level, ['advanced', 'premium'])) {
            $results['autoload_optimized'] = self::optimize_autoload();
            $results['images_checked'] = self::check_large_images();
            $results['webp_conversion'] = self::check_webp_support();
        }
        
        // Premium WPO (Ecosistema only)
        if ($wpo_level === 'premium') {
            $results['advanced_optimizations'] = self::run_premium_optimizations();
        }
        
        RP_Care_Utils::log('wpo', 'success', 'WPO tasks completed', $results);
        
        return $results;
    }
    
    private static function purge_cache() {
        $purged = [];
        
        // LiteSpeed Cache
        if (defined('LSCWP_V') && function_exists('do_action')) {
            do_action('litespeed_purge_all');
            $purged[] = 'LiteSpeed Cache';
        }
        
        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            call_user_func('rocket_clean_domain');
            $purged[] = 'WP Rocket';
        }
        
        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            call_user_func('w3tc_flush_all');
            $purged[] = 'W3 Total Cache';
        }
        
        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            call_user_func('wp_cache_clear_cache');
            $purged[] = 'WP Super Cache';
        }
        
        // WP Fastest Cache
        if (class_exists('WpFastestCache')) {
            $cache_class = 'WpFastestCache';
            $cache = new $cache_class();
            if (method_exists($cache, 'deleteCache')) {
                $cache->deleteCache();
                $purged[] = 'WP Fastest Cache';
            }
        }
        
        // Autoptimize
        if (class_exists('autoptimizeCache')) {
            $cache_class = 'autoptimizeCache';
            call_user_func(array($cache_class, 'clearall'));
            $purged[] = 'Autoptimize';
        }
        
        // WP built-in object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $purged[] = 'Object Cache';
        }
        
        // OpCache (if available)
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $purged[] = 'OpCache';
        }
        
        return !empty($purged) ? $purged : false;
    }
    
    private static function clean_transients() {
        global $wpdb;
        
        try {
            // Delete expired transients
            $expired = $wpdb->query(
                "DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b 
                WHERE a.option_name LIKE '_transient_%' 
                AND a.option_name NOT LIKE '_transient_timeout_%' 
                AND b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12))
                AND b.option_value < UNIX_TIMESTAMP()"
            );
            
            // Delete orphaned timeout options
            $orphaned = $wpdb->query(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE '_transient_timeout_%' 
                AND option_name NOT IN (
                    SELECT CONCAT('_transient_timeout_', SUBSTRING(option_name, 12)) 
                    FROM (SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%') AS temp
                )"
            );
            
            // Delete very old transients (older than 30 days)
            $old_transients = $wpdb->query(
                "DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b 
                WHERE a.option_name LIKE '_transient_%' 
                AND a.option_name NOT LIKE '_transient_timeout_%' 
                AND b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12))
                AND b.option_value < (UNIX_TIMESTAMP() - 2592000)" // 30 days
            );
            
            return ['expired' => $expired, 'orphaned' => $orphaned, 'old' => $old_transients];
            
        } catch (Exception $e) {
            RP_Care_Utils::log('wpo', 'error', 'Failed to clean transients: ' . $e->getMessage());
            return false;
        }
    }
    
    private static function optimize_database() {
        global $wpdb;
        
        $optimized_tables = [];
        
        try {
            // Get all WordPress tables
            $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
            
            foreach ($tables as $table) {
                // Skip very large tables during regular optimization
                $row_count = $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
                if ($row_count > 100000) {
                    continue;
                }
                
                $result = $wpdb->query("OPTIMIZE TABLE `$table`");
                if ($result !== false) {
                    $optimized_tables[] = $table;
                }
            }
            
            // Clean up spam comments
            $spam_deleted = $wpdb->query(
                "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam' AND comment_date < DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            
            // Clean up trash comments
            $trash_deleted = $wpdb->query(
                "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash' AND comment_date < DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            
            // Clean up post revisions (keep last 3)
            $revisions_deleted = $wpdb->query(
                "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' 
                AND ID NOT IN (
                    SELECT * FROM (
                        SELECT ID FROM {$wpdb->posts} 
                        WHERE post_type = 'revision' 
                        ORDER BY post_date DESC 
                        LIMIT 3
                    ) AS temp
                )"
            );
            
            return [
                'tables_optimized' => count($optimized_tables),
                'spam_comments_deleted' => $spam_deleted,
                'trash_comments_deleted' => $trash_deleted,
                'revisions_deleted' => $revisions_deleted
            ];
            
        } catch (Exception $e) {
            RP_Care_Utils::log('wpo', 'error', 'Database optimization failed: ' . $e->getMessage());
            return false;
        }
    }
    
    private static function optimize_autoload() {
        global $wpdb;
        
        try {
            // Find autoloaded options that are too large (> 100KB)
            $large_autoload = $wpdb->get_results(
                "SELECT option_name, CHAR_LENGTH(option_value) as size 
                FROM {$wpdb->options} 
                WHERE autoload = 'yes' 
                AND CHAR_LENGTH(option_value) > 102400 
                ORDER BY size DESC 
                LIMIT 20"
            );
            
            $optimized = [];
            
            foreach ($large_autoload as $option) {
                // Skip critical WordPress options
                $critical_options = ['active_plugins', 'stylesheet', 'template'];
                if (in_array($option->option_name, $critical_options)) {
                    continue;
                }
                
                // Set autoload to 'no' for large options
                $wpdb->update(
                    $wpdb->options,
                    ['autoload' => 'no'],
                    ['option_name' => $option->option_name],
                    ['%s'],
                    ['%s']
                );
                
                $optimized[] = [
                    'option' => $option->option_name,
                    'size' => RP_Care_Utils::format_bytes($option->size)
                ];
            }
            
            return $optimized;
            
        } catch (Exception $e) {
            RP_Care_Utils::log('wpo', 'error', 'Autoload optimization failed: ' . $e->getMessage());
            return false;
        }
    }
    
    private static function check_large_images() {
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'];
        $large_images = [];
        
        if (!is_dir($upload_path)) {
            return false;
        }
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($upload_path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $count = 0;
            
            foreach ($iterator as $file) {
                if ($count >= 100) break; // Limit check to avoid timeout
                
                $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                if (!in_array($extension, $image_extensions)) {
                    continue;
                }
                
                $size = $file->getSize();
                if ($size > 1048576) { // > 1MB
                    $large_images[] = [
                        'file' => str_replace($upload_path, '', $file->getPathname()),
                        'size' => RP_Care_Utils::format_bytes($size),
                        'bytes' => $size
                    ];
                }
                
                $count++;
            }
            
            // Sort by size (largest first)
            usort($large_images, function($a, $b) {
                return $b['bytes'] - $a['bytes'];
            });
            
            return array_slice($large_images, 0, 20); // Return top 20
            
        } catch (Exception $e) {
            RP_Care_Utils::log('wpo', 'error', 'Image check failed: ' . $e->getMessage());
            return false;
        }
    }
    
    private static function check_webp_support() {
        // Check if server supports WebP
        if (!function_exists('imagewebp')) {
            return ['supported' => false, 'reason' => 'GD extension does not support WebP'];
        }
        
        // Check for WebP conversion plugins
        $webp_plugins = [
            'WebP Converter for Media' => 'webp-converter-for-media/webp-converter-for-media.php',
            'Smush' => 'wp-smushit/wp-smush.php',
            'ShortPixel' => 'shortpixel-image-optimiser/wp-shortpixel.php',
            'Optimole' => 'optimole-wp/optimole-wp.php'
        ];
        
        $active_plugins = get_option('active_plugins', []);
        $detected_webp_plugin = null;
        
        foreach ($webp_plugins as $name => $plugin_file) {
            if (in_array($plugin_file, $active_plugins)) {
                $detected_webp_plugin = $name;
                break;
            }
        }
        
        // Check if .htaccess has WebP rules
        $htaccess_file = ABSPATH . '.htaccess';
        $has_webp_rules = false;
        
        if (is_readable($htaccess_file)) {
            $htaccess_content = file_get_contents($htaccess_file);
            $has_webp_rules = strpos($htaccess_content, 'webp') !== false;
        }
        
        return [
            'server_support' => true,
            'plugin_detected' => $detected_webp_plugin,
            'htaccess_rules' => $has_webp_rules,
            'recommendation' => $detected_webp_plugin ? 'WebP plugin active' : 'Consider installing a WebP plugin'
        ];
    }
    
    private static function run_premium_optimizations() {
        $optimizations = [];
        
        // Check and configure Redis if available
        if (class_exists('Redis') || extension_loaded('redis')) {
            $redis_status = self::check_redis_configuration();
            $optimizations['redis'] = $redis_status;
        }
        
        // Check and configure Memcached if available
        if (class_exists('Memcached') || extension_loaded('memcached')) {
            $memcached_status = self::check_memcached_configuration();
            $optimizations['memcached'] = $memcached_status;
        }
        
        // CDN configuration check
        $cdn_status = self::check_cdn_configuration();
        $optimizations['cdn'] = $cdn_status;
        
        // Database query optimization
        $query_optimization = self::optimize_database_queries();
        $optimizations['query_optimization'] = $query_optimization;
        
        return $optimizations;
    }
    
    private static function check_redis_configuration() {
        if (!class_exists('Redis')) {
            return ['available' => false, 'message' => 'Redis extension not available'];
        }
        
        try {
            $redis_class = 'Redis';
            $redis = new $redis_class();
            $connected = $redis->connect('127.0.0.1', 6379, 1);
            
            if ($connected) {
                $redis->close();
                return [
                    'available' => true,
                    'connected' => true,
                    'recommendation' => 'Redis is available and working'
                ];
            } else {
                return [
                    'available' => true,
                    'connected' => false,
                    'recommendation' => 'Redis is available but not running'
                ];
            }
        } catch (Exception $e) {
            return [
                'available' => true,
                'connected' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private static function check_memcached_configuration() {
        if (!class_exists('Memcached')) {
            return ['available' => false, 'message' => 'Memcached extension not available'];
        }
        
        try {
            $memcached_class = 'Memcached';
            $memcached = new $memcached_class();
            $memcached->addServer('127.0.0.1', 11211);
            $version = $memcached->getVersion();
            
            if ($version !== false) {
                return [
                    'available' => true,
                    'connected' => true,
                    'version' => array_values($version)[0],
                    'recommendation' => 'Memcached is available and working'
                ];
            } else {
                return [
                    'available' => true,
                    'connected' => false,
                    'recommendation' => 'Memcached is available but not running'
                ];
            }
        } catch (Exception $e) {
            return [
                'available' => true,
                'connected' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private static function check_cdn_configuration() {
        // Check for common CDN plugins
        $cdn_plugins = [
            'MaxCDN / StackPath' => 'w3-total-cache/w3-total-cache.php',
            'Cloudflare' => 'cloudflare/cloudflare.php',
            'CDN Enabler' => 'cdn-enabler/cdn-enabler.php',
            'WP Rocket CDN' => 'wp-rocket/wp-rocket.php'
        ];
        
        $active_plugins = get_option('active_plugins', []);
        $detected_cdn = null;
        
        foreach ($cdn_plugins as $name => $plugin_file) {
            if (in_array($plugin_file, $active_plugins)) {
                $detected_cdn = $name;
                break;
            }
        }
        
        return [
            'plugin_detected' => $detected_cdn,
            'recommendation' => $detected_cdn ? 'CDN plugin active' : 'Consider setting up a CDN'
        ];
    }
    
    private static function optimize_database_queries() {
        global $wpdb;
        
        try {
            // Check for slow query log
            $slow_query_log = $wpdb->get_var("SHOW VARIABLES LIKE 'slow_query_log'");
            
            // Check current query cache status
            $query_cache = $wpdb->get_results("SHOW VARIABLES LIKE 'query_cache%'", OBJECT_K);
            
            // Check for missing indexes on postmeta table
            $missing_indexes = [];
            
            $postmeta_indexes = $wpdb->get_results(
                "SHOW INDEX FROM {$wpdb->postmeta} WHERE Column_name IN ('meta_key', 'meta_value')"
            );
            
            $has_meta_key_index = false;
            foreach ($postmeta_indexes as $index) {
                if ($index->Column_name === 'meta_key') {
                    $has_meta_key_index = true;
                    break;
                }
            }
            
            if (!$has_meta_key_index) {
                $missing_indexes[] = 'meta_key index on postmeta table';
            }
            
            return [
                'slow_query_log' => $slow_query_log,
                'query_cache_enabled' => isset($query_cache['query_cache_type']) && $query_cache['query_cache_type']->Value !== 'OFF',
                'missing_indexes' => $missing_indexes,
                'recommendations' => count($missing_indexes) > 0 ? 'Database could benefit from additional indexes' : 'Database indexes look good'
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
