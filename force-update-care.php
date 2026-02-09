<?php
/**
 * Replanta Care - Force Update Script
 * Ejecuta este script una vez en cada sitio cliente para actualizar a la última versión.
 * ELIMINA ESTE ARCHIVO DESPUÉS DE USARLO.
 * 
 * Uso: https://tusitio.com/force-update-care.php
 */

// Seguridad básica - solo admins
require_once dirname(__FILE__) . '/wp-load.php';

if (!current_user_can('manage_options')) {
    wp_die('Acceso denegado. Inicia sesión como administrador.');
}

// Includes necesarios
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Replanta Care Update</title>';
echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:600px;margin:50px auto;padding:20px;background:#f5f5f5;}';
echo '.box{background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);margin-bottom:20px;}';
echo '.success{color:#1E2F23;background:#93F1C9;padding:15px;border-radius:6px;margin:10px 0;}';
echo '.error{color:#721c24;background:#f8d7da;padding:15px;border-radius:6px;margin:10px 0;}';
echo '.info{color:#0c5460;background:#d1ecf1;padding:15px;border-radius:6px;margin:10px 0;}';
echo 'h1{color:#1E2F23;}</style></head><body>';

echo '<div class="box"><h1>Replanta Care - Actualización</h1>';

// Paso 1: Mostrar versión actual
$plugin_file = WP_PLUGIN_DIR . '/replanta-care/replanta-care.php';
if (file_exists($plugin_file)) {
    $current_data = get_plugin_data($plugin_file);
    echo '<p><strong>Versión actual:</strong> ' . esc_html($current_data['Version']) . '</p>';
} else {
    echo '<div class="error">Plugin Replanta Care no encontrado.</div></body></html>';
    exit;
}

// Paso 2: Descargar nueva versión
$download_url = 'https://github.com/replantadev/care/archive/refs/heads/main.zip';
echo '<div class="info">Descargando última versión desde GitHub...</div>';

$tmp_file = download_url($download_url, 60);
if (is_wp_error($tmp_file)) {
    echo '<div class="error">Error al descargar: ' . esc_html($tmp_file->get_error_message()) . '</div></body></html>';
    exit;
}

// Paso 3: Desactivar plugin temporalmente
$plugin_slug = 'replanta-care/replanta-care.php';
$was_active = is_plugin_active($plugin_slug);
if ($was_active) {
    deactivate_plugins($plugin_slug);
    echo '<div class="info">Plugin desactivado temporalmente...</div>';
}

// Paso 4: Descomprimir
echo '<div class="info">Descomprimiendo...</div>';
WP_Filesystem();
global $wp_filesystem;

$unzip_result = unzip_file($tmp_file, WP_PLUGIN_DIR);
@unlink($tmp_file);

if (is_wp_error($unzip_result)) {
    echo '<div class="error">Error al descomprimir: ' . esc_html($unzip_result->get_error_message()) . '</div>';
    if ($was_active) {
        activate_plugin($plugin_slug);
    }
    echo '</body></html>';
    exit;
}

// Paso 5: Reemplazar directorio
$extracted_dir = WP_PLUGIN_DIR . '/care-main';
$plugin_dir = WP_PLUGIN_DIR . '/replanta-care';

if (is_dir($extracted_dir)) {
    // Backup de configuración
    $options_backup = get_option('rpcare_options');
    
    // Eliminar versión antigua
    $wp_filesystem->delete($plugin_dir, true);
    
    // Renombrar nueva versión
    if (rename($extracted_dir, $plugin_dir)) {
        echo '<div class="info">Archivos actualizados correctamente.</div>';
        
        // Restaurar configuración
        if ($options_backup) {
            update_option('rpcare_options', $options_backup);
        }
    } else {
        echo '<div class="error">Error al renombrar directorio.</div></body></html>';
        exit;
    }
} else {
    echo '<div class="error">Directorio extraído no encontrado.</div></body></html>';
    exit;
}

// Paso 6: Reactivar plugin
if ($was_active) {
    $activated = activate_plugin($plugin_slug);
    if (is_wp_error($activated)) {
        echo '<div class="error">Error al reactivar: ' . esc_html($activated->get_error_message()) . '</div>';
    } else {
        echo '<div class="info">Plugin reactivado.</div>';
    }
}

// Paso 7: Limpiar caches
delete_site_transient('update_plugins');
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%puc%'");

// Paso 8: Mostrar nueva versión
$new_data = get_plugin_data($plugin_file);
echo '<div class="success">';
echo '<strong>¡Actualización completada!</strong><br>';
echo 'Nueva versión: <strong>' . esc_html($new_data['Version']) . '</strong>';
echo '</div>';

echo '<p><a href="' . admin_url('plugins.php') . '" style="color:#41999F;">Ir a Plugins</a></p>';
echo '<p style="color:#f00;font-weight:bold;">IMPORTANTE: Elimina este archivo (force-update-care.php) por seguridad.</p>';
echo '</div></body></html>';
