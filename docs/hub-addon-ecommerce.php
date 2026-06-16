<?php
/**
 * Hub — Addon eCommerce: cambios necesarios en replanta-hub.
 *
 * Este archivo NO es parte del plugin Care. Es documentacion ejecutable:
 * pega cada bloque en el archivo de Hub indicado.
 *
 * ============================================================================
 * 1. SCHEMA — anadir columna addons a rphub_sites (o usar site meta)
 * ============================================================================
 *
 * Opcion A (columna JSON en la tabla de sitios):
 *
 *   ALTER TABLE rphub_sites ADD COLUMN addons JSON DEFAULT '[]';
 *   ALTER TABLE rphub_sites ADD COLUMN addon_ecommerce_config JSON DEFAULT '{}';
 *
 * Opcion B (site meta — sin migracion de schema):
 *   Usar get_post_meta($site_id, 'rphub_addons', true) y update_post_meta().
 *   Los sitios en Hub son CPT 'rphub_site', por lo que post_meta funciona directamente.
 *
 * ============================================================================
 * 2. METABOX en la pagina de edicion de sitio (hub/admin/class-site-edit.php)
 * ============================================================================
 */

// Pegar en el metodo que registra metaboxes del sitio:
add_meta_box(
    'rphub_addons',
    'Addons contratados',
    'rphub_render_addons_metabox',
    'rphub_site',
    'side',
    'default'
);

function rphub_render_addons_metabox($post) {
    $addons = get_post_meta($post->ID, 'rphub_addons', true) ?: [];
    $ecom   = get_post_meta($post->ID, 'rphub_addon_ecommerce_config', true) ?: [];
    wp_nonce_field('rphub_addons_save', 'rphub_addons_nonce');
    ?>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
        <input type="checkbox"
               name="rphub_addon_ecommerce"
               value="1"
               <?php checked(in_array('ecommerce', $addons, true)); ?>>
        Addon eCommerce
    </label>

    <div id="rphub-ecom-config" style="<?php echo in_array('ecommerce', $addons, true) ? '' : 'display:none'; ?>">
        <p><strong>Configuracion eCommerce</strong></p>
        <label>Umbral alerta ingresos (%)<br>
            <input type="number" name="rphub_ecom_revenue_threshold"
                   value="<?php echo esc_attr($ecom['revenue_alert_threshold'] ?? 35); ?>"
                   min="5" max="90" style="width:80px;">
        </label><br><br>
        <label>Email de alertas<br>
            <input type="email" name="rphub_ecom_alert_email"
                   value="<?php echo esc_attr($ecom['alert_email'] ?? ''); ?>"
                   style="width:100%;" placeholder="(por defecto: admin del sitio)">
        </label><br><br>
        <label>Hora pico inicio (0-23)<br>
            <input type="number" name="rphub_ecom_peak_start"
                   value="<?php echo esc_attr($ecom['peak_hours_start'] ?? 9); ?>"
                   min="0" max="23" style="width:60px;">
        </label>
        <label style="margin-left:12px;">Hora pico fin<br>
            <input type="number" name="rphub_ecom_peak_end"
                   value="<?php echo esc_attr($ecom['peak_hours_end'] ?? 22); ?>"
                   min="0" max="23" style="width:60px;">
        </label>
    </div>

    <script>
    document.querySelector('[name=rphub_addon_ecommerce]').addEventListener('change', function() {
        document.getElementById('rphub-ecom-config').style.display = this.checked ? '' : 'none';
    });
    </script>
    <?php
}

/**
 * Pegar en save_post hook del sitio (o en el metodo save_site() existente):
 */
