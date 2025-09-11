<?php
/**
 * REST API endpoints class
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_REST {
    
    private $namespace = 'replanta/v1';
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    public function register_routes() {
        // Main task execution endpoint
        register_rest_route($this->namespace, '/run', [
            'methods' => 'POST',
            'callback' => [$this, 'run_task'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'task' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['updates', 'backup', 'wpo', 'seo_review', 'seo_audit', 'health', 'monitor', '404_cleanup', 'report']
                ],
                'force' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false
                ],
                'args' => [
                    'required' => false,
                    'type' => 'object',
                    'default' => []
                ]
            ]
        ]);
        
        // Get site metrics
        register_rest_route($this->namespace, '/metrics', [
            'methods' => 'GET',
            'callback' => [$this, 'get_metrics'],
            'permission_callback' => [$this, 'check_permissions']
        ]);
        
        // Heartbeat/ping endpoint
        register_rest_route($this->namespace, '/ping', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'ping'],
            'permission_callback' => [$this, 'check_permissions']
        ]);
        
        // Get task logs
        register_rest_route($this->namespace, '/logs', [
            'methods' => 'GET',
            'callback' => [$this, 'get_logs'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 500
                ],
                'task_type' => [
                    'required' => false,
                    'type' => 'string'
                ],
                'status' => [
                    'required' => false,
                    'type' => 'string',
                    'enum' => ['success', 'error', 'warning', 'info']
                ]
            ]
        ]);
        
        // Apply 301 redirects
        register_rest_route($this->namespace, '/redirects/apply', [
            'methods' => 'POST',
            'callback' => [$this, 'apply_redirects'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'redirects' => [
                    'required' => true,
                    'type' => 'array'
                ]
            ]
        ]);
        
        // Get 404 reports
        register_rest_route($this->namespace, '/404s', [
            'methods' => 'GET',
            'callback' => [$this, 'get_404s'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 100
                ]
            ]
        ]);
        
        // Update configuration
        register_rest_route($this->namespace, '/config', [
            'methods' => 'POST',
            'callback' => [$this, 'update_config'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'plan' => [
                    'required' => false,
                    'type' => 'string',
                    'enum' => ['semilla', 'raiz', 'ecosistema']
                ],
                'settings' => [
                    'required' => false,
                    'type' => 'object'
                ]
            ]
        ]);
        
        // Get scheduled tasks
        register_rest_route($this->namespace, '/schedule', [
            'methods' => 'GET',
            'callback' => [$this, 'get_schedule'],
            'permission_callback' => [$this, 'check_permissions']
        ]);
    }
    
    public function check_permissions($request) {
        return RP_Care_Security::validate_request($request);
    }
    
    public function run_task($request) {
        $task = $request->get_param('task');
        $force = $request->get_param('force');
        $args = $request->get_param('args');
        $payload = $request->get_param('_rpcare_payload');
        
        // Check if task is allowed for current plan
        if (!RP_Care_Security::can_execute_task($task, $payload)) {
            return new WP_Error('task_not_allowed', 'Task not allowed for current plan', ['status' => 403]);
        }
        
        // Log the task execution request
        RP_Care_Utils::log('api_task', 'info', "Task '$task' requested via API", [
            'force' => $force,
            'args' => $args,
            'plan' => $payload['plan'] ?? 'unknown'
        ]);
        
        $start_time = microtime(true);
        
        try {
            // Map task names to actual hook names
            $task_hooks = [
                'updates' => 'rpcare_task_updates',
                'backup' => 'rpcare_task_backup',
                'wpo' => 'rpcare_task_wpo',
                'seo_review' => 'rpcare_task_seo_review',
                'seo_audit' => 'rpcare_task_seo_audit',
                'health' => 'rpcare_task_health',
                'monitor' => 'rpcare_task_monitor',
                '404_cleanup' => 'rpcare_task_404_cleanup',
                'report' => 'rpcare_task_report'
            ];
            
            if (!isset($task_hooks[$task])) {
                return new WP_Error('invalid_task', 'Invalid task name', ['status' => 400]);
            }
            
            $hook = $task_hooks[$task];
            
            // Execute the task
            do_action($hook, $args);
            
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            
            return [
                'success' => true,
                'task' => $task,
                'executed_at' => current_time('mysql'),
                'execution_time_ms' => $execution_time,
                'forced' => $force
            ];
            
        } catch (Exception $e) {
            RP_Care_Utils::log('api_task', 'error', "Task '$task' failed: " . $e->getMessage());
            
            return new WP_Error('task_execution_failed', $e->getMessage(), ['status' => 500]);
        }
    }
    
    public function get_metrics($request) {
        $metrics = RP_Care_Utils::get_site_metrics();
        
        // Add specific Replanta Care metrics
        $rpcare_metrics = [
            'plan' => RP_Care_Plan::get_current(),
            'activated' => get_option('rpcare_activated', false),
            'last_update_check' => get_option('rpcare_last_update_check', ''),
            'last_backup' => get_option('rpcare_last_backup', ''),
            'performance_score' => RP_Care_Utils::get_performance_score(),
            'total_404s' => $this->get_404_count(),
            'pending_updates' => $this->get_pending_updates_count(),
            'security_score' => $this->calculate_security_score()
        ];
        
        return array_merge($metrics, $rpcare_metrics);
    }
    
    public function ping($request) {
        // Update uptime status
        update_option('rpcare_last_uptime_check', time());
        update_option('rpcare_uptime_status', 'up');
        
        // Send basic site info
        return [
            'status' => 'ok',
            'timestamp' => time(),
            'site_url' => get_site_url(),
            'wp_version' => get_bloginfo('version'),
            'plugin_version' => RPCARE_VERSION,
            'plan' => RP_Care_Plan::get_current(),
            'memory_usage' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => ini_get('memory_limit')
            ]
        ];
    }
    
    public function get_logs($request) {
        $limit = $request->get_param('limit');
        $task_type = $request->get_param('task_type');
        $status = $request->get_param('status');
        
        $logs = RP_Care_Utils::get_logs($limit, $task_type, $status);
        
        return [
            'logs' => $logs,
            'total' => count($logs),
            'filters' => [
                'task_type' => $task_type,
                'status' => $status,
                'limit' => $limit
            ]
        ];
    }
    
    public function apply_redirects($request) {
        $redirects = $request->get_param('redirects');
        $applied = 0;
        $errors = [];
        
        foreach ($redirects as $redirect) {
            if (!isset($redirect['from']) || !isset($redirect['to'])) {
                $errors[] = 'Invalid redirect format';
                continue;
            }
            
            $from = sanitize_text_field($redirect['from']);
            $to = esc_url_raw($redirect['to']);
            
            // Try to apply via Redirection plugin first
            if ($this->apply_redirection_plugin($from, $to)) {
                $applied++;
            } elseif ($this->apply_htaccess_redirect($from, $to)) {
                $applied++;
            } else {
                $errors[] = "Failed to apply redirect: $from -> $to";
            }
        }
        
        return [
            'success' => $applied > 0,
            'applied' => $applied,
            'total' => count($redirects),
            'errors' => $errors
        ];
    }
    
    public function get_404s($request) {
        global $wpdb;
        
        $limit = $request->get_param('limit');
        $table_name = $wpdb->prefix . 'rpcare_404_logs';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY hits DESC, last_seen DESC LIMIT %d",
            $limit
        ));
        
        return [
            '404s' => $results,
            'total' => count($results)
        ];
    }
    
    public function update_config($request) {
        $plan = $request->get_param('plan');
        $settings = $request->get_param('settings');
        $updated = [];
        
        // Update plan if provided
        if ($plan && RP_Care_Plan::is_valid_plan($plan)) {
            $old_plan = RP_Care_Plan::get_current();
            if (RP_Care_Plan::set_current($plan)) {
                $updated['plan'] = ['old' => $old_plan, 'new' => $plan];
                
                // Reschedule tasks for new plan
                $scheduler = new RP_Care_Scheduler($plan);
                $scheduler->clear_all();
                $scheduler->ensure();
            }
        }
        
        // Update settings if provided
        if ($settings && is_array($settings)) {
            $sanitized_settings = RP_Care_Security::sanitize_settings($settings);
            
            foreach ($sanitized_settings as $key => $value) {
                $old_value = get_option($key);
                update_option($key, $value);
                $updated['settings'][$key] = ['old' => $old_value, 'new' => $value];
            }
        }
        
        RP_Care_Utils::log('config_update', 'info', 'Configuration updated via API', $updated);
        
        return [
            'success' => true,
            'updated' => $updated,
            'timestamp' => current_time('mysql')
        ];
    }
    
    public function get_schedule($request) {
        $scheduler = new RP_Care_Scheduler(RP_Care_Plan::get_current());
        $next_runs = $scheduler->get_next_runs();
        
        return [
            'next_runs' => $next_runs,
            'plan' => RP_Care_Plan::get_current(),
            'timezone' => get_option('timezone_string', 'UTC')
        ];
    }
    
    private function get_404_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rpcare_404_logs';
        return (int) $wpdb->get_var("SELECT SUM(hits) FROM $table_name");
    }
    
    private function get_pending_updates_count() {
        $updates = get_site_transient('update_core');
        $plugin_updates = get_site_transient('update_plugins');
        $theme_updates = get_site_transient('update_themes');
        
        $count = 0;
        
        if ($updates && !empty($updates->updates)) {
            $count += count($updates->updates);
        }
        
        if ($plugin_updates && !empty($plugin_updates->response)) {
            $count += count($plugin_updates->response);
        }
        
        if ($theme_updates && !empty($theme_updates->response)) {
            $count += count($theme_updates->response);
        }
        
        return $count;
    }
    
    private function calculate_security_score() {
        $score = 100;
        
        // Check for security plugins
        $security_plugins = [
            'wordfence/wordfence.php',
            'better-wp-security/better-wp-security.php',
            'sucuri-scanner/sucuri.php'
        ];
        
        $has_security_plugin = false;
        foreach ($security_plugins as $plugin) {
            if (RP_Care_Utils::is_plugin_active($plugin)) {
                $has_security_plugin = true;
                break;
            }
        }
        
        if (!$has_security_plugin) {
            $score -= 20;
        }
        
        // Check SSL
        if (!is_ssl()) {
            $score -= 15;
        }
        
        // Check WordPress version
        $wp_version = get_bloginfo('version');
        $latest_version = get_preferred_from_update_core();
        if ($latest_version && version_compare($wp_version, $latest_version->current, '<')) {
            $score -= 10;
        }
        
        // Check for debug mode in production
        if (defined('WP_DEBUG') && WP_DEBUG && !defined('WP_DEBUG_DISPLAY')) {
            $score -= 5;
        }
        
        return max(0, $score);
    }
    
    private function apply_redirection_plugin($from, $to) {
        // Check if Redirection plugin is active and has the required classes
        if (!class_exists('Red_Item') || !function_exists('red_get_groups')) {
            return false;
        }
        
        try {
            // Use WordPress database to create redirect entry
            global $wpdb;
            
            // Get redirection table (if exists)
            $table_name = $wpdb->prefix . 'redirection_items';
            
            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                return false;
            }
            
            // Insert redirect rule
            $result = $wpdb->insert(
                $table_name,
                array(
                    'url' => $from,
                    'action_data' => $to,
                    'action_type' => 'url',
                    'match_type' => 'url',
                    'group_id' => 1,
                    'title' => 'Auto-created by Replanta Care',
                    'status' => 'enabled',
                    'position' => 0
                ),
                array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d')
            );
            
            return $result !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function apply_htaccess_redirect($from, $to) {
        $htaccess_file = ABSPATH . '.htaccess';
        
        if (!is_writable($htaccess_file)) {
            return false;
        }
        
        $redirect_rule = "Redirect 301 $from $to\n";
        $htaccess_content = file_get_contents($htaccess_file);
        
        // Add redirect at the beginning of .htaccess
        $new_content = $redirect_rule . $htaccess_content;
        
        return file_put_contents($htaccess_file, $new_content) !== false;
    }
}
