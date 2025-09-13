<?php
/**
 * Plan management class
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Plan {
    
    const PLAN_BASIC = 'basic';
    const PLAN_ADVANCED = 'advanced';
    const PLAN_PREMIUM = 'premium';
    const PLAN_SEMILLA = 'semilla';
    const PLAN_RAIZ = 'raiz';
    const PLAN_ECOSISTEMA = 'ecosistema';
    
    const HUB_URL = 'http://repo.local';
    
    private static $plan_configs = [
        self::PLAN_BASIC => [
            'name' => 'Plan Básico',
            'price' => '49€/mes',
            'updates' => 'monthly',
            'backups' => 'weekly',
            'wpo' => 'basic',
            'reviews' => 'quarterly',
            'monitoring' => false,
            'priority_support' => false,
            'features' => [
                'Actualizaciones mensuales',
                'Copias de seguridad semanales',
                'Optimización básica WPO',
                'Revisión trimestral de rendimiento',
                'Soporte por email'
            ]
        ],
        self::PLAN_ADVANCED => [
            'name' => 'Plan Avanzado',
            'price' => '89€/mes',
            'updates' => 'weekly',
            'backups' => 'weekly',
            'wpo' => 'advanced',
            'reviews' => 'monthly',
            'monitoring' => true,
            'priority_support' => true,
            'features' => [
                'Todo lo del plan Básico',
                'Actualizaciones semanales',
                'Soporte prioritario',
                'Monitorización 24/7',
                'Revisión SEO + WPO mensual',
                'Informes de estado mensuales'
            ]
        ],
        self::PLAN_PREMIUM => [
            'name' => 'Plan Premium',
            'price' => '149€/mes',
            'updates' => 'weekly',
            'backups' => 'daily',
            'wpo' => 'premium',
            'reviews' => 'quarterly_audit',
            'monitoring' => true,
            'priority_support' => true,
            'hosting_included' => true,
            'features' => [
                'Todo lo del plan Avanzado',
                'Consultoría técnica trimestral',
                'Hosting ecológico incluido',
                'Auditoría SEO/WPO trimestral',
                'CDN y optimización avanzada'
            ]
        ],
        self::PLAN_SEMILLA => [
            'name' => 'Plan Semilla',
            'price' => '49€/mes',
            'updates' => 'monthly',
            'backups' => 'weekly',
            'wpo' => 'basic',
            'reviews' => 'quarterly',
            'monitoring' => false,
            'priority_support' => false,
            'features' => [
                'Actualizaciones mensuales',
                'Copias de seguridad semanales',
                'Optimización básica WPO',
                'Revisión trimestral de rendimiento',
                'Soporte por email'
            ]
        ],
        self::PLAN_RAIZ => [
            'name' => 'Plan Raíz',
            'price' => '89€/mes',
            'updates' => 'weekly',
            'backups' => 'weekly',
            'wpo' => 'advanced',
            'reviews' => 'monthly',
            'monitoring' => true,
            'priority_support' => true,
            'features' => [
                'Todo lo del plan Semilla',
                'Actualizaciones semanales',
                'Soporte prioritario',
                'Monitorización 24/7',
                'Revisión SEO + WPO mensual',
                'Informes de estado mensuales'
            ]
        ],
        self::PLAN_ECOSISTEMA => [
            'name' => 'Plan Ecosistema',
            'price' => '149€/mes',
            'updates' => 'weekly',
            'backups' => 'weekly',
            'wpo' => 'premium',
            'reviews' => 'quarterly',
            'monitoring' => true,
            'priority_support' => true,
            'hosting_included' => true,
            'features' => [
                'Todo lo del plan Raíz',
                'Consultoría técnica trimestral',
                'Hosting ecológico incluido',
                'Auditoría SEO/WPO trimestral',
                'CDN y optimización avanzada'
            ]
        ]
    ];
    
    public static function get_current() {
        // Auto-detect from hub
        $plan = self::detect_plan_from_hub();
        if ($plan) {
            update_option('rpcare_plan', $plan);
            update_option('rpcare_detected_plan', $plan);
            return $plan;
        }
        
        // Fallback to previously detected plan
        $detected_plan = get_option('rpcare_detected_plan', '');
        if ($detected_plan) {
            return $detected_plan;
        }
        
        return get_option('rpcare_plan', '');
    }
    
    public static function set_current($plan) {
        if (self::is_valid_plan($plan)) {
            update_option('rpcare_plan', $plan);
            return true;
        }
        return false;
    }
    
    public static function is_valid_plan($plan) {
        return in_array($plan, [self::PLAN_BASIC, self::PLAN_ADVANCED, self::PLAN_PREMIUM, self::PLAN_SEMILLA, self::PLAN_RAIZ, self::PLAN_ECOSISTEMA]);
    }
    
    /**
     * Detect plan from Replanta hub
     */
    private static function detect_plan_from_hub() {
        // Check if we should skip hub detection temporarily (backoff)
        $backoff_key = 'rpcare_hub_backoff';
        $backoff_time = get_transient($backoff_key);
        
        if ($backoff_time !== false) {
            // Still in backoff period, don't attempt connection
            return false;
        }
        
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        
        $response = wp_remote_get(self::HUB_URL . '/api/sites/plan', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Site-Domain' => $domain,
                'X-Site-URL' => $site_url,
                'User-Agent' => 'Replanta-Care/' . RPCARE_VERSION . ' (WordPress/' . get_bloginfo('version') . ')'
            ],
            'timeout' => 3  // Reduced from 15 to 3 seconds
        ]);
        
        if (is_wp_error($response)) {
            error_log('Care Plugin: Error detecting plan from hub: ' . $response->get_error_message());
            
            // Implement exponential backoff: start with 5 minutes, max 1 hour
            $failure_count = get_option('rpcare_hub_failures', 0) + 1;
            update_option('rpcare_hub_failures', $failure_count);
            
            $backoff_seconds = min(300 * pow(2, $failure_count - 1), 3600); // 5min, 10min, 20min, 40min, 1hour
            set_transient($backoff_key, time() + $backoff_seconds, $backoff_seconds);
            
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('Care Plugin: Hub returned HTTP ' . $response_code . ' for domain ' . $domain);
            
            // Implement backoff for HTTP errors too
            $failure_count = get_option('rpcare_hub_failures', 0) + 1;
            update_option('rpcare_hub_failures', $failure_count);
            
            $backoff_seconds = min(300 * pow(2, $failure_count - 1), 3600);
            set_transient($backoff_key, time() + $backoff_seconds, $backoff_seconds);
            
            return false;
        }
        
        // Success! Reset failure count
        delete_option('rpcare_hub_failures');
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Care Plugin: Invalid JSON response from hub: ' . $body);
            return false;
        }
        
        if (isset($data['plan']) && self::is_valid_plan($data['plan'])) {
            // Mark as activated automatically
            update_option('rpcare_activated', true);
            update_option('rpcare_hub_connected', true);
            update_option('rpcare_detected_plan', $data['plan']);
            update_option('rpcare_hub_last_check', current_time('mysql'));
            
            error_log('Care Plugin: Successfully detected plan ' . $data['plan'] . ' for domain ' . $domain);
            return $data['plan'];
        }
        
        error_log('Care Plugin: Invalid plan received from hub: ' . print_r($data, true));
        return false;
    }
    
    public static function get_plan_config($plan = null) {
        if ($plan === null) {
            $plan = self::get_current();
        }
        
        return self::$plan_configs[$plan] ?? null;
    }
    
    public static function get_all_plans() {
        return self::$plan_configs;
    }
    
    public static function get_current_plan() {
        // First try to get auto-detected plan
        $detected_plan = get_option('rpcare_detected_plan', '');
        if ($detected_plan && self::is_valid_plan($detected_plan)) {
            return $detected_plan;
        }
        
        // Fallback to manually set plan
        $options = get_option('rpcare_options', []);
        $manual_plan = isset($options['plan']) ? $options['plan'] : '';
        
        // If no manual plan, try auto-detection
        if (!$manual_plan) {
            $auto_plan = self::get_current();
            if ($auto_plan) {
                return $auto_plan;
            }
        }
        
        return $manual_plan ?: 'semilla';
    }
    
    public static function get_plan_features($plan = null) {
        if ($plan === null) {
            $plan = self::get_current_plan();
        }
        
        $all_features = [
            'auto_updates' => false,
            'backup' => false,
            'security_monitoring' => false,
            'performance_optimization' => false,
            'seo_monitoring' => false,
            'uptime_monitoring' => false,
            'malware_scanning' => false,
            'staging_environment' => false,
            'priority_support' => false,
            'white_label_reports' => false
        ];
        
        switch ($plan) {
            case self::PLAN_SEMILLA:
            case 'semilla':
                $all_features['auto_updates'] = true;
                $all_features['backup'] = true;
                $all_features['security_monitoring'] = true;
                break;
                
            case self::PLAN_RAIZ:
            case 'raiz':
                $all_features['auto_updates'] = true;
                $all_features['backup'] = true;
                $all_features['security_monitoring'] = true;
                $all_features['performance_optimization'] = true;
                $all_features['seo_monitoring'] = true;
                break;
                
            case self::PLAN_ECOSISTEMA:
            case 'ecosistema':
                foreach ($all_features as $feature => $value) {
                    $all_features[$feature] = true;
                }
                break;
                
            case self::PLAN_BASIC:
            case 'basic':
                $all_features['auto_updates'] = true;
                $all_features['backup'] = true;
                $all_features['security_monitoring'] = true;
                $all_features['performance_optimization'] = true;
                break;
                
            case self::PLAN_ADVANCED:
            case 'advanced':
                $all_features['auto_updates'] = true;
                $all_features['backup'] = true;
                $all_features['security_monitoring'] = true;
                $all_features['performance_optimization'] = true;
                $all_features['seo_monitoring'] = true;
                $all_features['uptime_monitoring'] = true;
                $all_features['priority_support'] = true;
                break;
                
            case self::PLAN_PREMIUM:
            case 'premium':
                foreach ($all_features as $feature => $value) {
                    $all_features[$feature] = true;
                }
                break;
        }
        
        return $all_features;
    }
    
    public static function get_update_frequency($plan = null) {
        $config = self::get_plan_config($plan);
        return $config['updates'] ?? 'monthly';
    }
    
    public static function get_backup_frequency($plan = null) {
        $config = self::get_plan_config($plan);
        return $config['backups'] ?? 'weekly';
    }
    
    public static function get_wpo_level($plan = null) {
        $config = self::get_plan_config($plan);
        return $config['wpo'] ?? 'basic';
    }
    
    public static function get_review_frequency($plan = null) {
        $config = self::get_plan_config($plan);
        return $config['reviews'] ?? 'quarterly';
    }
    
    public static function has_monitoring($plan = null) {
        $config = self::get_plan_config($plan);
        return $config['monitoring'] ?? false;
    }
    
    public static function has_priority_support($plan = null) {
        $config = self::get_plan_config($plan);
        return $config['priority_support'] ?? false;
    }
    
    public static function has_hosting_included($plan = null) {
        $config = self::get_plan_config($plan);
        return $config['hosting_included'] ?? false;
    }
    
    public static function get_plan_name($plan = null) {
        $config = self::get_plan_config($plan);
        return $config['name'] ?? 'Plan Desconocido';
    }
    
    public static function get_features($plan = null) {
        if (!$plan) {
            $plan = self::get_current();
        }
        
        $config = self::get_plan_config($plan);
        
        // Return basic features plus additional configuration
        $features = [
            'update_control' => true, // All plans have update control
            'automatic_updates' => true,
            'backup' => true,
            'monitoring' => $config['monitoring'] ?? false,
            'priority_support' => $config['priority_support'] ?? false,
            'hosting_included' => $config['hosting_included'] ?? false,
            'updates_frequency' => $config['updates'] ?? 'monthly',
            'backup_frequency' => $config['backups'] ?? 'weekly',
            'wpo_level' => $config['wpo'] ?? 'basic',
            'review_frequency' => $config['reviews'] ?? 'quarterly'
        ];
        
        return $features;
    }
    
    public static function can_access_feature($feature, $plan = null) {
        if ($plan === null) {
            $plan = self::get_current();
        }
        
        if (!self::is_valid_plan($plan)) {
            return false;
        }
        
        $features_by_plan = [
            'updates' => [self::PLAN_BASIC, self::PLAN_ADVANCED, self::PLAN_PREMIUM],
            'backups' => [self::PLAN_BASIC, self::PLAN_ADVANCED, self::PLAN_PREMIUM],
            'wpo_basic' => [self::PLAN_BASIC, self::PLAN_ADVANCED, self::PLAN_PREMIUM],
            'wpo_advanced' => [self::PLAN_ADVANCED, self::PLAN_PREMIUM],
            'wpo_premium' => [self::PLAN_PREMIUM],
            'monitoring' => [self::PLAN_ADVANCED, self::PLAN_PREMIUM],
            'priority_support' => [self::PLAN_ADVANCED, self::PLAN_PREMIUM],
            'seo_reviews' => [self::PLAN_ADVANCED, self::PLAN_PREMIUM],
            'quarterly_audit' => [self::PLAN_PREMIUM],
            'cdn_optimization' => [self::PLAN_PREMIUM],
            'technical_consulting' => [self::PLAN_PREMIUM]
        ];
        
        return in_array($plan, $features_by_plan[$feature] ?? []);
    }
    
    public static function get_schedule_intervals() {
        return [
            'daily' => DAY_IN_SECONDS,
            'weekly' => 7 * DAY_IN_SECONDS,
            'monthly' => 30 * DAY_IN_SECONDS,
            'quarterly' => 90 * DAY_IN_SECONDS
        ];
    }
    
    public static function upgrade_plan($new_plan) {
        $current_plan = self::get_current();
        
        if (!self::is_valid_plan($new_plan)) {
            return new WP_Error('invalid_plan', 'Plan no válido');
        }
        
        $plan_hierarchy = [
            self::PLAN_SEMILLA => 1,
            self::PLAN_RAIZ => 2,
            self::PLAN_ECOSISTEMA => 3
        ];
        
        $current_level = $plan_hierarchy[$current_plan] ?? 0;
        $new_level = $plan_hierarchy[$new_plan] ?? 0;
        
        if ($new_level < $current_level) {
            return new WP_Error('downgrade_not_allowed', 'No se permite degradar el plan automáticamente');
        }
        
        self::set_current($new_plan);
        
        // Re-schedule tasks based on new plan
        $scheduler = new RP_Care_Scheduler($new_plan);
        $scheduler->clear_all();
        $scheduler->ensure();
        
        // Log the upgrade
        RP_Care_Utils::log('plan_upgrade', 'success', "Plan actualizado de $current_plan a $new_plan");
        
        return true;
    }
}
