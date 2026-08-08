<?php
/**
 * Replanta Care — Staging Admin Banner
 *
 * Renders a persistent admin notice on all WP admin pages when the current site
 * is configured as a staging environment (OPT_ENVIRONMENT = 'staging').
 *
 * The banner is non-dismissible and reminds administrators that:
 *   - This is not the production site.
 *   - Email is redirected or suppressed.
 *   - Outgoing POST webhooks are blocked.
 *   - Changes may be overwritten on the next staging sync.
 *
 * PHP 8.0+ / WP 5.0+.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RP_Care_Staging_Admin_Banner {

	public static function register(): void {
		if ( ! self::is_staging() ) {
			return;
		}
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	public static function is_staging(): bool {
		$env = (string) get_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, '' );
		return $env === 'staging';
	}

	public static function render(): void {
		$sink = class_exists( 'RP_Care_Staging_Email_Sink' )
			? RP_Care_Staging_Email_Sink::get_sink_address()
			: null;

		$email_note = $sink !== null
			? sprintf( 'Emails redirigidos a <code>%s</code>.', esc_html( $sink ) )
			: 'Emails suprimidos.';

		?>
		<div class="notice notice-warning rpcare-staging-banner" style="border-left-color:#e6a817;padding:10px 14px;margin-bottom:0;">
			<p style="margin:.25em 0;">
				<strong>&#9888; Replanta Care &mdash; Entorno de staging</strong>
				&nbsp;&mdash;&nbsp;
				Esta es una copia de staging administrada por Plugin Center.
				Los cambios pueden ser sobreescritos en el siguiente sync.
				<?php echo wp_kses( $email_note, [ 'code' => [] ] ); ?>
				Los webhooks externos (POST) est&aacute;n bloqueados.
			</p>
		</div>
		<?php
	}
}
