<?php
defined( 'ABSPATH' ) || exit;

/**
 * Multi-channel notifier for Care events.
 *
 * Channels: wp_mail (email), generic JSON webhook, Slack Incoming Webhook (Block Kit).
 * Each event type has an independent throttle to prevent repeated alerts.
 *
 * Usage: do_action( 'rpcare_notify', 'backup_failed', [ 'message' => '...' ] )
 * Config stored in WP option rpcare_notification_config as:
 *   { notification_email, notification_webhook_url, notification_slack_webhook }
 */
class RP_Care_Notifier {

    // Throttle in seconds per event type (0 = no throttle).
    private static array $throttle = [
        'backup_failed'   => 14400,   // 4h — retry within 30min, don't spam
        'update_applied'  => 43200,   // 12h — daily window may run multiple times
        'update_failed'   => 7200,    // 2h
        'ssl_expiry_soon' => 604800,  // 7d — weekly reminder is enough
        'site_down'       => 3600,    // 1h
    ];

    private static array $labels = [
        'backup_failed'   => 'Backup fallido',
        'update_applied'  => 'Actualizaciones aplicadas',
        'update_failed'   => 'Actualización fallida',
        'ssl_expiry_soon' => 'SSL próximo a caducar',
        'site_down'       => 'Site caído',
    ];

    private static array $emoji = [
        'backup_failed'   => '🔴',
        'update_applied'  => '✅',
        'update_failed'   => '🔴',
        'ssl_expiry_soon' => '🔐',
        'site_down'       => '🚨',
    ];

    /**
     * Main dispatcher. Hooked to do_action('rpcare_notify', $event, $context).
     */
    public static function dispatch( string $event, array $ctx = [] ): void {
        $cfg = get_option( 'rpcare_notification_config', [] );
        if ( empty( $cfg ) ) return;

        // Throttle: skip if this event was notified recently.
        $ttl = self::$throttle[ $event ] ?? 14400;
        if ( $ttl > 0 ) {
            $tkey = 'rpcare_nthrottle_' . sanitize_key( $event );
            if ( get_transient( $tkey ) ) return;
            set_transient( $tkey, 1, $ttl );
        }

        // Enrich context with site-level defaults.
        $site_url = get_site_url();
        $ctx += [
            'site_url'    => $site_url,
            'site_domain' => wp_parse_url( $site_url, PHP_URL_HOST ) ?: $site_url,
            'plan'        => class_exists( 'RP_Care_Plan' ) ? ( RP_Care_Plan::get_current() ?: 'unknown' ) : 'unknown',
            'timestamp'   => current_time( 'mysql' ),
        ];

        $channels = [];

        if ( ! empty( $cfg['notification_email'] ) ) {
            self::send_email( $event, $ctx, $cfg['notification_email'] );
            $channels[] = 'email';
        }
        if ( ! empty( $cfg['notification_webhook_url'] ) ) {
            self::send_webhook( $event, $ctx, $cfg['notification_webhook_url'] );
            $channels[] = 'webhook';
        }
        if ( ! empty( $cfg['notification_slack_webhook'] ) ) {
            self::send_slack( $event, $ctx, $cfg['notification_slack_webhook'] );
            $channels[] = 'slack';
        }

        if ( ! empty( $channels ) && class_exists( 'RP_Care_Utils' ) ) {
            RP_Care_Utils::log( 'notifier', 'success', "Notificación enviada: {$event}", [ 'channels' => $channels ] );
        }
    }

    // ── Email ───────────────────────────────────────────────────────────────

    private static function send_email( string $event, array $ctx, string $to ): void {
        $label  = self::$labels[ $event ] ?? $event;
        $emo    = self::$emoji[ $event ]  ?? '⚠️';
        $domain = $ctx['site_domain'];
        $plan   = strtoupper( $ctx['plan'] );
        $ts     = $ctx['timestamp'];

        $subject = "[Replanta Care] {$emo} {$label} — {$domain}";

        $body  = "{$emo} {$label}\n\n";
        $body .= "Site:  {$ctx['site_url']}\n";
        $body .= "Plan:  {$plan}\n";
        $body .= "Fecha: {$ts}\n";

        if ( ! empty( $ctx['message'] ) ) {
            $body .= "\nDetalle: {$ctx['message']}\n";
        }
        if ( ! empty( $ctx['count'] ) ) {
            $body .= "\nPlugins actualizados: {$ctx['count']}\n";
        }
        if ( ! empty( $ctx['plugins'] ) && is_array( $ctx['plugins'] ) ) {
            foreach ( array_slice( $ctx['plugins'], 0, 20 ) as $p ) {
                $body .= "  · {$p}\n";
            }
        }
        if ( ! empty( $ctx['days_left'] ) ) {
            $body .= "\nDías hasta caducidad SSL: {$ctx['days_left']}\n";
        }

        $body .= "\n— Replanta Care · info@replanta.dev";

        wp_mail( $to, $subject, $body );
    }

    // ── Generic JSON webhook ────────────────────────────────────────────────

    private static function send_webhook( string $event, array $ctx, string $url ): void {
        wp_remote_post( $url, [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( [
                'event'   => $event,
                'label'   => self::$labels[ $event ] ?? $event,
                'source'  => 'replanta-care',
                'context' => $ctx,
            ] ),
        ] );
    }

    // ── Slack Incoming Webhook (Block Kit) ──────────────────────────────────

    private static function send_slack( string $event, array $ctx, string $hook_url ): void {
        $label  = self::$labels[ $event ] ?? $event;
        $emo    = self::$emoji[ $event ]  ?? '⚠️';
        $domain = $ctx['site_domain'];
        $plan   = strtoupper( $ctx['plan'] );
        $ts     = $ctx['timestamp'];

        // Main text block (mrkdwn).
        $text = "*{$emo} {$label}* — {$domain}\n";
        $text .= "[{$plan}] {$ts}";

        if ( ! empty( $ctx['message'] ) ) {
            $msg   = str_replace( "\n", "\n>", trim( $ctx['message'] ) );
            $text .= "\n>{$msg}";
        }
        if ( ! empty( $ctx['count'] ) ) {
            $text .= "\n*{$ctx['count']}* plugins actualizados";
        }
        if ( ! empty( $ctx['plugins'] ) && is_array( $ctx['plugins'] ) ) {
            $preview = array_slice( $ctx['plugins'], 0, 8 );
            $text  .= ': ' . implode( ', ', $preview );
            if ( count( $ctx['plugins'] ) > 8 ) {
                $text .= ' y ' . ( count( $ctx['plugins'] ) - 8 ) . ' más';
            }
        }
        if ( ! empty( $ctx['days_left'] ) ) {
            $text .= "\nCaduca en *{$ctx['days_left']} días*";
        }

        $payload = [
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [ 'type' => 'mrkdwn', 'text' => $text ],
                ],
                [
                    'type'     => 'context',
                    'elements' => [
                        [ 'type' => 'mrkdwn', 'text' => "Replanta Care · <{$ctx['site_url']}|{$domain}>" ],
                    ],
                ],
            ],
        ];

        wp_remote_post( $hook_url, [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( $payload ),
        ] );
    }
}
