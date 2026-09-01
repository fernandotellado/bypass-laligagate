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
	 * Operators subject to the court order (Movistar, Vodafone, Orange,
	 * Masmovil and DIGI). Used to tell a real ISP count from a legacy episode.
	 */
	const MAX_ISPS = 5;

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
		$body .= "Ahora mismo no hay bloqueos de IPs por parte de La Liga. El proxy de Cloudflare se ha reactivado automáticamente.\n\n";
		$body .= "Sitio: {$site}\n";
		$body .= "URL: {$url}\n";
		$body .= "Estado: Proxy ON (CDN)\n\n";
		$body .= "Los registros DNS seleccionados vuelven a estar en modo Proxied (CDN). Funcionamiento normal.\n\n";
		$body .= "Puedes consultar el estado actual en: " . admin_url( 'tools.php?page=bypass-laligagate' ) . "\n\n";
		$body .= "-- \nBypass LaLigaGate v" . AYUDAWP_BLG_VERSION . "\n";

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Send the periodic (daily/weekly) summary of block episodes during the
	 * last window. Total time reflects real La Liga block duration, i.e. the
	 * time the website would have been unreachable without the bypass.
	 *
	 * @param string $freq         'daily' or 'weekly'.
	 * @param int    $period_start Unix timestamp, window start (UTC).
	 * @param int    $period_end   Unix timestamp, window end (UTC).
	 * @param array  $episodes     Already clipped to the window.
	 * @param array  $stats        Keys: count, total_seconds, longest.
	 */
	public function notify_summary( $freq, $period_start, $period_end, $episodes, $stats ) {
		$to    = get_option( 'admin_email' );
		$site  = get_bloginfo( 'name' );
		$url   = home_url( '/' );
		$label = ( 'daily' === $freq ) ? 'diario' : 'semanal';
		$count = intval( $stats['count'] );

		$subject = '[' . $site . '] Resumen ' . $label . ' - ' . $count . ' bloqueo(s) evitado(s)';

		$fmt       = 'Y-m-d H:i';
		$from_str  = wp_date( $fmt, $period_start );
		$to_str    = wp_date( $fmt, $period_end );

		$body  = "Hola,\n\n";
		$body .= "Este es el resumen {$label} de Bypass LaLigaGate.\n\n";
		$body .= "Sitio: {$site}\n";
		$body .= "URL: {$url}\n";
		$body .= "Periodo: {$from_str} a {$to_str}\n\n";

		if ( 0 === $count ) {
			$body .= "Durante este periodo no se han detectado bloqueos. La web habría estado accesible sin necesidad del bypass.\n\n";
		} else {
			$total_str   = $this->format_duration( intval( $stats['total_seconds'] ) );
			$longest_str = $this->format_duration( intval( $stats['longest'] ) );
			$body .= "Bloqueos detectados: {$count}\n";
			$body .= "Tiempo total que la web habría estado inaccesible sin el bypass: {$total_str}\n";
			$body .= "Bloqueo más largo: {$longest_str}\n\n";
			$body .= "Detalle:\n";

			$limit = 20;
			$shown = array_slice( $episodes, 0, $limit );
			foreach ( $shown as $ep ) {
				$start_str = wp_date( $fmt, intval( $ep['start'] ) );
				$dur_str   = $this->format_duration( intval( $ep['duration'] ) );
				$isps      = intval( $ep['isps_max'] );
				$ips       = intval( $ep['ips_max'] ?? 0 );
				$body     .= "- {$start_str} ({$dur_str})";
				/*
				 * Spain has five operators under the court order, so anything
				 * above that is an episode logged before 1.5.0, when the TXT
				 * source stored the IP count in this field. Show it as IPs,
				 * which is what the number actually was.
				 */
				if ( $isps > 0 && $isps <= self::MAX_ISPS ) {
					$body .= " | ISPs afectados: {$isps}";
				} elseif ( $isps > self::MAX_ISPS && $ips <= 0 ) {
					$ips = $isps;
				}
				if ( $ips > 0 ) {
					$body .= " | IPs bloqueadas: {$ips}";
				}
				$body .= "\n";
			}
			$extra = $count - count( $shown );
			if ( $extra > 0 ) {
				$body .= "...y {$extra} más.\n";
			}
			$body .= "\n";
		}

		$body .= "Puedes consultar el estado actual en: " . admin_url( 'tools.php?page=bypass-laligagate' ) . "\n\n";
		$body .= "-- \nBypass LaLigaGate v" . AYUDAWP_BLG_VERSION . "\n";

		wp_mail( $to, $subject, $body );
	}

	private function format_duration( $seconds ) {
		$seconds = max( 0, intval( $seconds ) );
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}
		$hours   = intdiv( $seconds, HOUR_IN_SECONDS );
		$minutes = intdiv( $seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );
		if ( $hours > 0 ) {
			return sprintf( '%dh %02dm', $hours, $minutes );
		}
		return $minutes . 'm';
	}
}