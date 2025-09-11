<?php
/**
 * Main tasks orchestrator class
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Tasks {
    
    public static function run_scheduled_task($task_type, $args = []) {
        $start_time = microtime(true);
        
        RP_Care_Utils::log($task_type, 'info', "Starting scheduled task: $task_type");
        
        try {
            switch ($task_type) {
                case 'updates':
                    RP_Care_Task_Updates::run($args);
                    break;
                    
                case 'backup':
                    RP_Care_Task_Backup::run($args);
                    break;
                    
                case 'wpo':
                    RP_Care_Task_WPO::run($args);
                    break;
                    
                case 'seo_review':
                    RP_Care_Task_SEO::run_monthly_review($args);
                    break;
                    
                case 'seo_audit':
                    RP_Care_Task_SEO::run_quarterly_audit($args);
                    break;
                    
                case 'health':
                    RP_Care_Task_Health::run($args);
                    break;
                    
                case 'monitor':
                    RP_Care_Task_Health::run_monitoring($args);
                    break;
                    
                case '404_cleanup':
                    RP_Care_Task_404::cleanup($args);
                    break;
                    
                case 'report':
                    RP_Care_Task_Report::generate_monthly($args);
                    break;
                    
                default:
                    throw new Exception("Unknown task type: $task_type");
            }
            
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            
            RP_Care_Utils::log($task_type, 'success', "Task completed successfully in {$execution_time}ms");
            
            // Notify hub of completion
            self::notify_hub_completion($task_type, $execution_time);
            
        } catch (Exception $e) {
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            
            RP_Care_Utils::log($task_type, 'error', "Task failed: " . $e->getMessage(), [
                'execution_time_ms' => $execution_time,
                'error_trace' => $e->getTraceAsString()
            ]);
            
            // Notify hub of failure
            self::notify_hub_error($task_type, $e->getMessage());
        }
    }
    
    public static function get_task_status($task_type) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rpcare_logs';
        
        $last_run = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE task_type = %s ORDER BY created_at DESC LIMIT 1",
            $task_type
        ));
        
        if (!$last_run) {
            return [
                'status' => 'never_run',
                'last_run' => null,
                'next_run' => wp_next_scheduled("rpcare_task_$task_type")
            ];
        }
        
        return [
            'status' => $last_run->status,
            'last_run' => $last_run->created_at,
            'last_message' => $last_run->message,
            'next_run' => wp_next_scheduled("rpcare_task_$task_type")
        ];
    }
    
    public static function can_run_task($task_type) {
        $plan = RP_Care_Plan::get_current();
        
        if (!$plan) {
            return false;
        }
        
        // Check plan permissions
        $task_permissions = [
            'updates' => ['semilla', 'raiz', 'ecosistema'],
            'backup' => ['semilla', 'raiz', 'ecosistema'],
            'wpo' => ['semilla', 'raiz', 'ecosistema'],
            'seo_review' => ['raiz', 'ecosistema'],
            'seo_audit' => ['ecosistema'],
            'health' => ['semilla', 'raiz', 'ecosistema'],
            'monitor' => ['raiz', 'ecosistema'],
            '404_cleanup' => ['semilla', 'raiz', 'ecosistema'],
            'report' => ['semilla', 'raiz', 'ecosistema']
        ];
        
        return in_array($plan, $task_permissions[$task_type] ?? []);
    }
    
    public static function get_all_task_statuses() {
        $task_types = [
            'updates',
            'backup',
            'wpo',
            'seo_review',
            'seo_audit',
            'health',
            'monitor',
            '404_cleanup',
            'report'
        ];
        
        $statuses = [];
        
        foreach ($task_types as $task_type) {
            if (self::can_run_task($task_type)) {
                $statuses[$task_type] = self::get_task_status($task_type);
            }
        }
        
        return $statuses;
    }
    
    public static function force_run_task($task_type) {
        if (!self::can_run_task($task_type)) {
            return new WP_Error('task_not_allowed', "Task $task_type not allowed for current plan");
        }
        
        // Schedule immediate execution
        wp_schedule_single_event(time() + 10, "rpcare_task_$task_type");
        
        RP_Care_Utils::log('manual_task', 'info', "Task $task_type scheduled for immediate execution");
        
        return true;
    }
    
    public static function get_exclusions() {
        return [
            'plugins' => get_option('rpcare_exclude_plugins', []),
            'themes' => get_option('rpcare_exclude_themes', []),
            'core' => get_option('rpcare_exclude_core', false)
        ];
    }
    
    public static function set_exclusions($exclusions) {
        if (isset($exclusions['plugins'])) {
            update_option('rpcare_exclude_plugins', (array) $exclusions['plugins']);
        }
        
        if (isset($exclusions['themes'])) {
            update_option('rpcare_exclude_themes', (array) $exclusions['themes']);
        }
        
        if (isset($exclusions['core'])) {
            update_option('rpcare_exclude_core', (bool) $exclusions['core']);
        }
        
        RP_Care_Utils::log('settings', 'info', 'Update exclusions modified', $exclusions);
    }
    
    private static function notify_hub_completion($task_type, $execution_time) {
        $metrics = [
            'task_type' => $task_type,
            'status' => 'completed',
            'execution_time_ms' => $execution_time,
            'timestamp' => time(),
            'memory_usage' => memory_get_peak_usage(true)
        ];
        
        RP_Care_Utils::send_notification('task_completed', "Task $task_type completed", '', $metrics);
    }
    
    private static function notify_hub_error($task_type, $error_message) {
        $data = [
            'task_type' => $task_type,
            'status' => 'failed',
            'error' => $error_message,
            'timestamp' => time(),
            'site_url' => get_site_url()
        ];
        
        RP_Care_Utils::send_notification('task_failed', "Task $task_type failed", $error_message, $data);
    }
    
    public static function get_maintenance_window() {
        $start_hour = get_option('rpcare_maintenance_start', 2); // 2 AM default
        $end_hour = get_option('rpcare_maintenance_end', 6);     // 6 AM default
        
        return [
            'start' => $start_hour,
            'end' => $end_hour,
            'timezone' => get_option('timezone_string', 'UTC')
        ];
    }
    
    public static function is_maintenance_window() {
        $window = self::get_maintenance_window();
        $current_hour = (int) current_time('H');
        
        if ($window['start'] <= $window['end']) {
            // Same day window (e.g., 2 AM to 6 AM)
            return $current_hour >= $window['start'] && $current_hour < $window['end'];
        } else {
            // Overnight window (e.g., 10 PM to 6 AM)
            return $current_hour >= $window['start'] || $current_hour < $window['end'];
        }
    }
    
    public static function schedule_in_maintenance_window($task_type, $args = []) {
        if (self::is_maintenance_window()) {
            // We're in the window, schedule immediately
            wp_schedule_single_event(time() + rand(60, 600), "rpcare_task_$task_type", $args);
        } else {
            // Schedule for next maintenance window
            $window = self::get_maintenance_window();
            $next_window = self::calculate_next_maintenance_window($window);
            
            wp_schedule_single_event($next_window, "rpcare_task_$task_type", $args);
        }
    }
    
    private static function calculate_next_maintenance_window($window) {
        $timezone = new DateTimeZone($window['timezone']);
        $now = new DateTime('now', $timezone);
        $start_hour = $window['start'];
        
        // Set to maintenance window start time today
        $maintenance_time = clone $now;
        $maintenance_time->setTime($start_hour, rand(0, 59), rand(0, 59));
        
        // If maintenance window has already passed today, set for tomorrow
        if ($maintenance_time <= $now) {
            $maintenance_time->add(new DateInterval('P1D'));
        }
        
        return $maintenance_time->getTimestamp();
    }
}
