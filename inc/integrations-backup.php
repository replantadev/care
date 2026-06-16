<?php
/**
 * Backup Integration Task
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Backup {
    
    public static function run($args = []) {
        $environment = RP_Care_Scheduler::get_environment_type();
        $results = [];
        
        switch ($environment) {
            case 'whm':
                $results = self::handle_whm_backup($args);
                break;
            case 'external':
                $results = self::handle_external_backup($args);
                break;
            default:
                $results = self::handle_local_backup($args);
                break;
        }
        
        // Update last backup time if successful
        if ($results['success']) {
            update_option('rpcare_last_backup', current_time('mysql'));
        }
        
        RP_Care_Utils::log('backup', $results['success'] ? 'success' : 'error', $results['message'], $results);
        
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
            // Backuply present but no recent backup — request one
            try {
                do_action('backuply_cron_backup');
            } catch (\Throwable $e) {
                RP_Care_Utils::log('backup', 'error', 'backuply_cron_backup threw: ' . $e->getMessage());
                return ['success' => false, 'method' => 'backuply', 'message' => 'Backuply error: ' . $e->getMessage()];
            }
            return [
                'success'        => true,
                'method'         => 'backuply',
                'message'        => 'Backuply: copia programada (no había copia reciente)',
                'backup_time'    => current_time('mysql'),
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
    
    private static function is_updraftplus_active() {
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
            // Trigger UpdraftPlus backup
            $updraft_class = 'UpdraftPlus'; $updraftplus = class_exists($updraft_class) ? new $updraft_class() : null;
            
            // Set backup parameters
            $backup_files = true;
            $backup_database = true;
            
            // Start the backup
            $backup_result = $updraftplus->backup_files_and_db(
                $backup_files,
                $backup_database,
                false, // Don't show admin messages
                false, // Not incremental
                false, // Don't email
                $args['reason'] ?? 'replanta_care_scheduled'
            );
            
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
        
        $backup_dir = self::create_backup_directory();
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
        ];
    }

    /**
     * Authorize against B2 API. Returns ['api_url', 'auth_token'] or WP_Error.
     * Token is cached in a 23-hour transient to avoid hammering the auth endpoint.
     */
    private static function b2_authorize() {
        $cached = get_transient('rpcare_b2_auth');
        if ($cached && !empty($cached['auth_token'])) {
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
            return new WP_Error('b2_auth_failed', 'B2 auth HTTP ' . $code . ': ' . ($body['message'] ?? 'unknown'));
        }

        $auth = [
            'api_url'    => rtrim($body['apiInfo']['storageApi']['apiUrl'] ?? $body['apiUrl'], '/'),
            'auth_token' => $body['authorizationToken'],
        ];

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
            return ['success' => false, 'message' => $url_response->get_error_message()];
        }

        $url_body = json_decode(wp_remote_retrieve_body($url_response), true);
        if (empty($url_body['uploadUrl']) || empty($url_body['authorizationToken'])) {
            return ['success' => false, 'message' => 'Could not get B2 upload URL'];
        }

        $upload_url   = $url_body['uploadUrl'];
        $upload_token = $url_body['authorizationToken'];
        $file_content = file_get_contents($local_path);
        $sha1         = sha1($file_content);
        $file_size    = strlen($file_content);

        // Step 2: upload
        $up_response = wp_remote_post($upload_url, [
            'timeout' => max(60, intval($file_size / (1024 * 50))), // ~50 KB/s min
            'headers' => [
                'Authorization'     => $upload_token,
                'X-Bz-File-Name'    => rawurlencode($remote_name),
                'Content-Type'      => 'b2/x-auto',
                'Content-Length'    => $file_size,
                'X-Bz-Content-Sha1' => $sha1,
            ],
            'body' => $file_content,
        ]);

        // Free memory
        unset($file_content);

        if (is_wp_error($up_response)) {
            return ['success' => false, 'message' => $up_response->get_error_message()];
        }

        $code    = wp_remote_retrieve_response_code($up_response);
        $up_body = json_decode(wp_remote_retrieve_body($up_response), true);

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
    
    private static function get_backup_base_dir() {
        // Use a randomised directory name so the path cannot be guessed.
        $secret = get_option('rpcare_backup_dir_secret');
        if (!$secret) {
            $secret = wp_generate_password(16, false);
            update_option('rpcare_backup_dir_secret', $secret, false);
        }
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/rpcare-backups-' . $secret;
    }

    private static function create_backup_directory() {
        $backup_base_dir = self::get_backup_base_dir();
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
    
    private static function cleanup_old_backups() {
        $backup_base_dir = self::get_backup_base_dir();
        
        if (!is_dir($backup_base_dir)) {
            return;
        }
        
        $backup_dirs = glob($backup_base_dir . '/*', GLOB_ONLYDIR);
        
        // Keep only the 5 most recent backups
        if (count($backup_dirs) > 5) {
            // Sort by modification time
            usort($backup_dirs, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            // Remove old backups
            $to_remove = array_slice($backup_dirs, 5);
            
            foreach ($to_remove as $dir) {
                self::remove_directory($dir);
            }
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
    
    public static function get_backup_status() {
        $last_backup = get_option('rpcare_last_backup', '');
        $backup_frequency = RP_Care_Plan::get_backup_frequency();
        
        $status = [
            'last_backup' => $last_backup,
            'frequency' => $backup_frequency,
            'next_scheduled' => wp_next_scheduled('rpcare_task_backup'),
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
        } elseif (RP_Care_Scheduler::get_environment_type() === 'whm') {
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
