<?php
/**
 * Email notifications on proxy state changes.
 *
 * Sends an email to the site admin when the proxy is automatically
 * disabled (blocks detected) or re-enabled (blocks cleared + cooldown).
 * Only notifies on actual state CHANGES, not on every cron check.
 *
 * @package Bypass_LaLigaGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AyudaWP_BLG_Email_Notifier {

	/**
	 * Send notification when proxy has been automatically disabled.
	 *
	 * Called only when bypass_active changes from 0 to 1 during a cron check.
	 */
	public function notify_proxy_disabled() {
		$to      = get_option( 'admin_email' );
		$site    = get_bloginfo( 'name' );
		$url     = home_url( '/' );
		$subject = '[' . $site . '] Proxy de Cloudflare desactivado por bloqueos de La Liga';

		$body  = "Hola,\n\n";
		$body .= "Se han detectado bloqueos activos de IPs por parte de La Liga y el proxy de Cloudflare se ha desactivado automáticamente en los registros DNS configurados.\n\n";
		$body .= "Sitio: {$site}\n";
		$body .= "URL: {$url}\n";
		$body .= "Estado: Proxy OFF (DNS Only)\n";
		$body .= "Motivo: Bloqueos activos detectados en hayahora.futbol\n\n";
		$body .= "Los registros DNS seleccionados han pasado de Proxied (CDN) a DNS Only para que la web siga siendo accesible durante los bloqueos.\n\n";
		$body .= "El proxy se reactivará automáticamente cuando los bloqueos terminen y pase el periodo de espera configurado.\n\n";
		$body .= "Puedes consultar el estado actual en: " . admin_url( 'tools.php?page=bypass-laligagate' ) . "\n\n";
		$body .= "-- \nBypass LaLigaGate v" . AYUDAWP_BLG_VERSION . "\n";

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Send notification when proxy has been automatically re-enabled.
	 *
	 * Called only when bypass_active changes from 1 to 0 during a cron check
	 * (not during manual override).
	 */
	public function notify_proxy_restored() {
		$to      = get_option( 'admin_email' );
		$site    = get_bloginfo( 'name' );
		$url     = home_url( '/' );
		$subject = '[' . $site . '] Proxy de Cloudflare reactivado';

		$body  = "Hola,\n\n";
		$body .= "Los bloqueos de La Liga han terminado y el periodo de espera ha finalizado. El proxy de Cloudflare se ha reactivado automáticamente.\n\n";
		$body .= "Sitio: {$site}\n";
		$body .= "URL: {$url}\n";
		$body .= "Estado: Proxy ON (CDN)\n\n";
		$body .= "Los registros DNS seleccionados vuelven a estar en modo Proxied (CDN). Funcionamiento normal.\n\n";
		$body .= "Puedes consultar el estado actual en: " . admin_url( 'tools.php?page=bypass-laligagate' ) . "\n\n";
		$body .= "-- \nBypass LaLigaGate v" . AYUDAWP_BLG_VERSION . "\n";

		wp_mail( $to, $subject, $body );
	}
}
