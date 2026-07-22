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
        $schedules['one_minute'] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => __('Cada minuto', 'replanta-care')
        ];

        $schedules['fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Cada 15 minutos', 'replanta-care')
        ];

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
        
        // Schedule backups — eCommerce addon upgrades to 12h (twicedaily)
        $backup_frequency = RP_Care_Plan::get_backup_frequency($this->plan);
        if (class_exists('RP_Care_Addon_Manager') && RP_Care_Addon_Manager::get()->is_active('ecommerce')) {
            $ecom_cfg         = RP_Care_Addon_Manager::get()->get_config('ecommerce');
            $backup_frequency = $ecom_cfg['backup_frequency'] ?? 'twicedaily';
        }
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
            $this->maybe_schedule('rpcare_task_monitor', 'one_minute');
        }

        // Anomaly detection — Raíz+: poll every 15 min to approximate 24/7 monitoring
        if (RP_Care_Plan::can_access_feature('anomaly_detection')) {
            $this->maybe_schedule('rpcare_task_anomaly', 'fifteen_minutes');
        }

        // CWV measurement — all paid plans
        if (RP_Care_Plan::can_access_feature('cwv_reports')) {
            $freq = RP_Care_Plan::can_access_feature('seo_reviews') ? 'monthly' : 'quarterly';
            $this->maybe_schedule('rpcare_task_cwv', $freq);
        }
        
        // eCommerce addon tasks — solo si el addon esta activo
        if (class_exists('RP_Care_Addon_Manager') && RP_Care_Addon_Manager::get()->is_active('ecommerce')) {
            $ecom_cfg = RP_Care_Addon_Manager::get()->get_config('ecommerce');
            if (!empty($ecom_cfg['checkout_monitor'])) {
                $this->maybe_schedule('rpcare_task_checkout_monitor', 'fifteen_minutes');
            }
            $this->maybe_schedule('rpcare_task_peak_scheduler', 'daily');
            $this->maybe_schedule('rpcare_task_revenue_anomaly', 'twicedaily');
        }

        // Schedule health checks (all plans)
        $this->maybe_schedule('rpcare_task_health', 'daily');

        // Schedule 404 cleanup
        $this->maybe_schedule('rpcare_task_404_cleanup', 'weekly');

        // Schedule daily maintenance (log rotation, transient cleanup, old backups)
        $this->maybe_schedule('rpcare_task_maintenance', 'daily');

        // Monthly WP DB cleanup (revisions, trash, spam, orphan meta, expired transients)
        $this->maybe_schedule('rpcare_task_db_cleanup', 'monthly');
        
        // Schedule reports based on plan
        $this->maybe_schedule('rpcare_task_report', 'monthly');
        
        // Register action hooks for all tasks
        $this->register_task_hooks();
    }
    
    private function maybe_schedule($hook, $recurrence) {
        $as_available = function_exists('as_next_scheduled_action');

        if ($as_available) {
            if (!as_next_scheduled_action($hook, [], 'replanta-care')) {
                $interval = $this->interval_to_seconds($recurrence);
                $first_run = $this->first_run_timestamp($hook);
                as_schedule_recurring_action($first_run, $interval, $hook, [], 'replanta-care');
                RP_Care_Utils::log('scheduler', 'success', "Scheduled $hook with $recurrence via Action Scheduler");
            }
        } else {
            // Fallback: WP Cron
            if (!wp_next_scheduled($hook)) {
                $first_run = $this->first_run_timestamp($hook);
                $result = wp_schedule_event($first_run, $recurrence, $hook);
                if ($result === false) {
                    RP_Care_Utils::log('scheduler', 'error', "Failed to schedule $hook with $recurrence frequency");
                } else {
                    RP_Care_Utils::log('scheduler', 'success', "Scheduled $hook with $recurrence via WP Cron");
                }
            }
        }
    }

    private function first_run_timestamp($hook) {
        if ($hook === 'rpcare_task_updates') {
            return $this->next_update_window_timestamp();
        }

        if ($hook === 'rpcare_task_monitor') {
            return time() + rand(15, 60);
        }

        return time() + rand(300, 3600); // spread load across sites
    }

    private function next_update_window_timestamp() {
        $window = RP_Care_Plan::get_update_window($this->plan);
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $timezone);

        $start_hour = max(0, min(23, (int) ($window['start_hour'] ?? 2)));
        $end_hour = max(0, min(23, (int) ($window['end_hour'] ?? 6)));
        $day = $window['day'] ?? null;

        if ($day !== null && $day !== '') {
            $day = max(0, min(6, (int) $day));
            $days_ahead = ($day - (int) $now->format('w') + 7) % 7;
            $target = $now->modify('+' . $days_ahead . ' days')->setTime($start_hour, 0, 0);
            if ($target <= $now) {
                $target = $target->modify('+7 days');
            }
        } else {
            $target = $now->setTime($start_hour, 0, 0);
            if ($target <= $now) {
                $target = $target->modify('+1 day');
            }
        }

        $duration_hours = ($end_hour - $start_hour + 24) % 24;
        if ($duration_hours === 0) {
            $duration_hours = 1;
        }
        $jitter = rand(0, max(0, ($duration_hours * HOUR_IN_SECONDS) - MINUTE_IN_SECONDS));

        return $target->modify('+' . $jitter . ' seconds')->getTimestamp();
    }

    /**
     * Convert WP Cron interval name to seconds.
     */
    private function interval_to_seconds(string $name): int {
        $map = [
            'one_minute' => MINUTE_IN_SECONDS,
            'fifteen_minutes' => 15 * MINUTE_IN_SECONDS,
            'hourly'     => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'daily'      => DAY_IN_SECONDS,
            'weekly'     => WEEK_IN_SECONDS,
            'monthly'    => 30 * DAY_IN_SECONDS,
            'quarterly'  => 90 * DAY_IN_SECONDS,
        ];
        if (isset($map[$name])) {
            return $map[$name];
        }
        // Query WP cron_schedules for custom intervals
        $schedules = wp_get_schedules();
        return isset($schedules[$name]['interval']) ? (int) $schedules[$name]['interval'] : DAY_IN_SECONDS;
    }
    
    private function register_task_hooks() {
        // Use add_filter (1 arg) so that task handlers can return their
        // result back through apply_filters() in the REST run_task endpoint.
        // Each handler receives ($args) — the same signature they already have
        // — and returns its result array.
        
        // Updates
        add_filter('rpcare_task_updates', ['RP_Care_Task_Updates', 'run'], 10, 1);
        add_filter('rpcare_task_updates', static function($result) {
            if (!is_array($result) || !empty($result['__rpcare_unhandled'])) return $result;
            $count = count($result['updated_plugins'] ?? []) + (int)($result['wp_updated'] ?? 0);
            if (!empty($result['success']) && $count > 0) {
                do_action('rpcare_notify', 'update_applied', [
                    'count'   => $count,
                    'plugins' => $result['updated_plugins'] ?? [],
                ]);
            } elseif (isset($result['success']) && !$result['success']) {
                do_action('rpcare_notify', 'update_failed', ['message' => $result['message'] ?? '']);
            }
            return $result;
        }, 20, 1);

        // Backups
        add_filter('rpcare_task_backup', ['RP_Care_Task_Backup', 'run'], 10, 1);
        add_filter('rpcare_task_backup', static function($result) {
            if (!is_array($result) || !empty($result['__rpcare_unhandled'])) return $result;
            if (empty($result['success'])) {
                do_action('rpcare_notify', 'backup_failed', ['message' => $result['message'] ?? '']);
            }
            return $result;
        }, 20, 1);
        
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

        // CWV measurement
        add_filter('rpcare_task_cwv', ['RP_Care_Task_CWV', 'run']);

        // Anomaly detection
        add_filter('rpcare_task_anomaly', ['RP_Care_Task_Anomaly', 'run']);

        // Daily maintenance / cleanup
        add_filter('rpcare_task_maintenance', ['RP_Care_Utils', 'cleanup_all']);

        // Monthly WP DB cleanup
        add_filter('rpcare_task_db_cleanup', ['RP_Care_Utils', 'db_cleanup_wp']);

        // On-demand only (no recurring schedule)
        add_filter('rpcare_task_cloudflare_configure', ['RP_Care_Task_Cloudflare', 'configure']);
        add_filter('rpcare_task_orphan_media', ['RP_Care_Task_OrphanMedia', 'scan']);
        add_filter('rpcare_task_staging_clone', ['RP_Care_Task_Staging', 'create_clone']);

        // eCommerce addon
        add_filter('rpcare_task_checkout_monitor', ['RP_Care_Task_Checkout_Monitor', 'run']);
        add_filter('rpcare_task_peak_scheduler', ['RP_Care_Task_Peak_Scheduler', 'run']);
        add_filter('rpcare_task_revenue_anomaly', ['RP_Care_Task_Revenue_Anomaly', 'run']);
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
            'rpcare_task_maintenance',
            'rpcare_task_report',
            'rpcare_task_cwv',
            'rpcare_task_anomaly',
            'rpcare_task_db_cleanup',
            'rpcare_task_checkout_monitor',
            'rpcare_task_peak_scheduler',
            'rpcare_task_revenue_anomaly',
        ];

        foreach ($hooks as $hook) {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook, [], 'replanta-care');
            }
            wp_clear_scheduled_hook($hook); // also clear any legacy WP Cron entries
        }

        RP_Care_Utils::log('scheduler', 'info', 'Cleared all scheduled tasks');
    }

    /**
     * Limpia solo las tareas relacionadas con addons (backup + eCommerce).
     * Llamado desde REST cuando cambia la lista de addons activos, para
     * forzar que ensure() las reprograme con la nueva configuracion.
     */
    public function clear_addon_schedules(): void {
        $hooks = [
            'rpcare_task_backup',
            'rpcare_task_checkout_monitor',
            'rpcare_task_peak_scheduler',
            'rpcare_task_revenue_anomaly',
        ];
        foreach ($hooks as $hook) {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook, [], 'replanta-care');
            }
            wp_clear_scheduled_hook($hook);
        }
        RP_Care_Utils::log('scheduler', 'info', 'Addon schedules cleared for re-evaluation');
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
            'rpcare_task_maintenance' => 'Mantenimiento diario',
            'rpcare_task_report' => 'Informe mensual',
            'rpcare_task_cwv' => 'Core Web Vitals',
            'rpcare_task_anomaly' => 'Detección de anomalías'
        ];
        
        $next_runs = [];
        $as_available = function_exists('as_next_scheduled_action');
        
        foreach ($hooks as $hook => $label) {
            if ($as_available) {
                $timestamp = as_next_scheduled_action($hook, [], 'replanta-care');
            } else {
                $timestamp = wp_next_scheduled($hook);
            }

            if ($timestamp) {
                $next_runs[] = [
                    'task' => $label,
                    'hook' => $hook,
                    'timestamp' => $timestamp,
                    'human_time' => human_time_diff($timestamp, time()),
                    'formatted_date' => date_i18n('Y-m-d H:i:s', $timestamp),
                    'engine' => $as_available ? 'Action Scheduler' : 'WP Cron',
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
            'maintenance' => 'rpcare_task_maintenance',
            'report' => 'rpcare_task_report',
            'cwv' => 'rpcare_task_cwv',
            'anomaly' => 'rpcare_task_anomaly',
            'cloudflare_configure' => 'rpcare_task_cloudflare_configure',
            'orphan_media_scan' => 'rpcare_task_orphan_media',
            'staging_clone' => 'rpcare_task_staging_clone'
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
        if (class_exists('RP_Care_Environment')) {
            return RP_Care_Environment::detect();
        }
        // Minimal fallback when RP_Care_Environment isn't loaded yet
        if (self::is_whm_environment()) {
            return 'cpanel';
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (strpos($host, 'localhost') !== false) {
            return 'local';
        }
        return 'external';
    }
}
