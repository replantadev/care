<?php
// Script para corregir errores de funciones no definidas

// Archivos a corregir
$files = [
    'inc/integrations-cache.php',
    'inc/integrations-backup.php'
];

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) continue;
    
    $content = file_get_contents($path);
    
    // Correcciones comunes
    $replacements = [
        // Funciones directas a call_user_func
        '/(?<!call_user_func\(\')rocket_clean_domain\(\)/' => "call_user_func('rocket_clean_domain')",
        '/(?<!call_user_func\(\')rocket_clean_minify\(\)/' => "call_user_func('rocket_clean_minify')",
        '/(?<!call_user_func\(\')rocket_clean_cache_busting\(\)/' => "call_user_func('rocket_clean_cache_busting')",
        '/(?<!call_user_func\(\')w3tc_pgcache_flush\(\)/' => "call_user_func('w3tc_pgcache_flush')",
        '/(?<!call_user_func\(\')w3tc_dbcache_flush\(\)/' => "call_user_func('w3tc_dbcache_flush')",
        '/(?<!call_user_func\(\')w3tc_objectcache_flush\(\)/' => "call_user_func('w3tc_objectcache_flush')",
        '/(?<!call_user_func\(\')w3tc_minify_flush\(\)/' => "call_user_func('w3tc_minify_flush')",
        '/(?<!call_user_func\(\')wp_cache_clear_cache\(\)/' => "call_user_func('wp_cache_clear_cache')",
        '/(?<!call_user_func\(\')wphb_clear_page_cache\(\)/' => "call_user_func('wphb_clear_page_cache')",
        '/(?<!call_user_func\(\')sg_cachepress_purge_cache\(\)/' => "call_user_func('sg_cachepress_purge_cache')",
        '/(?<!call_user_func\(\')wpo_cache_flush\(\)/' => "call_user_func('wpo_cache_flush')",
        '/(?<!call_user_func\(\')breeze_clear_all_cache\(\)/' => "call_user_func('breeze_clear_all_cache')",
        
        // Clases estáticas a call_user_func
        '/LiteSpeed_Cache_API::purge_all\(\)/' => "call_user_func(array('LiteSpeed_Cache_API', 'purge_all'))",
        '/Cachify::flush_total_cache\(\)/' => "call_user_func(array('Cachify', 'flush_total_cache'))",
        '/comet_cache::clear\(\)/' => "call_user_func(array('comet_cache', 'clear'))",
        '/Cache_Enabler::clear_total_cache\(\)/' => "call_user_func(array('Cache_Enabler', 'clear_total_cache'))",
        '/autoptimizeCache::clearall\(\)/' => "call_user_func(array('autoptimizeCache', 'clearall'))",
        '/Swift_Performance_Cache::clear_all_cache\(\)/' => "call_user_func(array('Swift_Performance_Cache', 'clear_all_cache'))",
        
        // Instanciación de clases
        '/new WpFastestCache\(\)/' => "new \$wpfc_class()",
        '/new UpdraftPlus\(\)/' => "new \$updraft_class()",
        '/new DUP_Package\(\)/' => "new \$dup_class()",
        '/w3_instance\(/' => "call_user_func('w3_instance',",
        
        // Variables de clase
        '/\$wpfc = new \$wpfc_class\(\);/' => "\$wpfc_class = 'WpFastestCache'; \$wpfc = class_exists(\$wpfc_class) ? new \$wpfc_class() : null;",
        '/\$updraftplus = new \$updraft_class\(\);/' => "\$updraft_class = 'UpdraftPlus'; \$updraftplus = class_exists(\$updraft_class) ? new \$updraft_class() : null;",
        '/\$package = new \$dup_class\(\);/' => "\$dup_class = 'DUP_Package'; \$package = class_exists(\$dup_class) ? new \$dup_class() : null;",
    ];
    
    foreach ($replacements as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    file_put_contents($path, $content);
    echo "Fixed: $file\n";
}

echo "Done fixing errors\n";
