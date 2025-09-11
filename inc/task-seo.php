<?php
/**
 * SEO Task - Meta optimization and SEO improvements
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_SEO {
    
    public static function run_basic_review($args = []) {
        $results = [
            'metas_processed' => 0,
            'missing_metas_found' => 0,
            'metas_added' => 0,
            'sitemap_checked' => false,
            'robots_checked' => false
        ];
        
        $results = array_merge($results, self::process_missing_metas());
        $results['sitemap_checked'] = self::check_sitemap();
        $results['robots_checked'] = self::check_robots_txt();
        
        RP_Care_Utils::log('seo_basic_review', 'success', 'Basic SEO review completed', $results);
        
        return $results;
    }
    
    public static function run_monthly_review($args = []) {
        $results = self::run_basic_review($args);
        
        // Additional monthly tasks
        $results['og_tags_processed'] = self::process_og_tags();
        $results['images_alt_checked'] = self::check_image_alt_tags();
        $results['internal_links_checked'] = self::check_internal_linking();
        
        RP_Care_Utils::log('seo_monthly_review', 'success', 'Monthly SEO review completed', $results);
        
        return $results;
    }
    
    public static function run_quarterly_audit($args = []) {
        $results = self::run_monthly_review($args);
        
        // Comprehensive quarterly audit
        $results['schema_audit'] = self::audit_schema_markup();
        $results['pagespeed_audit'] = self::audit_pagespeed();
        $results['technical_seo_audit'] = self::audit_technical_seo();
        $results['content_audit'] = self::audit_content_optimization();
        
        RP_Care_Utils::log('seo_quarterly_audit', 'success', 'Quarterly SEO audit completed', $results);
        
        return $results;
    }
    
    private static function process_missing_metas() {
        $seo_plugin = self::detect_seo_plugin();
        $processed = 0;
        $missing = 0;
        $added = 0;
        
        // Get posts that might need meta tags
        $posts = get_posts([
            'post_type' => ['post', 'page', 'product'],
            'posts_per_page' => 500,
            'post_status' => 'publish',
            'fields' => 'ids'
        ]);
        
        foreach ($posts as $post_id) {
            $processed++;
            
            $title_meta = self::get_meta_title($post_id, $seo_plugin);
            $desc_meta = self::get_meta_description($post_id, $seo_plugin);
            
            if (empty($title_meta)) {
                $missing++;
                if (self::set_meta_title($post_id, $seo_plugin)) {
                    $added++;
                }
            }
            
            if (empty($desc_meta)) {
                $missing++;
                if (self::set_meta_description($post_id, $seo_plugin)) {
                    $added++;
                }
            }
        }
        
        return [
            'metas_processed' => $processed,
            'missing_metas_found' => $missing,
            'metas_added' => $added,
            'seo_plugin' => $seo_plugin
        ];
    }
    
    private static function detect_seo_plugin() {
        $seo_plugins = [
            'yoast' => 'wordpress-seo/wp-seo.php',
            'rankmath' => 'seo-by-rank-math/rank-math.php',
            'aioseo' => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
            'seopress' => 'wp-seopress/seopress.php'
        ];
        
        $active_plugins = get_option('active_plugins', []);
        
        foreach ($seo_plugins as $plugin_slug => $plugin_file) {
            if (in_array($plugin_file, $active_plugins)) {
                return $plugin_slug;
            }
        }
        
        return 'none';
    }
    
    private static function get_meta_title($post_id, $seo_plugin) {
        switch ($seo_plugin) {
            case 'yoast':
                return get_post_meta($post_id, '_yoast_wpseo_title', true);
                
            case 'rankmath':
                return get_post_meta($post_id, 'rank_math_title', true);
                
            case 'aioseo':
                return get_post_meta($post_id, '_aioseo_title', true);
                
            case 'seopress':
                return get_post_meta($post_id, '_seopress_titles_title', true);
                
            default:
                return '';
        }
    }
    
    private static function get_meta_description($post_id, $seo_plugin) {
        switch ($seo_plugin) {
            case 'yoast':
                return get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
                
            case 'rankmath':
                return get_post_meta($post_id, 'rank_math_description', true);
                
            case 'aioseo':
                return get_post_meta($post_id, '_aioseo_description', true);
                
            case 'seopress':
                return get_post_meta($post_id, '_seopress_titles_desc', true);
                
            default:
                return '';
        }
    }
    
    private static function set_meta_title($post_id, $seo_plugin) {
        $post = get_post($post_id);
        if (!$post) return false;
        
        $title = $post->post_title . ' | ' . get_bloginfo('name');
        
        switch ($seo_plugin) {
            case 'yoast':
                return update_post_meta($post_id, '_yoast_wpseo_title', $title);
                
            case 'rankmath':
                return update_post_meta($post_id, 'rank_math_title', $title);
                
            case 'aioseo':
                return update_post_meta($post_id, '_aioseo_title', $title);
                
            case 'seopress':
                return update_post_meta($post_id, '_seopress_titles_title', $title);
                
            default:
                // No SEO plugin, can't set meta title
                return false;
        }
    }
    
    private static function set_meta_description($post_id, $seo_plugin) {
        $post = get_post($post_id);
        if (!$post) return false;
        
        $description = self::generate_excerpt($post, 155);
        
        switch ($seo_plugin) {
            case 'yoast':
                return update_post_meta($post_id, '_yoast_wpseo_metadesc', $description);
                
            case 'rankmath':
                return update_post_meta($post_id, 'rank_math_description', $description);
                
            case 'aioseo':
                return update_post_meta($post_id, '_aioseo_description', $description);
                
            case 'seopress':
                return update_post_meta($post_id, '_seopress_titles_desc', $description);
                
            default:
                return false;
        }
    }
    
    private static function generate_excerpt($post, $length = 155) {
        $content = $post->post_excerpt ? $post->post_excerpt : $post->post_content;
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        
        if (strlen($content) <= $length) {
            return trim($content);
        }
        
        $content = substr($content, 0, $length);
        $last_space = strrpos($content, ' ');
        
        if ($last_space !== false) {
            $content = substr($content, 0, $last_space);
        }
        
        return trim($content) . '...';
    }
    
    private static function process_og_tags() {
        $posts = get_posts([
            'post_type' => ['post', 'page', 'product'],
            'posts_per_page' => 100,
            'post_status' => 'publish',
            'fields' => 'ids'
        ]);
        
        $processed = 0;
        
        foreach ($posts as $post_id) {
            $og_image = get_post_meta($post_id, '_og_image', true);
            
            if (empty($og_image)) {
                $thumbnail_id = get_post_thumbnail_id($post_id);
                if ($thumbnail_id) {
                    $image_url = wp_get_attachment_image_url($thumbnail_id, 'large');
                    if ($image_url) {
                        update_post_meta($post_id, '_og_image', $image_url);
                        $processed++;
                    }
                }
            }
        }
        
        return $processed;
    }
    
    private static function check_image_alt_tags() {
        $images_without_alt = 0;
        $images_processed = 0;
        
        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => 200,
            'post_status' => 'inherit'
        ]);
        
        foreach ($attachments as $attachment) {
            $images_processed++;
            $alt_text = get_post_meta($attachment->ID, '_wp_attachment_image_alt', true);
            
            if (empty($alt_text)) {
                $images_without_alt++;
                
                // Auto-generate alt text from filename or title
                $alt_suggestion = $attachment->post_title;
                if (empty($alt_suggestion)) {
                    $alt_suggestion = pathinfo($attachment->post_name, PATHINFO_FILENAME);
                    $alt_suggestion = str_replace(['-', '_'], ' ', $alt_suggestion);
                }
                
                if (!empty($alt_suggestion)) {
                    update_post_meta($attachment->ID, '_wp_attachment_image_alt', ucfirst($alt_suggestion));
                }
            }
        }
        
        return [
            'total_images' => $images_processed,
            'missing_alt' => $images_without_alt,
            'completion_rate' => $images_processed > 0 ? round((($images_processed - $images_without_alt) / $images_processed) * 100, 2) : 0
        ];
    }
    
    private static function check_internal_linking() {
        global $wpdb;
        
        // Find posts with few internal links
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'posts_per_page' => 50,
            'post_status' => 'publish'
        ]);
        
        $low_internal_links = [];
        
        foreach ($posts as $post) {
            $content = $post->post_content;
            $site_url = get_site_url();
            
            // Count internal links
            preg_match_all('/<a[^>]+href=["\'](' . preg_quote($site_url, '/') . '[^"\']*)["\'][^>]*>/i', $content, $matches);
            $internal_link_count = count($matches[1]);
            
            if ($internal_link_count < 2 && str_word_count(wp_strip_all_tags($content)) > 300) {
                $low_internal_links[] = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'internal_links' => $internal_link_count,
                    'word_count' => str_word_count(wp_strip_all_tags($content))
                ];
            }
        }
        
        return [
            'posts_checked' => count($posts),
            'low_internal_links' => count($low_internal_links),
            'suggestions' => array_slice($low_internal_links, 0, 10)
        ];
    }
    
    private static function check_sitemap() {
        $sitemap_urls = [
            '/sitemap.xml',
            '/sitemap_index.xml',
            '/wp-sitemap.xml',
            '/sitemap-index.xml'
        ];
        
        $site_url = get_site_url();
        
        foreach ($sitemap_urls as $sitemap_url) {
            $response = wp_remote_head($site_url . $sitemap_url);
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                return [
                    'found' => true,
                    'url' => $site_url . $sitemap_url,
                    'status' => 'accessible'
                ];
            }
        }
        
        return [
            'found' => false,
            'recommendation' => 'No sitemap found. Consider enabling XML sitemaps.'
        ];
    }
    
    private static function check_robots_txt() {
        $robots_url = get_site_url() . '/robots.txt';
        $response = wp_remote_get($robots_url);
        
        if (is_wp_error($response)) {
            return [
                'accessible' => false,
                'error' => $response->get_error_message()
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $content = wp_remote_retrieve_body($response);
        
        $analysis = [
            'accessible' => $status_code === 200,
            'has_sitemap' => strpos($content, 'sitemap') !== false,
            'blocks_important' => false,
            'content_length' => strlen($content)
        ];
        
        // Check if important areas are blocked
        $important_blocks = ['/wp-admin', '/wp-content', '/wp-includes'];
        foreach ($important_blocks as $block) {
            if (strpos($content, "Disallow: $block") !== false) {
                $analysis['blocks_important'] = true;
                break;
            }
        }
        
        return $analysis;
    }
    
    private static function audit_schema_markup() {
        // Check for common schema types
        $schema_types = ['Organization', 'WebSite', 'Article', 'Product', 'LocalBusiness'];
        $found_schemas = [];
        
        // Get homepage content
        $homepage_content = file_get_contents(get_home_url());
        
        foreach ($schema_types as $type) {
            if (strpos($homepage_content, $type) !== false) {
                $found_schemas[] = $type;
            }
        }
        
        return [
            'schemas_found' => $found_schemas,
            'recommendation' => empty($found_schemas) ? 'Consider adding schema markup' : 'Schema markup detected'
        ];
    }
    
    private static function audit_pagespeed() {
        // Basic pagespeed indicators
        $indicators = [
            'gzip_enabled' => function_exists('gzencode'),
            'browser_caching' => self::check_browser_caching(),
            'image_optimization' => self::check_image_optimization(),
            'minification' => self::check_minification()
        ];
        
        $score = array_sum($indicators) * 25; // Each indicator is worth 25 points
        
        return [
            'score' => $score,
            'indicators' => $indicators,
            'recommendation' => $score < 75 ? 'Consider optimizing page speed' : 'Page speed looks good'
        ];
    }
    
    private static function audit_technical_seo() {
        return [
            'ssl_enabled' => is_ssl(),
            'www_redirect' => self::check_www_redirect(),
            'canonical_tags' => self::check_canonical_tags(),
            'meta_robots' => self::check_meta_robots(),
            'hreflang' => self::check_hreflang()
        ];
    }
    
    private static function audit_content_optimization() {
        global $wpdb;
        
        // Get recent posts for analysis
        $posts = get_posts([
            'posts_per_page' => 20,
            'post_status' => 'publish'
        ]);
        
        $analysis = [
            'avg_word_count' => 0,
            'posts_with_h2' => 0,
            'posts_with_images' => 0,
            'total_posts' => count($posts)
        ];
        
        $total_words = 0;
        
        foreach ($posts as $post) {
            $content = wp_strip_all_tags($post->post_content);
            $word_count = str_word_count($content);
            $total_words += $word_count;
            
            if (strpos($post->post_content, '<h2') !== false) {
                $analysis['posts_with_h2']++;
            }
            
            if (has_post_thumbnail($post->ID) || strpos($post->post_content, '<img') !== false) {
                $analysis['posts_with_images']++;
            }
        }
        
        $analysis['avg_word_count'] = $analysis['total_posts'] > 0 ? round($total_words / $analysis['total_posts']) : 0;
        
        return $analysis;
    }
    
    private static function check_browser_caching() {
        $htaccess_file = ABSPATH . '.htaccess';
        if (!is_readable($htaccess_file)) {
            return false;
        }
        
        $content = file_get_contents($htaccess_file);
        return strpos($content, 'ExpiresActive') !== false || strpos($content, 'Cache-Control') !== false;
    }
    
    private static function check_image_optimization() {
        // Check if any image optimization plugins are active
        $image_plugins = [
            'smush/wp-smush.php',
            'shortpixel-image-optimiser/wp-shortpixel.php',
            'ewww-image-optimizer/ewww-image-optimizer.php'
        ];
        
        $active_plugins = get_option('active_plugins', []);
        
        foreach ($image_plugins as $plugin) {
            if (in_array($plugin, $active_plugins)) {
                return true;
            }
        }
        
        return false;
    }
    
    private static function check_minification() {
        // Check for minification plugins
        $minification_plugins = [
            'autoptimize/autoptimize.php',
            'wp-rocket/wp-rocket.php',
            'w3-total-cache/w3-total-cache.php'
        ];
        
        $active_plugins = get_option('active_plugins', []);
        
        foreach ($minification_plugins as $plugin) {
            if (in_array($plugin, $active_plugins)) {
                return true;
            }
        }
        
        return false;
    }
    
    private static function check_www_redirect() {
        $site_url = get_site_url();
        $has_www = strpos($site_url, 'www.') !== false;
        
        $test_url = $has_www ? str_replace('www.', '', $site_url) : str_replace('://', '://www.', $site_url);
        
        $response = wp_remote_head($test_url, ['timeout' => 10]);
        
        if (is_wp_error($response)) {
            return 'error';
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        return in_array($status_code, [301, 302]);
    }
    
    private static function check_canonical_tags() {
        $homepage = get_home_url();
        $response = wp_remote_get($homepage);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $content = wp_remote_retrieve_body($response);
        return strpos($content, '<link rel="canonical"') !== false;
    }
    
    private static function check_meta_robots() {
        return !get_option('blog_public') ? 'noindex' : 'index';
    }
    
    private static function check_hreflang() {
        // Check if WPML or Polylang is active
        $multilang_plugins = [
            'sitepress-multilingual-cms/sitepress.php', // WPML
            'polylang/polylang.php'
        ];
        
        $active_plugins = get_option('active_plugins', []);
        
        foreach ($multilang_plugins as $plugin) {
            if (in_array($plugin, $active_plugins)) {
                return true;
            }
        }
        
        return false;
    }
}
