<?php
/**
 * AJAX request handler.
 *
 * @package Bypass_LaLigaGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AyudaWP_BLG_Ajax_Handler {

	public function register_hooks() {
		add_action( 'wp_ajax_ayudawp_blg_test_and_load', array( $this, 'ajax_test_and_load' ) );
		add_action( 'wp_ajax_ayudawp_blg_check', array( $this, 'ajax_manual_check' ) );
		add_action( 'wp_ajax_ayudawp_blg_proxy_off', array( $this, 'ajax_force_proxy_off' ) );
		add_action( 'wp_ajax_ayudawp_blg_proxy_on', array( $this, 'ajax_force_proxy_on' ) );
	}

	private function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permiso denegado.' ) );
		}
		check_ajax_referer( 'ayudawp_blg_nonce', 'nonce' );
	}

	/**
	 * Get config using form credentials for the current AJAX request.
	 *
	 * Does NOT save to DB — credentials are only persisted when the user
	 * clicks "Guardar cambios" via the form. This prevents the AJAX from
	 * overwriting selected_records or other form-managed fields.
	 *
	 * @return array Config with credentials from POST (if available).
	 */
	private function get_config_with_form_creds() {
		$cfg = ayudawp_blg_get_config();
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['cf_api_key'] ) ) {
			$cfg['auth_type']  = isset( $_POST['auth_type'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_type'] ) ) : $cfg['auth_type'];
			$cfg['cf_email']   = isset( $_POST['cf_email'] ) ? sanitize_email( wp_unslash( $_POST['cf_email'] ) ) : $cfg['cf_email'];
			$cfg['cf_api_key'] = sanitize_text_field( wp_unslash( $_POST['cf_api_key'] ) );
			$cfg['cf_zone_id'] = isset( $_POST['cf_zone_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cf_zone_id'] ) ) : $cfg['cf_zone_id'];
		}
		// phpcs:enable
		return $cfg;
	}

	private function apply_proxy( $proxied_on ) {
		$cfg = ayudawp_blg_get_config();
		$api = new AyudaWP_BLG_Cloudflare_API( $cfg );

		if ( ! $api->is_configured() ) {
			wp_send_json_error( array( 'message' => 'Cloudflare no está configurado. Guarda las credenciales primero.' ) );
		}
		if ( empty( $cfg['selected_records'] ) ) {
			wp_send_json_error( array( 'message' => 'No hay registros DNS seleccionados. Marca los registros y guarda los ajustes.' ) );
		}

		$fresh = $api->fetch_dns_records();
		if ( empty( $fresh ) ) {
			wp_send_json_error( array( 'message' => 'No se pudieron obtener los registros DNS de Cloudflare.' ) );
		}

		$map = array();
		foreach ( $fresh as $rec ) {
			if ( ! empty( $rec['id'] ) ) {
				$map[ $rec['id'] ] = $rec;
			}
		}

		$ok = 0;
		$skip = 0;
		$errors = array();
		foreach ( $cfg['selected_records'] as $rid ) {
			if ( ! isset( $map[ $rid ] ) ) {
				$errors[] = substr( $rid, 0, 10 ) . '...: No encontrado';
				continue;
			}
			$result = $api->set_proxy_status( $map[ $rid ], $proxied_on );
			if ( ! empty( $result['success'] ) ) {
				empty( $result['skipped'] ) ? ++$ok : ++$skip;
			} else {
				$errors[] = ( $result['record_name'] ?? $rid ) . ': ' . ( $result['error'] ?? '?' );
			}
		}

		$updated = $api->fetch_dns_records();
		if ( ! empty( $updated ) ) {
			ayudawp_blg_save_dns_cache( $updated );
		}

		$state = ayudawp_blg_get_state();
		if ( $proxied_on ) {
			$state['bypass_active']   = 0;
			$state['bypass_since']    = 0;
			$state['manual_override'] = 0;
		} else {
			$state['bypass_active']   = 1;
			$state['bypass_since']    = time();
			$state['manual_override'] = 1;
		}
		ayudawp_blg_save_state( $state );

		$admin = new AyudaWP_BLG_Admin_Page();
		ob_start();
		$admin->render_dns_table( ayudawp_blg_get_dns_cache(), $cfg['selected_records'] );
		$html = ob_get_clean();

		$label = $proxied_on ? 'ON' : 'OFF';
		$msg   = "Proxy {$label} aplicado a {$ok} registros.";
		if ( $skip > 0 ) {
			$msg .= " {$skip} ya estaban en {$label}.";
		}
		if ( ! empty( $errors ) ) {
			$msg .= ' Errores: ' . implode( ' | ', $errors );
		}

		return array( 'message' => $msg, 'html' => $html, 'bypass' => $proxied_on ? 'NO' : 'SI' );
	}

	/**
	 * Test connection AND load DNS in one step.
	 */
	public function ajax_test_and_load() {
		$this->verify_request();
		$cfg = $this->get_config_with_form_creds();
		$api = new AyudaWP_BLG_Cloudflare_API( $cfg );

		$test = $api->test_connection();
		if ( ! $test['success'] ) {
			wp_send_json_error( array( 'message' => $test['message'] ) );
		}

		$records = $api->fetch_dns_records();
		if ( empty( $records ) ) {
			wp_send_json_success( array(
				'message' => $test['message'] . ' — Pero no se obtuvieron registros DNS.',
				'html'    => '',
			) );
		}

		ayudawp_blg_save_dns_cache( $records );

		$admin = new AyudaWP_BLG_Admin_Page();
		ob_start();
		$admin->render_dns_table( $records, $cfg['selected_records'] );
		$html = ob_get_clean();

		wp_send_json_success( array(
			'message' => $test['message'] . ' — ' . count( $records ) . ' registros DNS cargados.',
			'html'    => $html,
		) );
	}

	public function ajax_manual_check() {
		$this->verify_request();
		$cron = new AyudaWP_BLG_Cron_Manager();
		$cron->run_check();

		$state = ayudawp_blg_get_state();
		$cfg   = ayudawp_blg_get_config();
		$diag  = ayudawp_blg_get_cron_diagnostics();

		$msg = '';
		if ( ! empty( $state['manual_override'] ) ) {
			$msg = 'Forzado manual activo. ';
		}
		if ( 'SI' === $state['last_status'] ) {
			$msg .= 'Hay bloqueos activos ahora mismo.';
		} else {
			$msg .= 'No hay bloqueos activos.';
			if ( ! empty( $state['bypass_active'] ) && empty( $state['manual_override'] ) && intval( $state['blocks_ended_at'] ) > 0 ) {
				$remaining = max( 0, ( intval( $cfg['cooldown'] ) * 60 ) - ( time() - intval( $state['blocks_ended_at'] ) ) );
				if ( $remaining > 0 ) {
					$msg .= ' Periodo de espera: ' . ceil( $remaining / 60 ) . ' min.';
				}
			}
		}

		$is_bypass = ! empty( $state['bypass_active'] ) || ! empty( $state['manual_override'] );

		wp_send_json_success( array(
			'message'   => $msg,
			'blocked'   => $state['last_status'],
			'bypass'    => $is_bypass ? 'SI' : 'NO',
			'lastCheck' => $state['last_check'],
			'nextCheck' => $diag['next_human'],
		) );
	}

	public function ajax_force_proxy_off() {
		$this->verify_request();
		wp_send_json_success( $this->apply_proxy( false ) );
	}

	public function ajax_force_proxy_on() {
		$this->verify_request();
		wp_send_json_success( $this->apply_proxy( true ) );
	}
}
