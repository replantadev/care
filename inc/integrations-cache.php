<?php
/**
 * Cache Integration Task
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Cache {
    
    public static function run($args = []) {
        $results = [];
        $cache_plugins = self::detect_cache_plugins();
        
        if (empty($cache_plugins)) {
            return [
                'success' => true,
                'message' => 'No cache plugins detected',
                'plugins_cleared' => []
            ];
        }
        
        foreach ($cache_plugins as $plugin => $data) {
            $clear_result = self::clear_cache_plugin($plugin, $data);
            $results[$plugin] = $clear_result;
        }
        
        // Also clear WordPress object cache and opcache
        $results['wp_object_cache'] = self::clear_wp_object_cache();
        $results['opcache'] = self::clear_opcache();
        
        $success_count = count(array_filter($results, function($r) { return $r['success']; }));
        $total_count = count($results);
        
        $overall_result = [
            'success' => $success_count > 0,
            'message' => sprintf('Cleared cache for %d/%d systems', $success_count, $total_count),
            'details' => $results,
            'plugins_detected' => array_keys($cache_plugins)
        ];
        
        RP_Care_Utils::log('cache', $overall_result['success'] ? 'success' : 'error', $overall_result['message'], $overall_result);
        
        return $overall_result;
    }
    
    private static function detect_cache_plugins() {
        $cache_plugins = [];
        
        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            $cache_plugins['wp_rocket'] = [
                'name' => 'WP Rocket',
                'function' => 'rocket_clean_domain'
            ];
        }
        
        // W3 Total Cache
        if (class_exists('W3_Plugin_TotalCache')) {
            $cache_plugins['w3tc'] = [
                'name' => 'W3 Total Cache',
                'class' => 'W3_Plugin_TotalCache'
            ];
        }
        
        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            $cache_plugins['wp_super_cache'] = [
                'name' => 'WP Super Cache',
                'function' => 'wp_cache_clear_cache'
            ];
        }
        
        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API')) {
            $cache_plugins['litespeed'] = [
                'name' => 'LiteSpeed Cache',
                'class' => 'LiteSpeed_Cache_API'
            ];
        }
        
        // WP Fastest Cache
        if (class_exists('WpFastestCache')) {
            $cache_plugins['wp_fastest_cache'] = [
                'name' => 'WP Fastest Cache',
                'class' => 'WpFastestCache'
            ];
        }
        
        // Cachify
        if (class_exists('Cachify')) {
            $cache_plugins['cachify'] = [
                'name' => 'Cachify',
                'class' => 'Cachify'
            ];
        }
        
        // Comet Cache
        if (class_exists('comet_cache')) {
            $cache_plugins['comet_cache'] = [
                'name' => 'Comet Cache',
                'class' => 'comet_cache'
            ];
        }
        
        // Cache Enabler
        if (class_exists('Cache_Enabler')) {
            $cache_plugins['cache_enabler'] = [
                'name' => 'Cache Enabler',
                'class' => 'Cache_Enabler'
            ];
        }
        
        // Hummingbird
        if (class_exists('Hummingbird\\WP_Hummingbird')) {
            $cache_plugins['hummingbird'] = [
                'name' => 'Hummingbird',
                'class' => 'Hummingbird\\WP_Hummingbird'
            ];
        }
        
        // SG Optimizer
        if (class_exists('SiteGround_Optimizer\\Supercacher\\Supercacher')) {
            $cache_plugins['sg_optimizer'] = [
                'name' => 'SG Optimizer',
                'class' => 'SiteGround_Optimizer\\Supercacher\\Supercacher'
            ];
        }
        
        // Autoptimize
        if (class_exists('autoptimizeCache')) {
            $cache_plugins['autoptimize'] = [
                'name' => 'Autoptimize',
                'class' => 'autoptimizeCache'
            ];
        }
        
        // WP Optimize
        if (class_exists('WP_Optimize')) {
            $cache_plugins['wp_optimize'] = [
                'name' => 'WP Optimize',
                'class' => 'WP_Optimize'
            ];
        }
        
        // Swift Performance
        if (class_exists('Swift_Performance_Cache')) {
            $cache_plugins['swift_performance'] = [
                'name' => 'Swift Performance',
                'class' => 'Swift_Performance_Cache'
            ];
        }
        
        // Breeze
        if (class_exists('Breeze_Admin')) {
            $cache_plugins['breeze'] = [
                'name' => 'Breeze',
                'class' => 'Breeze_Admin'
            ];
        }
        
        // Redis Object Cache
        if (class_exists('RedisObjectCache')) {
            $cache_plugins['redis'] = [
                'name' => 'Redis Object Cache',
                'class' => 'RedisObjectCache'
            ];
        }
        
        return $cache_plugins;
    }
    
    private static function clear_cache_plugin($plugin, $data) {
        try {
            switch ($plugin) {
                case 'wp_rocket':
                    return self::clear_wp_rocket();
                    
                case 'w3tc':
                    return self::clear_w3tc();
                    
                case 'wp_super_cache':
                    return self::clear_wp_super_cache();
                    
                case 'litespeed':
                    return self::clear_litespeed();
                    
                case 'wp_fastest_cache':
                    return self::clear_wp_fastest_cache();
                    
                case 'cachify':
                    return self::clear_cachify();
                    
                case 'comet_cache':
                    return self::clear_comet_cache();
                    
                case 'cache_enabler':
                    return self::clear_cache_enabler();
                    
                case 'hummingbird':
                    return self::clear_hummingbird();
                    
                case 'sg_optimizer':
                    return self::clear_sg_optimizer();
                    
                case 'autoptimize':
                    return self::clear_autoptimize();
                    
                case 'wp_optimize':
                    return self::clear_wp_optimize();
                    
                case 'swift_performance':
                    return self::clear_swift_performance();
                    
                case 'breeze':
                    return self::clear_breeze();
                    
                case 'redis':
                    return self::clear_redis_cache();
                    
                default:
                    return [
                        'success' => false,
                        'message' => "Unknown cache plugin: {$data['name']}"
                    ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Error clearing {$data['name']}: " . $e->getMessage()
            ];
        }
    }
    
    private static function clear_wp_rocket() {
        if (function_exists('rocket_clean_domain')) {
            call_user_func('rocket_clean_domain');
            
            // Also clear minified files
            if (function_exists('rocket_clean_minify')) {
                call_user_func('rocket_clean_minify');
            }
            
            // Clear critical CSS
            if (function_exists('rocket_clean_cache_busting')) {
                call_user_func('rocket_clean_cache_busting');
            }
            
            return [
                'success' => true,
                'message' => 'WP Rocket cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'WP Rocket functions not available'
        ];
    }
    
    private static function clear_w3tc() {
        if (class_exists('W3_Plugin_TotalCache')) {
            $w3tc = call_user_func('w3_instance','W3_Plugin_TotalCache');
            
            // Clear all caches
            if (method_exists($w3tc, 'flush_all')) {
                $w3tc->flush_all();
            } else {
                // Fallback methods
                if (function_exists('w3tc_pgcache_flush')) {
                    call_user_func('w3tc_pgcache_flush');
                }
                if (function_exists('w3tc_dbcache_flush')) {
                    call_user_func('w3tc_dbcache_flush');
                }
                if (function_exists('w3tc_objectcache_flush')) {
                    call_user_func('w3tc_objectcache_flush');
                }
                if (function_exists('w3tc_minify_flush')) {
                    call_user_func('w3tc_minify_flush');
                }
            }
            
            return [
                'success' => true,
                'message' => 'W3 Total Cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'W3 Total Cache not available'
        ];
    }
    
    private static function clear_wp_super_cache() {
        if (function_exists('wp_cache_clear_cache')) {
            call_user_func('wp_cache_clear_cache');
            
            return [
                'success' => true,
                'message' => 'WP Super Cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'WP Super Cache function not available'
        ];
    }
    
    private static function clear_litespeed() {
        if (class_exists('LiteSpeed_Cache_API')) {
            if (method_exists('LiteSpeed_Cache_API', 'purge_all')) {
                call_user_func(array('LiteSpeed_Cache_API', 'purge_all'));
            }
            
            return [
                'success' => true,
                'message' => 'LiteSpeed Cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'LiteSpeed Cache API not available'
        ];
    }
    
    private static function clear_wp_fastest_cache() {
        if (class_exists('WpFastestCache')) {
            $wpfc_class = 'WpFastestCache'; $wpfc = class_exists($wpfc_class) ? new $wpfc_class() : null;
            
            if (method_exists($wpfc, 'deleteCache')) {
                $wpfc->deleteCache();
            }
            
            return [
                'success' => true,
                'message' => 'WP Fastest Cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'WP Fastest Cache not available'
        ];
    }
    
    private static function clear_cachify() {
        if (class_exists('Cachify')) {
            if (method_exists('Cachify', 'flush_total_cache')) {
                call_user_func(array('Cachify', 'flush_total_cache'));
            }
            
            return [
                'success' => true,
                'message' => 'Cachify cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Cachify not available'
        ];
    }
    
    private static function clear_comet_cache() {
        if (class_exists('comet_cache')) {
            if (method_exists('comet_cache', 'clear')) {
                call_user_func(array('comet_cache', 'clear'));
            }
            
            return [
                'success' => true,
                'message' => 'Comet Cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Comet Cache not available'
        ];
    }
    
    private static function clear_cache_enabler() {
        if (class_exists('Cache_Enabler')) {
            if (method_exists('Cache_Enabler', 'clear_total_cache')) {
                call_user_func(array('Cache_Enabler', 'clear_total_cache'));
            }
            
            return [
                'success' => true,
                'message' => 'Cache Enabler cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Cache Enabler not available'
        ];
    }
    
    private static function clear_hummingbird() {
        if (class_exists('Hummingbird\\WP_Hummingbird')) {
            // Try to clear page cache
            if (function_exists('wphb_clear_page_cache')) {
                call_user_func('wphb_clear_page_cache');
            }
            
            return [
                'success' => true,
                'message' => 'Hummingbird cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Hummingbird not available'
        ];
    }
    
    private static function clear_sg_optimizer() {
        if (class_exists('SiteGround_Optimizer\\Supercacher\\Supercacher')) {
            if (function_exists('sg_cachepress_purge_cache')) {
                call_user_func('sg_cachepress_purge_cache');
            }
            
            return [
                'success' => true,
                'message' => 'SG Optimizer cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'SG Optimizer not available'
        ];
    }
    
    private static function clear_autoptimize() {
        if (class_exists('autoptimizeCache')) {
            call_user_func(array('autoptimizeCache', 'clearall'));
            
            return [
                'success' => true,
                'message' => 'Autoptimize cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Autoptimize not available'
        ];
    }
    
    private static function clear_wp_optimize() {
        if (class_exists('WP_Optimize')) {
            if (function_exists('wpo_cache_flush')) {
                call_user_func('wpo_cache_flush');
            }
            
            return [
                'success' => true,
                'message' => 'WP Optimize cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'WP Optimize not available'
        ];
    }
    
    private static function clear_swift_performance() {
        if (class_exists('Swift_Performance_Cache')) {
            if (method_exists('Swift_Performance_Cache', 'clear_all_cache')) {
                call_user_func(array('Swift_Performance_Cache', 'clear_all_cache'));
            }
            
            return [
                'success' => true,
                'message' => 'Swift Performance cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Swift Performance not available'
        ];
    }
    
    private static function clear_breeze() {
        if (class_exists('Breeze_Admin')) {
            if (function_exists('breeze_clear_all_cache')) {
                call_user_func('breeze_clear_all_cache');
            }
            
            return [
                'success' => true,
                'message' => 'Breeze cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Breeze not available'
        ];
    }
    
    private static function clear_redis_cache() {
        if (class_exists('RedisObjectCache')) {
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            
            return [
                'success' => true,
                'message' => 'Redis Object Cache cleared successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Redis Object Cache not available'
        ];
    }
    
    private static function clear_wp_object_cache() {
        if (function_exists('wp_cache_flush')) {
            $result = wp_cache_flush();
            
            return [
                'success' => $result,
                'message' => $result ? 'WordPress object cache cleared' : 'Failed to clear WordPress object cache'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'WordPress object cache not available'
        ];
    }
    
    private static function clear_opcache() {
        if (function_exists('opcache_reset')) {
            $result = opcache_reset();
            
            return [
                'success' => $result,
                'message' => $result ? 'OPcache cleared successfully' : 'Failed to clear OPcache'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'OPcache not available'
        ];
    }
    
    public static function get_cache_status() {
        $cache_plugins = self::detect_cache_plugins();
        
        return [
            'plugins_detected' => count($cache_plugins),
            'plugins' => array_map(function($data) {
                return $data['name'];
            }, $cache_plugins),
            'wp_object_cache_enabled' => wp_using_ext_object_cache(),
            'opcache_enabled' => function_exists('opcache_get_status') ? opcache_get_status() : false
        ];
    }
    
    public static function schedule_cache_clearing($args = []) {
        // Schedule cache clearing based on plan
        $plan = RP_Care_Plan::get_current_plan();
        
        if ($plan === 'semilla') {
            // Weekly cache clearing for Semilla plan
            if (!wp_next_scheduled('rpcare_clear_cache')) {
                wp_schedule_event(time(), 'weekly', 'rpcare_clear_cache');
            }
        } elseif (in_array($plan, ['raiz', 'ecosistema'])) {
            // More frequent cache clearing for higher plans
            if (!wp_next_scheduled('rpcare_clear_cache')) {
                wp_schedule_event(time(), 'daily', 'rpcare_clear_cache');
            }
        }
    }
    
    public static function clear_cache_after_updates() {
        // This method is called after plugin/theme updates
        return self::run(['reason' => 'post_update']);
    }
}
