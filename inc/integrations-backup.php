<?php
/**
 * Backup Integration Task
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Backup {

    /** Test seam: callable () => string, overrides RP_Care_Scheduler::get_environment_type(). */
    public static $environment_reader = null;

    /** Test seam: string, overrides get_backup_base_dir() return value. */
    public static $backup_base_dir_override = null;

    public static function run($args = []) {
        $env_fn      = self::$environment_reader;
        $environment = $env_fn ? $env_fn() : RP_Care_Scheduler::get_environment_type();

        $mode = class_exists( 'RP_Care_Environment' )
            ? RP_Care_Environment::get_effective_backup_mode( $environment )
            : 'managed_by_host'; // safe fallback if class not loaded yet

        switch ( $mode ) {

            case 'local_dev':
                $results = self::handle_local_backup( $args );
                break;

            case 'b2':
                if ( ! self::is_b2_configured() ) {
                    $results = [
                        'success' => false,
                        'method'  => 'b2',
                        'message' => 'Modo B2 activo pero credenciales no configuradas en este site.',
                    ];
                    break;
                }
                $results = self::create_b2_backup( $args );
                break;

            case 'cloudflare_r2':
            case 's3':
                // These providers need their own endpoint/signing contract. Never
                // relabel a B2 upload as R2/S3: that creates false restore evidence.
                $results = [
                    'success'    => false,
                    'method'     => $mode,
                    'error_code' => 'provider_not_implemented',
                    'message'    => "El proveedor {$mode} aún no está implementado; no se ha creado ningún archivo local.",
                ];
                break;

            case 'updraftplus':
                $results = self::is_updraftplus_active()
                    ? self::trigger_updraftplus_backup( $args )
                    : [ 'success' => false, 'method' => 'updraftplus', 'message' => 'UpdraftPlus no está activo en este site.' ];
                break;

            case 'disabled':
                $results = [
                    'success'        => true,
                    'method'         => 'disabled',
                    'message'        => 'Backup desactivado para este site (solo monitorización).',
                    'managed_by_hub' => true,
                    'skipped'        => true,
                ];
                break;

            case 'managed_by_host':
            default:
                $results = self::handle_whm_backup( $args );
                break;
        }

        // Safety assertion: local files must NEVER be created outside local_dev mode.
        if ( 'local_dev' !== $mode && ! empty( $results['backup_dir'] ) ) {
            RP_Care_Utils::log( 'backup', 'critical',
                "SEGURIDAD: backup_dir en modo '{$mode}'. Contacta con soporte.",
                [ 'mode' => $mode, 'env' => $environment, 'backup_dir' => $results['backup_dir'] ]
            );
        }

        if ( ! empty( $results['success'] ) ) {
            update_option( 'rpcare_last_backup', current_time( 'mysql' ) );
        }
        RP_Care_Utils::log( 'backup', ! empty( $results['success'] ) ? 'success' : 'error', $results['message'] ?? '', $results );
        return $results;
    }
    
    private static function is_backuply_active() {
        return is_plugin_active('backuply/backuply.php');
    }

    private static function get_backuply_backup_info() {
        $backup_list = get_option('backuply_backup_list', []);

        if (empty($backup_list) || !is_array($backup_list)) {
            return false;
        }

        $latest_time   = 0;
        $latest_backup = null;

        foreach ($backup_list as $backup) {
            // Backuply stores timestamps in 'time' key (Unix) or 'date' key (string)
            $ts = isset($backup['time']) ? (int) $backup['time'] : strtotime($backup['date'] ?? '');
            if ($ts > $latest_time) {
                $latest_time   = $ts;
                $latest_backup = $backup;
            }
        }

        if (!$latest_backup || !$latest_time) {
            return false;
        }

        return [
            'date'      => date('Y-m-d H:i:s', $latest_time),
            'timestamp' => $latest_time,
            'name'      => $latest_backup['name'] ?? 'Backuply backup',
            'size'      => $latest_backup['size'] ?? 0,
            'age_hours' => round((time() - $latest_time) / 3600, 1),
        ];
    }

    private static function handle_whm_backup($args) {
        // Check Backuply first — most reliable if installed on the site
        if (self::is_backuply_active()) {
            $info = self::get_backuply_backup_info();
            if ($info && $info['age_hours'] <= 48) {
                return [
                    'success'      => true,
                    'method'       => 'backuply',
                    'message'      => sprintf('Backuply: copia disponible (hace %.1fh)', $info['age_hours']),
                    'last_backup'  => $info['date'],
                    'backup_name'  => $info['name'],
                    'managed_by_hub' => true,
                ];
            }
            // Managed-host mode is observation-only. Care must never start a
            // provider backup implicitly on shared hosting.
            return [
                'success'        => false,
                'verified'       => false,
                'method'         => 'backuply_observed',
                'message'        => 'Backuply detectado, pero no hay una copia reciente verificable. Care no ha iniciado ninguna copia.',
                'managed_by_hub' => true,
            ];
        }

        // Fall through to cPanel file scan
        $last_backup = self::check_cpanel_backup_status();

        if ($last_backup) {
            return [
                'success'        => true,
                'method'         => 'whm_cpanel',
                'message'        => 'Backup gestionado por WHM/cPanel',
                'last_backup'    => $last_backup,
                'managed_by_hub' => true,
            ];
        }

        return [
            'success'        => true,
            'verified'       => false,
            'method'         => 'whm_cpanel',
            'message'        => 'Backup gestionado por el hosting (no verificable desde WordPress)',
            'managed_by_hub' => true,
        ];
    }

    private static function handle_external_backup($args) {
        // For external sites, try multiple backup methods in order of preference

        // 1. Backuply (Replanta's preferred backup solution)
        if (self::is_backuply_active()) {
            $info = self::get_backuply_backup_info();
            if ($info && $info['age_hours'] <= 24) {
                return [
                    'success'            => true,
                    'method'             => 'backuply',
                    'message'            => sprintf('Backuply: copia disponible (hace %.1fh)', $info['age_hours']),
                    'last_backup'        => $info['date'],
                    'backup_name'        => $info['name'],
                    'skipped_new_backup' => true,
                ];
            }
            // Backuply present but backup is stale or missing — trigger a new one
            try {
                do_action('backuply_cron_backup');
            } catch (\Throwable $e) {
                RP_Care_Utils::log('backup', 'error', 'backuply_cron_backup threw: ' . $e->getMessage());
                return ['success' => false, 'method' => 'backuply', 'message' => 'Backuply error: ' . $e->getMessage()];
            }
            return [
                'success'     => true,
                'method'      => 'backuply',
                'message'     => 'Backuply: nueva copia iniciada',
                'backup_time' => current_time('mysql'),
            ];
        }

        // 2. Try UpdraftPlus
        if (self::is_updraftplus_active()) {
            return self::trigger_updraftplus_backup($args);
        }

        // 3. Try other backup plugins
        $backup_plugins = [
            'backupbuddy' => 'BackupBuddy',
            'duplicator' => 'Duplicator',
            'backwpup' => 'BackWPup'
        ];
        
        foreach ($backup_plugins as $plugin_slug => $plugin_name) {
            if (self::is_backup_plugin_active($plugin_slug)) {
                return self::trigger_generic_backup_plugin($plugin_slug, $plugin_name, $args);
            }
        }
        
        // 3. Fallback to basic backup
        return self::perform_basic_backup($args);
    }
    
    private static function handle_local_backup($args) {
        // For local development, just create a simple backup
        return self::perform_basic_backup($args);
    }
    
    private static function check_cpanel_backup_status() {
        // Try to detect cPanel backup files
        $backup_dirs = [
            '/home/' . get_current_user() . '/backups',
            '/backup',
            '/var/backup'
        ];
        
        foreach ($backup_dirs as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/*.tar.gz');
                if (!empty($files)) {
                    // Get the most recent backup file
                    $latest_file = '';
                    $latest_time = 0;
                    
                    foreach ($files as $file) {
                        $time = filemtime($file);
                        if ($time > $latest_time) {
                            $latest_time = $time;
                            $latest_file = $file;
                        }
                    }
                    
                    if ($latest_time > (time() - 7 * DAY_IN_SECONDS)) { // Within last 7 days
                        return date('Y-m-d H:i:s', $latest_time);
                    }
                }
            }
        }
        
        return false;
    }
    
    public static function is_updraftplus_active(): bool {
        return is_plugin_active('updraftplus/updraftplus.php') && class_exists('UpdraftPlus');
    }
    
    private static function trigger_updraftplus_backup($args) {
        if (!class_exists('UpdraftPlus')) {
            return [
                'success' => false,
                'method' => 'updraftplus',
                'message' => 'UpdraftPlus class not found'
            ];
        }
        
        try {
            // UpdraftPlus is a singleton — use the global instance, not new UpdraftPlus().
            // Instantiating a second copy corrupts its internal state.
            $updraftplus = $GLOBALS['updraftplus'] ?? null;
            if ( ! $updraftplus && class_exists( 'UpdraftPlus' ) ) {
                $updraftplus = new UpdraftPlus();
            }
            if ( ! $updraftplus ) {
                return [ 'success' => false, 'method' => 'updraftplus', 'message' => 'UpdraftPlus instance not available' ];
            }

            // boot_backup(files, db, restrict_files_to_override, one_shot, services, restrict_sites_to)
            // Prefer boot_backup (UpdraftPlus 1.x/2.x); fall back to legacy backup_files_and_db.
            if ( method_exists( $updraftplus, 'boot_backup' ) ) {
                $backup_result = $updraftplus->boot_backup( 1, 1, false, false,
                    get_option( 'updraft_service', '' ),
                    $args['reason'] ?? 'replanta_care_scheduled' );
            } else {
                $backup_result = $updraftplus->backup_files_and_db(
                    true, true, false, false, false,
                    $args['reason'] ?? 'replanta_care_scheduled'
                );
            }
            
            if ($backup_result) {
                return [
                    'success' => true,
                    'method' => 'updraftplus',
                    'message' => 'UpdraftPlus backup initiated successfully',
                    'backup_time' => current_time('mysql')
                ];
            } else {
                return [
                    'success' => false,
                    'method' => 'updraftplus',
                    'message' => 'UpdraftPlus backup failed to start'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'method' => 'updraftplus',
                'message' => 'UpdraftPlus backup error: ' . $e->getMessage()
            ];
        }
    }
    
    private static function is_backup_plugin_active($plugin_slug) {
        $plugin_files = [
            'backupbuddy' => 'backupbuddy/backupbuddy.php',
            'duplicator' => 'duplicator/duplicator.php',
            'backwpup' => 'backwpup/backwpup.php'
        ];
        
        return isset($plugin_files[$plugin_slug]) && is_plugin_active($plugin_files[$plugin_slug]);
    }
    
    private static function trigger_generic_backup_plugin($plugin_slug, $plugin_name, $args) {
        // Generic backup plugin integration
        // This would need to be customized for each plugin's API
        
        switch ($plugin_slug) {
            case 'duplicator':
                return self::trigger_duplicator_backup($args);
                
            case 'backwpup':
                return self::trigger_backwpup_backup($args);
                
            default:
                return [
                    'success' => false,
                    'method' => $plugin_slug,
                    'message' => "$plugin_name detected but integration not implemented"
                ];
        }
    }
    
    private static function trigger_duplicator_backup($args) {
        // Basic Duplicator integration
        if (class_exists('DUP_Package')) {
            try {
                // Create a new package
                $dup_class = 'DUP_Package'; $package = class_exists($dup_class) ? new $dup_class() : null;
                $package->Name = 'ReplantaCare_' . date('Y-m-d_H-i-s');
                $package->Notes = 'Automated backup by Replanta Care';
                
                // This is a simplified version - actual implementation would need more setup
                return [
                    'success' => true,
                    'method' => 'duplicator',
                    'message' => 'Duplicator backup scheduled',
                    'backup_time' => current_time('mysql')
                ];
                
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'method' => 'duplicator',
                    'message' => 'Duplicator backup error: ' . $e->getMessage()
                ];
            }
        }
        
        return [
            'success' => false,
            'method' => 'duplicator',
            'message' => 'Duplicator classes not available'
        ];
    }
    
    private static function trigger_backwpup_backup($args) {
        // Basic BackWPup integration
        if (class_exists('BackWPup')) {
            // BackWPup typically uses jobs, we would need to find and trigger an existing job
            $jobs = get_option('backwpup_jobs', []);
            
            if (!empty($jobs)) {
                $job_id = array_keys($jobs)[0]; // Use first available job
                
                // Schedule immediate backup
                wp_schedule_single_event(time() + 60, 'backwpup_cron', ['job_id' => $job_id]);
                
                return [
                    'success' => true,
                    'method' => 'backwpup',
                    'message' => 'BackWPup backup scheduled',
                    'job_id' => $job_id,
                    'backup_time' => current_time('mysql')
                ];
            }
        }
        
        return [
            'success' => false,
            'method' => 'backwpup',
            'message' => 'BackWPup not configured or no jobs available'
        ];
    }
    
    private static function perform_basic_backup($args) {
        // Basic backup implementation for sites without backup plugins
        // This creates a simple database backup and optionally files
        
        $backup_dir = self::create_backup_directory( true );
        if (!$backup_dir) {
            return [
                'success' => false,
                'method' => 'basic',
                'message' => 'Could not create backup directory'
            ];
        }
        
        $results = [
            'success' => false,
            'method' => 'basic',
            'database_backup' => false,
            'files_backup' => false,
            'backup_dir' => $backup_dir
        ];
        
        // Create database backup
        $db_backup = self::backup_database($backup_dir);
        $results['database_backup'] = $db_backup['success'];
        
        if (!$db_backup['success']) {
            $results['message'] = 'Database backup failed: ' . $db_backup['message'];
            return $results;
        }
        
        // Create files backup (only for smaller sites)
        $site_size = self::estimate_site_size();
        if ($site_size < 100 * 1024 * 1024) { // Less than 100MB
            $files_backup = self::backup_essential_files($backup_dir);
            $results['files_backup'] = $files_backup['success'];
        } else {
            $results['files_backup'] = 'skipped_large_site';
        }
        
        $results['success'] = $results['database_backup'];
        $results['message'] = 'Basic backup completed';
        $results['backup_size'] = self::get_directory_size($backup_dir);
        $results['backup_time'] = current_time('mysql');

        // Upload to Backblaze B2 if configured
        if (self::is_b2_configured()) {
            $b2_result = self::upload_to_b2($backup_dir, $db_backup['file'] ?? null);
            $results['b2_upload'] = $b2_result;
            if (!$b2_result['success']) {
                RP_Care_Utils::log('backup', 'warning', 'B2 upload failed: ' . $b2_result['message'], $b2_result);
            }
        }

        // Clean old backups
        self::cleanup_old_backups();

        return $results;
    }

    // -------------------------------------------------------------------------
    // Backblaze B2 integration
    // -------------------------------------------------------------------------

    public static function is_b2_configured_public(): bool {
        return self::is_b2_configured();
    }

    private static function is_b2_configured() {
        return !empty(get_option('rpcare_b2_key_id'))
            && !empty(get_option('rpcare_b2_app_key'))
            && !empty(get_option('rpcare_b2_bucket_id'));
    }

    private static function get_b2_config() {
        return [
            'key_id'      => get_option('rpcare_b2_key_id', ''),
            'app_key'     => get_option('rpcare_b2_app_key', ''),
            'bucket_id'   => get_option('rpcare_b2_bucket_id', ''),
            'bucket_name' => get_option('rpcare_b2_bucket_name', ''),
            'prefix'      => get_option('rpcare_b2_prefix', ''),
        ];
    }

    /**
     * Authorize against B2 API. Returns ['api_url', 'auth_token'] or WP_Error.
     * Token is cached in a 23-hour transient to avoid hammering the auth endpoint.
     */
    private static function b2_authorize() {
        $cached = get_transient('rpcare_b2_auth');
        if ($cached && !empty($cached['auth_token']) && !empty($cached['download_url'])) {
            return $cached;
        }

        $cfg  = self::get_b2_config();
        $creds = base64_encode($cfg['key_id'] . ':' . $cfg['app_key']);

        $response = wp_remote_get('https://api.backblazeb2.com/b2api/v3/b2_authorize_account', [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Basic ' . $creds],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['authorizationToken'])) {
            $b2_code = $body['code'] ?? '';
            $error_code = self::b2_classify_auth_error( $code, $b2_code );
            return new WP_Error( $error_code, 'B2 auth HTTP ' . $code . ' (' . $b2_code . '): ' . ( $body['message'] ?? 'unknown' ) );
        }

        $auth = [
            'api_url'      => rtrim($body['apiInfo']['storageApi']['apiUrl'] ?? $body['apiUrl'] ?? '', '/'),
            'download_url' => rtrim($body['apiInfo']['storageApi']['downloadUrl'] ?? $body['downloadUrl'] ?? '', '/'),
            'auth_token'   => $body['authorizationToken'],
        ];

        if (empty($auth['api_url']) || empty($auth['download_url'])) {
            return new WP_Error('b2_auth_failed', 'B2 auth response missing apiUrl/downloadUrl');
        }

        set_transient('rpcare_b2_auth', $auth, 23 * HOUR_IN_SECONDS);
        return $auth;
    }

    /**
     * Upload the backup SQL dump (gzipped) plus a site manifest to B2.
     * Prefix: {domain}/backup_{YYYY-MM-DD_HH-ii-ss}/
     */
    private static function upload_to_b2($backup_dir, $sql_file = null) {
        $auth = self::b2_authorize();
        if (is_wp_error($auth)) {
            return ['success' => false, 'message' => $auth->get_error_message()];
        }

        $cfg    = self::get_b2_config();
        $domain = wp_parse_url(home_url(), PHP_URL_HOST);
        $ts     = date('Y-m-d_H-i-s');
        $prefix = $domain . '/backup_' . $ts . '/';

        $uploaded = [];
        $errors   = [];

        // 1. Gzip + upload the database dump
        $sql_path = $sql_file ?: ($backup_dir . '/database.sql');
        if (file_exists($sql_path)) {
            $gz_path = $sql_path . '.gz';
            self::gzip_file($sql_path, $gz_path);
            $remote_name = $prefix . 'database.sql.gz';
            $up = self::b2_upload_file($auth, $cfg['bucket_id'], $gz_path, $remote_name);
            if ($up['success']) {
                $uploaded[] = $remote_name;
            } else {
                $errors[] = 'database: ' . $up['message'];
            }
            @unlink($gz_path);
        }

        // 2. Upload a manifest (JSON metadata about the site)
        $manifest = wp_json_encode([
            'domain'     => $domain,
            'wp_version' => get_bloginfo('version'),
            'php_version'=> PHP_VERSION,
            'backup_time'=> current_time('c'),
            'plan'       => get_option('rpcare_plan', 'unknown'),
            'db_name'    => DB_NAME,
            'site_url'   => home_url(),
        ]);
        $manifest_tmp = $backup_dir . '/manifest.json';
        file_put_contents($manifest_tmp, $manifest);
        $up = self::b2_upload_file($auth, $cfg['bucket_id'], $manifest_tmp, $prefix . 'manifest.json');
        if ($up['success']) {
            $uploaded[] = $prefix . 'manifest.json';
        }
        @unlink($manifest_tmp);

        // Persist the remote path so Hub can verify without reading B2
        update_option('rpcare_last_b2_backup', [
            'prefix'    => $prefix,
            'timestamp' => time(),
            'files'     => $uploaded,
            'domain'    => $domain,
        ], false);

        if (empty($errors)) {
            return ['success' => true, 'message' => 'B2 upload OK', 'files' => $uploaded, 'prefix' => $prefix];
        }

        return [
            'success' => count($uploaded) > 0,
            'message' => 'B2 partial: ' . implode('; ', $errors),
            'files'   => $uploaded,
        ];
    }

    /**
     * Upload a single local file to B2.
     * Gets a fresh upload URL per file (B2 requirement).
     */
    private static function b2_upload_file($auth, $bucket_id, $local_path, $remote_name) {
        if (!file_exists($local_path)) {
            return ['success' => false, 'message' => 'Local file not found: ' . $local_path];
        }

        // Step 1: get upload URL
        $url_response = wp_remote_post($auth['api_url'] . '/b2api/v3/b2_get_upload_url', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => $auth['auth_token'],
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode(['bucketId' => $bucket_id]),
        ]);

        if (is_wp_error($url_response)) {
            return [
                'success'    => false,
                'error_code' => 'provider_unreachable',
                'message'    => $url_response->get_error_message(),
            ];
        }

        $url_http = (int) wp_remote_retrieve_response_code($url_response);
        $url_body = json_decode(wp_remote_retrieve_body($url_response), true);
        if (empty($url_body['uploadUrl']) || empty($url_body['authorizationToken'])) {
            $b2_error = $url_body['code'] ?? '';
            $error_code = self::b2_classify_upload_url_error( $url_http, $b2_error );
            return [
                'success'    => false,
                'error_code' => $error_code,
                'http_status' => $url_http,
                'b2_code'    => $b2_error,
                'message'    => 'B2 upload URL failed (' . $url_http . '/' . $b2_error . '): ' . ( $url_body['message'] ?? 'unknown' ),
            ];
        }

        $upload_url   = $url_body['uploadUrl'];
        $upload_token = $url_body['authorizationToken'];
        $file_size    = filesize($local_path);
        $sha1         = hash_file('sha1', $local_path);
        $timeout      = max(60, intval($file_size / (1024 * 50))); // ~50 KB/s min

        if (function_exists('curl_init')) {
            $fh = fopen($local_path, 'rb');
            if (!$fh) {
                return ['success' => false, 'message' => 'Could not open file for B2 upload'];
            }
            $ch = curl_init($upload_url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_UPLOAD         => true,
                CURLOPT_INFILE         => $fh,
                CURLOPT_INFILESIZE     => $file_size,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: ' . $upload_token,
                    'X-Bz-File-Name: ' . rawurlencode($remote_name),
                    'Content-Type: b2/x-auto',
                    'Content-Length: ' . $file_size,
                    'X-Bz-Content-Sha1: ' . $sha1,
                ],
            ]);
            $raw_body = curl_exec($ch);
            $curl_error = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fh);
            if ($raw_body === false) {
                return ['success' => false, 'message' => $curl_error ?: 'cURL upload failed'];
            }
            $up_body = json_decode($raw_body, true);
        } else {
            $file_content = file_get_contents($local_path);
            $up_response = wp_remote_post($upload_url, [
                'timeout' => $timeout,
                'headers' => [
                    'Authorization'     => $upload_token,
                    'X-Bz-File-Name'    => rawurlencode($remote_name),
                    'Content-Type'      => 'b2/x-auto',
                    'Content-Length'    => $file_size,
                    'X-Bz-Content-Sha1' => $sha1,
                ],
                'body' => $file_content,
            ]);
            unset($file_content);
            if (is_wp_error($up_response)) {
                return ['success' => false, 'message' => $up_response->get_error_message()];
            }
            $code    = wp_remote_retrieve_response_code($up_response);
            $up_body = json_decode(wp_remote_retrieve_body($up_response), true);
        }

        if ($code === 200 && !empty($up_body['fileId'])) {
            return ['success' => true, 'file_id' => $up_body['fileId'], 'size' => $file_size];
        }

        return ['success' => false, 'message' => 'B2 upload HTTP ' . $code . ': ' . ($up_body['message'] ?? 'unknown')];
    }

    private static function gzip_file($source, $destination) {
        $fh_in  = fopen($source, 'rb');
        $fh_out = gzopen($destination, 'wb9');
        if (!$fh_in || !$fh_out) {
            return false;
        }
        while (!feof($fh_in)) {
            gzwrite($fh_out, fread($fh_in, 524288)); // 512 KB chunks
        }
        fclose($fh_in);
        gzclose($fh_out);
        return true;
    }

    /**
     * Returns B2 backup info for get_backup_status().
     */
    private static function get_b2_backup_info() {
        $data = get_option('rpcare_last_b2_backup', null);
        if (!$data || empty($data['timestamp'])) {
            return null;
        }
        return [
            'date'      => date('Y-m-d H:i:s', $data['timestamp']),
            'timestamp' => $data['timestamp'],
            'prefix'    => $data['prefix'],
            'age_hours' => round((time() - $data['timestamp']) / 3600, 1),
            'domain'    => $data['domain'] ?? '',
        ];
    }

    public static function create_b2_backup($args = []) {
        if (!self::is_b2_configured()) {
            return ['success' => false, 'method' => 'b2', 'message' => 'B2 no configurado'];
        }

        $backup_dir = self::create_backup_directory( false );
        if (!$backup_dir) {
            return ['success' => false, 'method' => 'b2', 'message' => 'No se pudo crear directorio temporal de backup'];
        }

        try {
        $backup_id = 'backup_' . gmdate('Y-m-d_H-i-s');
        $prefix = self::get_b2_backup_prefix($backup_id);
        $scopes = self::normalize_b2_scopes($args['scopes'] ?? ($args['type'] ?? 'full'));
        $artifacts = [];
        $errors = [];

        $manifest = [
            'backup_id' => $backup_id,
            'provider' => 'b2',
            'version' => 1,
            'created_at' => current_time('mysql'),
            'created_at_gmt' => gmdate('c'),
            'site_url' => home_url(),
            'domain' => wp_parse_url(home_url(), PHP_URL_HOST),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'care_version' => defined('RPCARE_VERSION') ? RPCARE_VERSION : '',
            'plan' => get_option('rpcare_plan', 'unknown'),
            'scopes' => $scopes,
            'artifacts' => [],
            'errors' => [],
        ];

        if (in_array('database', $scopes, true)) {
            $db_backup = self::backup_database($backup_dir);
            if (!empty($db_backup['success']) && !empty($db_backup['file']) && file_exists($db_backup['file'])) {
                $gz_path = $db_backup['file'] . '.gz';
                if (self::gzip_file($db_backup['file'], $gz_path)) {
                    $artifact = self::upload_b2_artifact($gz_path, $prefix . 'database.sql.gz', 'database');
                    is_wp_error($artifact) ? $errors[] = $artifact->get_error_message() : $artifacts[] = $artifact;
                    @unlink($gz_path);
                } else {
                    $errors[] = 'No se pudo comprimir database.sql';
                }
            } else {
                $errors[] = 'Database backup failed: ' . ($db_backup['message'] ?? 'unknown');
            }
        }

        $zip_specs = self::get_b2_zip_specs($backup_dir);
        foreach ($zip_specs as $scope => $spec) {
            if (!in_array($scope, $scopes, true)) {
                continue;
            }

            $zip_path = $backup_dir . '/' . $scope . '.zip';
            $zip_result = !empty($spec['files'])
                ? self::create_zip_from_files($spec['files'], $zip_path)
                : self::create_zip_from_directory($spec['source'], $zip_path, $spec['root'], $spec['exclude'] ?? []);

            if (is_wp_error($zip_result)) {
                $errors[] = $scope . ': ' . $zip_result->get_error_message();
                continue;
            }

            $artifact = self::upload_b2_artifact($zip_path, $prefix . $scope . '.zip', $scope);
            is_wp_error($artifact) ? $errors[] = $artifact->get_error_message() : $artifacts[] = $artifact;
            @unlink($zip_path);
        }

        $manifest['artifacts'] = $artifacts;
        $manifest['errors'] = $errors;
        $manifest['status'] = self::compute_backup_status( $scopes, $artifacts, $errors );
        $manifest_path = $backup_dir . '/manifest.json';
        file_put_contents($manifest_path, wp_json_encode($manifest, JSON_PRETTY_PRINT));
        $manifest_artifact = self::upload_b2_artifact($manifest_path, $prefix . 'manifest.json', 'manifest');
        if (is_wp_error($manifest_artifact)) {
            $errors[] = $manifest_artifact->get_error_message();
        } else {
            $artifacts[] = $manifest_artifact;
        }

        // Recompute after manifest upload attempt.
        $backup_status = self::compute_backup_status( $scopes, $artifacts, $errors );
        $backup_usable = ( $backup_status === 'complete' );

        update_option('rpcare_last_b2_backup', [
            'backup_id'     => $backup_id,
            'prefix'        => $prefix,
            'timestamp'     => time(),
            'files'         => array_map(function($artifact) {
                return $artifact['file_name'] ?? '';
            }, $artifacts),
            'artifacts'     => $artifacts,
            'domain'        => $manifest['domain'],
            'status'        => $backup_status,
            'backup_usable' => $backup_usable,
        ], false);

        self::cleanup_b2_old_backups();

        return [
            'success'       => $backup_usable,
            'method'        => 'b2',
            'provider'      => 'b2',
            'message'       => $backup_status === 'complete'
                                ? 'Backup B2 completado'
                                : ( $backup_status === 'partial'
                                    ? 'Backup B2 parcial: ' . implode('; ', $errors)
                                    : 'Backup B2 fallido: ' . implode('; ', $errors) ),
            'backup_id'     => $backup_id,
            'prefix'        => $prefix,
            'status'        => $backup_status,
            'backup_usable' => $backup_usable,
            'scopes'        => $scopes,
            'artifacts'     => $artifacts,
            'errors'        => $errors,
        ];
        } finally {
            // Remote backups use a temporary work area only. It must not
            // survive success, HTTP failure or an exception.
            self::remove_directory( $backup_dir );
        }
    }

    public static function list_b2_backups($limit = 50) {
        if (!self::is_b2_configured()) {
            return new WP_Error('b2_not_configured', 'B2 no configurado');
        }

        $auth = self::b2_authorize();
        if (is_wp_error($auth)) {
            return $auth;
        }

        $cfg = self::get_b2_config();
        $files = self::b2_list_files($auth, $cfg['bucket_id'], self::get_b2_prefix_root() . 'backup_', max(100, (int) $limit * 8));
        if (is_wp_error($files)) {
            return $files;
        }

        $groups = [];
        foreach ($files as $file) {
            $file_name = $file['fileName'] ?? '';
            if (!preg_match('#(.+/)?(backup_[^/]+)/([^/]+)$#', $file_name, $m)) {
                continue;
            }
            $backup_id = $m[2];
            if (!isset($groups[$backup_id])) {
                $groups[$backup_id] = [
                    'id' => $backup_id,
                    'provider' => 'b2',
                    'status' => 'partial',
                    'size' => 0,
                    'created_at' => '',
                    'completed_at' => '',
                    'artifacts' => [],
                    'is_restorable' => false,
                ];
            }

            $ts = !empty($file['uploadTimestamp']) ? (int) ($file['uploadTimestamp'] / 1000) : 0;
            $groups[$backup_id]['size'] += (int) ($file['contentLength'] ?? 0);
            if ($ts && (empty($groups[$backup_id]['created_at']) || strtotime($groups[$backup_id]['created_at']) < $ts)) {
                $groups[$backup_id]['created_at'] = date('Y-m-d H:i:s', $ts);
                $groups[$backup_id]['completed_at'] = date('Y-m-d H:i:s', $ts);
            }

            $scope = self::scope_from_b2_file_name($file_name);
            $groups[$backup_id]['artifacts'][] = [
                'scope' => $scope,
                'file_name' => $file_name,
                'file_id' => $file['fileId'] ?? '',
                'size' => (int) ($file['contentLength'] ?? 0),
                'sha1' => $file['contentSha1'] ?? '',
            ];

            if ($scope === 'manifest') {
                $groups[$backup_id]['status'] = 'completed';
            }
            if ($scope === 'database') {
                $groups[$backup_id]['is_restorable'] = true;
            }
        }

        $backups = array_values($groups);
        usort($backups, function($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });

        return [
            'success' => true,
            'provider' => 'b2',
            'backups' => array_slice($backups, 0, max(1, (int) $limit)),
            'total' => count($backups),
            'plugin_active' => true,
        ];
    }

    public static function verify_b2_backup($backup_id) {
        $files = self::get_b2_backup_files($backup_id);
        if (is_wp_error($files)) {
            return $files;
        }

        $scopes = array_map(function($file) {
            return self::scope_from_b2_file_name($file['fileName'] ?? '');
        }, $files);

        return [
            'success' => in_array('database', $scopes, true) && in_array('manifest', $scopes, true),
            'backup_id' => $backup_id,
            'provider' => 'b2',
            'scopes' => array_values(array_unique($scopes)),
            'file_count' => count($files),
            'has_database' => in_array('database', $scopes, true),
            'has_manifest' => in_array('manifest', $scopes, true),
        ];
    }

    public static function restore_b2_backup($backup_id, $scopes = ['database']) {
        $scopes = self::normalize_b2_scopes($scopes);
        if (in_array('full', $scopes, true)) {
            $scopes = ['database', 'config', 'uploads', 'plugins', 'themes'];
        }

        $auth = self::b2_authorize();
        if (is_wp_error($auth)) {
            return $auth;
        }

        $files = self::get_b2_backup_files($backup_id);
        if (is_wp_error($files)) {
            return $files;
        }

        $by_scope = [];
        foreach ($files as $file) {
            $by_scope[self::scope_from_b2_file_name($file['fileName'] ?? '')] = $file;
        }

        if (empty($by_scope['database']) && in_array('database', $scopes, true)) {
            return new WP_Error('b2_restore_no_database', 'El backup no contiene database.sql.gz');
        }

        $pre_restore = self::create_b2_backup(['reason' => 'pre_restore', 'scopes' => ['database', 'config']]);
        if (empty($pre_restore['success'])) {
            return new WP_Error('pre_restore_backup_failed', 'No se pudo crear backup pre-restore: ' . ($pre_restore['message'] ?? 'unknown'));
        }

        $restore_dir = self::create_backup_directory();
        if (!$restore_dir) {
            return new WP_Error('restore_tmp_failed', 'No se pudo crear directorio temporal de restore');
        }

        $restored = [];
        $errors = [];

        foreach ($scopes as $scope) {
            if (empty($by_scope[$scope])) {
                $errors[] = "Scope no disponible en backup: $scope";
                continue;
            }

            $local_file = $restore_dir . '/' . basename($by_scope[$scope]['fileName']);
            $download = self::b2_download_file($auth, $by_scope[$scope]['fileId'], $local_file);
            if (is_wp_error($download)) {
                $errors[] = $scope . ': ' . $download->get_error_message();
                continue;
            }

            if ($scope === 'database') {
                $sql_file = $restore_dir . '/database.sql';
                if (!self::gunzip_file($local_file, $sql_file)) {
                    $errors[] = 'database: no se pudo descomprimir';
                    continue;
                }
                $import = self::import_sql_file($sql_file);
                is_wp_error($import) ? $errors[] = 'database: ' . $import->get_error_message() : $restored[] = 'database';
                continue;
            }

            $target = ($scope === 'config') ? ABSPATH : WP_CONTENT_DIR;
            $extract = self::extract_zip_to_path($local_file, $target);
            is_wp_error($extract) ? $errors[] = $scope . ': ' . $extract->get_error_message() : $restored[] = $scope;
        }

        self::remove_directory($restore_dir);

        RP_Care_Utils::log('backup_restore', empty($errors) ? 'success' : 'warning', 'Restore B2 ejecutado', [
            'backup_id' => $backup_id,
            'scopes' => $scopes,
            'restored' => $restored,
            'errors' => $errors,
            'pre_restore_backup' => $pre_restore['backup_id'] ?? '',
        ]);

        return [
            'success' => empty($errors),
            'provider' => 'b2',
            'backup_id' => $backup_id,
            'restored' => $restored,
            'errors' => $errors,
            'pre_restore_backup_id' => $pre_restore['backup_id'] ?? '',
        ];
    }

    private static function get_b2_prefix_root() {
        $cfg = self::get_b2_config();
        $prefix = trim((string) ($cfg['prefix'] ?? ''), '/');
        if ($prefix === '') {
            $prefix = wp_parse_url(home_url(), PHP_URL_HOST);
        }
        $prefix = preg_replace('#[^a-zA-Z0-9._/-]+#', '-', $prefix);
        return trim($prefix, '/') . '/';
    }

    private static function get_b2_backup_prefix($backup_id) {
        return self::get_b2_prefix_root() . sanitize_file_name($backup_id) . '/';
    }

    private static function normalize_b2_scopes($scopes) {
        if (is_string($scopes)) {
            $scopes = [$scopes];
        }
        if (!is_array($scopes) || empty($scopes)) {
            $scopes = ['full'];
        }
        if (in_array('full', $scopes, true)) {
            return ['database', 'config', 'uploads', 'plugins', 'themes'];
        }
        if (in_array('files', $scopes, true)) {
            $scopes = array_merge($scopes, ['config', 'uploads', 'plugins', 'themes']);
        }
        $allowed = ['database', 'config', 'uploads', 'plugins', 'themes'];
        return array_values(array_intersect($allowed, array_map('sanitize_key', $scopes))) ?: ['database'];
    }

    private static function get_b2_zip_specs($backup_dir) {
        $upload_dir = wp_upload_dir();
        return [
            'config' => [
                'files' => [
                    ABSPATH . 'wp-config.php' => 'wp-config.php',
                    ABSPATH . '.htaccess' => '.htaccess',
                ],
            ],
            'uploads' => [
                'source' => $upload_dir['basedir'],
                'root' => 'uploads',
                'exclude' => [self::get_backup_base_dir(), $backup_dir],
            ],
            'plugins' => [
                'source' => WP_PLUGIN_DIR,
                'root' => 'plugins',
                'exclude' => [self::get_backup_base_dir(), $backup_dir],
            ],
            'themes' => [
                'source' => get_theme_root(),
                'root' => 'themes',
                'exclude' => [self::get_backup_base_dir(), $backup_dir],
            ],
        ];
    }

    private static function upload_b2_artifact($local_path, $remote_name, $scope) {
        $auth = self::b2_authorize();
        if (is_wp_error($auth)) {
            return $auth;
        }
        $cfg = self::get_b2_config();
        $upload = self::b2_upload_file($auth, $cfg['bucket_id'], $local_path, $remote_name);
        if (empty($upload['success'])) {
            return new WP_Error('b2_upload_failed', $scope . ': ' . ($upload['message'] ?? 'unknown'));
        }
        return [
            'scope' => $scope,
            'file_name' => $remote_name,
            'file_id' => $upload['file_id'] ?? '',
            'size' => $upload['size'] ?? filesize($local_path),
            'sha256' => file_exists($local_path) ? hash_file('sha256', $local_path) : '',
        ];
    }

    /**
     * Compute canonical backup status from result of a backup attempt.
     *
     * complete  — every mandatory scope (database + all requested scopes) succeeded and
     *             at least one non-manifest artifact was uploaded.
     * partial   — at least one artifact uploaded AND at least one error.
     * failed    — nothing uploaded, or database scope requested but absent from artifacts.
     *
     * Public so tests can verify the semantics without executing a full backup.
     */
    public static function compute_backup_status( array $scopes, array $artifacts, array $errors ): string {
        $data_artifacts = array_filter( $artifacts, function( $a ) {
            return ( $a['scope'] ?? '' ) !== 'manifest';
        } );

        if ( empty( $data_artifacts ) ) {
            return 'failed';
        }

        // Database is mandatory when requested.
        if ( in_array( 'database', $scopes, true ) ) {
            $has_db = (bool) array_filter( $data_artifacts, function( $a ) {
                return ( $a['scope'] ?? '' ) === 'database';
            } );
            if ( ! $has_db ) {
                return empty( $errors ) ? 'partial' : 'failed';
            }
        }

        return empty( $errors ) ? 'complete' : 'partial';
    }

    /**
     * Map B2 auth HTTP status + B2 error code to a structured error slug.
     * Never logs credentials — only HTTP status and B2 error code are safe.
     * Public so REST connection-test handler can call it without duplicating logic.
     */
    public static function b2_classify_auth_error( int $http, string $b2_code ): string {
        if ( $http === 401 ) return 'authentication_failed';
        if ( $http === 403 ) return 'key_unauthorized';
        if ( $http === 429 ) return 'provider_rate_limited';
        if ( $http >= 500 )  return 'provider_unreachable';
        if ( $b2_code === 'bad_auth_token' )    return 'authentication_failed';
        if ( $b2_code === 'expired_auth_token') return 'authentication_failed';
        if ( $b2_code === 'storage_cap_exceeded' ) return 'storage_cap_exceeded';
        return 'unknown_provider_error';
    }

    /**
     * Map b2_get_upload_url failure to a structured error slug.
     * B2-specific codes take precedence over generic HTTP status so that a structured
     * code returned inside a 200 or 0-status response (network error with body) is
     * correctly classified.  Public for use by REST connection-test handler.
     */
    public static function b2_classify_upload_url_error( int $http, string $b2_code ): string {
        // B2-specific codes first — they are authoritative regardless of HTTP status.
        if ( $b2_code === 'storage_cap_exceeded' ) return 'storage_cap_exceeded';
        if ( $b2_code === 'bad_auth_token' )       return 'authentication_failed';
        if ( $b2_code === 'expired_auth_token' )   return 'authentication_failed';
        if ( $b2_code === 'access_denied' )        return 'key_unauthorized';
        // HTTP status fallback.
        if ( $http === 401 ) return 'authentication_failed';
        if ( $http === 403 ) return 'bucket_not_allowed';
        if ( $http === 404 ) return 'bucket_not_found';
        if ( $http === 429 ) return 'provider_rate_limited';
        if ( $http >= 500 )  return 'provider_unreachable';
        if ( $http === 0 )   return 'provider_unreachable';
        return 'unknown_provider_error';
    }

    private static function b2_list_files($auth, $bucket_id, $prefix, $max_files = 1000) {
        $response = wp_remote_post($auth['api_url'] . '/b2api/v3/b2_list_file_names', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => $auth['auth_token'],
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'bucketId' => $bucket_id,
                'prefix' => $prefix,
                'maxFileCount' => min(1000, max(1, (int) $max_files)),
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            return new WP_Error('b2_list_failed', 'B2 list HTTP ' . $code . ': ' . ($body['message'] ?? 'unknown'));
        }
        return $body['files'] ?? [];
    }

    private static function get_b2_backup_files($backup_id) {
        if (!preg_match('/^backup_[A-Za-z0-9_-]+$/', $backup_id)) {
            return new WP_Error('invalid_backup_id', 'backup_id invalido');
        }
        $auth = self::b2_authorize();
        if (is_wp_error($auth)) {
            return $auth;
        }
        $cfg = self::get_b2_config();
        $files = self::b2_list_files($auth, $cfg['bucket_id'], self::get_b2_backup_prefix($backup_id), 1000);
        if (is_wp_error($files)) {
            return $files;
        }
        if (empty($files)) {
            return new WP_Error('b2_backup_not_found', 'Backup B2 no encontrado');
        }
        return $files;
    }

    private static function scope_from_b2_file_name($file_name) {
        $base = basename($file_name);
        if ($base === 'manifest.json') {
            return 'manifest';
        }
        if ($base === 'database.sql.gz') {
            return 'database';
        }
        return sanitize_key(preg_replace('/\.zip$/', '', $base));
    }

    private static function b2_download_file($auth, $file_id, $local_path) {
        if (empty($auth['download_url'])) {
            return new WP_Error('b2_no_download_url', 'B2 no devolvio downloadUrl');
        }
        wp_mkdir_p(dirname($local_path));
        $response = wp_remote_get(add_query_arg(['fileId' => $file_id], $auth['download_url'] . '/b2api/v3/b2_download_file_by_id'), [
            'timeout' => 300,
            'headers' => ['Authorization' => $auth['auth_token']],
            'stream' => true,
            'filename' => $local_path,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200 || !file_exists($local_path)) {
            return new WP_Error('b2_download_failed', 'B2 download HTTP ' . $code);
        }
        return true;
    }

    private static function create_zip_from_files($files, $zip_path) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('zip_missing', 'ZipArchive no disponible');
        }
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_open_failed', 'No se pudo crear ZIP');
        }
        $count = 0;
        foreach ($files as $source => $local_name) {
            if (is_file($source) && is_readable($source)) {
                $zip->addFile($source, $local_name);
                $count++;
            }
        }
        $zip->close();
        return $count > 0 ? ['files' => $count] : new WP_Error('zip_empty', 'Sin archivos para comprimir');
    }

    private static function create_zip_from_directory($source, $zip_path, $root_name, $exclude_paths = []) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('zip_missing', 'ZipArchive no disponible');
        }
        $source = realpath($source);
        if (!$source || !is_dir($source)) {
            return new WP_Error('zip_source_missing', 'Directorio no disponible');
        }
        $excludes = array_filter(array_map('realpath', $exclude_paths));
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_open_failed', 'No se pudo crear ZIP');
        }
        $count = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }
            $path = $file->getRealPath();
            foreach ($excludes as $exclude) {
                if ($exclude && strpos($path, $exclude) === 0) {
                    continue 2;
                }
            }
            $relative = ltrim(str_replace($source, '', $path), DIRECTORY_SEPARATOR);
            $zip->addFile($path, trim($root_name, '/') . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative));
            $count++;
        }
        $zip->close();
        return $count > 0 ? ['files' => $count] : new WP_Error('zip_empty', 'Directorio vacio');
    }

    private static function gunzip_file($source, $destination) {
        $in = gzopen($source, 'rb');
        $out = fopen($destination, 'wb');
        if (!$in || !$out) {
            return false;
        }
        while (!gzeof($in)) {
            fwrite($out, gzread($in, 524288));
        }
        gzclose($in);
        fclose($out);
        return true;
    }

    private static function import_sql_file($sql_file) {
        global $wpdb;
        if (!is_readable($sql_file)) {
            return new WP_Error('sql_unreadable', 'SQL no legible');
        }
        $fh = fopen($sql_file, 'r');
        if (!$fh) {
            return new WP_Error('sql_open_failed', 'No se pudo abrir SQL');
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
        $query = '';
        while (($line = fgets($fh)) !== false) {
            $trim = trim($line);
            if ($trim === '' || strpos($trim, '--') === 0) {
                continue;
            }
            $query .= $line;
            if (substr(rtrim($line), -1) === ';') {
                $result = $wpdb->query($query);
                if ($result === false) {
                    fclose($fh);
                    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
                    return new WP_Error('sql_import_failed', $wpdb->last_error ?: 'SQL import failed');
                }
                $query = '';
            }
        }
        fclose($fh);
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
        return true;
    }

    private static function extract_zip_to_path($zip_path, $target_dir) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('zip_missing', 'ZipArchive no disponible');
        }
        $target_dir = realpath($target_dir);
        if (!$target_dir || !is_dir($target_dir)) {
            return new WP_Error('restore_target_missing', 'Destino de restore no disponible');
        }
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return new WP_Error('zip_open_failed', 'No se pudo abrir ZIP');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, '..') !== false || strpos($name, ':') !== false) {
                $zip->close();
                return new WP_Error('zip_unsafe_path', 'Ruta insegura en ZIP: ' . $name);
            }
        }
        $ok = $zip->extractTo($target_dir);
        $zip->close();
        return $ok ? true : new WP_Error('zip_extract_failed', 'No se pudo extraer ZIP');
    }
    
    private static function get_backup_base_dir() {
        if ( self::$backup_base_dir_override !== null ) {
            return self::$backup_base_dir_override;
        }
        // Use a randomised directory name so the path cannot be guessed.
        $secret = get_option('rpcare_backup_dir_secret');
        if (!$secret) {
            $secret = wp_generate_password(16, false);
            update_option('rpcare_backup_dir_secret', $secret, false);
        }
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/rpcare-backups-' . $secret;
    }

    private static function create_backup_directory( bool $persistent_local_dev = false ) {
        $backup_base_dir = $persistent_local_dev
            ? self::get_backup_base_dir()
            : self::get_temporary_backup_base_dir();
        $backup_dir = $backup_base_dir . '/' . date('Y-m-d_H-i-s');
        
        if (!wp_mkdir_p($backup_dir)) {
            return false;
        }
        
        // Protect directory: .htaccess
        $htaccess_file = $backup_base_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "# Deny direct access\nOrder deny,allow\nDeny from all\n");
        }
        
        // Protect directory: index.php
        $index_file = $backup_base_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, "<?php\n// Silence is golden\n");
        }
        
        return $backup_dir;
    }

    /**
     * Non-persistent work area for remote backup/restore operations.
     * It intentionally lives outside wp-content/uploads.
     */
    private static function get_temporary_backup_base_dir(): string {
        $site = function_exists( 'home_url' ) ? (string) home_url() : ABSPATH;
        return trailingslashit( get_temp_dir() ) . 'rpcare-' . substr( hash( 'sha256', $site ), 0, 12 );
    }
    
    private static function backup_database($backup_dir) {
        global $wpdb;
        
        $backup_file = $backup_dir . '/database.sql';
        $batch_size  = 1000;
        
        try {
            $tables = $wpdb->get_col('SHOW TABLES');
            
            // Open file handle for streaming writes instead of building one huge string
            $fh = fopen($backup_file, 'w');
            if (!$fh) {
                return [
                    'success' => false,
                    'message' => 'Failed to open database backup file for writing'
                ];
            }
            
            // Header
            fwrite($fh, "-- Replanta Care Database Backup\n");
            fwrite($fh, "-- Generated on: " . current_time('mysql') . "\n");
            fwrite($fh, "-- Database: " . DB_NAME . "\n\n");
            
            foreach ($tables as $table) {
                // Table structure
                $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
                fwrite($fh, "\n\n-- Table structure for table `$table`\n");
                fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
                fwrite($fh, $create_table[1] . ";\n\n");
                
                // Batched data dump — avoids memory exhaustion on large tables
                $offset = 0;
                $first_batch = true;
                
                while (true) {
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT * FROM `$table` LIMIT %d OFFSET %d",
                            $batch_size,
                            $offset
                        ),
                        ARRAY_A
                    );
                    
                    if (empty($rows)) {
                        break;
                    }
                    
                    if ($first_batch) {
                        fwrite($fh, "-- Dumping data for table `$table`\n");
                        $first_batch = false;
                    }
                    
                    foreach ($rows as $row) {
                        $values = array_map(function($value) use ($wpdb) {
                            return $value === null ? 'NULL' : "'" . $wpdb->_escape($value) . "'";
                        }, array_values($row));
                        
                        fwrite($fh, "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n");
                    }
                    
                    $offset += $batch_size;
                    
                    // If the batch returned fewer rows than the limit we're done
                    if (count($rows) < $batch_size) {
                        break;
                    }
                }
            }
            
            fclose($fh);
            
            // Generate SHA-256 checksum for integrity verification
            $hash = hash_file('sha256', $backup_file);
            file_put_contents($backup_file . '.sha256', $hash . '  database.sql' . "\n");
            
            return [
                'success'  => true,
                'message'  => 'Database backup completed',
                'file'     => $backup_file,
                'size'     => filesize($backup_file),
                'sha256'   => $hash
            ];
            
        } catch (Exception $e) {
            if (isset($fh) && is_resource($fh)) {
                fclose($fh);
            }
            return [
                'success' => false,
                'message' => 'Database backup error: ' . $e->getMessage()
            ];
        }
    }
    
    private static function backup_essential_files($backup_dir) {
        $essential_files = [
            'wp-config.php',
            '.htaccess'
        ];
        
        $essential_dirs = [
            'wp-content/themes',
            'wp-content/plugins',
            'wp-content/uploads'
        ];
        
        $files_backed_up = 0;
        
        try {
            // Backup essential files
            foreach ($essential_files as $file) {
                $source = ABSPATH . $file;
                $destination = $backup_dir . '/' . $file;
                
                if (file_exists($source)) {
                    wp_mkdir_p(dirname($destination));
                    if (copy($source, $destination)) {
                        $files_backed_up++;
                    }
                }
            }
            
            // Backup essential directories (with size limit)
            foreach ($essential_dirs as $dir) {
                $source_dir = ABSPATH . $dir;
                $dest_dir = $backup_dir . '/' . $dir;
                
                if (is_dir($source_dir)) {
                    $copied = self::copy_directory_limited($source_dir, $dest_dir, 50 * 1024 * 1024); // 50MB limit
                    $files_backed_up += $copied;
                }
            }
            
            return [
                'success' => true,
                'message' => 'Files backup completed',
                'files_backed_up' => $files_backed_up
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Files backup error: ' . $e->getMessage()
            ];
        }
    }
    
    private static function copy_directory_limited($source, $dest, $size_limit) {
        if (!is_dir($source)) {
            return 0;
        }
        
        wp_mkdir_p($dest);
        $files_copied = 0;
        $total_size = 0;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            if ($total_size > $size_limit) {
                break;
            }
            
            $relative_path = str_replace($source . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $target = $dest . DIRECTORY_SEPARATOR . $relative_path;
            
            if ($item->isDir()) {
                wp_mkdir_p($target);
            } else {
                $file_size = $item->getSize();
                if ($total_size + $file_size <= $size_limit) {
                    wp_mkdir_p(dirname($target));
                    if (copy($item, $target)) {
                        $files_copied++;
                        $total_size += $file_size;
                    }
                }
            }
        }
        
        return $files_copied;
    }
    
    private static function estimate_site_size() {
        $upload_dir = wp_upload_dir();
        $upload_size = self::get_directory_size($upload_dir['basedir']);
        
        // Estimate based on uploads directory (usually the largest)
        return $upload_size * 1.5; // Add 50% for other files
    }
    
    private static function get_directory_size($directory) {
        if (!is_dir($directory)) {
            return 0;
        }
        
        $size = 0;
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (Exception $e) {
            // If we can't calculate, return 0
            return 0;
        }
        
        return $size;
    }
    
    private static function b2_delete_file_version(array $auth, string $file_id, string $file_name): bool {
        $response = wp_remote_post($auth['api_url'] . '/b2api/v3/b2_delete_file_version', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => $auth['auth_token'],
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'fileId'   => $file_id,
                'fileName' => $file_name,
            ]),
        ]);
        if (is_wp_error($response)) {
            return false;
        }
        return wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Public entry point for the 'retention' hub_run() task.
     * Called by rpcare_task_retention AS hook and directly by run_b2_retention().
     *
     * @param mixed $args Ignored — kept for apply_filters() compat.
     * @return array {success, deleted, skipped, retention_days, message}
     */
    public static function run_b2_retention( $args = [] ): array {
        if ( ! self::is_b2_configured() ) {
            RP_Care_Utils::log( 'retention', 'info', 'B2 no configurado — nada que limpiar.' );
            return [ 'success' => true, 'deleted' => 0, 'skipped' => 0, 'message' => 'B2 no configurado' ];
        }

        $result = self::cleanup_b2_old_backups();
        $deleted = count( $result['deleted'] ?? [] );
        $errors  = $result['errors']  ?? [];

        $msg = "Retención B2: {$deleted} backup(s) eliminados, {$result['retention_days']} días.";
        if ( $errors ) {
            $msg .= ' Errores: ' . implode( '; ', $errors );
            RP_Care_Utils::log( 'retention', 'error', $msg );
        } else {
            RP_Care_Utils::log( 'retention', 'success', $msg );
        }

        return [
            'success'        => empty( $errors ),
            'deleted'        => $deleted,
            'skipped'        => $result['skipped'] ?? 0,
            'retention_days' => $result['retention_days'] ?? 0,
            'errors'         => $errors,
            'message'        => $msg,
        ];
    }

    private static function cleanup_b2_old_backups(): array {
        if (!self::is_b2_configured()) {
            return ['deleted' => [], 'skipped' => 0, 'errors' => [], 'retention_days' => 0];
        }

        $auth = self::b2_authorize();
        if (is_wp_error($auth)) {
            return ['deleted' => [], 'skipped' => 0, 'errors' => [$auth->get_error_message()], 'retention_days' => 0];
        }

        $retention_days = (int) get_option('rpcare_backup_retention_days', 0);
        if ($retention_days <= 0 && class_exists('RP_Care_Plan')) {
            $config         = RP_Care_Plan::get_plan_config();
            $retention_days = (int) ($config['backup_retention_days'] ?? 7);
        }
        if ( class_exists( 'RP_Care_Addon_Manager' ) && RP_Care_Addon_Manager::get()->is_active( 'ecommerce' ) ) {
            $ecommerce      = RP_Care_Addon_Manager::get()->get_config( 'ecommerce' );
            $retention_days = max( $retention_days, (int) ( $ecommerce['backup_retention_days'] ?? 90 ) );
        }
        $retention_days = max(1, $retention_days ?: 7);
        $cutoff         = time() - ($retention_days * DAY_IN_SECONDS);

        $cfg   = self::get_b2_config();
        $files = self::b2_list_files($auth, $cfg['bucket_id'], self::get_b2_prefix_root() . 'backup_', 1000);
        if (is_wp_error($files) || empty($files)) {
            return ['deleted' => [], 'skipped' => 0, 'errors' => [], 'retention_days' => $retention_days];
        }

        $groups = [];
        foreach ($files as $file) {
            $file_name = $file['fileName'] ?? '';
            if (!preg_match('#(.+/)?(backup_[^/]+)/([^/]+)$#', $file_name, $m)) {
                continue;
            }
            $backup_id = $m[2];
            $ts        = !empty($file['uploadTimestamp']) ? (int) ($file['uploadTimestamp'] / 1000) : 0;

            if (!isset($groups[$backup_id])) {
                $groups[$backup_id] = ['max_ts' => 0, 'files' => []];
            }
            if ($ts > $groups[$backup_id]['max_ts']) {
                $groups[$backup_id]['max_ts'] = $ts;
            }
            $groups[$backup_id]['files'][] = [
                'file_id'   => $file['fileId']   ?? '',
                'file_name' => $file_name,
            ];
        }

        // Always keep at least 2 backups regardless of retention policy.
        uasort($groups, static function($a, $b) { return $b['max_ts'] - $a['max_ts']; });

        $deleted = [];
        $errors  = [];
        $skipped = 0;
        $index   = 0;

        foreach ($groups as $backup_id => $group) {
            $index++;
            if ($index <= 2) {
                $skipped++;
                continue;
            }
            if ($group['max_ts'] >= $cutoff) {
                $skipped++;
                continue;
            }
            foreach ($group['files'] as $f) {
                if ($f['file_id'] && $f['file_name']) {
                    $ok = self::b2_delete_file_version($auth, $f['file_id'], $f['file_name']);
                    if (!$ok) {
                        $errors[] = "No se pudo eliminar: {$f['file_name']}";
                    }
                }
            }
            $deleted[] = $backup_id;
        }

        return ['deleted' => $deleted, 'skipped' => $skipped, 'errors' => $errors, 'retention_days' => $retention_days];
    }

    private static function cleanup_old_backups() {
        $backup_base_dir = self::get_backup_base_dir();
        
        if (!is_dir($backup_base_dir)) {
            return;
        }
        
        $backup_dirs = glob($backup_base_dir . '/*', GLOB_ONLYDIR);
        
        usort($backup_dirs, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // Keep at most 3 most-recent local backup directories; delete the rest unconditionally.
        // Age-based retention applies to remote (B2) backups via cleanup_b2_old_backups().
        // Local dirs are either temp dirs (B2 path already removes them) or basic-backup storage
        // where keeping more than a few copies would fill disk quickly.
        foreach ($backup_dirs as $index => $dir) {
            if ($index < 3) {
                continue;
            }
            self::remove_directory($dir);
        }
    }
    
    private static function remove_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file);
            } else {
                unlink($file);
            }
        }
        
        rmdir($dir);
    }
    
    // -------------------------------------------------------------------------
    // UpdraftPlus — list + restore
    // -------------------------------------------------------------------------

    private static function get_updraftplus_local_dir(): string {
        $dir = get_option('updraft_backupdir', '');
        if (!$dir) {
            $dir = WP_CONTENT_DIR . '/updraft';
        }
        if (!path_is_absolute($dir)) {
            $dir = ABSPATH . $dir;
        }
        return is_dir($dir) ? rtrim($dir, '/\\') : '';
    }

    public static function list_updraftplus_backups(int $limit = 10): array {
        if (!self::is_updraftplus_active()) {
            return ['success' => false, 'provider' => 'updraftplus', 'error' => 'UpdraftPlus no está activo', 'backups' => []];
        }

        $history = get_option('updraft_backup_history', []);
        if (empty($history) || !is_array($history)) {
            return ['success' => true, 'provider' => 'updraftplus', 'backups' => [], 'total' => 0, 'plugin_active' => true];
        }

        krsort($history); // newest first (keys are timestamps)

        $backup_dir = self::get_updraftplus_local_dir();
        $backups    = [];

        $scope_keys = ['db' => 'database', 'plugins' => 'plugins', 'themes' => 'themes', 'uploads' => 'uploads', 'others' => 'others'];

        foreach ($history as $ts => $backup) {
            if (count($backups) >= $limit) break;

            $timestamp = (int) ($backup['backup_time'] ?? $ts);
            $scopes    = [];
            $size      = 0;
            $db_local  = false;

            foreach ($scope_keys as $udp_key => $scope_name) {
                if (!empty($backup[$udp_key])) {
                    $scopes[] = $scope_name;
                    foreach ((array) $backup[$udp_key] as $file) {
                        $fpath = $backup_dir ? ($backup_dir . '/' . $file) : '';
                        if ($fpath && file_exists($fpath)) {
                            $size += filesize($fpath);
                            if ($udp_key === 'db') $db_local = true;
                        }
                    }
                }
            }

            $backups[] = [
                'id'            => 'udp_' . $timestamp,
                'provider'      => 'updraftplus',
                'created_at'    => date('Y-m-d H:i:s', $timestamp),
                'size'          => $size,
                'scopes'        => $scopes,
                'is_restorable' => $db_local,
                'status'        => in_array('database', $scopes, true) ? 'completed' : 'partial',
                'label'         => $backup['label'] ?? '',
                'nonce'         => $backup['nonce'] ?? '',
            ];
        }

        return [
            'success'      => true,
            'provider'     => 'updraftplus',
            'backups'      => $backups,
            'total'        => count($history),
            'plugin_active'=> true,
        ];
    }

    /**
     * @param string   $backup_id
     * @param string[] $scopes
     * @return array|\WP_Error
     */
    public static function restore_updraftplus_backup(string $backup_id, array $scopes = ['database']) {
        if (!self::is_updraftplus_active()) {
            return new \WP_Error('udp_not_active', 'UpdraftPlus no está activo');
        }

        if (!preg_match('/^udp_(\d+)$/', $backup_id, $m)) {
            return new \WP_Error('udp_invalid_id', 'backup_id UpdraftPlus inválido');
        }
        $timestamp = (int) $m[1];

        $history = get_option('updraft_backup_history', []);
        $backup  = $history[$timestamp] ?? null;
        if (!$backup) {
            foreach ($history as $ts => $b) {
                if ((int) ($b['backup_time'] ?? $ts) === $timestamp) { $backup = $b; break; }
            }
        }
        if (!$backup) {
            return new \WP_Error('udp_not_found', 'Backup UpdraftPlus no encontrado en historial');
        }

        $backup_dir = self::get_updraftplus_local_dir();
        if (!$backup_dir) {
            return new \WP_Error('udp_no_local_dir', 'Directorio local de UpdraftPlus no disponible (backup remoto no soportado)');
        }

        $errors   = [];
        $restored = [];

        if (in_array('database', $scopes, true)) {
            $db_files = (array) ($backup['db'] ?? []);
            if (empty($db_files)) {
                return new \WP_Error('udp_no_db', 'Este backup no contiene base de datos');
            }
            $local_path = $backup_dir . '/' . $db_files[0];
            if (!file_exists($local_path)) {
                return new \WP_Error('udp_file_missing', 'Archivo de BD no disponible localmente: ' . $db_files[0]);
            }

            // Pre-restore safety backup
            if (self::is_b2_configured()) {
                $pre = self::create_b2_backup(['reason' => 'pre_restore_udp', 'scopes' => ['database']]);
                if (empty($pre['success'])) {
                    $errors[] = 'Advertencia: pre-restore B2 backup falló';
                }
            }

            $tmp_dir  = self::create_backup_directory();
            if (!$tmp_dir) return new \WP_Error('udp_tmp_failed', 'No se pudo crear directorio temporal');

            $sql_file  = $tmp_dir . '/udp_restore.sql';
            $extracted = false;

            if (substr($local_path, -4) === '.bz2' && function_exists('bzopen')) {
                $in = bzopen($local_path, 'r'); $out = fopen($sql_file, 'w');
                if ($in && $out) {
                    while (!feof($in)) fwrite($out, bzread($in, 524288));
                    bzclose($in); fclose($out); $extracted = true;
                }
            } elseif (substr($local_path, -3) === '.gz') {
                $extracted = self::gunzip_file($local_path, $sql_file);
            } else {
                $extracted = copy($local_path, $sql_file);
            }

            if (!$extracted) {
                self::remove_directory($tmp_dir);
                return new \WP_Error('udp_extract_failed', 'No se pudo descomprimir el backup de BD de UpdraftPlus');
            }

            $import = self::import_sql_file($sql_file);
            self::remove_directory($tmp_dir);

            if (is_wp_error($import)) {
                return new \WP_Error('udp_import_failed', 'Importación SQL falló: ' . $import->get_error_message());
            }
            $restored[] = 'database';
        }

        RP_Care_Utils::log('backup_restore', empty($errors) ? 'success' : 'warning', 'Restore UpdraftPlus ejecutado', [
            'backup_id' => $backup_id,
            'timestamp' => $timestamp,
            'restored'  => $restored,
            'errors'    => $errors,
        ]);

        return [
            'success'   => !empty($restored),
            'provider'  => 'updraftplus',
            'backup_id' => $backup_id,
            'restored'  => $restored,
            'errors'    => $errors,
        ];
    }

    public static function get_backup_status() {
        $last_backup = get_option('rpcare_last_backup', '');
        $backup_frequency = RP_Care_Plan::get_backup_frequency();
        $backup_retention_days = (int) get_option('rpcare_backup_retention_days', 0);
        if ($backup_retention_days <= 0 && class_exists('RP_Care_Plan')) {
            $config = RP_Care_Plan::get_plan_config();
            $backup_retention_days = (int) ($config['backup_retention_days'] ?? 30);
        }
        $next_scheduled = function_exists('as_next_scheduled_action')
            ? as_next_scheduled_action('rpcare_task_backup', [], 'replanta-care')
            : wp_next_scheduled('rpcare_task_backup');
        
        $status = [
            'last_backup' => $last_backup,
            'frequency' => $backup_frequency,
            'retention_days' => max(1, $backup_retention_days ?: 30),
            'next_scheduled' => $next_scheduled,
            'method' => 'unknown'
        ];
        
        // Determine backup method (priority order mirrors handle_* methods)
        if (self::is_backuply_active()) {
            $status['method'] = 'backuply';
            $info = self::get_backuply_backup_info();
            if ($info) {
                $status['last_backup']       = $info['date'];
                $status['last_backup_name']  = $info['name'];
                $status['backup_age_hours']  = $info['age_hours'];
            }
        } elseif (in_array(RP_Care_Scheduler::get_environment_type(), ['whm', 'cpanel'], true)) {
            $status['method'] = 'whm_cpanel';
        } elseif (self::is_updraftplus_active()) {
            $status['method'] = 'updraftplus';
        } elseif (self::is_backup_plugin_active('backupbuddy')) {
            $status['method'] = 'backupbuddy';
        } elseif (self::is_backup_plugin_active('duplicator')) {
            $status['method'] = 'duplicator';
        } elseif (self::is_backup_plugin_active('backwpup')) {
            $status['method'] = 'backwpup';
        } else {
            $status['method'] = 'basic';
        }

        // B2 remote status (overlaid on any local method)
        if (self::is_b2_configured()) {
            $b2_info = self::get_b2_backup_info();
            $status['b2_enabled']   = true;
            $status['b2_last_backup'] = $b2_info ? $b2_info['date'] : null;
            $status['b2_age_hours']   = $b2_info ? $b2_info['age_hours'] : null;
            $status['b2_prefix']      = $b2_info ? $b2_info['prefix'] : null;
        } else {
            $status['b2_enabled'] = false;
        }

        return $status;
    }
}
