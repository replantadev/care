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

        // Medición ANTES (estado actual con su caché)
        $perf_before = self::measure_response_time();
        $cwv_last = get_option('rpcare_cwv_last');

        // Basic WPO (all plans)
        $results['cache_purged'] = self::purge_cache();
        $results['transients_cleaned'] = self::clean_transients();
        $results['database_optimized'] = self::optimize_database();
        $results['lscache_preset'] = self::apply_lscache_presets();
        $results['orphan_media'] = self::scan_orphan_media();

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

        // Medición DESPUÉS (con caché regenerada por el warm-up interno)
        $perf_after = self::measure_response_time();

        $wpo_perf = [
            'measured_at' => current_time('mysql'),
            'before' => [
                'response_ms' => $perf_before,
                'psi_mobile'  => $cwv_last['mobile']['scores']['performance'] ?? null,
                'psi_desktop' => $cwv_last['desktop']['scores']['performance'] ?? null,
            ],
            'after' => [
                'response_ms' => $perf_after,
                'psi_mobile'  => null,
                'psi_desktop' => null,
            ],
            'psi_pending' => true,
        ];
        update_option('rpcare_wpo_perf', $wpo_perf, false);

        // PSI tarda 30-60s por estrategia: medir "después" en segundo plano (~5 min)
        if (!wp_next_scheduled('rpcare_wpo_after_cwv')) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'rpcare_wpo_after_cwv');
        }

        $mejora = null;
        if ($perf_before && $perf_after && $perf_before > 0) {
            $mejora = round((($perf_before - $perf_after) / $perf_before) * 100, 1);
        }
        $results['rendimiento'] = [
            'respuesta_antes_ms'   => $perf_before,
            'respuesta_despues_ms' => $perf_after,
            'mejora'               => $mejora !== null ? ($mejora >= 0 ? "-{$mejora}% tiempo de respuesta" : '+' . abs($mejora) . '% tiempo de respuesta') : 'sin datos',
            'pagespeed'            => 'Medición PageSpeed programada (resultado en ~5 min)',
        ];

        RP_Care_Utils::log('wpo', 'success', 'WPO tasks completed', $results);

        return $results;
    }

    /**
     * Mide el tiempo de respuesta de la portada (ms). Warm-up + mejor de 2 mediciones.
     */
    private static function measure_response_time() {
        $args = [
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => ['Cache-Control' => 'no-cache, no-store', 'Pragma' => 'no-cache'],
        ];
        // Unique query param bypasses CDN and server page caches on every call
        $url = add_query_arg('rpcare_measure', wp_generate_password(8, false), home_url('/'));
        wp_remote_get($url, $args); // warm-up

        $times = [];
        for ($i = 0; $i < 2; $i++) {
            $url   = add_query_arg('rpcare_measure', wp_generate_password(8, false), home_url('/'));
            $start = microtime(true);
            $res   = wp_remote_get($url, $args);
            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) < 500) {
                $times[] = (microtime(true) - $start) * 1000;
            }
        }

        return !empty($times) ? (int) round(min($times)) : null;
    }

    /**
     * Captura PageSpeed "después" en segundo plano tras un WPO.
     */
    public static function capture_after_cwv() {
        if (!class_exists('RP_Care_Task_CWV')) {
            return;
        }
        $payload = RP_Care_Task_CWV::run();
        $perf = get_option('rpcare_wpo_perf');
        if (!is_array($perf)) {
            return;
        }
        $perf['after']['psi_mobile']  = $payload['mobile']['scores']['performance'] ?? null;
        $perf['after']['psi_desktop'] = $payload['desktop']['scores']['performance'] ?? null;
        $perf['psi_pending'] = false;
        update_option('rpcare_wpo_perf', $perf, false);
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
            return ['supported' => false, 'reason' => 'La extensión GD no soporta WebP'];
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
            'recommendation' => $detected_webp_plugin ? 'Plugin WebP activo' : 'Considera instalar un plugin de conversión WebP'
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
                    'recommendation' => 'Redis disponible y funcionando'
                ];
            } else {
                return [
                    'available' => true,
                    'connected' => false,
                    'recommendation' => 'Redis disponible pero no está en ejecución'
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
                    'recommendation' => 'Memcached disponible y funcionando'
                ];
            } else {
                return [
                    'available' => true,
                    'connected' => false,
                    'recommendation' => 'Memcached disponible pero no está en ejecución'
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
            'recommendation' => $detected_cdn ? 'Plugin CDN activo' : 'Considera configurar un CDN'
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
                'recommendations' => count($missing_indexes) > 0 ? 'La base de datos podría beneficiarse de índices adicionales' : 'Los índices de la base de datos están correctos'
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private static function apply_lscache_presets() {
        if (!defined('LSCWP_V')) {
            return ['active' => false, 'reason' => 'LiteSpeed Cache no instalado'];
        }

        // Recommended presets for a generic WP/Woo site
        $presets = [
            'cache'                          => 1,
            'cache-mobile'                   => 0,
            'cache-browser'                  => 1,
            'cache-login'                    => 0,
            'cache-favicon'                  => 1,
            'cache-resources'                => 1,
            'cache-priv'                     => 1,
            'cache-rest'                     => 1,
            'cache-ttl_pub'                  => 604800,
            'cache-ttl_priv'                 => 1800,
            'cache-ttl_frontpage'            => 604800,
            'optm-css_min'                   => 1,
            'optm-js_min'                    => 1,
            'optm-html_min'                  => 1,
            'optm-qs_rm'                     => 1,
            'optm-ggfonts_async'             => 1,
            'media-lazy'                     => 1,
            'media-iframe_lazy'              => 1,
            'media-lazy_placeholder'         => 1,
            'object'                         => 0,
        ];

        $applied = 0;
        if (class_exists('\\LiteSpeed\\Conf') && method_exists('\\LiteSpeed\\Conf', 'cls')) {
            try {
                $conf = \LiteSpeed\Conf::cls();
                foreach ($presets as $key => $value) {
                    if (method_exists($conf, 'update')) {
                        $conf->update($key, $value);
                        $applied++;
                    }
                }
            } catch (\Throwable $e) {
                return ['active' => true, 'error' => $e->getMessage()];
            }
        }

        do_action('litespeed_purge_all');
        return ['active' => true, 'presets_applied' => $applied];
    }

    private static function scan_orphan_media() {
        if (!class_exists('RP_Care_Task_OrphanMedia')) {
            return ['skipped' => 'module_missing'];
        }
        $result = RP_Care_Task_OrphanMedia::scan();
        return [
            'orphans' => $result['orphans'] ?? 0,
            'checked' => $result['checked'] ?? 0,
        ];
    }
}

add_action('rpcare_wpo_after_cwv', ['RP_Care_Task_WPO', 'capture_after_cwv']);
