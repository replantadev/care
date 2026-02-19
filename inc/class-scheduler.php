<?php
/**
 * Task scheduler class
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Scheduler {
    
    private $plan;
    
    public function __construct($plan) {
        $this->plan = $plan;
        $this->register_custom_intervals();
    }
    
    public function register_custom_intervals() {
        add_filter('cron_schedules', [$this, 'add_custom_intervals']);
    }
    
    public function add_custom_intervals($schedules) {
        $schedules['weekly'] = [
            'interval' => 7 * DAY_IN_SECONDS,
            'display' => __('Semanal', 'replanta-care')
        ];
        
        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => __('Mensual', 'replanta-care')
        ];
        
        $schedules['quarterly'] = [
            'interval' => 90 * DAY_IN_SECONDS,
            'display' => __('Trimestral', 'replanta-care')
        ];
        
        return $schedules;
    }
    
    public function ensure() {
        // Schedule updates based on plan
        $update_frequency = RP_Care_Plan::get_update_frequency($this->plan);
        $this->maybe_schedule('rpcare_task_updates', $update_frequency);
        
        // Schedule backups (all plans)
        $backup_frequency = RP_Care_Plan::get_backup_frequency($this->plan);
        $this->maybe_schedule('rpcare_task_backup', $backup_frequency);
        
        // Schedule WPO tasks
        $this->maybe_schedule('rpcare_task_wpo', 'weekly');
        
        // Schedule SEO/WPO reviews based on plan
        $review_frequency = RP_Care_Plan::get_review_frequency($this->plan);
        if ($review_frequency === 'quarterly_audit') {
            $this->maybe_schedule('rpcare_task_seo_audit', 'quarterly');
        } elseif ($review_frequency === 'monthly') {
            $this->maybe_schedule('rpcare_task_seo_review', 'monthly');
        } elseif ($review_frequency === 'quarterly') {
            $this->maybe_schedule('rpcare_task_basic_review', 'quarterly');
        }
        
        // Schedule monitoring (Raíz and Ecosistema only)
        if (RP_Care_Plan::has_monitoring($this->plan)) {
            $this->maybe_schedule('rpcare_task_monitor', 'daily');
        }
        
        // Schedule health checks (all plans)
        $this->maybe_schedule('rpcare_task_health', 'daily');
        
        // Schedule 404 cleanup
        $this->maybe_schedule('rpcare_task_404_cleanup', 'weekly');
        
        // Schedule reports based on plan
        $this->maybe_schedule('rpcare_task_report', 'monthly');
        
        // Register action hooks for all tasks
        $this->register_task_hooks();
    }
    
    private function maybe_schedule($hook, $recurrence) {
        if (!wp_next_scheduled($hook)) {
            // Add some randomization to avoid all sites hitting at the same time
            $delay = rand(300, 3600); // 5 minutes to 1 hour delay
            $timestamp = time() + $delay;
            
            $result = wp_schedule_event($timestamp, $recurrence, $hook);
            
            if ($result === false) {
                RP_Care_Utils::log('scheduler', 'error', "Failed to schedule $hook with $recurrence frequency");
            } else {
                RP_Care_Utils::log('scheduler', 'success', "Scheduled $hook with $recurrence frequency");
            }
        }
    }
    
    private function register_task_hooks() {
        // Use add_filter (1 arg) so that task handlers can return their
        // result back through apply_filters() in the REST run_task endpoint.
        // Each handler receives ($args) — the same signature they already have
        // — and returns its result array.
        
        // Updates
        add_filter('rpcare_task_updates', ['RP_Care_Task_Updates', 'run']);
        
        // Backups
        add_filter('rpcare_task_backup', ['RP_Care_Task_Backup', 'run']);
        
        // WPO
        add_filter('rpcare_task_wpo', ['RP_Care_Task_WPO', 'run']);
        
        // SEO/Reviews
        add_filter('rpcare_task_seo_review', ['RP_Care_Task_SEO', 'run_monthly_review']);
        add_filter('rpcare_task_seo_audit', ['RP_Care_Task_SEO', 'run_quarterly_audit']);
        add_filter('rpcare_task_basic_review', ['RP_Care_Task_SEO', 'run_basic_review']);
        
        // Monitoring
        add_filter('rpcare_task_monitor', ['RP_Care_Task_Health', 'run_monitoring']);
        
        // Health checks
        add_filter('rpcare_task_health', ['RP_Care_Task_Health', 'run']);
        
        // 404 management
        add_filter('rpcare_task_404_cleanup', ['RP_Care_Task_404', 'cleanup']);
        
        // Reports
        add_filter('rpcare_task_report', ['RP_Care_Task_Report', 'generate_monthly']);
    }
    
    public function clear_all() {
        $hooks = [
            'rpcare_task_updates',
            'rpcare_task_backup',
            'rpcare_task_wpo',
            'rpcare_task_seo_review',
            'rpcare_task_seo_audit',
            'rpcare_task_basic_review',
            'rpcare_task_monitor',
            'rpcare_task_health',
            'rpcare_task_404_cleanup',
            'rpcare_task_report'
        ];
        
        foreach ($hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        
        RP_Care_Utils::log('scheduler', 'info', 'Cleared all scheduled tasks');
    }
    
    public function get_next_runs() {
        $hooks = [
            'rpcare_task_updates' => 'Actualizaciones',
            'rpcare_task_backup' => 'Backup',
            'rpcare_task_wpo' => 'Optimización WPO',
            'rpcare_task_seo_review' => 'Revisión SEO mensual',
            'rpcare_task_seo_audit' => 'Auditoría SEO trimestral',
            'rpcare_task_basic_review' => 'Revisión básica',
            'rpcare_task_monitor' => 'Monitorización',
            'rpcare_task_health' => 'Chequeo de salud',
            'rpcare_task_404_cleanup' => 'Limpieza 404',
            'rpcare_task_report' => 'Informe mensual'
        ];
        
        $next_runs = [];
        
        foreach ($hooks as $hook => $label) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                $next_runs[] = [
                    'task' => $label,
                    'hook' => $hook,
                    'timestamp' => $timestamp,
                    'human_time' => human_time_diff($timestamp, time()),
                    'formatted_date' => date_i18n('Y-m-d H:i:s', $timestamp)
                ];
            }
        }
        
        // Sort by timestamp
        usort($next_runs, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });
        
        return $next_runs;
    }
    
    public function run_task_now($task_name) {
        $valid_tasks = [
            'updates' => 'rpcare_task_updates',
            'backup' => 'rpcare_task_backup',
            'wpo' => 'rpcare_task_wpo',
            'seo_review' => 'rpcare_task_seo_review',
            'seo_audit' => 'rpcare_task_seo_audit',
            'basic_review' => 'rpcare_task_basic_review',
            'monitor' => 'rpcare_task_monitor',
            'health' => 'rpcare_task_health',
            '404_cleanup' => 'rpcare_task_404_cleanup',
            'report' => 'rpcare_task_report'
        ];
        
        if (!isset($valid_tasks[$task_name])) {
            return new WP_Error('invalid_task', 'Tarea no válida');
        }
        
        $hook = $valid_tasks[$task_name];
        
        // Log the manual execution
        RP_Care_Utils::log('manual_task', 'info', "Ejecutando tarea manual: $task_name");
        
        // Execute the task
        do_action($hook);
        
        return true;
    }
    
    public static function is_whm_environment() {
        // Detect if we're in a WHM/cPanel environment
        $indicators = [
            defined('CPANEL_ENV'),
            function_exists('cpanel_api'),
            file_exists('/usr/local/cpanel'),
            isset($_SERVER['HTTP_X_FORWARDED_HOST']) && strpos($_SERVER['HTTP_X_FORWARDED_HOST'], 'cpanel') !== false,
            file_exists('/var/cpanel'),
            getenv('CPANEL_USER') !== false
        ];
        
        return in_array(true, $indicators, true);
    }
    
    public static function get_environment_type() {
        if (self::is_whm_environment()) {
            return 'whm';
        }
        
        // Check for common hosting providers
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $server_name = $_SERVER['SERVER_NAME'] ?? '';
        
        if (strpos($host, 'localhost') !== false || strpos($server_name, 'localhost') !== false) {
            return 'local';
        }
        
        return 'external';
    }
}
