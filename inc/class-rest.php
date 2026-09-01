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

    private string $control_ns = 'replanta-care/v1';

    /** @var callable|null Test seam for the read-only isolation report. */
    public static $isolation_report_reader = null;

    /** @var callable|null Test seam for an authenticated inventory refresh. */
    public static $inventory_refresher = null;

    /** @var callable|null Test seam for an authenticated immediate pipeline poll. */
    public static $pipeline_poll_runner = null;

    /** @var callable|null Test seam for controlled local-command recovery. */
    public static $pipeline_local_runner = null;

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        // ── Bootstrap: Plugin Center → set Hub token ──────────────────────
        // Public endpoint authenticated by license_key in request body.
        register_rest_route($this->control_ns, '/set-token', [
            'methods'             => 'POST',
            'callback'            => [$this, 'set_hub_token'],
            'permission_callback' => '__return_true',
        ]);

        // ── Hub health ping ────────────────────────────────────────────────
        // Called by PC_Hub_Care::health() with X-Hub-Token header (sha256 of site_token).
        register_rest_route($this->control_ns, '/ping', [
            'methods'             => 'GET',
            'callback'            => [$this, 'hub_ping'],
            'permission_callback' => '__return_true',
        ]);

        // ── Hub remote update ──────────────────────────────────────────────
        // Called by PC_Hub_Care::remote_action('update') to self-update Care.
        register_rest_route($this->control_ns, '/update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'hub_update'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->control_ns, '/config', [
            'methods'             => 'POST',
            'callback'            => [$this, 'hub_config'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($this->control_ns, '/notify-test', [
            'methods'             => 'POST',
            'callback'            => [$this, 'hub_notify_test'],
            'permission_callback' => '__return_true',
        ]);

        // ── Hub run task ──────────────────────────────────────────────────────
        // Called by Plugin Center to dispatch an AS task immediately.
        register_rest_route($this->control_ns, '/run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'hub_run'],
            'permission_callback' => '__return_true',
        ]);

        // ── Hub backup list (for Hub-driven UI when client admin may be broken)
        register_rest_route($this->control_ns, '/backup/list', [
            'methods'             => 'POST',
            'callback'            => [$this, 'hub_backup_list'],
            'permission_callback' => '__return_true',
        ]);

        // ── Hub-driven restore (control namespace, X-Hub-Token, strict) ───────
        register_rest_route($this->control_ns, '/backup/restore', [
            'methods'             => 'POST',
            'callback'            => [$this, 'hub_backup_restore'],
            'permission_callback' => '__return_true',
        ]);

        // ── Async job status poll — returns AS status + recent logs ─────────
        register_rest_route($this->control_ns, '/job/status', [
            'methods'             => 'POST',
            'callback'            => [$this, 'hub_job_status'],
            'permission_callback' => '__return_true',
        ]);

        // ── Staging approval — clears cooldown and schedules production update ─
        register_rest_route($this->control_ns, '/staging-approve', [
            'methods'             => 'POST',
            'callback'            => [$this, 'hub_staging_approve'],
            'permission_callback' => '__return_true',
        ]);

        // ── Staging baseline — returns the production DOM snapshot captured at clone time ─
        register_rest_route($this->control_ns, '/staging-baseline', [
            'methods'             => 'GET',
            'callback'            => [$this, 'hub_staging_baseline'],
            'permission_callback' => '__return_true',
        ]);

        // ── Reports: Hub reads Care's local report history ─────────────────────
        register_rest_route($this->control_ns, '/reports', [
            'methods'             => 'GET',
            'callback'            => [$this, 'hub_get_reports'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($this->control_ns, '/reports/(?P<id>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'hub_get_report_html'],
            'permission_callback' => '__return_true',
        ]);

        // ── Woo autonomous maintenance endpoints (Capa 2) ────────────────────────
        // All five are read-only Hub-authenticated operations.
        register_rest_route( $this->control_ns, '/woo/telemetry', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'hub_woo_telemetry' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $this->control_ns, '/woo/routes', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'hub_woo_routes' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $this->control_ns, '/woo/snapshot', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'hub_woo_snapshot' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $this->control_ns, '/woo/hygiene-dry', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'hub_woo_hygiene_dry' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $this->control_ns, '/woo/anomalies', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'hub_woo_anomalies' ],
            'permission_callback' => '__return_true',
        ] );

        // ── Smart Updates: executor/pipeline status for drift detection ────────
        // Called by PC ajax_smart_updates_overview to compare desired policy vs
        // effective Care configuration.  Returns schema_version=1 envelope.
        register_rest_route( $this->control_ns, '/smart-updates/status', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'hub_smart_updates_status' ],
            'permission_callback' => '__return_true',
        ] );
		register_rest_route( $this->control_ns, '/pipeline/heartbeat', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'hub_pipeline_heartbeat' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( $this->control_ns, '/pipeline/poll-now', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'hub_pipeline_poll_now' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( $this->control_ns, '/pipeline/local-command/run', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'hub_pipeline_run_local_command' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( $this->control_ns, '/pipeline/loopback-probe', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'pipeline_loopback_probe' ],
			'permission_callback' => '__return_true',
		] );

        // ── Update inventory: full raw transient dump for 4-way count reconciliation ─
        // Returns every plugin/theme entry from the raw update_plugins transient,
        // bypassing Care's policy filter.  Requires X-Hub-Token auth (strict).
        register_rest_route( $this->control_ns, '/updates/inventory', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'hub_updates_inventory' ],
            'permission_callback' => '__return_true',
        ] );

        // ── Pipeline isolation report: read-only gate for paired staging ──────
        register_rest_route( $this->control_ns, '/pipeline/isolation-report', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'hub_pipeline_isolation_report' ],
            'permission_callback' => '__return_true',
        ] );

        // ── B2 connection test: read-only diagnostic, never writes to B2 ────────
        // Phases: DNS, TLS, b2_authorize_account, bucket access, key capabilities.
        // Never logs credentials (key_id, app_key, auth_token, download URLs).
        // Requires X-Hub-Token auth (strict).
        register_rest_route( $this->control_ns, '/b2/connection-test', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'hub_b2_connection_test' ],
            'permission_callback' => '__return_true',
        ] );

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
                'b2_key_id' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'b2_app_key' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'b2_bucket_id' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'b2_bucket_name' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'b2_prefix' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'portal_cache' => [
                    'required' => false,
                    'type'     => 'object',
                ],
                'addons' => [
                    'required' => false,
                    'type'     => 'array',
                    'items'    => ['type' => 'string'],
                    'default'  => [],
                ],
                'ecommerce_config' => [
                    'required' => false,
                    'type'     => 'object',
                ],
                'backup_frequency' => [
                    'required' => false,
                    'type'     => 'string',
                    'enum'     => ['hourly', 'twicedaily', 'daily', 'weekly', 'monthly', 'quarterly'],
                ],
                'backup_retention_days' => [
                    'required' => false,
                    'type'     => 'integer',
                    'minimum'  => 1,
                    'maximum'  => 365,
                ],
                'update_window' => [
                    'required' => false,
                    'type'     => 'object',
                ],
                'smart_updates_mode' => [
                    'required' => false,
                    'type'     => 'string',
                    'enum'     => [ 'observe_only', 'staging_required', 'disabled' ],
                ],
                'approval_policy' => [
                    'required' => false,
                    'type'     => 'string',
                    'enum'     => [ 'always', 'auto_approve_minor', 'disabled' ],
                ],
                'maximum_batch_size' => [
                    'required' => false,
                    'type'     => 'integer',
                    'minimum'  => 1,
                    'maximum'  => 10,
                ],
                'maintenance_window_auto' => [
                    'required' => false,
                    'type'     => 'boolean',
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

        register_rest_route($this->namespace, '/backup/list', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'backup_list'],
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

        register_rest_route($this->namespace, '/backup/create', [
            'methods' => 'POST',
            'callback' => [$this, 'backup_create'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'scopes' => [
                    'required' => false,
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'type' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/backup/verify', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'backup_verify'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'backup_id' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/backup/restore', [
            'methods' => 'POST',
            'callback' => [$this, 'backup_restore'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'backup_id' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'scopes' => [
                    'required' => false,
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
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

        // SA summary — reads replanta-site-audit transient directly (no HTTP)
        register_rest_route($this->namespace, '/sa/summary', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_sa_summary'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        // SA fix — executes a replanta-site-audit atomic fix
        register_rest_route($this->namespace, '/sa/fix', [
            'methods'             => 'POST',
            'callback'            => [$this, 'run_sa_fix'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'fix_id' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ]);

        // SA issues — returns full list of fixable/critical checks from SA transient
        register_rest_route($this->namespace, '/sa/issues', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_sa_issues'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        // ── Staging-First Pipeline: Care-side endpoints ───────────────────────

        // GET /pipeline/commands — Care polls for pending commands from PC.
        register_rest_route($this->control_ns, '/pipeline/commands', [
            'methods'             => 'GET',
            'callback'            => [$this, 'pipeline_poll_commands'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        // POST /pipeline/command-ack — Care acknowledges a completed or failed command to PC (forwarded).
        register_rest_route($this->control_ns, '/pipeline/command-ack', [
            'methods'             => 'POST',
            'callback'            => [$this, 'pipeline_command_ack'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        // POST /pipeline/pairing — Begin or complete a pairing flow from Care admin UI.
        register_rest_route($this->control_ns, '/pipeline/pairing', [
            'methods'             => 'POST',
            'callback'            => [$this, 'pipeline_pairing'],
            // Pairing is initiated by Plugin Center, whose transport contract
            // is X-Hub-Token. The handler validates it strictly before touching
            // the one-time, URL-bound pairing token.
            'permission_callback' => '__return_true',
        ]);

        // POST /pipeline/approval-request — Operator submits approval from the Care admin approval screen.
        register_rest_route($this->control_ns, '/pipeline/approval-request', [
            'methods'             => 'POST',
            'callback'            => [$this, 'pipeline_approval_request'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }

    // ── Pipeline endpoint handlers ────────────────────────────────────────────

    public function pipeline_poll_commands( WP_REST_Request $request ) {
        if ( ! class_exists( 'RP_Care_Pipeline_Client' ) || ! RP_Care_Pipeline_Client::is_pipeline_enabled() ) {
            return new WP_REST_Response( [ 'ok' => false, 'message' => 'Pipeline not enabled.' ], 200 );
        }

        $result = RP_Care_Pipeline_Client::poll_and_execute();
        return new WP_REST_Response( $result, 200 );
    }

    public function pipeline_command_ack( WP_REST_Request $request ) {
        if ( ! class_exists( 'RP_Care_Pipeline_Client' ) ) {
            return new WP_REST_Response( [ 'ok' => false ], 200 );
        }
        // Forward the ACK to PC's REST endpoint.
        $body   = $request->get_json_params();
        $result = RP_Care_Pipeline_Client::forward_ack( $body );
        return new WP_REST_Response( $result, 200 );
    }

    public function pipeline_pairing( WP_REST_Request $request ) {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }
        if ( ! class_exists( 'RP_Care_Pipeline_Client' ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'message' => 'Pipeline client not loaded.' ], 200 );
        }
        $body   = $request->get_json_params();
        $result = RP_Care_Pipeline_Client::handle_pairing_request( $body );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'message' => $result->get_error_message() ], 422 );
        }
        return new WP_REST_Response( array_merge( [ 'ok' => true ], $result ), 200 );
    }

    public function pipeline_approval_request( WP_REST_Request $request ) {
        if ( ! class_exists( 'RP_Care_Approval_Screen' ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'message' => 'Approval module not loaded.' ], 200 );
        }
        $body   = $request->get_json_params();
        $result = RP_Care_Approval_Screen::handle_rest_approval( $body );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'message' => $result->get_error_message() ], 422 );
        }
        return new WP_REST_Response( array_merge( [ 'ok' => true ], $result ), 200 );
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

        // Ensure PUC is registered — it is NOT initialized during REST requests by the
        // bootstrap (is_admin() is false for /wp-json/ requests). rpcare_init_puc() is
        // idempotent: calling it here is safe even if bootstrap already ran it.
        rpcare_init_puc();

        // Bust PUC + WP plugin transient so the next check hits the Hub
        delete_site_transient('update_plugins');
        delete_site_transient('puc_request_info_replanta-care');
        wp_clean_plugins_cache(true);

        // Trigger PUC to refetch metadata immediately, then run WP's full check
        do_action( 'puc_request_info_replanta-care' );
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
            'security_score' => $this->calculate_security_score(),
            'ssl_type' => $this->detect_ssl_type(),
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
        $opts = get_option( 'rpcare_options', [] );
        $updated = [];
        $needs_reschedule = false;
        
        // Update plan if provided
        if ($plan && RP_Care_Plan::is_valid_plan($plan)) {
            $old_plan = RP_Care_Plan::get_current();
            if (RP_Care_Plan::set_current($plan)) {
                $updated['plan'] = ['old' => $old_plan, 'new' => $plan];
                $needs_reschedule = true;
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

        // Per-site toggle for WP Core major version updates (e.g. 6.x → 7.x)
        $allow_major_updates = $request->get_param('allow_major_updates');
        if (!is_null($allow_major_updates)) {
            update_option('rpcare_allow_major_updates', (bool) $allow_major_updates);
            $updated['allow_major_updates'] = (bool) $allow_major_updates;
        }

        $backup_frequency = $request->get_param('backup_frequency');
        if ($backup_frequency !== null) {
            $allowed = ['hourly', 'twicedaily', 'daily', 'weekly', 'monthly', 'quarterly'];
            if (in_array($backup_frequency, $allowed, true)) {
                update_option('rpcare_backup_frequency_override', $backup_frequency);
                $updated['backup_frequency'] = $backup_frequency;
                $needs_reschedule = true;
            }
        }

        $backup_retention_days = $request->get_param('backup_retention_days');
        if ($backup_retention_days !== null) {
            $backup_retention_days = max(1, min(365, (int) $backup_retention_days));
            update_option('rpcare_backup_retention_days', $backup_retention_days);
            $updated['backup_retention_days'] = $backup_retention_days;
        }

        $update_window = $request->get_param('update_window');
        if (is_array($update_window)) {
            if (array_key_exists('day', $update_window)) {
                $day = $update_window['day'];
                if ($day === null || $day === '') {
                    update_option('rpcare_update_window_day', '');
                    $updated['update_window']['day'] = null;
                } else {
                    $day = max(0, min(6, (int) $day));
                    update_option('rpcare_update_window_day', $day);
                    $updated['update_window']['day'] = $day;
                }
            }
            if (array_key_exists('start_hour', $update_window)) {
                $start_hour = max(0, min(23, (int) $update_window['start_hour']));
                update_option('rpcare_update_window_start_hour', $start_hour);
                $updated['update_window']['start_hour'] = $start_hour;
            }
            if (array_key_exists('end_hour', $update_window)) {
                $end_hour = max(0, min(23, (int) $update_window['end_hour']));
                update_option('rpcare_update_window_end_hour', $end_hour);
                $updated['update_window']['end_hour'] = $end_hour;
            }
            $needs_reschedule = true;
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

        // Credenciales Backblaze B2 empujadas por Hub
        $b2_saved = $this->saveB2Config($request);
        if (!empty($b2_saved)) {
            $updated['b2_config'] = $b2_saved;
        }

        // Portal cache empujado por Hub tras ciclos de actualización y checks diarios
        $portal_cache = $request->get_param('portal_cache');
        if (!empty($portal_cache)) {
            $portal_cache['pushed_at'] = current_time('mysql');
            update_option('rpcare_portal_cache', $portal_cache, false);
            $updated['portal_cache'] = 'saved';
        }

        // Hub can update its own URL remotely (e.g. on migration)
        $new_hub_url = esc_url_raw(rtrim((string) $request->get_param('hub_url'), '/'));
        if ($new_hub_url) {
            $opts             = get_option('rpcare_options', []);
            $opts['hub_url']  = $new_hub_url;
            update_option('rpcare_options', $opts);
            delete_transient('rpcare_plan_cache');
            delete_transient('rpcare_hub_backoff');
            $updated['hub_url'] = $new_hub_url;
        }

        // Addons activos empujados por Hub (ej: ['ecommerce'])
        $addons           = $request->get_param('addons');
        $ecommerce_config = $request->get_param('ecommerce_config');

        if (!is_null($addons) && is_array($addons) && class_exists('RP_Care_Addon_Manager')) {
            $manager    = RP_Care_Addon_Manager::get();
            $old_addons = $manager->get_active();
            $new_addons = array_values(array_map('sanitize_key', $addons));

            $addon_configs = [];
            if ($ecommerce_config && is_array($ecommerce_config)) {
                $addon_configs['ecommerce'] = $ecommerce_config;
            }

            $manager->update($new_addons, $addon_configs);
            $updated['addons'] = $new_addons;

            // Re-evaluate addon schedules when the addon list changes
            $addons_changed = ($old_addons !== $new_addons);
            if ($addons_changed && class_exists('RP_Care_Scheduler')) {
                $current_plan = RP_Care_Plan::get_current();
                if ($current_plan) {
                    $scheduler = new RP_Care_Scheduler($current_plan);
                    $scheduler->clear_addon_schedules();
                    $scheduler->ensure();
                }
            }
        }

        if ($needs_reschedule && class_exists('RP_Care_Scheduler')) {
            $current_plan = RP_Care_Plan::get_current();
            if ($current_plan) {
                $scheduler = new RP_Care_Scheduler($current_plan);
                $scheduler->clear_all();
                $scheduler->ensure();
                $updated['schedule'] = 'rescheduled';
            }
        }

        RP_Care_Utils::log('config_update', 'info', 'Configuration updated via API', $updated);

        return [
            'success' => true,
            'updated' => $updated,
            'timestamp' => current_time('mysql')
        ];
    }
    
    private function saveB2Config($request) {
        $saved = [];
        foreach (['b2_key_id', 'b2_app_key', 'b2_bucket_id', 'b2_bucket_name', 'b2_prefix'] as $field) {
            $value = $request->get_param($field);
            if ($value !== null && $value !== '') {
                update_option('rpcare_' . $field, sanitize_text_field($value));
                $saved[] = $field;
            }
        }
        return $saved;
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
                'is_restorable' => false,
                'restore_note'  => 'Backuply queda como copia auxiliar; restore remoto se gestiona via B2.',
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

    public function backup_list($request) {
        $result = RP_Care_Task_Backup::list_b2_backups((int) $request->get_param('limit'));
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response($result);
    }

    public function backup_create($request) {
        $args = [
            'type' => $request->get_param('type') ?: 'full',
            'scopes' => $request->get_param('scopes') ?: null,
            'reason' => 'hub_request',
        ];
        $result = RP_Care_Task_Backup::create_b2_backup($args);
        if (empty($result['success'])) {
            return new WP_Error('backup_create_failed', $result['message'] ?? 'Backup B2 fallido', ['status' => 500, 'data' => $result]);
        }
        return rest_ensure_response($result);
    }

    public function backup_verify($request) {
        $result = RP_Care_Task_Backup::verify_b2_backup((string) $request->get_param('backup_id'));
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response($result);
    }

    public function backup_restore($request) {
        $scopes = $request->get_param('scopes') ?: ['database'];
        $result = RP_Care_Task_Backup::restore_b2_backup((string) $request->get_param('backup_id'), $scopes);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response($result);
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

    /**
     * Detect SSL certificate type for this site's domain.
     * Returns: 'cf', 'le', 'autossl', 'paid', 'https', 'none'
     */
    private function detect_ssl_type(): string {
        if (!is_ssl()) return 'none';

        $cached = get_transient('rpcare_ssl_type');
        if ($cached !== false) return $cached;

        $host = parse_url(get_site_url(), PHP_URL_HOST) ?: '';
        if (!$host) return 'https';

        $ctx = @stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false]]);
        $stream = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);

        if (!$stream) {
            set_transient('rpcare_ssl_type', 'https', HOUR_IN_SECONDS * 6);
            return 'https';
        }

        $params  = stream_context_get_params($stream);
        fclose($stream);

        $cert    = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            set_transient('rpcare_ssl_type', 'https', HOUR_IN_SECONDS * 6);
            return 'https';
        }

        $parsed = openssl_x509_parse($cert);
        $issuer = $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? '';

        if (stripos($issuer, 'Cloudflare') !== false)                                          $type = 'cf';
        elseif (stripos($issuer, "Let's Encrypt") !== false || stripos($issuer, 'ISRG') !== false) $type = 'le';
        elseif (stripos($issuer, 'cPanel') !== false || stripos($issuer, 'AutoSSL') !== false) $type = 'autossl';
        elseif (stripos($issuer, 'Sectigo') !== false || stripos($issuer, 'DigiCert') !== false
            || stripos($issuer, 'GeoTrust') !== false || stripos($issuer, 'Comodo') !== false) $type = 'paid';
        else                                                                                   $type = 'https';

        set_transient('rpcare_ssl_type', $type, HOUR_IN_SECONDS * 6);
        return $type;
    }

    /**
     * GET /sa/summary
     * Reads replanta-site-audit cached result directly (no HTTP, same WP instance).     * Returns scores and issue counts for Hub to store in site_meta.
     */
    public function get_sa_summary($request) {
        $sa_available = defined('RSA_VERSION') || class_exists('RSA_Audit_Engine');

        if (!$sa_available) {
            return rest_ensure_response([
                'sa_available' => false,
                'message'      => 'replanta-site-audit no está activo en este sitio',
            ]);
        }

        $result = get_transient('rsa_audit_result');

        if ($result === false) {
            // No cached result — trigger a fresh audit in background and return empty
            return rest_ensure_response([
                'sa_available'       => true,
                'global_score'       => 0,
                'cf_score'           => 0,
                'seo_score'          => 0,
                'perf_score'         => 0,
                'cwv_score'          => 0,
                'issue_count_critical' => 0,
                'issue_count_warning'  => 0,
                'last_audit_at'      => null,
                'message'            => 'Sin auditoría cacheada. Ejecuta una auditoría primero.',
            ]);
        }

        $modules = $result['modules'] ?? [];
        $checks  = $result['checks']  ?? [];

        $critical = count(array_filter($checks, fn($c) => ($c['status'] ?? '') === 'critical'));
        $warning  = count(array_filter($checks, fn($c) => ($c['status'] ?? '') === 'warning'));

        return rest_ensure_response([
            'sa_available'         => true,
            'global_score'         => (int) ($result['global_score'] ?? 0),
            'cf_score'             => (int) ($modules['cloudflare']['score']      ?? 0),
            'seo_score'            => (int) ($modules['seo']['score']             ?? 0),
            'perf_score'           => (int) ($modules['performance']['score']     ?? 0),
            'cwv_score'            => (int) ($modules['core_web_vitals']['score'] ?? 0),
            'issue_count_critical' => $critical,
            'issue_count_warning'  => $warning,
            'last_audit_at'        => $result['timestamp'] ?? null,
        ]);
    }

    /**
     * POST /sa/fix  { fix_id: "..." }
     * Delegates to RSA_Audit_Engine::execute_fix() (same WP instance, no HTTP).
     */
    public function run_sa_fix($request) {
        if (!class_exists('RSA_Audit_Engine')) {
            return new WP_Error('sa_unavailable', 'replanta-site-audit no está activo', ['status' => 503]);
        }

        $fix_id = sanitize_key($request->get_param('fix_id'));

        if (!array_key_exists($fix_id, RSA_Auto_Fixer::get_catalogue())) {
            return new WP_Error('invalid_fix_id', "Fix desconocido: {$fix_id}", ['status' => 400]);
        }

        $result = RSA_Audit_Engine::execute_fix($fix_id);
        $status = ($result['success'] ?? false) ? 200 : 422;

        return rest_ensure_response(array_merge($result, ['fix_id' => $fix_id]));
    }

    /**
     * GET /sa/issues
     * Returns the full list of warning/critical checks from the SA transient,
     * including fix_id for fixable items. Used by Hub to render the fix modal.
     */
    public function get_sa_issues($request) {
        if (!defined('RSA_VERSION') && !class_exists('RSA_Audit_Engine')) {
            return rest_ensure_response(['sa_available' => false, 'issues' => []]);
        }

        $result = get_transient('rsa_audit_result');
        if ($result === false) {
            return rest_ensure_response(['sa_available' => true, 'issues' => [], 'message' => 'Sin auditoría cacheada']);
        }

        $checks = $result['checks'] ?? [];
        $issues = [];
        foreach ($checks as $c) {
            if (!in_array($c['status'] ?? '', ['critical', 'warning'], true)) continue;
            $issues[] = [
                'id'          => $c['id']          ?? '',
                'label'       => $c['label']        ?? $c['id'] ?? '',
                'description' => $c['description']  ?? '',
                'status'      => $c['status'],
                'module'      => $c['module']       ?? '',
                'fixable'     => !empty($c['fixable']),
                'fix_id'      => $c['fix_id']       ?? null,
            ];
        }

        // Sort: critical first, then by label
        usort($issues, fn($a, $b) =>
            ($b['status'] === 'critical' ? 1 : 0) - ($a['status'] === 'critical' ? 1 : 0)
            ?: strcmp($a['label'], $b['label'])
        );

        return rest_ensure_response([
            'sa_available' => true,
            'issues'       => $issues,
            'global_score' => (int) ($result['global_score'] ?? 0),
            'last_audit_at'=> $result['timestamp'] ?? null,
        ]);
    }

    // ── Bootstrap: receive Hub token from Plugin Center ──────────────────

    /**
     * POST /wp-json/replanta-care/v1/set-token
     * Body (JSON): {license_key, token, hub_url}
     *
     * Authenticated by the license_key stored in rpcare_options.
     * Stores the Hub token so Care can call rphub_get_site_plan automatically.
     */
    public function set_hub_token(WP_REST_Request $request) {
        $license_key = sanitize_text_field($request->get_param('license_key') ?? '');
        $token       = sanitize_text_field($request->get_param('token')       ?? '');
        $hub_url     = esc_url_raw($request->get_param('hub_url')             ?? '');

        if (empty($license_key) || empty($token)) {
            return new WP_Error('missing_params', 'license_key and token are required', ['status' => 400]);
        }

        $options    = get_option('rpcare_options', []);
        $stored_key = trim($options['license_key'] ?? '');

        if ( empty( $stored_key ) ) {
            if ( ! empty( $options['site_token'] ) ) {
                // Site already has a token but license_key was cleared — reject to prevent
                // an unauthenticated caller from reclaiming an already-onboarded site.
                return new WP_Error( 'already_onboarded', 'Este sitio ya tiene un token activo. Configura la licencia desde el panel de administración.', [ 'status' => 403 ] );
            }
            // Truly fresh Care install: accept the license_key for PC bootstrap flow.
            $options['license_key'] = $license_key;
        } elseif ( ! hash_equals( $stored_key, $license_key ) ) {
            return new WP_Error( 'unauthorized', 'License key incorrecta', [ 'status' => 403 ] );
        }

        // Validate hub_url: must be https, non-localhost
        if ($hub_url) {
            $parsed = parse_url($hub_url);
            $scheme = strtolower($parsed['scheme'] ?? '');
            $host   = strtolower($parsed['host']   ?? '');
            if (!in_array($scheme, ['https', 'http'], true) || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                return new WP_Error('invalid_hub_url', 'hub_url inválida', ['status' => 422]);
            }
        }

        // Store Hub token + optionally update Hub URL
        $options['site_token'] = $token;
        if ($hub_url) {
            $options['hub_url'] = esc_url_raw($hub_url);
        }
        update_option('rpcare_options', $options);

        // Apply plan + addons through central entitlement method
        $plan   = sanitize_key($request->get_param('plan')   ?? '');
        $addons = $request->get_param('addons');
        $addons = is_array($addons) ? $addons : [];

        if (class_exists('RP_Care_Plan')) {
            RP_Care_Plan::apply_hub_entitlements('active', $plan, $addons);
        }

        delete_transient('rpcare_hub_backoff');
        delete_option('rpcare_hub_failures');

        if (class_exists('RP_Care_Utils')) {
            RP_Care_Utils::log('bootstrap', 'success', 'Hub token configurado via Plugin Center bootstrap');
        }

        return new WP_REST_Response([
            'status'     => 'ok',
            'site_url'   => get_site_url(),
            'plugin_ver' => defined('RPCARE_VERSION') ? RPCARE_VERSION : '?',
        ], 200);
    }

    /**
     * Validates the X-Hub-Token header against the stored site_token.
     * Returns true if valid, false otherwise.
     * @param bool $require_token  If false, allows requests when no token is configured yet.
     */
    private function validate_hub_token(WP_REST_Request $request, bool $require_token = true): bool {
        $options    = get_option('rpcare_options', []);
        $site_token = $options['site_token'] ?? '';

        if (empty($site_token)) {
            return !$require_token; // strict: reject; permissive: allow pre-bootstrap
        }

        $header = $request->get_header('x_hub_token') ?: '';
        if (empty($header)) return false;

        return hash_equals(hash('sha256', $site_token), $header);
    }

    /**
     * GET /wp-json/replanta-care/v1/ping
     * Hub health check.
     *
     * Three-state auth gate:
     *   1. No site_token configured (unpaired) → minimal pre-pairing response.
     *      Reveals only plugin_ver and schema_version; no operational data.
     *   2. Token configured, X-Hub-Token header absent or wrong → 403.
     *   3. Token configured, header matches → full health payload.
     */
    public function hub_ping(WP_REST_Request $request) {
        $options    = get_option( 'rpcare_options', [] );
        $site_token = $options['site_token'] ?? '';

        // State 1 — unpaired: no token has been provisioned by Plugin Center yet.
        if ( empty( $site_token ) ) {
            return new WP_REST_Response( [
                'status'         => 'unpaired',
                'pairing_required' => true,
                'plugin_ver'     => defined( 'RPCARE_VERSION' ) ? RPCARE_VERSION : '?',
                'schema_version' => 1,
            ], 200 );
        }

        // States 2 & 3 — paired: token present, validate the header.
        $header = $request->get_header( 'x_hub_token' ) ?: '';
        if ( empty( $header ) || ! hash_equals( hash( 'sha256', $site_token ), $header ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }

        $plan = class_exists('RP_Care_Plan') ? (RP_Care_Plan::get_current() ?: '') : '';

        // Provider-specific canonical evidence. Never show a stale B2 result
        // when the effective mode is managed_by_host (or vice versa).
        $backup = class_exists( 'RP_Care_Environment' )
            ? RP_Care_Environment::get_backup_report()
            : [
                'backup_mode' => 'disabled', 'backup_provider' => 'disabled',
                'backup_configured' => false, 'backup_last_at' => null,
                'backup_status' => null, 'backup_evidence_id' => null,
                'backup_verified' => false, 'backup_restore_available' => false,
                'backup_usable' => false,
            ];
        $backup_last_at = (string) ( $backup['backup_last_at'] ?? '' );

        // Plugin update inventory — cross-referenced with get_plugins() so orphaned
        // transient entries are excluded, matching WordPress admin's own count.
        $inv = class_exists( 'RP_Care_Update_Control' )
            ? RP_Care_Update_Control::read_inventory()
            : [ 'raw_total' => null, 'actionable_total' => null, 'orphaned_total' => null, 'checked_at' => null, 'inventory_stale' => true ];

        // SSL certificate expiry — non-blocking, 10s timeout, cached 12h.
        $ssl_expires_at = '';
        $ssl_days_left  = null;
        $ssl_cache_key  = 'rpcare_ssl_expiry';
        $ssl_cached     = get_transient($ssl_cache_key);
        if (false !== $ssl_cached) {
            $ssl_expires_at = $ssl_cached['expires_at'] ?? '';
            $ssl_days_left  = $ssl_cached['days_left']  ?? null;
        } elseif (function_exists('openssl_x509_parse')) {
            $host = wp_parse_url(get_site_url(), PHP_URL_HOST);
            if ($host && 'https' === wp_parse_url(get_site_url(), PHP_URL_SCHEME)) {
                $ctx  = stream_context_create(['ssl' => [
                    'capture_peer_cert'  => true,
                    'verify_peer'        => false,
                    'verify_peer_name'   => false,
                ]]);
                $conn = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
                if ($conn) {
                    $params = stream_context_get_params($conn);
                    $cert   = $params['options']['ssl']['peer_certificate'] ?? null;
                    if ($cert) {
                        $parsed   = openssl_x509_parse($cert);
                        $valid_to = $parsed['validTo_time_t'] ?? 0;
                        if ($valid_to > 0) {
                            $ssl_expires_at = gmdate('Y-m-d', $valid_to);
                            $ssl_days_left  = (int) ceil(($valid_to - time()) / DAY_IN_SECONDS);
                        }
                    }
                    fclose($conn);
                }
                set_transient($ssl_cache_key, ['expires_at' => $ssl_expires_at, 'days_left' => $ssl_days_left], 12 * HOUR_IN_SECONDS);
            }
        }

        // Backup stale: compare last backup age against plan threshold.
        $stale_cfg    = class_exists('RP_Care_Plan') ? (RP_Care_Plan::get_plan_config($plan)['backup_stale_threshold_h'] ?? 192) : 192;
        $backup_stale = false;
        if ($backup_last_at) {
            $age_h        = (time() - strtotime($backup_last_at)) / HOUR_IN_SECONDS;
            $backup_stale = $age_h > $stale_cfg;
        } elseif (get_option('rpcare_activated')) {
            $backup_stale = true; // activated but no backup ever recorded
        }

        // SSL expiry notification — fire when < 30 days; throttled 7d in notifier.
        if ($ssl_days_left !== null && $ssl_days_left > 0 && $ssl_days_left < 30) {
            do_action('rpcare_notify', 'ssl_expiry_soon', [
                'days_left'  => $ssl_days_left,
                'expires_at' => $ssl_expires_at,
            ]);
        }

        // Backup stale notification — fire when backup is overdue; throttled 24h.
        if ($backup_stale) {
            do_action('rpcare_notify', 'backup_stale', [
                'message'       => $backup_last_at
                    ? 'El último backup fue el ' . $backup_last_at . '.'
                    : 'No hay ningún backup registrado aún.',
                'plan'          => $plan,
                'backup_last_at' => $backup_last_at ?: 'nunca',
            ]);
        }

        // TTFB — HEAD to home URL, cached 1 h. Skip inside CLI/cron to avoid loops.
        $ttfb_ms = null;
        if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
            $ttfb_cached = get_transient( 'rpcare_ttfb' );
            if ( false !== $ttfb_cached ) {
                $ttfb_ms = (int) $ttfb_cached;
            } else {
                $t0      = microtime( true );
                $ttfb_r  = wp_remote_head( home_url( '/' ), [
                    'timeout'     => 8,
                    'sslverify'   => true,
                    'redirection' => 0,
                ] );
                if ( ! is_wp_error( $ttfb_r ) ) {
                    $ttfb_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
                    set_transient( 'rpcare_ttfb', $ttfb_ms, HOUR_IN_SECONDS );
                }
            }
        }

        // Which notification channels are configured (no URLs — metadata only).
        $notif_cfg      = get_option('rpcare_notification_config', []);
        $notif_channels = array_keys(array_filter($notif_cfg));

        return new WP_REST_Response([
            'status'               => 'ok',
            'plugin_ver'           => defined('RPCARE_VERSION') ? RPCARE_VERSION : '',
            'plan'                 => $plan,
            'wp_version'           => get_bloginfo('version'),
            'site_url'             => get_site_url(),
            'backup_last_at'       => $backup_last_at,
            'backup_mode'          => $backup['backup_mode'],
            'backup_provider'      => $backup['backup_provider'],
            'backup_configured'    => $backup['backup_configured'],
            'backup_status'        => $backup['backup_status'],
            'backup_evidence_id'   => $backup['backup_evidence_id'],
            'backup_verified'      => $backup['backup_verified'],
            'backup_restore_available' => $backup['backup_restore_available'],
			'backup_usable'        => $backup['backup_usable'],
            'backup_stale'             => $backup_stale,
            'updates_pending_total'    => $inv['actionable_total'],   // installed-only; excludes orphaned
            'updates_checked_at'       => $inv['checked_at'],
            'updates_inventory_stale'  => $inv['inventory_stale'],
            'updates_pending'          => $inv['actionable_total'],  // alias: backwards compat with PC ≤ 1.2.5
            'updates_orphaned'         => $inv['orphaned_total'] ?? 0,
            'ssl_expires_at'           => $ssl_expires_at,
            'ssl_days_left'        => $ssl_days_left,
            'ttfb_ms'              => $ttfb_ms,
            'db_cleanup_last_at'   => get_option( 'rpcare_last_db_cleanup', [] )['at'] ?? '',
            'as_failed_24h'        => class_exists( 'RP_Care_Plan' ) ? RP_Care_Plan::count_as_failures_24h() : 0,
            'notification_channels'=> $notif_channels,
        ], 200);
    }

    /**
     * POST /wp-json/replanta-care/v1/config
     * Hub pushes configuration to this site (B2 credentials, plan, update window…).
     * Permissive token validation — allows pre-bootstrap calls.
     */
    public function hub_config(WP_REST_Request $request) {
        if (!$this->validate_hub_token($request, false)) {
            return new WP_REST_Response(['error' => 'Unauthorized'], 403);
        }

        $updated = [];

        // B2 credentials
        $b2_saved = $this->saveB2Config($request);
        if (!empty($b2_saved)) {
            $updated['b2_config'] = $b2_saved;
        }

        // Plan override — reschedule AS jobs when plan changes (backup + update frequency differ)
        $plan = $request->get_param('plan');
        if ($plan && class_exists('RP_Care_Plan') && RP_Care_Plan::is_valid_plan($plan)) {
            $old = RP_Care_Plan::get_current();
            RP_Care_Plan::set_current($plan);
            $updated['plan'] = ['old' => $old, 'new' => $plan];

            if ($old !== $plan && class_exists('RP_Care_Scheduler')) {
                $plan_hooks = ['rpcare_task_backup', 'rpcare_task_updates'];
                foreach ($plan_hooks as $hook) {
                    if (function_exists('as_unschedule_all_actions')) {
                        as_unschedule_all_actions($hook, [], 'replanta-care');
                    }
                    wp_clear_scheduled_hook($hook);
                }
                $scheduler = new RP_Care_Scheduler($plan);
                $scheduler->ensure();
            }
        }

        // Update window
        $window_day   = $request->get_param('update_window_day');
        $window_start = $request->get_param('update_window_start');
        $window_end   = $request->get_param('update_window_end');
        if ($window_day !== null)   { update_option('rpcare_update_window_day', (int) $window_day);        $updated['update_window_day']   = (int) $window_day; }
        if ($window_start !== null) { update_option('rpcare_update_window_start_hour', (int) $window_start); $updated['update_window_start'] = (int) $window_start; }
        if ($window_end !== null)   { update_option('rpcare_update_window_end_hour', (int) $window_end);   $updated['update_window_end']   = (int) $window_end; }

        // Notification channels — stored as a single option array
        $nfields  = ['notification_email', 'notification_webhook_url', 'notification_slack_webhook'];
        $nchanged = false;
        $ncfg     = get_option('rpcare_notification_config', []);
        foreach ($nfields as $nf) {
            $val = $request->get_param($nf);
            if ($val !== null) {
                $ncfg[$nf] = ($nf === 'notification_email')
                    ? sanitize_email((string) $val)
                    : esc_url_raw((string) $val);
                $nchanged = true;
            }
        }
        if ($nchanged) {
            update_option('rpcare_notification_config', array_filter($ncfg));
            $updated['notification_config'] = array_keys(array_filter($ncfg));
        }

        // Smart Updates executor settings.
        $update_executor  = $request->get_param( 'update_executor' );
        $native_updates   = $request->get_param( 'native_auto_updates' );
        $pipeline_enabled = $request->get_param( 'pipeline_enabled' );
        if ( null !== $update_executor && in_array( $update_executor, [ 'care_pipeline', 'external', 'disabled' ], true ) ) {
            $opts                    = get_option( 'rpcare_options', [] );
            $opts['update_executor'] = $update_executor;
            update_option( 'rpcare_options', $opts );
            $updated['update_executor'] = $update_executor;
        }
        if ( null !== $native_updates && in_array( $native_updates, [ 'disabled', 'enabled' ], true ) ) {
            $opts                        = get_option( 'rpcare_options', [] );
            $opts['native_auto_updates'] = $native_updates;
            update_option( 'rpcare_options', $opts );
            $updated['native_auto_updates'] = $native_updates;
        }
        if ( null !== $pipeline_enabled && class_exists( 'RP_Care_Pipeline_Client' ) ) {
            $enabled = filter_var( $pipeline_enabled, FILTER_VALIDATE_BOOLEAN );
            update_option( RP_Care_Pipeline_Client::OPT_ENABLED, $enabled );
			RP_Care_Pipeline_Client::ensure_poll_schedule( $enabled );
            $updated['pipeline_enabled'] = $enabled;
        }

        // Addons — enables/disables addons pushed from PC Config tab (e.g. ecommerce)
        $addons = $request->get_param('addons');
        if ( ! is_null( $addons ) && is_array( $addons ) && class_exists( 'RP_Care_Addon_Manager' ) ) {
            $manager    = RP_Care_Addon_Manager::get();
            $old_addons = $manager->get_active();
            $new_addons = array_values( array_map( 'sanitize_key', $addons ) );
            $manager->update( $new_addons, [] );
            $updated['addons'] = $new_addons;

            if ( $old_addons !== $new_addons && class_exists( 'RP_Care_Scheduler' ) ) {
                $current_plan = RP_Care_Plan::get_current();
                if ( $current_plan ) {
                    $scheduler = new RP_Care_Scheduler( $current_plan );
                    $scheduler->clear_addon_schedules();
                    $scheduler->ensure();
                }
            }
        }

        // Staging role — set production/staging relationship so get_status_report() reflects it.
        $staging_role = $request->get_param( 'staging_role' );
        if ( $staging_role !== null && in_array( $staging_role, [ 'production', 'staging', 'unset' ], true ) ) {
            $opts                 = get_option( 'rpcare_options', [] );
            $opts['staging_role'] = $staging_role;
            update_option( 'rpcare_options', $opts );
            $updated['staging_role'] = $staging_role;
        }

        // Backup mode — controls how/where Care stores backups for this site.
        $backup_mode        = $request->get_param( 'backup_mode' );
        $valid_backup_modes = [ 'auto', 'local_dev', 'b2', 'cloudflare_r2', 's3', 'updraftplus', 'managed_by_host', 'disabled' ];
        if ( $backup_mode !== null && in_array( $backup_mode, $valid_backup_modes, true ) ) {
            $opts                 = get_option( 'rpcare_options', [] );
            $opts['backup_mode']  = $backup_mode;
            update_option( 'rpcare_options', $opts );
            $updated['backup_mode'] = $backup_mode;
        }

        // Read-only operator policy projection from Plugin Center. Care stores
        // it so the local administrator sees the same effective contract.
        $policy_fields = [
            'smart_updates_mode'      => [ 'observe_only', 'staging_required', 'disabled' ],
            'approval_policy'         => [ 'always', 'auto_approve_minor', 'disabled' ],
            'staging_method'          => [ 'auto', 'wptoolkit', 'wpstaging', 'paired', 'none' ],
        ];
        foreach ( $policy_fields as $field => $allowed ) {
            $value = $request->get_param( $field );
            if ( $value !== null && in_array( $value, $allowed, true ) ) {
                $opts[ $field ] = $value;
                $updated[ $field ] = $value;
            }
        }
        $maximum_batch_size = $request->get_param( 'maximum_batch_size' );
        if ( $maximum_batch_size !== null ) {
            $opts['maximum_batch_size'] = max( 1, min( 10, (int) $maximum_batch_size ) );
            $updated['maximum_batch_size'] = $opts['maximum_batch_size'];
        }
        $maintenance_window_auto = $request->get_param( 'maintenance_window_auto' );
        if ( $maintenance_window_auto !== null ) {
            $opts['maintenance_window_auto'] = (bool) $maintenance_window_auto;
            $updated['maintenance_window_auto'] = $opts['maintenance_window_auto'];
        }
        if ( array_intersect( array_keys( $updated ), array_merge( array_keys( $policy_fields ), [ 'maximum_batch_size', 'maintenance_window_auto' ] ) ) ) {
            update_option( 'rpcare_options', $opts );
        }

        return new WP_REST_Response([
            'status'  => 'ok',
            'updated' => $updated,
        ], 200);
    }

    /**
     * POST /wp-json/replanta-care/v1/update
     * Triggered by Plugin Center to self-update Care to the latest Hub release.
     * Strict token validation — requires X-Hub-Token.
     *
     * Strategy:
     * 1. Ask the Hub update endpoint for the latest version + download_url directly.
     * 2. If already on that version → return already_latest.
     * 3. Otherwise install from the zip URL (bypasses PUC transient cache entirely).
     */
    public function hub_update(WP_REST_Request $request) {
        if (!$this->validate_hub_token($request, true)) {
            return new WP_REST_Response(['error' => 'Unauthorized'], 403);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';

        $plugin_file = 'replanta-care/replanta-care.php';
        $from_ver    = defined('RPCARE_VERSION') ? RPCARE_VERSION : '';

        // 1. Fetch update info directly from Hub — bypasses PUC's 12h cache.
        $update_url  = defined('RPCARE_UPDATE_URL') ? RPCARE_UPDATE_URL : 'https://replanta.net/wp-json/replanta-hub/v1/updates/care';
        $info_res    = wp_remote_get($update_url, ['timeout' => 15, 'sslverify' => true]);
        $zip_url     = '';
        $new_version = '';

        if (!is_wp_error($info_res) && wp_remote_retrieve_response_code($info_res) === 200) {
            $info        = json_decode(wp_remote_retrieve_body($info_res), true);
            $new_version = $info['version']      ?? '';
            $zip_url     = $info['download_url'] ?? '';
        }

        // 2. Hub fetch es la única ruta soportada — sin fallback a wp_update_plugins() (riesgo OOM).
        if (!$zip_url) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'No se pudo obtener la URL de descarga desde Hub. Verifica conectividad con replanta.net.',
            ], 503);
        }

        // 3. Already on latest?
        if (!$zip_url || !$new_version || version_compare($from_ver, $new_version, '>=')) {
            return new WP_REST_Response([
                'status'     => 'already_latest',
                'plugin_ver' => $from_ver,
                'latest'     => $new_version ?: $from_ver,
                'message'    => "Ya en la versión más reciente ({$from_ver})",
            ], 200);
        }

        // 3b. Pre-update backup: database + config. Fallo = warning, no bloquea la actualización.
        $backup_warning = null;
        if ( class_exists( 'RP_Care_Task_Backup' ) && RP_Care_Task_Backup::is_b2_configured_public() ) {
            $pre_update = RP_Care_Task_Backup::create_b2_backup( [
                'reason' => 'pre_update',
                'scopes' => [ 'database', 'config' ],
            ] );
            if ( empty( $pre_update['success'] ) ) {
                $backup_warning = 'Backup pre-update falló: ' . ( $pre_update['message'] ?? 'Error desconocido' );
            }
        }

        // 4. Download ZIP to temp file.
        $tmp = download_url($zip_url, 60);
        if (is_wp_error($tmp)) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Download failed: ' . $tmp->get_error_message()], 500);
        }

        // 5. Validate it's a real ZIP (magic bytes PK\x03\x04) — avoids installing an auth-error HTML page.
        $magic = file_get_contents($tmp, false, null, 0, 4);
        if ($magic !== "PK\x03\x04") {
            @unlink($tmp);
            return new WP_REST_Response(['status' => 'error', 'message' => 'Downloaded file is not a valid ZIP'], 500);
        }

        // 6. ZipArchive + atomic rename — bypasses Plugin_Upgrader hooks (security plugins can block them).
        $plugin_dir = WP_PLUGIN_DIR . '/replanta-care';
        $work_dir   = WP_CONTENT_DIR . '/upgrade/rpcare-' . time();
        $backup_dir = $plugin_dir . '-bkp-' . time();
        $installed  = false;

        @mkdir($work_dir, 0755, true);

        $zip = new ZipArchive();
        if ($zip->open($tmp) === true) {
            $zip->extractTo($work_dir);
            $zip->close();
            @unlink($tmp);

            $extracted = $work_dir . '/replanta-care';
            if (!is_dir($extracted)) {
                $dirs = glob($work_dir . '/*', GLOB_ONLYDIR);
                $extracted = !empty($dirs) ? $dirs[0] : '';
            }

            if ($extracted && is_dir($extracted)) {
                if (@rename($plugin_dir, $backup_dir)) {
                    if (@rename($extracted, $plugin_dir)) {
                        $installed = true;
                        $this->rmdir_recursive($backup_dir);
                    } else {
                        @rename($backup_dir, $plugin_dir); // Rollback
                    }
                }
            }

            $this->rmdir_recursive($work_dir);
        } else {
            @unlink($tmp);
        }

        // 7. Fall back to Plugin_Upgrader if ZipArchive path failed.
        if (!$installed) {
            $skin     = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            $result   = $upgrader->install($zip_url);

            if (is_wp_error($result)) {
                do_action('rpcare_notify', 'update_failed', ['message' => $result->get_error_message()]);
                return new WP_REST_Response(['status' => 'error', 'message' => $result->get_error_message()], 500);
            }
            if ($result === false) {
                $err = $skin->get_errors();
                $msg = (is_wp_error($err) && $err->get_error_message()) ? $err->get_error_message() : 'Update failed (ZipArchive + Plugin_Upgrader both failed)';
                do_action('rpcare_notify', 'update_failed', ['message' => $msg]);
                return new WP_REST_Response(['status' => 'error', 'message' => $msg], 500);
            }
        }

        // 8. Post-install: reset PUC state and opcache.
        delete_site_option('external_updates-replanta-care');
        delete_site_transient('update_plugins');
        delete_transient('rpcare_ttfb'); // force fresh TTFB after update
        if (function_exists('opcache_reset')) opcache_reset();

        if (class_exists('RP_Care_Utils')) {
            RP_Care_Utils::log('self_update', 'success', "v{$from_ver} → v{$new_version} via Hub (" . ($installed ? 'ZipArchive' : 'Plugin_Upgrader') . ')');
        }

        // 9. Post-update health check — new PHP process loads the updated plugin.
        $post_health = 'unknown';
        $post_code   = null;
        $post_ms     = null;
        $t0          = microtime( true );
        $hr          = wp_remote_get( home_url( '/' ), [
            'timeout'     => 10,
            'sslverify'   => true,
            'redirection' => 0,
        ] );
        if ( ! is_wp_error( $hr ) ) {
            $post_code   = (int) wp_remote_retrieve_response_code( $hr );
            $post_body   = wp_remote_retrieve_body( $hr );
            $has_fatal   = str_contains( $post_body, 'Ha habido un error' ) || str_contains( $post_body, 'critical error' );
            $post_health = ( 200 === $post_code && ! $has_fatal ) ? 'ok' : 'error';
            $post_ms     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
            if ( 'error' === $post_health ) {
                do_action( 'rpcare_notify', 'update_failed', [
                    'message' => "Post-update health check failed: HTTP {$post_code}" . ( $has_fatal ? ' (fatal detected)' : '' ),
                ] );
            }
        }

        do_action('rpcare_notify', 'update_applied', [
            'count'   => 1,
            'plugins' => ["replanta-care: v{$from_ver} → v{$new_version}"],
        ]);

        return new WP_REST_Response([
            'status'         => 'updated',
            'from_ver'       => $from_ver,
            'to_ver'         => $new_version,
            'message'        => "Actualizado de v{$from_ver} a v{$new_version}",
            'backup_warning' => $backup_warning,
            'health_check'   => [
                'status'      => $post_health,
                'http_code'   => $post_code,
                'response_ms' => $post_ms,
            ],
        ], 200);
    }

    /**
     * POST /wp-json/replanta-care/v1/notify-test
     * Sends a test notification on all configured channels. No throttle bypass — uses a 60s TTL
     * so spam-clicking "Probar" from PC won't flood channels.
     */
    public function hub_notify_test(WP_REST_Request $request) {
        if (!$this->validate_hub_token($request, true)) {
            return new WP_REST_Response(['error' => 'Unauthorized'], 403);
        }

        $cfg = get_option('rpcare_notification_config', []);
        if (empty(array_filter($cfg))) {
            return new WP_REST_Response([
                'status'  => 'no_channels',
                'message' => 'No hay canales de notificación configurados.',
            ], 200);
        }

        // Clear the per-test throttle so the test always fires (own 60s TTL in notifier).
        delete_transient('rpcare_nthrottle_test');

        do_action('rpcare_notify', 'test', [
            'message' => 'Notificación de prueba enviada desde Replanta Care.',
        ]);

        $channels = array_keys(array_filter($cfg));
        return new WP_REST_Response([
            'status'   => 'sent',
            'channels' => $channels,
            'message'  => 'Enviado a: ' . implode(', ', $channels),
        ], 200);
    }

    private function rmdir_recursive(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->rmdir_recursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * POST /wp-json/replanta-care/v1/staging-approve
     * PC Hub approves staging evaluation → clears gate cooldown and schedules production update.
     */
    public function hub_staging_approve( WP_REST_Request $request ) {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }

        // Clear the 6h cooldown so the production update can proceed
        delete_transient( 'rpcare_staging_gate_cooldown' );

        // Schedule update with staging_validated=true so check_staging_gate() passes
        $args = [ 'staging_validated' => true ];
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'rpcare_task_updates', [ $args ], 'replanta-care' );
            $method = 'as_async';
        } else {
            do_action( 'rpcare_task_updates', $args );
            $method = 'direct';
        }

        RP_Care_Utils::log( 'staging', 'success', 'Staging aprobado desde Hub — update a producción programado', [
            'method' => $method,
        ] );

        return new WP_REST_Response( [
            'status'  => 'approved',
            'message' => 'Staging aprobado — actualización de producción programada',
            'method'  => $method,
        ], 200 );
    }

    /**
     * GET /wp-json/replanta-care/v1/staging-baseline
     * Returns the production DOM snapshot captured at the last staging-clone trigger.
     * PC uses this as the authoritative "before" snapshot instead of a live fetch.
     */
    public function hub_staging_baseline( WP_REST_Request $request ) {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }

        if ( ! class_exists( 'RP_Care_Task_StagingEval' ) ) {
            return new WP_REST_Response( [ 'error' => 'StagingEval module not available' ], 503 );
        }

        $snapshot = get_option( RP_Care_Task_StagingEval::OPT_BASELINE, null );
        if ( ! $snapshot ) {
            return new WP_REST_Response( [ 'error' => 'No baseline captured yet. Trigger a staging clone first.' ], 404 );
        }

        // Strip the raw Elementor DB data (large, not needed for HTTP fingerprint diff)
        unset( $snapshot['elementor_db'] );

        return new WP_REST_Response( [ 'snapshot' => $snapshot ], 200 );
    }

    /**
     * POST /wp-json/replanta-care/v1/backup/list
     * Hub requests the site's backup list. Returns same payload as backup_list().
     */
    public function hub_backup_list(WP_REST_Request $request) {
        if (!$this->validate_hub_token($request, true)) {
            return new WP_REST_Response(['error' => 'Unauthorized'], 403);
        }
        $limit   = max(1, min(50, (int) ($request->get_param('limit') ?: 10)));
        $backups = [];

        // B2 backups
        if (RP_Care_Task_Backup::is_b2_configured_public()) {
            $b2 = RP_Care_Task_Backup::list_b2_backups($limit);
            if (!is_wp_error($b2) && !empty($b2['backups'])) {
                $backups = array_merge($backups, $b2['backups']);
            }
        }

        // UpdraftPlus backups
        if (RP_Care_Task_Backup::is_updraftplus_active()) {
            $udp = RP_Care_Task_Backup::list_updraftplus_backups($limit);
            if (!empty($udp['backups'])) {
                $backups = array_merge($backups, $udp['backups']);
            }
        }

        if (empty($backups)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Ningún proveedor de backup disponible (B2 ni UpdraftPlus)',
                'backups' => [],
            ], 200);
        }

        // Sort unified list newest-first
        usort($backups, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return new WP_REST_Response([
            'success' => true,
            'backups' => array_slice($backups, 0, $limit),
            'total'   => count($backups),
        ], 200);
    }

    /**
     * POST /wp-json/replanta-care/v1/backup/restore
     * Hub-driven granular restore — works even when WP admin is broken.
     * Body: { backup_id: string, scopes: string[] }
     * Always creates a pre-restore safety backup first.
     */
    public function hub_backup_restore(WP_REST_Request $request) {
        if (!$this->validate_hub_token($request, true)) {
            return new WP_REST_Response(['error' => 'Unauthorized'], 403);
        }

        $backup_id = sanitize_key((string) $request->get_param('backup_id'));
        if (!$backup_id) {
            return new WP_REST_Response(['error' => 'backup_id requerido'], 400);
        }

        $scopes = $request->get_param('scopes');
        if (!is_array($scopes) || empty($scopes)) {
            $scopes = ['database'];
        }
        $scopes = array_map('sanitize_key', $scopes);

        // Route by provider prefix
        if (strncmp($backup_id, 'udp_', 4) === 0) {
            $result = RP_Care_Task_Backup::restore_updraftplus_backup($backup_id, $scopes);
        } else {
            $result = RP_Care_Task_Backup::restore_b2_backup($backup_id, $scopes);
        }

        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 500);
        }

        return new WP_REST_Response($result, $result['success'] ? 200 : 500);
    }

    /**
     * POST /wp-json/replanta-care/v1/run
     * Plugin Center dispatches an Action Scheduler task to run immediately.
     * Accepted tasks: backup, health, updates, report.
     */
    public function hub_run(WP_REST_Request $request) {
        if (!$this->validate_hub_token($request, true)) {
            return new WP_REST_Response(['error' => 'Unauthorized'], 403);
        }

        $allowed = [
            'backup'     => 'rpcare_task_backup',
            'health'     => 'rpcare_task_health',
            'updates'    => 'rpcare_task_updates',
            'report'     => 'rpcare_task_report',
            'db_cleanup' => 'rpcare_task_db_cleanup',
            'retention'  => 'rpcare_task_retention',
        ];

        $task = sanitize_key((string) $request->get_param('task'));
        if (!isset($allowed[$task])) {
            return new WP_REST_Response([
                'error'   => 'task_invalid',
                'message' => 'Tarea no reconocida. Válidas: ' . implode(', ', array_keys($allowed)),
            ], 400);
        }

        $hook = $allowed[$task];

        if (function_exists('as_enqueue_async_action')) {
            $action_id = as_enqueue_async_action($hook, [], 'replanta-care');
            $method    = 'as_async';
            // Store job meta so /job/status can find the result via log lookup
            update_option( "rpcare_job_{$action_id}", [
                'task'       => $task,
                'dispatched' => current_time( 'mysql' ),
            ], false );
        } else {
            do_action($hook);
            $action_id = null;
            $method    = 'direct';
        }

        if (class_exists('RP_Care_Utils')) {
            RP_Care_Utils::log($task, 'dispatched', "Hub run via REST (method: {$method})");
        }

        return new WP_REST_Response([
            'status'    => 'dispatched',
            'task'      => $task,
            'hook'      => $hook,
            'action_id' => $action_id,
            'method'    => $method,
        ], 200);
    }

    public function hub_job_status( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->validate_hub_token( $request, true ) )
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );

        $action_id = (int) $request->get_param( 'action_id' );
        if ( ! $action_id )
            return new WP_REST_Response( [ 'error' => 'action_id required' ], 400 );

        $meta      = get_option( "rpcare_job_{$action_id}", [] );
        $task      = $meta['task']       ?? '';
        $since_str = $meta['dispatched'] ?? '1970-01-01 00:00:00';
        $status    = 'queued';
        $logs      = [];
        $errors    = [];

        // Check AS store for running/complete state
        if ( class_exists( 'ActionScheduler_Store' ) ) {
            try {
                $as_status = ActionScheduler_Store::instance()->get_status( $action_id );
                if ( $as_status === 'in-progress' ) {
                    $status = 'running';
                } elseif ( $as_status === 'complete' || $as_status === 'failed' ) {
                    $status = ( $as_status === 'complete' ) ? 'complete' : 'failed';
                    delete_option( "rpcare_job_{$action_id}" );
                }
            } catch ( \Exception $e ) { /* ignore */ }
        }

        // Detect completion via log entries written by the task.
        // Only 'success' or 'error' rows are terminal; skip 'dispatched'/'info'/'warning'.
        if ( $task && class_exists( 'RP_Care_Utils' ) ) {
            $all_logs = RP_Care_Utils::get_logs( 20, $task );
            $terminal = null;
            foreach ( $all_logs as $row ) {
                if ( $row->created_at < $since_str ) continue;
                $logs[] = $row;
                if ( in_array( $row->status, [ 'error', 'warning' ], true ) )
                    $errors[] = $row->message;
                if ( $terminal === null && in_array( $row->status, [ 'success', 'error' ], true ) )
                    $terminal = $row;
            }
            if ( $terminal !== null ) {
                $status = ( $terminal->status === 'error' ) ? 'failed' : 'complete';
                delete_option( "rpcare_job_{$action_id}" );
            }
        }

        return new WP_REST_Response( [
            'action_id' => $action_id,
            'task'      => $task,
            'status'    => $status,
            'logs'      => $logs,
            'errors'    => $errors,
        ], 200 );
    }

    /**
     * GET /wp-json/replanta-care/v1/reports
     * Returns the list of generated reports (metadata only, no HTML).
     */
    public function hub_get_reports( WP_REST_Request $request ) {
        if ( ! $this->validate_hub_token( $request ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }

        $history = (array) get_option( 'rpcare_reports_history', [] );
        $list    = array_map( function ( $entry ) {
            return [
                'id'           => $entry['id']           ?? '',
                'generated_at' => $entry['generated_at'] ?? '',
                'plan'         => $entry['plan']          ?? '',
            ];
        }, $history );

        return new WP_REST_Response( [ 'reports' => $list, 'count' => count( $list ) ], 200 );
    }

    /**
     * GET /wp-json/replanta-care/v1/reports/{id}
     * Returns the full HTML of a generated report, embedded in a JSON envelope.
     */
    public function hub_get_report_html( WP_REST_Request $request ) {
        if ( ! $this->validate_hub_token( $request ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }

        $id      = sanitize_key( $request->get_param( 'id' ) );
        $history = (array) get_option( 'rpcare_reports_history', [] );

        $entry = null;
        foreach ( $history as $h ) {
            if ( ( $h['id'] ?? '' ) === $id ) {
                $entry = $h;
                break;
            }
        }

        if ( ! $entry ) {
            return new WP_REST_Response( [ 'error' => 'Informe no encontrado.' ], 404 );
        }

        $html_file = $entry['html_file'] ?? '';
        if ( ! $html_file || ! file_exists( $html_file ) ) {
            return new WP_REST_Response( [ 'error' => 'Archivo HTML no disponible.' ], 404 );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $html = file_get_contents( $html_file );
        if ( $html === false ) {
            return new WP_REST_Response( [ 'error' => 'Error al leer el archivo.' ], 500 );
        }

        return new WP_REST_Response( [
            'id'           => $id,
            'generated_at' => $entry['generated_at'] ?? '',
            'plan'         => $entry['plan']          ?? '',
            'html'         => $html,
        ], 200 );
    }

    // ── Woo Autonomous Maintenance — Capa 2 endpoints ─────────────────────────
    // All five are read-only, Hub-authenticated, and return versioned envelopes.
    // PC calls these via PC_Hub_Care::remote_action() with X-Hub-Token auth.

    /**
     * POST /wp-json/replanta-care/v1/woo/telemetry
     *
     * Returns the Care telemetry ring buffer (warning+ events, capped at 20).
     * Safe and read-only: never modifies state.
     *
     * Response schema v1:
     *   schema_version  int
     *   generated_at    ISO-8601
     *   event_count     int   — events at warning+ in buffer
     *   buffer_size     int   — total events currently in ring buffer
     *   max_buffer      int   — ring buffer capacity
     *   events          array — up to 20 most-recent warning+ events (already redacted)
     */
    public function hub_woo_telemetry( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }
        if ( ! class_exists( 'RP_Care_Telemetry' ) ) {
            return new WP_REST_Response( [ 'error' => 'Telemetry module not available' ], 503 );
        }

        $buf    = RP_Care_Telemetry::get_buffer();
        $events = RP_Care_Telemetry::get_events_above( 'warning' );

        // Return at most 20 events, most-recent first, to bound response size.
        $events = array_slice( array_reverse( $events ), 0, 20 );

        return new WP_REST_Response( [
            'schema_version' => 1,
            'generated_at'   => gmdate( 'c' ),
            'event_count'    => count( $events ),
            'buffer_size'    => count( $buf ),
            'max_buffer'     => RP_Care_Telemetry::MAX_BUFFER_EVENTS,
            'events'         => $events,
        ], 200 );
    }

    /**
     * POST /wp-json/replanta-care/v1/woo/routes
     *
     * Returns the last stored route monitor results.
     * Read-only: never probes routes on demand (avoids self-loop and latency).
     * Callers should trigger a route check separately if data is stale.
     *
     * Response schema v1:
     *   schema_version  int
     *   generated_at    ISO-8601
     *   status          'ok' | 'no_data' | 'unhealthy'
     *   healthy         bool   (absent when status=no_data)
     *   checked_at      ISO-8601 (absent when status=no_data)
     *   routes          object — per-route fingerprints (no body content)
     *   issues          array  — routes with non-200 status
     *   is_stale        bool   — true when a fresh check is overdue
     */
    public function hub_woo_routes( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }
        if ( ! class_exists( 'RP_Care_Route_Monitor' ) ) {
            return new WP_REST_Response( [ 'error' => 'Route monitor module not available' ], 503 );
        }

        $results = RP_Care_Route_Monitor::get_last_results();
        if ( $results === null ) {
            return new WP_REST_Response( [
                'schema_version' => 1,
                'generated_at'   => gmdate( 'c' ),
                'status'         => 'no_data',
                'is_stale'       => true,
                'message'        => 'No route check has run yet.',
            ], 200 );
        }

        return new WP_REST_Response( [
            'schema_version' => 1,
            'generated_at'   => gmdate( 'c' ),
            'status'         => ( $results['healthy'] ?? true ) ? 'ok' : 'unhealthy',
            'healthy'        => $results['healthy']  ?? true,
            'checked_at'     => $results['checked_at'] ?? '',
            'routes'         => $results['routes']   ?? [],
            'issues'         => $results['issues']   ?? [],
            'is_stale'       => RP_Care_Route_Monitor::is_check_due(),
        ], 200 );
    }

    /**
     * POST /wp-json/replanta-care/v1/woo/snapshot
     *
     * Returns the golden snapshot diff against current state.
     * Read-only: calls diff_against_golden() which captures current state
     * but does NOT promote or persist anything.
     *
     * Response schema v1:
     *   schema_version      int
     *   generated_at        ISO-8601
     *   has_golden          bool
     *   has_drift           bool   (absent when has_golden=false)
     *   diffs               object — field → {golden, current} (empty when no drift)
     *   snapshot_age_sec    int
     *   repair_steps        string[]
     *   golden_captured_at  ISO-8601
     *   golden_snapshot_id  string
     */
    public function hub_woo_snapshot( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }
        if ( ! class_exists( 'RP_Care_Golden_Snapshot' ) ) {
            return new WP_REST_Response( [ 'error' => 'Golden snapshot module not available' ], 503 );
        }

        $golden = RP_Care_Golden_Snapshot::get_golden();
        if ( $golden === null ) {
            return new WP_REST_Response( [
                'schema_version' => 1,
                'generated_at'   => gmdate( 'c' ),
                'has_golden'     => false,
                'status'         => 'no_snapshot',
                'message'        => 'No golden snapshot exists. Promote a snapshot first.',
            ], 200 );
        }

        $diff = RP_Care_Golden_Snapshot::diff_against_golden();

        return new WP_REST_Response( [
            'schema_version'     => 1,
            'generated_at'       => gmdate( 'c' ),
            'has_golden'         => true,
            'has_drift'          => $diff['has_drift']          ?? false,
            'diffs'              => $diff['diffs']               ?? [],
            'snapshot_age_sec'   => $diff['snapshot_age_sec']   ?? 0,
            'repair_steps'       => $diff['repair_steps']        ?? [],
            'golden_captured_at' => $golden['captured_at']      ?? '',
            'golden_snapshot_id' => $golden['snapshot_id']      ?? '',
        ], 200 );
    }

    /**
     * POST /wp-json/replanta-care/v1/woo/hygiene-dry
     *
     * Runs a hygiene estimate in strict dry-run mode: counts rows eligible for
     * cleanup but makes ZERO database mutations. The dry_run flag is hardcoded
     * to true and cannot be overridden by the caller.
     *
     * Response schema v1:
     *   schema_version      int
     *   generated_at        ISO-8601
     *   dry_run             bool   (always true — explicit guarantee)
     *   operations          object — per-operation row counts
     *   total_rows_deleted  int    — total eligible rows (not deleted)
     *   wall_seconds        float
     *   errors              string[]
     */
    public function hub_woo_hygiene_dry( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }
        if ( ! class_exists( 'RP_Care_Woo_Hygiene' ) ) {
            return new WP_REST_Response( [ 'error' => 'Hygiene module not available' ], 503 );
        }

        // STRICT: dry_run=true is hardcoded — the caller cannot override this.
        $result = RP_Care_Woo_Hygiene::run( [
            'dry_run'     => true,
            'max_rows'    => 500,
            'max_seconds' => 20, // tighter budget for a synchronous API response
        ] );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'error' => $result->get_error_message(),
                'code'  => $result->get_error_code(),
            ], 503 );
        }

        return new WP_REST_Response( [
            'schema_version'     => 1,
            'generated_at'       => gmdate( 'c' ),
            'dry_run'            => true,
            'operations'         => $result['operations']         ?? [],
            'total_rows_deleted' => $result['total_rows_deleted'] ?? 0,
            'wall_seconds'       => $result['wall_seconds']       ?? 0.0,
            'errors'             => $result['errors']             ?? [],
        ], 200 );
    }

    /**
     * POST /wp-json/replanta-care/v1/woo/anomalies
     *
     * Returns all active integration anomalies.
     * Read-only: never resolves anomalies or modifies state.
     *
     * Response schema v1:
     *   schema_version  int
     *   generated_at    ISO-8601
     *   anomaly_count   int
     *   anomalies       array — see RP_Care_Integration_Detector::get_active_anomalies()
     *                           Each entry: family, count, threshold, escalate_at,
     *                           escalated, detected_at, message_hash, context_keys.
     *                           No message content is stored or returned.
     */
    public function hub_woo_anomalies( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }
        if ( ! class_exists( 'RP_Care_Integration_Detector' ) ) {
            return new WP_REST_Response( [ 'error' => 'Integration detector module not available' ], 503 );
        }

        $anomalies = RP_Care_Integration_Detector::get_active_anomalies();

        return new WP_REST_Response( [
            'schema_version' => 1,
            'generated_at'   => gmdate( 'c' ),
            'anomaly_count'  => count( $anomalies ),
            'anomalies'      => $anomalies,
        ], 200 );
    }

    /**
     * POST /wp-json/replanta-care/v1/smart-updates/status
     *
     * Returns the effective executor/pipeline configuration of this Care instance
     * for Plugin Center drift detection.  Requires X-Hub-Token auth (strict).
     *
     * Response fields:
     *   schema_version         int    always 1
     *   generated_at           string ISO-8601
     *   plugin_version         string Care version (RPCARE_VERSION)
     *   update_executor        string care_pipeline | external | disabled
     *   update_executor_source string explicit | wptoolkit_fallback | default
     *   native_auto_updates    string disabled | enabled
     *   pipeline_enabled       bool   whether PC↔Care pipeline is active
     *   staging_role           string production | staging | unset
     *   wp_toolkit_detected    bool   informational — does NOT imply executor
     *   direct_updates_blocked bool   true when native WP auto-updates are suppressed
     *
     * SECURITY: never includes raw token, credentials, binary paths, or secrets.
     *
     * SECURITY DEBT (SD-01): X-Hub-Token is a static SHA-256 bearer
     * (hash('sha256', raw_site_token)).  Constant-time comparison via hash_equals()
     * prevents timing attacks, and this endpoint is READ-ONLY, making replay attacks
     * low-impact.  However, the static bearer model MUST NOT be used for any
     * mutating endpoint.  Future write operations (policy activation, update triggers)
     * require HMAC-per-request (HMAC-SHA256 over method+path+timestamp+nonce),
     * a server-checked timestamp window (+/-60 s), and a short-lived anti-replay log.
     * Tracking issue: SD-01 — replace static bearer with HMAC+timestamp+nonce for
     * mutating Hub endpoints before removing the pilot_activation_allowed guard.
     */
    public function hub_smart_updates_status( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }
        if ( ! class_exists( 'RP_Care_Environment' ) ) {
            return new WP_REST_Response( [ 'error' => 'RP_Care_Environment not available' ], 503 );
        }
        return new WP_REST_Response( RP_Care_Environment::get_status_report(), 200 );
    }

	/** Read-only channel proof: contacts PC but never leases or executes commands. */
	public function hub_pipeline_heartbeat( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->validate_hub_token( $request, true ) ) {
			return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
		}
		if ( ! class_exists( 'RP_Care_Pipeline_Client' ) || ! RP_Care_Pipeline_Client::is_pipeline_enabled() ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'pipeline_disabled' ], 409 );
		}
		$result = RP_Care_Pipeline_Client::send_heartbeat();
		return new WP_REST_Response( $result, ! empty( $result['success'] ) ? 200 : 502 );
	}

	/** Authenticated operational trigger: lease and execute the next PC command now. */
	public function hub_pipeline_poll_now( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->validate_hub_token( $request, true ) ) {
			return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
		}
		if ( is_callable( self::$pipeline_poll_runner ) ) {
			$result = call_user_func( self::$pipeline_poll_runner );
			return new WP_REST_Response( is_array( $result ) ? $result : [ 'ok' => false, 'error' => 'invalid_poll_result' ], 200 );
		}
		if ( ! class_exists( 'RP_Care_Pipeline_Client' ) || ! RP_Care_Pipeline_Client::is_pipeline_enabled() ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'pipeline_disabled' ], 409 );
		}
		$result = RP_Care_Pipeline_Client::poll_and_execute();
		$batch_id = sanitize_text_field( (string) $request->get_param( 'batch_id' ) );
		if ( class_exists( 'RP_Care_Pipeline_Outbox' ) ) {
			RP_Care_Pipeline_Outbox::deliver_pending();
			if ( '' !== $batch_id ) {
				$result['outbox'] = RP_Care_Pipeline_Outbox::safe_status_for_batch( $batch_id );
			}
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Recover one accepted staging update whose Action Scheduler worker did not run.
	 * The durable journal must bind command, batch, environment and command type.
	 */
	public function hub_pipeline_run_local_command( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->validate_hub_token( $request, true ) ) {
			return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
		}
		$command_id = sanitize_text_field( (string) $request->get_param( 'command_id' ) );
		$batch_id   = sanitize_text_field( (string) $request->get_param( 'batch_id' ) );
		if ( '' === $command_id || '' === $batch_id ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'missing_identifiers' ], 422 );
		}
		if ( is_callable( self::$pipeline_local_runner ) ) {
			return new WP_REST_Response( call_user_func( self::$pipeline_local_runner, $batch_id, $command_id ), 200 );
		}
		if ( ! class_exists( 'RP_Care_Pipeline_Client' ) || ! RP_Care_Pipeline_Client::is_pipeline_enabled() || ! RP_Care_Pipeline_Client::is_staging() ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'staging_pipeline_unavailable' ], 409 );
		}
		if ( ! class_exists( 'RP_Care_Pipeline_Command_Journal' ) || ! class_exists( 'RP_Care_Task_Updates' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'pipeline_components_unavailable' ], 503 );
		}
		$context = RP_Care_Pipeline_Command_Journal::get_context( $command_id );
		if (
			! $context
			|| ! hash_equals( (string) ( $context['batch_id'] ?? '' ), $batch_id )
			|| 'staging' !== ( $context['environment'] ?? '' )
			|| 'apply_update_batch' !== ( $context['command_type'] ?? '' )
			|| ! in_array( (string) ( $context['state'] ?? '' ), [ 'accepted', 'running', 'failed_retryable' ], true )
		) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'journal_precondition_failed' ], 409 );
		}
		$result = RP_Care_Task_Updates::apply_staging_batch( $batch_id, $command_id );
		// The operation result is authenticated and already structured/redacted.
		// Return 200 even on a task failure so PC can display the exact fail-closed
		// reason instead of collapsing it to an opaque HTTP 409.
		return new WP_REST_Response( [
			'ok'     => ! empty( $result['success'] ),
			'error'  => ! empty( $result['success'] ) ? null : sanitize_text_field( (string) ( $result['error'] ?? 'local_command_failed' ) ),
			'result' => $result,
		], 200 );
	}

	/**
	 * One-time local HTTP probe used by the pipeline test runner.
	 * The URL token is random, expires after 60 seconds and only its SHA-256 is
	 * stored. The endpoint returns no site data and consumes the token on use.
	 */
	public function pipeline_loopback_probe( WP_REST_Request $request ): WP_REST_Response {
		$probe = sanitize_text_field( (string) $request->get_param( 'probe' ) );
		if ( '' === $probe ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'missing_probe' ], 403 );
		}
		$key    = 'rpcare_loopback_' . hash( 'sha256', $probe );
		$stored = get_transient( $key );
		delete_transient( $key );
		if ( '1' !== $stored ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_or_consumed_probe' ], 403 );
		}
		return new WP_REST_Response( [ 'ok' => true, 'schema_version' => 1 ], 200 );
	}

    /**
     * POST /wp-json/replanta-care/v1/updates/inventory
     *
     * Returns the canonical update inventory for Plugin Center 4-way count reconciliation.
     * Cross-references the raw update_plugins transient with get_plugins() — the same
     * logic WordPress uses for the admin update count — to separate actionable entries
     * (plugin file still on disk) from orphaned entries (stale transient, slug mismatch,
     * plugin uninstalled).
     *
     * Authentication: X-Hub-Token required (strict, sha256(site_token)).
     * Response fields:
     *   schema_version  int    always 2
     *   generated_at    string ISO-8601 UTC
     *   checked_at      string|null ISO-8601 of last WP update check; null when transient absent
     *   stale           bool   true when transient absent, malformed, or older than 26 h
     *   raw_total       int|null all entries in response[]; null when transient absent
     *   installed_total int|null entries where plugin file is on disk (= actionable_total currently)
     *   actionable_total int|null entries that can be updated; = installed_total
     *   orphaned_total  int|null raw_total − actionable_total
     *   plugins         array  actionable entries (installed, update available)
     *   orphaned_plugins array  orphaned entries (transient entry but file absent)
     *   installed_plugins_total int all plugin main files currently installed
     *   installed_plugins array  canonical installed identity list, including plugins
     *                            whose vendor updater does not populate update_plugins
     *   themes_total    int|null count(themes); null when theme transient absent
     *   themes          array  per-theme entries
     *   inventory_hash  string sha256 of installed_plugins+updates+themes (stable, no timestamps)
     *
     * Per-plugin fields (both plugins[] and orphaned_plugins[]):
     *   plugin_file       string  transient key (path relative to wp-content/plugins)
     *   slug              string  from transient entry or dirname fallback
     *   name              string  Name header (empty for orphaned — file not present)
     *   installed         bool    true if file found by get_plugins()
     *   orphaned          bool    true if NOT found by get_plugins()
     *   installed_version string  Version header (empty for orphaned)
     *   available_version string  new_version from transient
     *   package_available bool    whether a download package is in the transient
     *
     * translations[], no_update[] — never counted or included.
     * SECURITY: never exposes package URLs, license keys, raw tokens, or credentials.
     * By default this is read-only. With authenticated `refresh=true`, it asks
     * WordPress to refresh only provider update metadata before returning the
     * inventory; it never installs or updates a plugin/theme.
     */
    public function hub_updates_inventory( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }

        if ( filter_var( $request->get_param( 'refresh' ), FILTER_VALIDATE_BOOLEAN ) ) {
            if ( null !== self::$inventory_refresher ) {
                call_user_func( self::$inventory_refresher );
            } else {
                if ( ! function_exists( 'wp_update_plugins' ) || ! function_exists( 'wp_update_themes' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/update.php';
                }
                wp_update_plugins();
                wp_update_themes();
            }
        }

        $generated_at = gmdate( 'c' );

        // Read raw transients, bypassing Care's policy filter.
        $prev = RP_Care_Update_Control::$bypass_for_task;
        try {
            RP_Care_Update_Control::$bypass_for_task = true;
            $reader         = RP_Care_Update_Control::$transient_reader ?? 'get_site_transient';
            $update_plugins = $reader( 'update_plugins' );
            $update_themes  = $reader( 'update_themes' );
        } finally {
            RP_Care_Update_Control::$bypass_for_task = $prev;
        }

        // Build the installed-plugins map (plugin_file → headers) via seam or get_plugins().
        $plugins_fn = RP_Care_Update_Control::$get_plugins_reader;
        if ( $plugins_fn !== null ) {
            $installed_map = $plugins_fn();
        } else {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $installed_map = get_plugins();
        }
        $installed_keys = array_flip( array_keys( $installed_map ) );

        // Canonical installed identity list. Some premium vendors render their
        // own wp-admin notice without adding an entry to update_plugins. PC
        // needs this list to bind an operator-uploaded ZIP to the exact plugin
        // main file and installed version without fuzzy name/slug matching.
        $installed_plugins = [];
        $state_plugins     = [];
        $active_plugins    = array_fill_keys( (array) get_option( 'active_plugins', [] ), true );
        $network_active    = function_exists( 'get_site_option' )
            ? (array) get_site_option( 'active_sitewide_plugins', [] )
            : [];
        foreach ( $installed_map as $plugin_file => $headers ) {
            if ( ! is_string( $plugin_file ) || '' === $plugin_file || ! is_array( $headers ) ) continue;
            $slug = dirname( $plugin_file );
            if ( '.' === $slug || '' === $slug ) $slug = $plugin_file;
            $is_active = isset( $active_plugins[ $plugin_file ] ) || isset( $network_active[ $plugin_file ] );
            $installed_plugins[ $plugin_file ] = [
                'plugin_file'       => $plugin_file,
                'slug'              => $slug,
                'name'              => (string) ( $headers['Name'] ?? '' ),
                'installed_version' => (string) ( $headers['Version'] ?? '' ),
            ];
            $state_plugins[ $plugin_file ] = [
                'version' => (string) ( $headers['Version'] ?? '' ),
                'active'  => $is_active,
            ];
        }
        ksort( $installed_plugins );
        $installed_plugins = array_values( $installed_plugins );

        // ── Plugins ──────────────────────────────────────────────────────────

        $plugins_checked = null;
        $plugins_stale   = true;
        $raw_total       = null;
        $installed_total = null;
        $actionable      = null;
        $orphaned_count  = null;
        $plugins         = [];     // installed entries
        $orphaned        = [];     // orphaned entries

        if ( is_object( $update_plugins ) ) {
            $ts = isset( $update_plugins->last_checked ) ? (int) $update_plugins->last_checked : 0;
            if ( $ts > 0 ) {
                $plugins_checked = gmdate( 'c', $ts );
                $plugins_stale   = ( time() - $ts ) > 26 * HOUR_IN_SECONDS;
            }

            if ( is_array( $update_plugins->response ?? null ) ) {
                $raw_total      = 0;
                $installed_total = 0;
                $actionable     = 0;
                $orphaned_count = 0;

                foreach ( $update_plugins->response as $plugin_file => $entry ) {
                    if ( ! is_string( $plugin_file ) || '' === $plugin_file ) continue;
                    if ( ! is_object( $entry ) && ! is_array( $entry ) ) continue;
                    $entry = (object) $entry;
                    $raw_total++;

                    $slug = (string) ( $entry->slug ?? dirname( $plugin_file ) );
                    if ( '.' === $slug || '' === $slug ) $slug = $plugin_file;

                    $is_installed = isset( $installed_keys[ $plugin_file ] );
                    $item = [
                        'plugin_file'       => $plugin_file,
                        'slug'              => $slug,
                        'name'              => '',
                        'installed'         => $is_installed,
                        'orphaned'          => ! $is_installed,
                        'installed_version' => '',
                        'available_version' => (string) ( $entry->new_version ?? '' ),
                        'package_available' => ! empty( $entry->package ),
                    ];

                    if ( $is_installed ) {
                        $headers          = $installed_map[ $plugin_file ] ?? [];
                        $item['name']              = (string) ( $headers['Name']    ?? '' );
                        $item['installed_version'] = (string) ( $headers['Version'] ?? '' );
                        $plugins[ $plugin_file ]   = $item;
                        $installed_total++;
                        $actionable++;
                    } else {
                        $orphaned[ $plugin_file ] = $item;
                        $orphaned_count++;
                    }
                }

                ksort( $plugins );
                ksort( $orphaned );
                $plugins  = array_values( $plugins );
                $orphaned = array_values( $orphaned );
            } else {
                // A timestamp without a response[] collection is not a usable
                // inventory, even when last_checked is recent.
                $plugins_stale = true;
            }
        }

        // ── Themes ───────────────────────────────────────────────────────────

        $themes_total = null;
        $themes       = [];

        if ( is_object( $update_themes ) && is_array( $update_themes->response ?? null ) ) {
            $themes_total = 0;
            foreach ( $update_themes->response as $theme_file => $data ) {
                if ( ! is_string( $theme_file ) || '' === $theme_file ) continue;
                if ( ! is_array( $data ) ) continue;

                $themes[ $theme_file ] = [
                    'theme_file'        => $theme_file,
                    'slug'              => $theme_file,
                    'installed_version' => (string) ( $data['Version'] ?? '' ),
                    'available_version' => (string) ( $data['new_version'] ?? '' ),
                    'package_available' => ! empty( $data['package'] ),
                ];
                $themes_total++;
            }
            ksort( $themes );
            $themes = array_values( $themes );
        }

        // Installed-state drift contract. Unlike inventory_hash below, this
        // covers only durable application state and never depends on whether an
        // update transient happens to be fresh, expired or temporarily absent.
        $state_themes = [];
        $active_theme = function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '';
        $all_themes   = function_exists( 'wp_get_themes' ) ? (array) wp_get_themes() : [];
        foreach ( $all_themes as $theme_slug => $theme ) {
            if ( ! is_string( $theme_slug ) || '' === $theme_slug || ! is_object( $theme ) || ! method_exists( $theme, 'get' ) ) continue;
            $state_themes[ $theme_slug ] = [
                'version' => (string) $theme->get( 'Version' ),
                'active'  => hash_equals( $active_theme, $theme_slug ),
            ];
        }
        ksort( $state_plugins, SORT_STRING );
        ksort( $state_themes, SORT_STRING );
        $installed_state_hash = class_exists( 'RP_Care_Inventory_Snapshot' )
            ? RP_Care_Inventory_Snapshot::installed_state_hash( [
                'wp_version'  => (string) get_bloginfo( 'version' ),
                'php_version' => PHP_VERSION,
                'plugins'     => $state_plugins,
                'themes'      => $state_themes,
            ] )
            : '';

        // ── Inventory hash ────────────────────────────────────────────────────
        // Covers installed identities + updates + themes; excludes timestamps.
        $inventory_hash = hash(
            'sha256',
            json_encode(
                [
                    'installed_plugins' => $installed_plugins,
                    'plugins'           => $plugins,
                    'orphaned_plugins'  => $orphaned,
                    'themes'            => $themes,
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: ''
        );

        return new WP_REST_Response(
            [
                'schema_version'   => 2,
                'generated_at'     => $generated_at,
                'checked_at'       => $plugins_checked,
                'stale'            => $plugins_stale,
                'raw_total'        => $raw_total,
                'installed_total'  => $installed_total,
                'actionable_total' => $actionable,
                'orphaned_total'   => $orphaned_count,
                'plugins'          => $plugins,
                'orphaned_plugins' => $orphaned,
                'installed_plugins_total' => count( $installed_plugins ),
                'installed_plugins'       => $installed_plugins,
                'themes_total'     => $themes_total,
                'themes'           => $themes,
                'inventory_hash'   => $inventory_hash,
                'installed_state_hash' => $installed_state_hash,
            ],
            200
        );
    }

    /**
     * POST /wp-json/replanta-care/v1/pipeline/isolation-report
     *
     * Read-only. PC calls this after the operator confirms that the paired clone
     * was refreshed. A production instance always rejects the check.
     */
    public function hub_pipeline_isolation_report( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }

        $environment = (string) get_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, '' );
        if ( 'staging' !== $environment ) {
            return new WP_REST_Response(
                [ 'error' => 'not_staging', 'environment' => $environment ?: 'unset' ],
                409
            );
        }

        if ( null === self::$isolation_report_reader && ! class_exists( 'RP_Care_Isolation_Checker' ) ) {
            return new WP_REST_Response( [ 'error' => 'isolation_checker_unavailable' ], 503 );
        }

        $report = null !== self::$isolation_report_reader
            ? call_user_func( self::$isolation_report_reader )
            : RP_Care_Isolation_Checker::run();
        return new WP_REST_Response( [
            'schema_version' => 1,
            'generated_at'   => gmdate( 'c' ),
            'environment'    => 'staging',
            'passed'         => (bool) ( $report['passed'] ?? false ),
            'checks'         => array_values( (array) ( $report['checks'] ?? [] ) ),
        ], 200 );
    }

    /**
     * POST /replanta-care/v1/hub/b2/connection-test
     *
     * Read-only B2 diagnostic. Runs sequentially: DNS → TLS → b2_authorize_account
     * → bucket access (b2_get_upload_url dry-run check using b2_list_buckets) →
     * key capabilities. Never writes to B2. Never logs credentials.
     *
     * Response fields:
     *   schema_version  int    always 1
     *   generated_at    string ISO-8601 UTC
     *   configured      bool   whether B2 credentials are set in Care options
     *   phases          array  each phase: {name, ok, error_code, detail, duration_ms}
     *   overall_ok      bool   all phases passed
     *   error_code      string|null structured slug of first failure (null when ok)
     *   credential_fingerprint  string  sha256(key_id) — safe to log, never reveals key
     *
     * Structured error codes: authentication_failed, key_unauthorized, bucket_not_found,
     *   bucket_not_allowed, storage_cap_exceeded, provider_rate_limited,
     *   provider_unreachable, tls_failure, invalid_provider_response, unknown_provider_error.
     *
     * SECURITY: never logs key_id, app_key, auth_token, or signed download URLs.
     *           credential_fingerprint is sha256(key_id) — safe to surface.
     *
     * Authentication: X-Hub-Token required (strict, sha256(site_token)).
     */
    public function hub_b2_connection_test( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->validate_hub_token( $request, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
        }

        $generated_at = gmdate( 'c' );
        $phases       = [];
        $overall_ok   = true;
        $first_error  = null;

        if ( ! class_exists( 'RP_Care_Task_Backup' ) ) {
            return new WP_REST_Response( [
                'schema_version' => 1,
                'generated_at'   => $generated_at,
                'configured'     => false,
                'phases'         => [],
                'overall_ok'     => false,
                'error_code'     => 'unknown_provider_error',
                'credential_fingerprint' => null,
            ], 200 );
        }

        $configured = (bool) ( method_exists( 'RP_Care_Task_Backup', 'is_b2_configured' )
            ? RP_Care_Task_Backup::is_b2_configured()
            : get_option( 'rpcare_b2_key_id' ) );

        $cfg = get_option( 'rpcare_b2_key_id' )
            ? [
                'key_id'     => get_option( 'rpcare_b2_key_id', '' ),
                'app_key'    => get_option( 'rpcare_b2_app_key', '' ),
                'bucket_id'  => get_option( 'rpcare_b2_bucket_id', '' ),
                'bucket_name'=> get_option( 'rpcare_b2_bucket_name', '' ),
            ]
            : [];

        $key_id     = $cfg['key_id'] ?? '';
        $credential_fingerprint = $key_id ? hash( 'sha256', $key_id ) : null;

        if ( ! $configured || empty( $key_id ) ) {
            return new WP_REST_Response( [
                'schema_version'         => 1,
                'generated_at'           => $generated_at,
                'configured'             => false,
                'phases'                 => [],
                'overall_ok'             => false,
                'error_code'             => 'authentication_failed',
                'credential_fingerprint' => $credential_fingerprint,
            ], 200 );
        }

        // ── Phase 1: DNS resolution ──────────────────────────────────────────
        $t0 = microtime( true );
        $dns_ok = false;
        $dns_detail = '';
        $resolved = gethostbynamel( 'api.backblazeb2.com' );
        $dns_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
        if ( ! empty( $resolved ) ) {
            $dns_ok = true;
        } else {
            $dns_detail = 'DNS lookup for api.backblazeb2.com returned no addresses';
            $overall_ok = false;
            $first_error = $first_error ?? 'provider_unreachable';
        }
        $phases[] = [
            'name'        => 'dns',
            'ok'          => $dns_ok,
            'error_code'  => $dns_ok ? null : 'provider_unreachable',
            'detail'      => $dns_ok ? 'Resolved ' . count( $resolved ) . ' address(es)' : $dns_detail,
            'duration_ms' => $dns_ms,
        ];

        // ── Phase 2: TLS connection ──────────────────────────────────────────
        if ( $dns_ok ) {
            $t0 = microtime( true );
            $tls_ok = false;
            $tls_detail = '';
            $ctx = stream_context_create( [ 'ssl' => [ 'capture_peer_cert' => true, 'verify_peer' => true ] ] );
            $sock = @stream_socket_client( 'ssl://api.backblazeb2.com:443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx );
            $tls_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
            if ( $sock ) {
                $tls_ok = true;
                fclose( $sock );
            } else {
                $tls_detail = 'TLS connect failed: ' . $errstr . ' (errno ' . $errno . ')';
                $overall_ok = false;
                $first_error = $first_error ?? 'tls_failure';
            }
            $phases[] = [
                'name'        => 'tls',
                'ok'          => $tls_ok,
                'error_code'  => $tls_ok ? null : 'tls_failure',
                'detail'      => $tls_ok ? 'TLS handshake succeeded' : $tls_detail,
                'duration_ms' => $tls_ms,
            ];
        }

        // ── Phase 3: b2_authorize_account ────────────────────────────────────
        $auth_result = null;
        if ( $overall_ok ) {
            $t0 = microtime( true );
            $auth_ok = false;
            $auth_detail = '';
            $auth_error_code = null;

            $creds    = base64_encode( $key_id . ':' . ( $cfg['app_key'] ?? '' ) );
            $response = wp_remote_get( 'https://api.backblazeb2.com/b2api/v3/b2_authorize_account', [
                'timeout' => 20,
                'headers' => [ 'Authorization' => 'Basic ' . $creds ],
            ] );
            $auth_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

            if ( is_wp_error( $response ) ) {
                $auth_detail = 'WP HTTP error (credentials redacted)';
                $auth_error_code = 'provider_unreachable';
                $overall_ok = false;
                $first_error = $first_error ?? $auth_error_code;
            } else {
                $http = (int) wp_remote_retrieve_response_code( $response );
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                $b2_code = $body['code'] ?? '';
                if ( $http === 200 && ! empty( $body['authorizationToken'] ) ) {
                    $auth_ok = true;
                    $auth_detail = 'Authorized. API URL: ' . ( $body['apiInfo']['storageApi']['apiUrl'] ?? $body['apiUrl'] ?? 'n/a' );
                    $auth_result = [
                        'api_url'    => rtrim( $body['apiInfo']['storageApi']['apiUrl'] ?? $body['apiUrl'] ?? '', '/' ),
                        'auth_token' => $body['authorizationToken'],
                        'capabilities' => $body['allowed']['capabilities'] ?? [],
                        'bucket_id_allowed' => $body['allowed']['bucketId'] ?? null,
                    ];
                } else {
                    // Never include credentials, tokens or auth details in error message.
                    $auth_error_code = RP_Care_Task_Backup::b2_classify_auth_error( $http, $b2_code );
                    $auth_detail = 'HTTP ' . $http . ', B2 code: ' . $b2_code . ' (credentials redacted)';
                    $overall_ok = false;
                    $first_error = $first_error ?? $auth_error_code;
                }
            }
            $phases[] = [
                'name'        => 'authorize',
                'ok'          => $auth_ok,
                'error_code'  => $auth_ok ? null : $auth_error_code,
                'detail'      => $auth_detail,
                'duration_ms' => $auth_ms,
            ];
        }

        // ── Phase 4: Bucket access ────────────────────────────────────────────
        if ( $overall_ok && $auth_result !== null ) {
            $t0 = microtime( true );
            $bucket_ok = false;
            $bucket_detail = '';
            $bucket_error_code = null;
            $bucket_id = $cfg['bucket_id'] ?? '';

            $bl_response = wp_remote_post( $auth_result['api_url'] . '/b2api/v3/b2_list_buckets', [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => $auth_result['auth_token'],
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode( [ 'accountId' => '', 'bucketId' => $bucket_id ] ),
            ] );
            $bucket_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

            if ( is_wp_error( $bl_response ) ) {
                $bucket_error_code = 'provider_unreachable';
                $bucket_detail = 'WP HTTP error on b2_list_buckets';
                $overall_ok = false;
                $first_error = $first_error ?? $bucket_error_code;
            } else {
                $http = (int) wp_remote_retrieve_response_code( $bl_response );
                $body = json_decode( wp_remote_retrieve_body( $bl_response ), true );
                $b2_code = $body['code'] ?? '';
                $buckets = $body['buckets'] ?? [];
                $found = array_filter( $buckets, fn( $b ) => ( $b['bucketId'] ?? '' ) === $bucket_id );
                if ( $http === 200 && ! empty( $found ) ) {
                    $bucket_ok = true;
                    $b = reset( $found );
                    $bucket_detail = 'Bucket visible: ' . ( $b['bucketName'] ?? $bucket_id );
                } elseif ( $http === 200 && empty( $found ) ) {
                    // Key may not have permission to list but may still have upload rights.
                    // Treat as bucket_not_found only if b2_list_buckets returned nothing and no error.
                    $bucket_error_code = 'bucket_not_found';
                    $bucket_detail = 'Bucket ID not visible in b2_list_buckets (key may be restricted)';
                    // Not necessarily fatal — restricted key may not enumerate buckets.
                    // Mark ok=false but don't propagate as hard failure yet.
                } elseif ( $http === 403 ) {
                    $bucket_error_code = 'bucket_not_allowed';
                    $bucket_detail = 'HTTP 403 on b2_list_buckets (B2: ' . $b2_code . ')';
                    $overall_ok = false;
                    $first_error = $first_error ?? $bucket_error_code;
                } else {
                    $bucket_error_code = 'unknown_provider_error';
                    $bucket_detail = 'HTTP ' . $http . ' on b2_list_buckets (B2: ' . $b2_code . ')';
                    $overall_ok = false;
                    $first_error = $first_error ?? $bucket_error_code;
                }
            }
            $phases[] = [
                'name'        => 'bucket_access',
                'ok'          => $bucket_ok,
                'error_code'  => $bucket_ok ? null : $bucket_error_code,
                'detail'      => $bucket_detail,
                'duration_ms' => $bucket_ms,
            ];
        }

        // ── Phase 5: Key capabilities ─────────────────────────────────────────
        if ( $auth_result !== null ) {
            $required = [ 'readFiles', 'writeFiles', 'deleteFiles', 'listBuckets', 'listFiles', 'readBuckets' ];
            $actual   = $auth_result['capabilities'];
            $missing  = array_values( array_diff( $required, $actual ) );
            $caps_ok  = empty( $missing );
            $phases[] = [
                'name'       => 'key_capabilities',
                'ok'         => $caps_ok,
                'error_code' => $caps_ok ? null : 'key_unauthorized',
                'detail'     => $caps_ok
                    ? 'All required capabilities present'
                    : 'Missing capabilities: ' . implode( ', ', $missing ),
                'duration_ms' => 0,
            ];
            if ( ! $caps_ok ) {
                $overall_ok  = false;
                $first_error = $first_error ?? 'key_unauthorized';
            }
        }

        return new WP_REST_Response( [
            'schema_version'         => 1,
            'generated_at'           => $generated_at,
            'configured'             => $configured,
            'phases'                 => $phases,
            'overall_ok'             => $overall_ok,
            'error_code'             => $first_error,
            'credential_fingerprint' => $credential_fingerprint,
        ], 200 );
    }
}
