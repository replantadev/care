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
                    'enum' => ['updates', 'backup', 'wpo', 'seo_review', 'seo_audit', 'health', 'monitor', '404_cleanup', 'report', 'self_update', 'cwv', 'anomaly', 'cloudflare_configure', 'orphan_media_scan', 'staging_clone']
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
                ],
                'update_managed' => [
                    'required' => false,
                    'type'     => 'boolean',
                ],
                'vulnerability_data' => [
                    'required' => false,
                    'type'     => 'object',
                ],
                'hub_url' => [
                    'required' => false,
                    'type'     => 'string',
                ],
            ]
        ]);
        
        // Get scheduled tasks
        register_rest_route($this->namespace, '/schedule', [
            'methods' => 'GET',
            'callback' => [$this, 'get_schedule'],
            'permission_callback' => [$this, 'check_permissions']
        ]);

        // Backuply: list backups
        register_rest_route($this->namespace, '/backuply/list', [
            'methods' => 'GET',
            'callback' => [$this, 'backuply_list'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 200,
                ],
            ],
        ]);

        // Backuply: trigger a new backup
        register_rest_route($this->namespace, '/backuply/create', [
            'methods' => 'POST',
            'callback' => [$this, 'backuply_create'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        // Hub-triggered self-update
        register_rest_route($this->namespace, '/upgrade', [
            'methods'             => 'POST',
            'callback'            => [$this, 'self_upgrade'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        // Hub-triggered WP fix execution
        register_rest_route($this->namespace, '/execute-fix', [
            'methods'             => 'POST',
            'callback'            => [$this, 'execute_fix'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'fix_id' => [
                    'required' => true,
                    'type'     => 'string',
                    'enum'     => [
                        'wp_debug_off', 'wp_memory_limit', 'wp_cron_disable',
                        'heartbeat_optimize', 'db_clean_revisions',
                        'db_clean_transients', 'db_clean_spam', 'ls_enable_object_cache',
                    ],
                ],
            ],
        ]);
    }
    
    public function check_permissions($request) {
        try {
            return RP_Care_Security::validate_request($request);
        } catch (\Throwable $e) {
            error_log('[Replanta Care] check_permissions fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new WP_Error('auth_error', 'Authentication error: ' . $e->getMessage(), ['status' => 500]);
        }
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
                'report' => 'rpcare_task_report',
                'cwv' => 'rpcare_task_cwv',
                'anomaly' => 'rpcare_task_anomaly',
                'cloudflare_configure' => 'rpcare_task_cloudflare_configure',
                'orphan_media_scan' => 'rpcare_task_orphan_media',
                'staging_clone' => 'rpcare_task_staging_clone',
                'self_update' => '__rpcare_self_update'
            ];

            if (!isset($task_hooks[$task])) {
                return new WP_Error('invalid_task', 'Invalid task name', ['status' => 400]);
            }

            // Self-update is handled inline (forces WP to install the latest Care zip from Hub)
            if ($task === 'self_update') {
                $result = $this->do_self_update();
                $execution_time = round((microtime(true) - $start_time) * 1000, 2);
                return array_merge($result, [
                    'task' => $task,
                    'executed_at' => current_time('mysql'),
                    'execution_time_ms' => $execution_time,
                    'forced' => $force,
                ]);
            }
            
            $hook = $task_hooks[$task];
            
            // Execute the task and capture the result.
            // Task handlers are registered via add_filter and receive $args
            // as the filtered value, returning their result array.
            // If no handler is hooked the raw $args pass through unchanged;
            // we detect that with the __rpcare_unhandled sentinel.
            $sentinel = is_array($args) ? $args : [];
            $sentinel['__rpcare_unhandled'] = true;
            $task_result = apply_filters($hook, $sentinel);
            
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            
            // If the sentinel survived, no handler processed the task
            if (is_array($task_result) && !empty($task_result['__rpcare_unhandled'])) {
                return new WP_Error(
                    'task_no_handler',
                    "No handler registered for task '$task'",
                    ['status' => 500]
                );
            }
            
            // Normalise: if the handler returned a non-array, wrap it
            if (!is_array($task_result)) {
                $task_result = ['success' => true, 'data' => $task_result];
            }
            
            // If the handler didn't set 'success', assume true
            if (!isset($task_result['success'])) {
                $task_result['success'] = true;
            }
            
            return array_merge($task_result, [
                'task' => $task,
                'executed_at' => current_time('mysql'),
                'execution_time_ms' => $execution_time,
                'forced' => $force
            ]);
            
        } catch (Exception $e) {
            RP_Care_Utils::log('api_task', 'error', "Task '$task' failed: " . $e->getMessage());
            
            return new WP_Error('task_execution_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Force WP to check + install the latest Care zip served by the Hub.
     * Used by the Hub "Actualizar Care" button so admins don't have to wait
     * for PUC's periodic check.
     */
    private function do_self_update() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $before = defined('RPCARE_VERSION') ? RPCARE_VERSION : '';

        // Bust PUC + WP plugin transient so the next check hits the Hub
        delete_site_transient('update_plugins');
        delete_site_transient('puc_request_info_replanta-care');
        wp_clean_plugins_cache(true);

        // Trigger PUC to refetch metadata immediately
        if (class_exists('\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
            do_action('puc_request_info_replanta-care');
        }
        wp_update_plugins();

        $transient = get_site_transient('update_plugins');
        $plugin_file = 'replanta-care/replanta-care.php';
        $available   = isset($transient->response[$plugin_file]) ? $transient->response[$plugin_file]->new_version : null;

        if (!$available || version_compare($available, $before, '<=')) {
            return [
                'success' => true,
                'message' => 'Care ya está en la última versión disponible',
                'version_before' => $before,
                'version_available' => $available,
                'upgraded' => false,
            ];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result   = $upgrader->upgrade($plugin_file);

        if (is_wp_error($result)) {
            return ['success' => false, 'message' => $result->get_error_message(), 'version_before' => $before];
        }
        if ($result === false) {
            return ['success' => false, 'message' => 'Upgrader devolvió false', 'version_before' => $before];
        }

        return [
            'success' => true,
            'message' => 'Care actualizado correctamente',
            'version_before' => $before,
            'version_after' => $available,
            'upgraded' => true,
        ];
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
        try {
            // Update uptime status
            update_option('rpcare_last_uptime_check', time());
            update_option('rpcare_uptime_status', 'up');

            // Read plan from DB (no HTTP call) to avoid blocking remote request
            $plan = get_option('rpcare_plan', get_option('rpcare_detected_plan', ''));
            if (!$plan) {
                // Only use the cached transient — never trigger an outgoing Hub call inside a REST handler
                $plan = get_transient('rpcare_plan_cache') ?: '';
            }

            // Send basic site info
            return [
                'status' => 'ok',
                'timestamp' => time(),
                'site_url' => get_site_url(),
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => RPCARE_VERSION,
                'plan' => $plan,
                'memory_usage' => [
                    'current' => memory_get_usage(true),
                    'peak' => memory_get_peak_usage(true),
                    'limit' => ini_get('memory_limit')
                ]
            ];
        } catch (\Throwable $e) {
            error_log('[Replanta Care] ping fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new WP_Error('ping_error', $e->getMessage(), ['status' => 500]);
        }
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
        
        // Hub telling Care whether it manages updates (WP Toolkit Pro)
        $update_managed = $request->get_param('update_managed');
        if (!is_null($update_managed)) {
            update_option('rpcare_update_managed', (bool) $update_managed);
            $updated['update_managed'] = (bool) $update_managed;
        }

        // Vulnerability scan results pushed by Hub (from WP Toolkit Pro)
        $vulnerability_data = $request->get_param('vulnerability_data');
        if ($vulnerability_data && is_array($vulnerability_data)) {
            $vulnerability_data['received_at'] = current_time('mysql');
            update_option('rpcare_vulnerability_data', $vulnerability_data);
            $updated['vulnerability_data'] = [
                'count' => count($vulnerability_data['vulnerabilities_found'] ?? []),
            ];
        }

        // Hub can update its own URL remotely (e.g. on migration)
        $new_hub_url = $request->get_param('hub_url');
        if ($new_hub_url) {
            $clean = esc_url_raw(rtrim($new_hub_url, '/'));
            if ($clean) {
                $opts = get_option('rpcare_options', []);
                $opts['hub_url'] = $clean;
                update_option('rpcare_options', $opts);
                delete_transient('rpcare_plan_cache');
                delete_transient('rpcare_hub_backoff');
                $updated['hub_url'] = $clean;
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
        
        // Sanitize inputs to prevent .htaccess injection
        // $from must be a relative path starting with /
        $from = '/' . ltrim(sanitize_text_field($from), '/');
        if (preg_match('/[\r\n]/', $from) || !preg_match('#^/[a-zA-Z0-9/_.-]+$#', $from)) {
            error_log('Replanta Care: Rejected invalid redirect source: ' . $from);
            return false;
        }
        
        // $to must be a valid URL
        $to = esc_url_raw($to);
        if (empty($to) || preg_match('/[\r\n]/', $to)) {
            error_log('Replanta Care: Rejected invalid redirect target: ' . $to);
            return false;
        }
        
        $redirect_rule = "Redirect 301 $from $to\n";
        $htaccess_content = file_get_contents($htaccess_file);
        
        // Avoid duplicate redirects
        if (strpos($htaccess_content, "Redirect 301 $from ") !== false) {
            return true; // Already exists
        }
        
        // Add redirect at the beginning of .htaccess
        $new_content = $redirect_rule . $htaccess_content;

        return file_put_contents($htaccess_file, $new_content) !== false;
    }

    /**
     * GET /backuply/list — returns backups stored by Backuply plugin.
     */
    public function backuply_list($request) {
        $limit = (int) $request->get_param('limit');

        $backup_list = get_option('backuply_backup_list', []);

        if (!is_array($backup_list)) {
            return rest_ensure_response(['backups' => [], 'total' => 0, 'plugin_active' => false]);
        }

        $plugin_active = is_plugin_active('backuply/backuply.php');

        // Normalize and sort by timestamp descending
        $backups = [];
        foreach ($backup_list as $key => $b) {
            $ts = isset($b['time']) ? (int) $b['time'] : (int) strtotime($b['date'] ?? '');
            $backups[] = [
                'id'           => is_string($key) ? $key : ($b['id'] ?? uniqid('bkp_')),
                'name'         => $b['name'] ?? $b['filename'] ?? 'Backup',
                'type'         => $b['type'] ?? 'full',
                'status'       => $b['status'] ?? 'completed',
                'size'         => $b['size'] ?? 0,
                'created_at'   => $ts ? date('Y-m-d H:i:s', $ts) : '',
                'completed_at' => $ts ? date('Y-m-d H:i:s', $ts) : '',
                'is_restorable' => true,
            ];
        }

        usort($backups, function ($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });

        return rest_ensure_response([
            'backups'       => array_slice($backups, 0, $limit),
            'total'         => count($backups),
            'plugin_active' => $plugin_active,
        ]);
    }

    /**
     * POST /backuply/create — triggers a Backuply backup.
     */
    public function backuply_create($request) {
        if (!is_plugin_active('backuply/backuply.php')) {
            return new WP_Error('backuply_inactive', 'Backuply no está activo en este sitio', ['status' => 503]);
        }

        do_action('backuply_cron_backup');

        RP_Care_Utils::log('backup', 'info', 'Backup Backuply solicitado desde Hub via REST');

        return rest_ensure_response([
            'success'    => true,
            'message'    => 'Backup iniciado con Backuply',
            'started_at' => current_time('mysql'),
        ]);
    }

    /**
     * Hub-triggered self-update.
     * Hub calls POST /replanta/v1/upgrade to push Care to the latest release.
     */
    public function self_upgrade($request) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

        $plugin_file = plugin_basename(RPCARE_PLUGIN_FILE);

        // Force a fresh update check
        delete_site_transient('update_plugins');
        wp_update_plugins();

        $updates = get_site_transient('update_plugins');
        if (empty($updates->response[$plugin_file])) {
            return rest_ensure_response([
                'up_to_date' => true,
                'message'    => 'Ya estás en la última versión.',
                'version'    => RPCARE_VERSION,
            ]);
        }

        $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
        $result   = $upgrader->upgrade($plugin_file);

        if (is_wp_error($result)) {
            return new WP_Error('upgrade_failed', $result->get_error_message(), ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Plugin actualizado correctamente.',
            'from'    => RPCARE_VERSION,
            'to'      => $updates->response[$plugin_file]->new_version ?? 'nueva versión',
        ]);
    }

    /**
     * POST /execute-fix — apply a single WP-level fix dispatched by Hub.
     */
    public function execute_fix($request) {
        $fix_id = $request->get_param('fix_id');

        switch ($fix_id) {
            case 'wp_debug_off':
                return rest_ensure_response($this->fix_wpconfig_define('WP_DEBUG', 'false', false));

            case 'wp_memory_limit':
                return rest_ensure_response($this->fix_wpconfig_define('WP_MEMORY_LIMIT', "'256M'", false));

            case 'wp_cron_disable':
                return rest_ensure_response($this->fix_wpconfig_define('DISABLE_WP_CRON', 'true', false));

            case 'heartbeat_optimize':
                update_option('rpcare_heartbeat_interval', 60);
                add_filter('heartbeat_settings', function($settings) {
                    $settings['interval'] = 60;
                    return $settings;
                });
                return rest_ensure_response(['success' => true, 'fix_id' => $fix_id, 'message' => 'Heartbeat optimizado a 60s']);

            case 'db_clean_revisions':
                global $wpdb;
                $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
                return rest_ensure_response(['success' => true, 'fix_id' => $fix_id, 'deleted_rows' => (int) $deleted]);

            case 'db_clean_transients':
                global $wpdb;
                $deleted = $wpdb->query(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"
                );
                return rest_ensure_response(['success' => true, 'fix_id' => $fix_id, 'deleted_rows' => (int) $deleted]);

            case 'db_clean_spam':
                global $wpdb;
                $deleted = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
                return rest_ensure_response(['success' => true, 'fix_id' => $fix_id, 'deleted_rows' => (int) $deleted]);

            case 'ls_enable_object_cache':
                update_option('litespeed_conf_object', 1);
                update_option('litespeed-object-cache', 1);
                return rest_ensure_response(['success' => true, 'fix_id' => $fix_id, 'message' => 'LiteSpeed Object Cache habilitado']);

            default:
                return new WP_Error('invalid_fix', "Fix desconocido: {$fix_id}", ['status' => 400]);
        }
    }

    private function fix_wpconfig_define(string $constant, string $new_value, bool $add_if_missing): array {
        $config_path = ABSPATH . 'wp-config.php';
        if (!is_readable($config_path) || !is_writable($config_path)) {
            return ['success' => false, 'fix_id' => strtolower($constant), 'error' => 'wp-config.php no es accesible'];
        }

        $content  = file_get_contents($config_path);
        $original = $content;

        $pattern     = "/define\s*\(\s*['\"]" . preg_quote($constant, '/') . "['\"]\s*,\s*[^)]+\)\s*;/";
        $replacement = "define('{$constant}', {$new_value});";

        if (preg_match($pattern, $content)) {
            $replaced = preg_replace($pattern, $replacement, $content);
            if ($replaced === null) {
                return ['success' => false, 'fix_id' => strtolower($constant), 'error' => 'Error interno al modificar wp-config.php'];
            }
            $content = $replaced;
        } elseif ($add_if_missing) {
            $content = str_replace('/* That\'s all, stop editing!', $replacement . "\n/* That's all, stop editing!", $content);
        } else {
            return ['success' => true, 'fix_id' => strtolower($constant), 'message' => 'Constante no encontrada; no es necesario cambiarla'];
        }

        if ($content === $original) {
            return ['success' => true, 'fix_id' => strtolower($constant), 'message' => 'Sin cambios'];
        }

        file_put_contents($config_path, $content);
        return ['success' => true, 'fix_id' => strtolower($constant), 'message' => 'wp-config.php actualizado'];
    }
}
