<?php
/**
 * Helper para configurar Replanta Care correctamente
 * 
 * INSTRUCCIONES:
 * 1. Copia este archivo al sitio donde tienes instalado Replanta Care
 * 2. Ejecuta este archivo visitando: tu-sitio.com/wp-content/plugins/replanta-care/configure.php
 * 3. Sigue las instrucciones para configurar correctamente
 */

// Verificar que estamos en WordPress
if (!defined('ABSPATH')) {
    // Si no estamos en WordPress, cargar wp-config
    require_once('../../../wp-config.php');
}

// Solo ejecutar si el usuario está logueado y tiene permisos
if (!current_user_can('manage_options')) {
    die('Acceso denegado. Debes ser administrador.');
}

echo '<h2>🔧 Configuración de Replanta Care</h2>';

// Obtener configuración actual
$current_options = get_option('rpcare_options', []);

echo '<h3>📋 Configuración Actual</h3>';
echo '<table border="1" cellpadding="10">';
echo '<tr><th>Setting</th><th>Current Value</th><th>Required Value</th></tr>';

$required_config = [
    'hub_url' => 'https://sitios.replanta.dev',
    'site_token' => '[DEBE SER CONFIGURADO]'
];

foreach ($required_config as $key => $required_value) {
    $current_value = $current_options[$key] ?? 'NO CONFIGURADO';
    $status = ($key === 'site_token') ? '⚠️' : (($current_value === $required_value) ? '✅' : '❌');
    
    echo "<tr>";
    echo "<td><strong>{$key}</strong></td>";
    echo "<td>{$current_value}</td>";
    echo "<td>{$required_value}</td>";
    echo "<td>{$status}</td>";
    echo "</tr>";
}
echo '</table>';

// Auto-configurar si se solicita
if (isset($_GET['auto_configure'])) {
    $new_options = $current_options;
    $new_options['hub_url'] = 'https://sitios.replanta.dev';
    
    // Mantener el token existente si ya está configurado
    if (empty($new_options['site_token'])) {
        echo '<div style="background: #fff3cd; padding: 15px; margin: 20px 0; border: 1px solid #ffeaa7;">
            <p><strong>⚠️ ATENCIÓN:</strong> No se puede configurar automáticamente el token.</p>
            <p>Debes obtener el token desde el HUB:</p>
            <ol>
                <li>Ve al HUB (https://sitios.replanta.dev/wp-admin)</li>
                <li>Ve a Replanta HUB → Sites</li>
                <li>Añade un nuevo sitio con la URL: <strong>' . site_url() . '</strong></li>
                <li>Copia el token que se genere</li>
                <li>Pégalo en Replanta Care → Settings → Site Token</li>
            </ol>
        </div>';
    }
    
    update_option('rpcare_options', $new_options);
    echo '<div style="background: #d4edda; padding: 15px; margin: 20px 0; border: 1px solid #c3e6cb;">
        <p>✅ <strong>HUB URL configurado correctamente!</strong></p>
    </div>';
    
    // Refrescar la página para mostrar los nuevos valores
    echo '<script>setTimeout(() => window.location.reload(), 2000);</script>';
}

echo '<h3>🛠️ Acciones</h3>';

if (!isset($_GET['auto_configure'])) {
    echo '<a href="?auto_configure=1" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
        🔧 Auto-configurar HUB URL
    </a>';
}

echo '<h3>🧪 Test de Configuración</h3>';

// Test de conexión
if (isset($_GET['test_connection'])) {
    echo '<div style="background: #f8f9fa; padding: 15px; border: 1px solid #dee2e6;">';
    
    $hub_url = $current_options['hub_url'] ?? '';
    $site_token = $current_options['site_token'] ?? '';
    
    if (empty($hub_url) || empty($site_token)) {
        echo '<p>❌ <strong>Error:</strong> HUB URL y Site Token son requeridos para el test.</p>';
    } else {
        echo '<p>🔄 <strong>Probando conexión...</strong></p>';
        echo '<p><strong>HUB URL:</strong> ' . $hub_url . '</p>';
        echo '<p><strong>Site Token:</strong> ' . (strlen($site_token) > 10 ? substr($site_token, 0, 10) . '...' : $site_token) . '</p>';
        
        // Test the connection
        $test_url = $hub_url . '/wp-admin/admin-ajax.php';
        $response = wp_remote_post($test_url, [
            'body' => [
                'action' => 'rphub_test_care_connection',
                'site_token' => $site_token,
                'site_url' => site_url()
            ],
            'timeout' => 10
        ]);
        
        if (is_wp_error($response)) {
            echo '<p>❌ <strong>Error de conexión:</strong> ' . $response->get_error_message() . '</p>';
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            echo '<p><strong>Código de respuesta:</strong> ' . $code . '</p>';
            echo '<p><strong>Respuesta:</strong> ' . htmlspecialchars($body) . '</p>';
            
            if ($code === 200) {
                $data = json_decode($body, true);
                if ($data && $data['success']) {
                    echo '<p>✅ <strong>¡Conexión exitosa!</strong></p>';
                } else {
                    echo '<p>⚠️ <strong>Conexión establecida pero hay un error:</strong> ' . ($data['data'] ?? 'Error desconocido') . '</p>';
                }
            } else {
                echo '<p>❌ <strong>Error HTTP ' . $code . '</strong></p>';
            }
        }
    }
    
    echo '</div>';
}

if (!isset($_GET['test_connection'])) {
    echo '<a href="?test_connection=1" style="background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-left: 10px;">
        🧪 Probar Conexión
    </a>';
}

echo '<h3>📖 Información del Sistema</h3>';
echo '<p><strong>URL del sitio:</strong> ' . site_url() . '</p>';
echo '<p><strong>Nombre del sitio:</strong> ' . get_bloginfo('name') . '</p>';
echo '<p><strong>Versión de Care:</strong> ' . (defined('RPCARE_VERSION') ? RPCARE_VERSION : 'No detectada') . '</p>';

?>
