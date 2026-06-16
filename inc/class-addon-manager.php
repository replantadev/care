<?php
/**
 * Addon Manager — gestiona addons activos y su configuracion.
 *
 * El Hub empuja addons activos via POST /replanta/v1/config.
 * Care los almacena en rpcare_options['addons'] y la configuracion
 * especifica de cada addon en la opcion rpcare_addon_{slug}.
 *
 * API publica (singleton):
 *   RP_Care_Addon_Manager::get()->is_active('ecommerce')
 *   RP_Care_Addon_Manager::get()->get_config('ecommerce')
 *   RP_Care_Addon_Manager::get()->get_active()
 *   RP_Care_Addon_Manager::get()->update(['ecommerce'], ['ecommerce' => [...]])
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Addon_Manager {

    private static $instance = null;

    /** @var string[] Active addon slugs */
    private $active = [];

    /** @var array[] Merged (defaults + stored) config per addon */
    private $configs = [];

    private const DEFAULTS = [
        'ecommerce' => [
            'backup_frequency'        => 'twicedaily',
            'backup_retention_days'   => 90,
            'checkout_monitor'        => true,
            'checkout_check_interval' => 15,
            'staging_required'        => true,
            'peak_hours_start'        => 9,
            'peak_hours_end'          => 22,
            'revenue_alert_threshold' => 35,
            'alert_email'             => '',
        ],
    ];

    private function __construct() {
        $this->load();
    }

    public static function get(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function is_active(string $addon): bool {
        return in_array($addon, $this->active, true);
    }

    /** @return string[] */
    public function get_active(): array {
        return $this->active;
    }

    /**
     * Returns merged config for an addon (defaults overridden by stored values).
     * Returns empty array for addons with no registered defaults.
     */
    public function get_config(string $addon): array {
        return $this->configs[$addon] ?? (self::DEFAULTS[$addon] ?? []);
    }

    /**
     * Called from RP_Care_REST::update_config() when Hub pushes new addon data.
     *
     * @param string[] $addons       Slugs of active addons: ['ecommerce']
     * @param array    $addon_configs Per-addon config objects: ['ecommerce' => [...]]
     */
    public function update(array $addons, array $addon_configs = []): void {
        $addons         = array_values(array_map('sanitize_key', $addons));
        $opts           = get_option('rpcare_options', []);
        $opts['addons'] = $addons;
        update_option('rpcare_options', $opts);

        foreach ($addon_configs as $slug => $config) {
            $slug   = sanitize_key($slug);
            $merged = array_merge(self::DEFAULTS[$slug] ?? [], (array) $config);
            update_option("rpcare_addon_{$slug}", $merged);
        }

        $this->load();
    }

    /**
     * Send a non-blocking fire-and-forget alert to Hub.
     *
     * @param string $event Short event key, e.g. 'checkout_failure'
     * @param array  $data  Context data serialized as JSON string
     */
    public static function notify_hub(string $event, array $data = []): void {
        $opts  = get_option('rpcare_options', []);
        $hub   = rtrim($opts['hub_url'] ?? '', '/');
        $token = $opts['site_token'] ?? '';

        if (!$hub || !$token) {
            return;
        }

        wp_remote_post(
            "{$hub}/wp-admin/admin-ajax.php",
            [
                'timeout'  => 5,
                'blocking' => false,
                'body'     => [
                    'action'     => 'rphub_care_alert',
                    'site_token' => $token,
                    'event'      => sanitize_key($event),
                    'site_url'   => home_url(),
                    'data'       => wp_json_encode($data),
                ],
            ]
        );
    }

    private function load(): void {
        $opts         = get_option('rpcare_options', []);
        $this->active = array_map('sanitize_key', (array) ($opts['addons'] ?? []));
        $this->configs = [];

        foreach ($this->active as $slug) {
            $stored              = (array) get_option("rpcare_addon_{$slug}", []);
            $this->configs[$slug] = array_merge(self::DEFAULTS[$slug] ?? [], $stored);
        }
    }
}
