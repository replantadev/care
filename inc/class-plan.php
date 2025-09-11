<?php
/**
 * Plan management class
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Plan {
    
    const PLAN_SEMILLA = 'semilla';
    const PLAN_RAIZ = 'raiz';
    const PLAN_ECOSISTEMA = 'ecosistema';
    
    private static $plan_configs = [
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
            'backups' => 'daily',
            'wpo' => 'premium',
            'reviews' => 'quarterly_audit',
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
        return in_array($plan, [self::PLAN_SEMILLA, self::PLAN_RAIZ, self::PLAN_ECOSISTEMA]);
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
        $options = get_option('rpcare_options', []);
        return isset($options['plan']) ? $options['plan'] : 'semilla';
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
            case 'semilla':
                $all_features['auto_updates'] = true;
                $all_features['backup'] = true;
                $all_features['security_monitoring'] = true;
                break;
                
            case 'raiz':
                $all_features['auto_updates'] = true;
                $all_features['backup'] = true;
                $all_features['security_monitoring'] = true;
                $all_features['performance_optimization'] = true;
                $all_features['seo_monitoring'] = true;
                break;
                
            case 'ecosistema':
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
    
    public static function can_access_feature($feature, $plan = null) {
        if ($plan === null) {
            $plan = self::get_current();
        }
        
        if (!self::is_valid_plan($plan)) {
            return false;
        }
        
        $features_by_plan = [
            'updates' => [self::PLAN_SEMILLA, self::PLAN_RAIZ, self::PLAN_ECOSISTEMA],
            'backups' => [self::PLAN_SEMILLA, self::PLAN_RAIZ, self::PLAN_ECOSISTEMA],
            'wpo_basic' => [self::PLAN_SEMILLA, self::PLAN_RAIZ, self::PLAN_ECOSISTEMA],
            'wpo_advanced' => [self::PLAN_RAIZ, self::PLAN_ECOSISTEMA],
            'wpo_premium' => [self::PLAN_ECOSISTEMA],
            'monitoring' => [self::PLAN_RAIZ, self::PLAN_ECOSISTEMA],
            'priority_support' => [self::PLAN_RAIZ, self::PLAN_ECOSISTEMA],
            'seo_reviews' => [self::PLAN_RAIZ, self::PLAN_ECOSISTEMA],
            'quarterly_audit' => [self::PLAN_ECOSISTEMA],
            'cdn_optimization' => [self::PLAN_ECOSISTEMA],
            'technical_consulting' => [self::PLAN_ECOSISTEMA]
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
