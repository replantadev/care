/**
 * Security Task Implementation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Security {
    
    public static function run($args = []) {
        $results = [];
        
        // Check WordPress version
        $results['wp_version'] = self::check_wp_version();
        
        // Check plugin vulnerabilities
        $results['plugin_vulnerabilities'] = self::check_plugin_vulnerabilities();
        
        // Check file permissions
        $results['file_permissions'] = self::check_file_permissions();
        
        // Check for suspicious files
        $results['suspicious_files'] = self::scan_suspicious_files();
        
        // Check user accounts
        $results['user_security'] = self::check_user_security();
        
        // Check htaccess security
        $results['htaccess_security'] = self::check_htaccess_security();
        
        // Calculate overall security score
        $security_score = self::calculate_security_score($results);
        
        $overall_result = [
            'success' => $security_score >= 70,
            'message' => sprintf('Escaneo de seguridad completado. Puntuación: %d/100', $security_score),
            'security_score' => $security_score,
            'details' => $results,
            'recommendations' => self::get_security_recommendations($results)
        ];
        
        // Log the security scan
        RP_Care_Utils::log('security', $overall_result['success'] ? 'success' : 'warning', $overall_result['message'], $overall_result);
        
        // Update security score option
        update_option('rpcare_security_score', $security_score);
        update_option('rpcare_last_security_scan', current_time('mysql'));
        
        return $overall_result;
    }
    
    private static function check_wp_version() {
        $current_version = get_bloginfo('version');
        $latest_version = self::get_latest_wp_version();
        
        $is_updated = version_compare($current_version, $latest_version, '>=');
        
        return [
            'status' => $is_updated ? 'good' : 'warning',
            'current_version' => $current_version,
            'latest_version' => $latest_version,
            'message' => $is_updated ? 'WordPress está actualizado' : 'WordPress necesita actualización'
        ];
    }
    
    private static function get_latest_wp_version() {
        $response = wp_remote_get('https://api.wordpress.org/core/version-check/1.7/');
        
        if (is_wp_error($response)) {
            return get_bloginfo('version'); // Fallback to current version
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['offers'][0]['version'])) {
            return $data['offers'][0]['version'];
        }
        
        return get_bloginfo('version');
    }
    
    private static function check_plugin_vulnerabilities() {
        $plugins = get_plugins();
        $vulnerabilities = [];
        $checked = 0;
        
        foreach ($plugins as $plugin_file => $plugin_data) {
            if (is_plugin_active($plugin_file)) {
                $vuln_check = self::check_plugin_vuln($plugin_data);
                if ($vuln_check['has_vulnerability']) {
                    $vulnerabilities[] = $vuln_check;
                }
                $checked++;
            }
        }
        
        return [
            'status' => empty($vulnerabilities) ? 'good' : 'critical',
            'plugins_checked' => $checked,
            'vulnerabilities_found' => count($vulnerabilities),
            'vulnerabilities' => $vulnerabilities,
            'message' => empty($vulnerabilities) ? 
                'No se encontraron vulnerabilidades conocidas' : 
                sprintf('Se encontraron %d vulnerabilidades', count($vulnerabilities))
        ];
    }
    
    private static function check_plugin_vuln($plugin_data) {
        // This is a simplified check - in production you'd integrate with vulnerability databases
        $vulnerable_plugins = [
            'revslider' => ['versions' => ['< 6.5.0'], 'severity' => 'high'],
            'wp-file-manager' => ['versions' => ['< 6.7'], 'severity' => 'critical'],
            'elementor' => ['versions' => ['< 3.1.4'], 'severity' => 'medium']
        ];
        
        $plugin_slug = dirname(plugin_basename($plugin_data['TextDomain']));
        
        if (isset($vulnerable_plugins[$plugin_slug])) {
            $vuln_info = $vulnerable_plugins[$plugin_slug];
            
            foreach ($vuln_info['versions'] as $version_constraint) {
                if (self::version_matches_constraint($plugin_data['Version'], $version_constraint)) {
                    return [
                        'has_vulnerability' => true,
                        'plugin_name' => $plugin_data['Name'],
                        'plugin_version' => $plugin_data['Version'],
                        'severity' => $vuln_info['severity'],
                        'constraint' => $version_constraint
                    ];
                }
            }
        }
        
        return ['has_vulnerability' => false];
    }
    
    private static function version_matches_constraint($version, $constraint) {
        // Simple version constraint checking
        if (strpos($constraint, '<') === 0) {
            $target_version = trim(substr($constraint, 1));
            return version_compare($version, $target_version, '<');
        }
        
        return false;
    }
    
    private static function check_file_permissions() {
        $checks = [];
        $issues = 0;
        
        // Check wp-config.php permissions
        $wp_config_perms = self::get_file_permissions(ABSPATH . 'wp-config.php');
        if ($wp_config_perms && $wp_config_perms > 644) {
            $checks[] = [
                'file' => 'wp-config.php',
                'current_perms' => decoct($wp_config_perms),
                'recommended_perms' => '644',
                'status' => 'warning'
            ];
            $issues++;
        }
        
        // Check .htaccess permissions
        $htaccess_file = ABSPATH . '.htaccess';
        if (file_exists($htaccess_file)) {
            $htaccess_perms = self::get_file_permissions($htaccess_file);
            if ($htaccess_perms && $htaccess_perms > 644) {
                $checks[] = [
                    'file' => '.htaccess',
                    'current_perms' => decoct($htaccess_perms),
                    'recommended_perms' => '644',
                    'status' => 'warning'
                ];
                $issues++;
            }
        }
        
        // Check uploads directory
        $upload_dir = wp_upload_dir();
        $upload_perms = self::get_file_permissions($upload_dir['basedir']);
        if ($upload_perms && $upload_perms > 755) {
            $checks[] = [
                'file' => 'uploads directory',
                'current_perms' => decoct($upload_perms),
                'recommended_perms' => '755',
                'status' => 'info'
            ];
        }
        
        return [
            'status' => $issues === 0 ? 'good' : 'warning',
            'issues_found' => $issues,
            'checks' => $checks,
            'message' => $issues === 0 ? 
                'Permisos de archivos correctos' : 
                sprintf('%d archivos con permisos incorrectos', $issues)
        ];
    }
    
    private static function get_file_permissions($file) {
        if (!file_exists($file)) {
            return false;
        }
        
        return fileperms($file) & 0777;
    }
    
    private static function scan_suspicious_files() {
        $suspicious_patterns = [
            'eval(',
            'base64_decode(',
            'shell_exec(',
            'system(',
            'exec(',
            'passthru(',
            'file_get_contents(',
            'curl_exec('
        ];
        
        $suspicious_files = [];
        $scanned_files = 0;
        $max_files = 100; // Limit to avoid timeouts
        
        $upload_dir = wp_upload_dir();
        $scan_dirs = [
            $upload_dir['basedir'],
            WP_CONTENT_DIR . '/themes',
            WP_CONTENT_DIR . '/plugins'
        ];
        
        foreach ($scan_dirs as $dir) {
            if (is_dir($dir)) {
                $files = self::scan_directory_for_php_files($dir, $max_files - $scanned_files);
                
                foreach ($files as $file) {
                    if ($scanned_files >= $max_files) break;
                    
                    $content = file_get_contents($file);
                    if ($content !== false) {
                        foreach ($suspicious_patterns as $pattern) {
                            if (strpos($content, $pattern) !== false) {
                                $suspicious_files[] = [
                                    'file' => str_replace(ABSPATH, '', $file),
                                    'pattern' => $pattern,
                                    'size' => filesize($file)
                                ];
                                break;
                            }
                        }
                    }
                    $scanned_files++;
                }
            }
        }
        
        return [
            'status' => empty($suspicious_files) ? 'good' : 'critical',
            'files_scanned' => $scanned_files,
            'suspicious_files_found' => count($suspicious_files),
            'suspicious_files' => $suspicious_files,
            'message' => empty($suspicious_files) ? 
                'No se encontraron archivos sospechosos' : 
                sprintf('Se encontraron %d archivos sospechosos', count($suspicious_files))
        ];
    }
    
    private static function scan_directory_for_php_files($dir, $max_files) {
        $php_files = [];
        
        if (!is_dir($dir)) {
            return $php_files;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if (count($php_files) >= $max_files) {
                break;
            }
            
            if ($file->isFile() && $file->getExtension() === 'php') {
                $php_files[] = $file->getPathname();
            }
        }
        
        return $php_files;
    }
    
    private static function check_user_security() {
        $issues = [];
        
        // Check for admin user with username 'admin'
        $admin_user = get_user_by('login', 'admin');
        if ($admin_user) {
            $issues[] = [
                'type' => 'weak_username',
                'message' => 'Usuario "admin" encontrado - considera cambiarlo',
                'severity' => 'medium'
            ];
        }
        
        // Check for users with weak passwords (simplified check)
        $users = get_users(['role' => 'administrator']);
        foreach ($users as $user) {
            // This is a very basic check - in reality you'd need more sophisticated password analysis
            if (strlen($user->user_login) < 4) {
                $issues[] = [
                    'type' => 'weak_username',
                    'message' => 'Nombre de usuario muy corto: ' . $user->user_login,
                    'severity' => 'low'
                ];
            }
        }
        
        return [
            'status' => empty($issues) ? 'good' : 'warning',
            'issues_found' => count($issues),
            'issues' => $issues,
            'admin_users_count' => count($users),
            'message' => empty($issues) ? 
                'Configuración de usuarios segura' : 
                sprintf('%d problemas de seguridad de usuarios', count($issues))
        ];
    }
    
    private static function check_htaccess_security() {
        $htaccess_file = ABSPATH . '.htaccess';
        
        if (!file_exists($htaccess_file)) {
            return [
                'status' => 'info',
                'message' => 'Archivo .htaccess no encontrado',
                'has_security_rules' => false
            ];
        }
        
        $content = file_get_contents($htaccess_file);
        $security_rules = [
            'disable_directory_browsing' => strpos($content, 'Options -Indexes') !== false,
            'block_sensitive_files' => strpos($content, 'wp-config.php') !== false,
            'limit_file_uploads' => strpos($content, 'LimitRequestBody') !== false,
            'hide_wp_version' => strpos($content, 'remove_action') !== false
        ];
        
        $active_rules = count(array_filter($security_rules));
        $total_rules = count($security_rules);
        
        return [
            'status' => $active_rules >= 2 ? 'good' : 'warning',
            'active_security_rules' => $active_rules,
            'total_possible_rules' => $total_rules,
            'rules' => $security_rules,
            'message' => sprintf('%d de %d reglas de seguridad activas', $active_rules, $total_rules)
        ];
    }
    
    private static function calculate_security_score($results) {
        $score = 100;
        $weights = [
            'wp_version' => 20,
            'plugin_vulnerabilities' => 30,
            'file_permissions' => 15,
            'suspicious_files' => 25,
            'user_security' => 5,
            'htaccess_security' => 5
        ];
        
        foreach ($results as $check => $result) {
            if (!isset($weights[$check])) continue;
            
            $weight = $weights[$check];
            
            switch ($result['status']) {
                case 'critical':
                    $score -= $weight;
                    break;
                case 'warning':
                    $score -= $weight * 0.5;
                    break;
                case 'info':
                    $score -= $weight * 0.1;
                    break;
                case 'good':
                    // No penalty
                    break;
            }
        }
        
        return max(0, min(100, $score));
    }
    
    private static function get_security_recommendations($results) {
        $recommendations = [];
        
        foreach ($results as $check => $result) {
            switch ($check) {
                case 'wp_version':
                    if ($result['status'] === 'warning') {
                        $recommendations[] = 'Actualiza WordPress a la última versión';
                    }
                    break;
                    
                case 'plugin_vulnerabilities':
                    if ($result['status'] === 'critical') {
                        $recommendations[] = 'Actualiza los plugins con vulnerabilidades inmediatamente';
                    }
                    break;
                    
                case 'file_permissions':
                    if ($result['status'] === 'warning') {
                        $recommendations[] = 'Corrige los permisos de archivos sensibles';
                    }
                    break;
                    
                case 'suspicious_files':
                    if ($result['status'] === 'critical') {
                        $recommendations[] = 'Revisa y elimina archivos sospechosos detectados';
                    }
                    break;
                    
                case 'user_security':
                    if ($result['status'] === 'warning') {
                        $recommendations[] = 'Mejora la seguridad de las cuentas de usuario';
                    }
                    break;
                    
                case 'htaccess_security':
                    if ($result['status'] === 'warning') {
                        $recommendations[] = 'Añade más reglas de seguridad al archivo .htaccess';
                    }
                    break;
            }
        }
        
        if (empty($recommendations)) {
            $recommendations[] = 'Tu sitio web tiene una buena configuración de seguridad';
        }
        
        return $recommendations;
    }
    
    public static function get_security_status() {
        $last_scan = get_option('rpcare_last_security_scan', '');
        $security_score = get_option('rpcare_security_score', 0);
        
        return [
            'last_scan' => $last_scan,
            'security_score' => $security_score,
            'next_scheduled' => wp_next_scheduled('rpcare_task_security'),
            'status' => $security_score >= 80 ? 'good' : ($security_score >= 60 ? 'warning' : 'critical')
        ];
    }
}
