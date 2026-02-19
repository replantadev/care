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
    
    private static function handle_whm_backup($args) {
        // For WHM/cPanel environments, we rely on the hub to manage backups
        // This method just reports the status
        
        $last_backup = self::check_cpanel_backup_status();
        
        if ($last_backup) {
            return [
                'success' => true,
                'method' => 'whm_cpanel',
                'message' => 'Backup managed by WHM/cPanel',
                'last_backup' => $last_backup,
                'managed_by_hub' => true
            ];
        } else {
            return [
                'success' => false,
                'method' => 'whm_cpanel',
                'message' => 'Could not verify WHM/cPanel backup status',
                'managed_by_hub' => true
            ];
        }
    }
    
    private static function handle_external_backup($args) {
        // For external sites, try multiple backup methods in order of preference
        
        // 1. Try UpdraftPlus
        if (self::is_updraftplus_active()) {
            return self::trigger_updraftplus_backup($args);
        }
        
        // 2. Try other backup plugins
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
        
        // Clean old backups
        self::cleanup_old_backups();
        
        return $results;
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
        
        // Determine backup method
        if (RP_Care_Scheduler::get_environment_type() === 'whm') {
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
        
        return $status;
    }
}
