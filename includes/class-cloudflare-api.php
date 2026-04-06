<?php
/**
 * Cloudflare API wrapper.
 *
 * @package Bypass_LaLigaGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AyudaWP_BLG_Cloudflare_API {

	/** @var array Plugin settings with CF credentials. */
	private $settings;

	/** @var string */
	private $api_base = 'https://api.cloudflare.com/client/v4';

	/**
	 * @param array $settings Plugin settings with CF credentials.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build authentication headers.
	 *
	 * @return array
	 */
	private function get_headers() {
		$headers = array( 'Content-Type' => 'application/json' );

		if ( 'token' === $this->settings['auth_type'] ) {
			$headers['Authorization'] = 'Bearer ' . $this->settings['cf_api_key'];
		} else {
			$headers['X-Auth-Email'] = $this->settings['cf_email'];
			$headers['X-Auth-Key']   = $this->settings['cf_api_key'];
		}

		return $headers;
	}

	/**
	 * Check if minimum credentials are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		if ( empty( $this->settings['cf_api_key'] ) || empty( $this->settings['cf_zone_id'] ) ) {
			return false;
		}
		if ( 'global' === $this->settings['auth_type'] && empty( $this->settings['cf_email'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Test connection by verifying zone access.
	 *
	 * @return array{success: bool, message: string, zone_name?: string}
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return array(
				'success' => false,
				'message' => 'Faltan credenciales de Cloudflare.',
			);
		}

		$url = $this->api_base . '/zones/' . rawurlencode( $this->settings['cf_zone_id'] );
		$r   = wp_remote_get( $url, array(
			'headers' => $this->get_headers(),
			'timeout' => 20,
		) );

		if ( is_wp_error( $r ) ) {
			return array(
				'success' => false,
				'message' => 'Error de conexión: ' . $r->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $r );
		$body = json_decode( wp_remote_retrieve_body( $r ), true );

		if ( 200 !== $code || empty( $body['success'] ) ) {
			$err = isset( $body['errors'][0]['message'] ) ? $body['errors'][0]['message'] : 'HTTP ' . $code;
			return array(
				'success' => false,
				'message' => 'Error de Cloudflare: ' . $err,
			);
		}

		return array(
			'success'   => true,
			'message'   => 'Conexión correcta. Zona: ' . ( $body['result']['name'] ?? '' ),
			'zone_name' => $body['result']['name'] ?? '',
		);
	}

	/**
	 * Fetch DNS records (A, AAAA, CNAME) paginated.
	 *
	 * @return array
	 */
	public function fetch_dns_records() {
		if ( ! $this->is_configured() ) {
			return array();
		}

		$allowed = array( 'A', 'AAAA', 'CNAME' );
		$records = array();
		$page    = 1;

		do {
			$url = $this->api_base . '/zones/' . rawurlencode( $this->settings['cf_zone_id'] )
				. '/dns_records?per_page=100&page=' . $page;

			$r = wp_remote_get( $url, array(
				'headers' => $this->get_headers(),
				'timeout' => 30,
			) );

			if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
				break;
			}

			$body = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( ! is_array( $body ) || empty( $body['success'] ) || ! isset( $body['result'] ) ) {
				break;
			}

			foreach ( $body['result'] as $rec ) {
				$type = strtoupper( $rec['type'] ?? '' );
				if ( ! in_array( $type, $allowed, true ) ) {
					continue;
				}
				$records[] = array(
					'id'      => (string) ( $rec['id'] ?? '' ),
					'name'    => (string) ( $rec['name'] ?? '' ),
					'type'    => $type,
					'content' => (string) ( $rec['content'] ?? '' ),
					'proxied' => isset( $rec['proxied'] ) ? (bool) $rec['proxied'] : null,
					'ttl'     => intval( $rec['ttl'] ?? 1 ),
				);
			}

			$total_pages = 1;
			if ( isset( $body['result_info']['total_count'], $body['result_info']['per_page'] ) ) {
				$per = intval( $body['result_info']['per_page'] );
				if ( $per > 0 ) {
					$total_pages = (int) ceil( intval( $body['result_info']['total_count'] ) / $per );
				}
			}
			++$page;
		} while ( $page <= $total_pages && $page <= 20 );

		return $records;
	}

	/**
	 * Update proxy status for a single DNS record.
	 *
	 * Receives full record data — no cache lookup needed.
	 *
	 * @param array $record    Full record data (id, name, type, content, proxied, ttl).
	 * @param bool  $proxied_on True for Proxied (CDN), false for DNS Only.
	 * @return array{success: bool, skipped?: bool, error?: string, record_name: string}
	 */
	public function set_proxy_status( $record, $proxied_on ) {
		$name = $record['name'] ?? '';

		/* Skip if already in desired state */
		if ( isset( $record['proxied'] ) && (bool) $record['proxied'] === (bool) $proxied_on ) {
			return array(
				'success'     => true,
				'skipped'     => true,
				'record_name' => $name,
			);
		}

		$type = strtoupper( $record['type'] ?? '' );
		if ( ! in_array( $type, array( 'A', 'AAAA', 'CNAME' ), true ) ) {
			return array(
				'success'     => false,
				'error'       => "Tipo {$type} no admite proxy",
				'record_name' => $name,
			);
		}

		$ttl = intval( $record['ttl'] ?? 1 );
		if ( $proxied_on ) {
			$ttl = 1;
		}

		$payload = array(
			'type'    => $type,
			'name'    => $name,
			'content' => $record['content'] ?? '',
			'ttl'     => $ttl,
			'proxied' => (bool) $proxied_on,
		);

		$url = $this->api_base . '/zones/' . rawurlencode( $this->settings['cf_zone_id'] )
			. '/dns_records/' . rawurlencode( $record['id'] );

		$r = wp_remote_request( $url, array(
			'method'  => 'PUT',
			'headers' => $this->get_headers(),
			'timeout' => 30,
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $r ) ) {
			return array(
				'success'     => false,
				'error'       => $r->get_error_message(),
				'record_name' => $name,
			);
		}

		$code = wp_remote_retrieve_response_code( $r );
		$body = json_decode( wp_remote_retrieve_body( $r ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['success'] ) ) {
			$err = isset( $body['errors'][0]['message'] ) ? $body['errors'][0]['message'] : 'HTTP ' . $code;
			return array(
				'success'     => false,
				'error'       => $err,
				'record_name' => $name,
			);
		}

		return array(
			'success'     => true,
			'skipped'     => false,
			'record_name' => $name,
		);
	}
}