function rphub_save_addons_metabox($post_id) {
    if (!isset($_POST['rphub_addons_nonce'])) {
        return;
    }
    if (!wp_verify_nonce($_POST['rphub_addons_nonce'], 'rphub_addons_save')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    $addons = [];
    if (!empty($_POST['rphub_addon_ecommerce'])) {
        $addons[] = 'ecommerce';
    }
    update_post_meta($post_id, 'rphub_addons', $addons);

    $ecom_config = [
        'revenue_alert_threshold' => (int) ($_POST['rphub_ecom_revenue_threshold'] ?? 35),
        'alert_email'             => sanitize_email($_POST['rphub_ecom_alert_email'] ?? ''),
        'peak_hours_start'        => (int) ($_POST['rphub_ecom_peak_start'] ?? 9),
        'peak_hours_end'          => (int) ($_POST['rphub_ecom_peak_end'] ?? 22),
    ];
    update_post_meta($post_id, 'rphub_addon_ecommerce_config', $ecom_config);
}
add_action('save_post_rphub_site', 'rphub_save_addons_metabox');

/**
 * ============================================================================
 * 3. INCLUIR addons en el payload /config que Hub envia a Care
 *    (hub/inc/class-site-sync.php o donde se construya el payload)
 * ============================================================================
 *
 * Buscar donde Hub hace:
 *   wp_remote_post($site_url . '/wp-json/replanta/v1/config', ['body' => $payload])
 *
 * Anadir al array $payload:
 */

$site_id         = 123; // ID real del CPT del sitio
$addons          = get_post_meta($site_id, 'rphub_addons', true) ?: [];
$ecommerce_cfg   = get_post_meta($site_id, 'rphub_addon_ecommerce_config', true) ?: [];

$payload['addons'] = $addons;

if (in_array('ecommerce', $addons, true)) {
    $payload['ecommerce_config'] = $ecommerce_cfg;
}

/**
 * ============================================================================
 * 4. WEBHOOK — recibir alertas de Care (admin-ajax rphub_care_alert)
 *    (hub/inc/class-ajax.php o equivalente)
 * ============================================================================
 */
add_action('wp_ajax_nopriv_rphub_care_alert', 'rphub_handle_care_alert');
add_action('wp_ajax_rphub_care_alert',        'rphub_handle_care_alert');

function rphub_handle_care_alert() {
    $token    = sanitize_text_field($_POST['site_token'] ?? '');
    $event    = sanitize_key($_POST['event'] ?? '');
    $site_url = esc_url_raw($_POST['site_url'] ?? '');
    $data     = json_decode(wp_unslash($_POST['data'] ?? '{}'), true);

    // Validar token (buscar sitio por site_token en post meta)
    $sites = get_posts([
        'post_type'      => 'rphub_site',
        'posts_per_page' => 1,
        'meta_query'     => [['key' => 'rphub_site_token', 'value' => $token]],
    ]);

    if (empty($sites)) {
        wp_send_json_error('invalid_token', 403);
        return;
    }

    $site_id = $sites[0]->ID;

    // Guardar alerta en post meta con timestamp
    $alerts   = get_post_meta($site_id, 'rphub_addon_alerts', true) ?: [];
    $alerts[] = [
        'event'  => $event,
        'ts'     => current_time('mysql'),
        'data'   => $data,
    ];
    $alerts = array_slice($alerts, -50); // retener las 50 mas recientes
    update_post_meta($site_id, 'rphub_addon_alerts', $alerts);

    // Notificar al admin del Hub segun tipo de evento
    $admin_email = get_option('admin_email');

    if ($event === 'checkout_failure') {
        $subject = "[Hub] Fallo de checkout en {$site_url}";
        $body    = "El checkout de {$site_url} ha fallado {$data['consecutive_failures']} veces seguidas.\n\nDetalles: " . wp_json_encode($data['checks'] ?? []);
        wp_mail($admin_email, $subject, $body);
    }

    if ($event === 'revenue_anomaly') {
        $subject = "[Hub] Anomalia de ingresos en {$site_url}";
        $body    = "Caida de {$data['drop_pct']}% detectada en {$site_url}.\n"
                 . "Actual: {$data['current']['total']} EUR — Hace 7d: {$data['baseline']['total']} EUR";
        wp_mail($admin_email, $subject, $body);
    }

    wp_send_json_success(['stored' => true]);
}
