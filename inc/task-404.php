<?php
/**
 * 404 Error Tracking and Management Task
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_404 {
    
    public function __construct() {
        // Hook into template_redirect to log 404s
        add_action('template_redirect', [$this, 'log_404']);
    }
    
    public function log_404() {
        if (!is_404()) {
            return;
        }
        
        global $wpdb;
        
        $url = esc_url_raw($_SERVER['REQUEST_URI'] ?? '');
        $referer = esc_url_raw($_SERVER['HTTP_REFERER'] ?? '');
        $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = RP_Care_Utils::get_client_ip();
        
        // Skip common non-important 404s
        $skip_patterns = [
            '/favicon.ico',
            '/robots.txt',
            '/.well-known/',
            '/wp-content/uploads/',
            '/xmlrpc.php',
            '/wp-cron.php'
        ];
        
        foreach ($skip_patterns as $pattern) {
            if (strpos($url, $pattern) !== false) {
                return;
            }
        }
        
        $table_name = $wpdb->prefix . 'rpcare_404_logs';
        
        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle race conditions
        // This prevents "Duplicate entry" errors when two requests hit the same 404 simultaneously
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table_name (url, hits, first_seen, last_seen, referer, user_agent, ip, status) 
            VALUES (%s, 1, %s, %s, %s, %s, %s, 'pending')
            ON DUPLICATE KEY UPDATE 
                hits = hits + 1, 
                last_seen = VALUES(last_seen)",
            $url,
            current_time('mysql'),
            current_time('mysql'),
            $referer,
            $user_agent,
            $ip
        ));
        
        // Only suggest redirect for new entries (affected_rows = 1 means INSERT, 2 means UPDATE)
        if ($result === 1) {
            // Auto-suggest redirects for new 404s
            $this->suggest_redirect($url);
        }
    }
    
    public static function cleanup($args = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rpcare_404_logs';
        $cleanup_results = [];
        
        // Remove old 404s with low hit counts (older than 90 days, less than 3 hits)
        $old_low_hits = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name 
            WHERE first_seen < DATE_SUB(NOW(), INTERVAL 90 DAY) 
            AND hits < 3"
        ));
        
        $cleanup_results['old_low_hits_removed'] = $old_low_hits;
        
        // Remove resolved 404s older than 30 days
        $resolved_old = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name 
            WHERE status = 'resolved' 
            AND last_seen < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        ));
        
        $cleanup_results['resolved_old_removed'] = $resolved_old;
        
        // Generate suggestions for high-traffic 404s
        $high_traffic_404s = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE status = 'pending' 
            AND hits >= 5 
            ORDER BY hits DESC 
            LIMIT 20"
        ));
        
        $suggestions_generated = 0;
        foreach ($high_traffic_404s as $log) {
            if (self::suggest_redirect($log->url, $log->id)) {
                $suggestions_generated++;
            }
        }
        
        $cleanup_results['suggestions_generated'] = $suggestions_generated;
        
        RP_Care_Utils::log('404_cleanup', 'success', '404 cleanup completed', $cleanup_results);
        
        return $cleanup_results;
    }
    
    private static function suggest_redirect($url, $log_id = null) {
        // Clean and normalize the URL
        $clean_url = self::clean_url($url);
        
        if (empty($clean_url)) {
            return false;
        }
        
        $suggestions = [];
        
        // Try to find similar existing content
        $suggestions = array_merge($suggestions, self::find_similar_posts($clean_url));
        $suggestions = array_merge($suggestions, self::find_similar_pages($clean_url));
        $suggestions = array_merge($suggestions, self::find_category_matches($clean_url));
        
        if (empty($suggestions)) {
            return false;
        }
        
        // Get the best suggestion (highest score)
        usort($suggestions, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        $best_suggestion = $suggestions[0];
        
        if ($best_suggestion['score'] >= 50) { // Minimum confidence threshold
            global $wpdb;
            $table_name = $wpdb->prefix . 'rpcare_404_logs';
            
            if ($log_id) {
                $wpdb->update(
                    $table_name,
                    [
                        'suggested_redirect' => $best_suggestion['url'],
                        'suggestion_score' => $best_suggestion['score'],
                        'status' => 'suggestion_ready'
                    ],
                    ['id' => $log_id]
                );
            } else {
                $wpdb->update(
                    $table_name,
                    [
                        'suggested_redirect' => $best_suggestion['url'],
                        'suggestion_score' => $best_suggestion['score'],
                        'status' => 'suggestion_ready'
                    ],
                    ['url' => $url]
                );
            }
            
            return true;
        }
        
        return false;
    }
    
    private static function clean_url($url) {
        // Remove query parameters and fragments
        $url = strtok($url, '?');
        $url = strtok($url, '#');
        
        // Remove common file extensions that shouldn't be redirected
        $skip_extensions = ['.jpg', '.jpeg', '.png', '.gif', '.pdf', '.doc', '.docx', '.zip'];
        foreach ($skip_extensions as $ext) {
            if (substr($url, -strlen($ext)) === $ext) {
                return '';
            }
        }
        
        // Extract meaningful parts from URL
        $url = trim($url, '/');
        $url = str_replace(['-', '_'], ' ', $url);
        
        return $url;
    }
    
    private static function find_similar_posts($clean_url) {
        global $wpdb;
        
        $suggestions = [];
        $keywords = explode(' ', $clean_url);
        $keywords = array_filter($keywords, function($word) {
            return strlen($word) > 2; // Skip very short words
        });
        
        if (empty($keywords)) {
            return $suggestions;
        }
        
        // Search in post titles and slugs using prepared statements
        $conditions = [];
        $values = [];
        foreach ($keywords as $keyword) {
            $like = '%' . $wpdb->esc_like($keyword) . '%';
            $conditions[] = '(post_title LIKE %s OR post_name LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }
        
        if (!empty($conditions)) {
            $search_query = $wpdb->prepare(
                "SELECT ID, post_title, post_name, post_type 
                FROM {$wpdb->posts} 
                WHERE post_status = 'publish' 
                AND post_type IN ('post', 'page', 'product')
                AND (" . implode(' OR ', $conditions) . ") LIMIT 10",
                $values
            );
            
            $results = $wpdb->get_results($search_query);
            
            foreach ($results as $result) {
                $score = self::calculate_similarity_score($clean_url, $result->post_title . ' ' . $result->post_name);
                
                if ($score > 30) { // Minimum similarity threshold
                    $suggestions[] = [
                        'url' => get_permalink($result->ID),
                        'title' => $result->post_title,
                        'type' => $result->post_type,
                        'score' => $score
                    ];
                }
            }
        }
        
        return $suggestions;
    }
    
    private static function find_similar_pages($clean_url) {
        // Similar to find_similar_posts but focuses on pages
        return self::find_similar_posts($clean_url);
    }
    
    private static function find_category_matches($clean_url) {
        global $wpdb;
        
        $suggestions = [];
        $keywords = explode(' ', $clean_url);
        
        // Search in category names
        foreach ($keywords as $keyword) {
            if (strlen($keyword) < 3) continue;
            
            $categories = get_categories([
                'search' => $keyword,
                'number' => 5
            ]);
            
            foreach ($categories as $category) {
                $score = self::calculate_similarity_score($clean_url, $category->name . ' ' . $category->slug);
                
                if ($score > 40) {
                    $suggestions[] = [
                        'url' => get_category_link($category->term_id),
                        'title' => $category->name,
                        'type' => 'category',
                        'score' => $score * 0.8 // Slightly lower score for categories
                    ];
                }
            }
        }
        
        return $suggestions;
    }
    
    private static function calculate_similarity_score($str1, $str2) {
        $str1 = strtolower($str1);
        $str2 = strtolower($str2);
        
        // Calculate Levenshtein distance
        $distance = levenshtein($str1, $str2);
        $max_length = max(strlen($str1), strlen($str2));
        
        if ($max_length === 0) {
            return 100;
        }
        
        $similarity = (1 - $distance / $max_length) * 100;
        
        // Boost score for exact word matches
        $words1 = explode(' ', $str1);
        $words2 = explode(' ', $str2);
        $common_words = array_intersect($words1, $words2);
        
        if (!empty($common_words)) {
            $word_match_bonus = (count($common_words) / max(count($words1), count($words2))) * 30;
            $similarity += $word_match_bonus;
        }
        
        return min(100, max(0, $similarity));
    }
    
    public static function get_404_stats() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rpcare_404_logs';
        
        $stats = [
            'total_404s' => 0,
            'total_hits' => 0,
            'unique_urls' => 0,
            'pending_suggestions' => 0,
            'resolved' => 0,
            'top_404s' => [],
            'recent_404s' => []
        ];
        
        // Basic stats
        $basic_stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as unique_urls,
                SUM(hits) as total_hits,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'suggestion_ready' THEN 1 END) as pending_suggestions,
                COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved
            FROM $table_name"
        );
        
        if ($basic_stats) {
            $stats['unique_urls'] = (int) $basic_stats->unique_urls;
            $stats['total_hits'] = (int) $basic_stats->total_hits;
            $stats['pending_suggestions'] = (int) $basic_stats->pending_suggestions;
            $stats['resolved'] = (int) $basic_stats->resolved;
        }
        
        // Top 404s by hits
        $stats['top_404s'] = $wpdb->get_results(
            "SELECT url, hits, last_seen, status, suggested_redirect 
            FROM $table_name 
            ORDER BY hits DESC 
            LIMIT 10"
        );
        
        // Recent 404s
        $stats['recent_404s'] = $wpdb->get_results(
            "SELECT url, hits, first_seen, status 
            FROM $table_name 
            ORDER BY first_seen DESC 
            LIMIT 10"
        );
        
        return $stats;
    }
    
    public static function apply_redirect($url, $target_url) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rpcare_404_logs';
        
        // Try to apply via Redirection plugin first
        if (self::apply_redirection_plugin($url, $target_url)) {
            $method = 'redirection_plugin';
        } elseif (self::apply_htaccess_redirect($url, $target_url)) {
            $method = 'htaccess';
        } else {
            return false;
        }
        
        // Mark as resolved in our logs
        $wpdb->update(
            $table_name,
            [
                'status' => 'resolved',
                'applied_redirect' => $target_url,
                'redirect_method' => $method,
                'resolved_at' => current_time('mysql')
            ],
            ['url' => $url]
        );
        
        RP_Care_Utils::log('404_redirect', 'success', "Applied redirect: $url -> $target_url", [
            'method' => $method
        ]);
        
        return true;
    }
    
    private static function apply_redirection_plugin($from, $to) {
        if (!class_exists('Red_Item')) {
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
                    'title' => 'Auto-created by Replanta Care 404 cleanup',
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
    
    private static function apply_htaccess_redirect($from, $to) {
        $htaccess_file = ABSPATH . '.htaccess';
        
        if (!is_writable($htaccess_file)) {
            return false;
        }
        
        $redirect_rule = "Redirect 301 " . trim($from, '/') . " " . $to . "\n";
        $htaccess_content = file_get_contents($htaccess_file);
        
        // Check if redirect already exists
        if (strpos($htaccess_content, trim($from, '/')) !== false) {
            return false;
        }
        
        // Add redirect after the opening RewriteEngine directive or at the beginning
        if (strpos($htaccess_content, 'RewriteEngine On') !== false) {
            $htaccess_content = str_replace(
                'RewriteEngine On',
                'RewriteEngine On' . "\n" . $redirect_rule,
                $htaccess_content
            );
        } else {
            $htaccess_content = $redirect_rule . $htaccess_content;
        }
        
        return file_put_contents($htaccess_file, $htaccess_content) !== false;
    }
    
    public static function bulk_apply_suggestions($limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rpcare_404_logs';
        
        // Get high-confidence suggestions
        $suggestions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE status = 'suggestion_ready' 
            AND suggestion_score >= 70 
            AND hits >= 10 
            ORDER BY hits DESC, suggestion_score DESC 
            LIMIT %d",
            $limit
        ));
        
        $applied = 0;
        $errors = [];
        
        foreach ($suggestions as $suggestion) {
            if (self::apply_redirect($suggestion->url, $suggestion->suggested_redirect)) {
                $applied++;
            } else {
                $errors[] = $suggestion->url;
            }
        }
        
        RP_Care_Utils::log('404_bulk_apply', 'info', "Bulk applied $applied redirects", [
            'applied' => $applied,
            'errors' => $errors
        ]);
        
        return [
            'applied' => $applied,
            'errors' => $errors,
            'total_processed' => count($suggestions)
        ];
    }
}
