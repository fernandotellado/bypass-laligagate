<?php
/**
 * Block status checker.
 *
 * Fetches the hayahora.futbol JSON endpoint to determine if there are
 * active football-related IP blocks in Spain. Operates in preventive mode:
 * any active block triggers the bypass regardless of domain-specific detection.
 *
 * @package Bypass_LaLigaGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AyudaWP_BLG_Block_Checker
 */
class AyudaWP_BLG_Block_Checker {

	/**
	 * Remote JSON endpoint URL.
	 *
	 * @var string
	 */
	private $json_url = 'https://hayahora.futbol/estado/data.json';

	/**
	 * Check if there are active blocks filtered by ISP count.
	 *
	 * Returns true only when the number of distinct ISPs reporting
	 * blocked IPs meets or exceeds $min_isps. This avoids false
	 * positives from a single ISP with network issues.
	 *
	 * @param int $min_isps Minimum ISPs with blocks to trigger (default 2).
	 * @return array{blocked: bool, last_update: string, error: string, blocked_isps: int}
	 */
	public function check_status( $min_isps = 1 ) {
		$result = array(
			'blocked'      => false,
			'last_update'  => '',
			'error'        => '',
			'blocked_isps' => 0,
		);

		$response = wp_remote_get( $this->json_url, array(
			'timeout'     => 20,
			'redirection' => 3,
			'user-agent'  => 'BypassLaLigaGate/' . AYUDAWP_BLG_VERSION . '; ' . home_url( '/' ),
		) );

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			return $result;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$result['error'] = 'HTTP ' . $code;
			return $result;
		}

		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			$result['error'] = 'Respuesta JSON no válida';
			return $result;
		}

		/* Extract last update timestamp */
		if ( ! empty( $json['lastUpdate'] ) && is_string( $json['lastUpdate'] ) ) {
			$result['last_update'] = $json['lastUpdate'];
		}

		/* Count distinct ISPs with blocked IPs */
		$blocked_isps              = $this->count_blocked_isps( $json );
		$result['blocked_isps']    = $blocked_isps;
		$result['blocked']         = ( $blocked_isps >= max( 1, $min_isps ) );

		return $result;
	}

	/**
	 * Count distinct ISPs that have at least one blocked IP.
	 *
	 * Iterates the JSON data looking for objects with 'isp' and blocked
	 * status. Returns the number of unique ISP names with blocks.
	 * When ISP info is not available, falls back to the simple IP map
	 * and treats all blocked IPs as coming from a single ISP.
	 *
	 * @param array $json Decoded JSON data.
	 * @return int Number of ISPs with blocked IPs.
	 */
	private function count_blocked_isps( $json ) {
		$blocked_by_isp = array();

		/* Find the data array */
		$ips_data = null;
		foreach ( array( 'ips', 'ip', 'data', 'results' ) as $key ) {
			if ( isset( $json[ $key ] ) && is_array( $json[ $key ] ) ) {
				$ips_data = $json[ $key ];
				break;
			}
		}

		if ( ! is_array( $ips_data ) ) {
			/*
			 * Fallback: use extract_ip_map for simple structures without ISP info.
			 * Without ISP data we treat all blocked IPs as 1 ISP (conservative).
			 */
			$ip_map  = $this->extract_ip_map( $json );
			$has_any = false;
			foreach ( $ip_map as $blocked ) {
				if ( true === $blocked ) {
					$has_any = true;
					break;
				}
			}
			return $has_any ? 1 : 0;
		}

		foreach ( $ips_data as $index => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['ip'] ) ) {
				continue;
			}

			$isp     = ! empty( $entry['isp'] ) ? strtolower( trim( $entry['isp'] ) ) : 'unknown';
			$blocked = $this->extract_blocked_from_object( $entry );

			if ( true === $blocked ) {
				$blocked_by_isp[ $isp ] = true;
			}
		}

		return count( $blocked_by_isp );
	}

	/**
	 * Extract an IP => blocked map from the JSON data.
	 *
	 * Handles various JSON structures that hayahora.futbol may return.
	 *
	 * @param array $json Decoded JSON data.
	 * @return array Associative array of IP => bool.
	 */
	private function extract_ip_map( $json ) {
		$map = array();

		/* Try known keys first */
		$ips_data = null;
		foreach ( array( 'ips', 'ip', 'data', 'results' ) as $key ) {
			if ( isset( $json[ $key ] ) && is_array( $json[ $key ] ) ) {
				$ips_data = $json[ $key ];
				break;
			}
		}

		/* Fallback: check if root keys are all IPs */
		if ( null === $ips_data && is_array( $json ) ) {
			$all_ips = true;
			foreach ( $json as $k => $v ) {
				if ( ! filter_var( $k, FILTER_VALIDATE_IP ) ) {
					$all_ips = false;
					break;
				}
			}
			if ( $all_ips && ! empty( $json ) ) {
				$ips_data = $json;
			}
		}

		if ( ! is_array( $ips_data ) ) {
			return $map;
		}

		foreach ( $ips_data as $ip => $value ) {
			/*
			 * Handle numerically-indexed arrays where each element
			 * is an object with 'ip' and 'stateChanges' fields.
			 * Example: {"data": [{"ip": "1.2.3.4", "isp": "X", "stateChanges": [...]}]}
			 */
			if ( is_int( $ip ) && is_array( $value ) && ! empty( $value['ip'] ) ) {
				$real_ip = $value['ip'];
				$blocked = $this->extract_blocked_from_object( $value );
				if ( null !== $blocked ) {
					/* If any ISP entry for this IP is blocked, mark as blocked */
					if ( ! isset( $map[ $real_ip ] ) || true === $blocked ) {
						$map[ $real_ip ] = $blocked;
					}
				}
				continue;
			}

			if ( ! is_string( $ip ) ) {
				continue;
			}

			/* Direct boolean or string value */
			if ( is_bool( $value ) ) {
				$map[ $ip ] = $value;
				continue;
			}

			if ( is_int( $value ) ) {
				$map[ $ip ] = ( 0 !== $value );
				continue;
			}

			if ( is_string( $value ) ) {
				$normalized = $this->normalize_bool_string( $value );
				if ( null !== $normalized ) {
					$map[ $ip ] = $normalized;
				}
				continue;
			}

			/* Nested object with stateChanges or blocked key */
			if ( is_array( $value ) ) {
				$blocked = $this->extract_blocked_from_object( $value );
				if ( null !== $blocked ) {
					$map[ $ip ] = $blocked;
				}
			}
		}

		return $map;
	}

	/**
	 * Normalize a string to boolean.
	 *
	 * @param string $value String to normalize.
	 * @return bool|null Boolean value or null if not recognizable.
	 */
	private function normalize_bool_string( $value ) {
		$lower = strtolower( trim( $value ) );
		if ( in_array( $lower, array( '1', 'true', 'yes', 'si', 'blocked', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( $lower, array( '0', 'false', 'no', 'unblocked', 'off' ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * Extract blocked status from a nested object.
	 *
	 * Supports objects with 'blocked', 'state', or 'stateChanges' keys.
	 *
	 * @param array $obj Nested data object.
	 * @return bool|null Whether the IP is blocked, or null.
	 */
	private function extract_blocked_from_object( $obj ) {
		/* Direct blocked key */
		foreach ( array( 'blocked', 'Blocked', 'BLOCKED' ) as $key ) {
			if ( isset( $obj[ $key ] ) ) {
				if ( is_bool( $obj[ $key ] ) ) {
					return $obj[ $key ];
				}
				$normalized = $this->normalize_bool_string( (string) $obj[ $key ] );
				if ( null !== $normalized ) {
					return $normalized;
				}
			}
		}

		/* stateChanges: pick the latest entry */
		foreach ( array( 'stateChanges', 'statechanges', 'StateChanges' ) as $key ) {
			if ( isset( $obj[ $key ] ) && is_array( $obj[ $key ] ) ) {
				return $this->latest_state_from_changes( $obj[ $key ] );
			}
		}

		return null;
	}

	/**
	 * Get the latest blocked state from a stateChanges array.
	 *
	 * @param array $changes Array of state change objects.
	 * @return bool|null Latest blocked state.
	 */
	private function latest_state_from_changes( $changes ) {
		$max_ts = null;
		$state  = null;

		foreach ( $changes as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$ts = null;
			if ( isset( $entry['timestamp'] ) ) {
				$ts = strtotime( $entry['timestamp'] );
			} elseif ( isset( $entry['date'] ) ) {
				$ts = strtotime( $entry['date'] );
			}

			if ( null === $ts || false === $ts ) {
				continue;
			}

			if ( null === $max_ts || $ts > $max_ts ) {
				$max_ts = $ts;

				/* Look for state/blocked in the entry */
				foreach ( array( 'blocked', 'state', 'status' ) as $key ) {
					if ( isset( $entry[ $key ] ) ) {
						if ( is_bool( $entry[ $key ] ) ) {
							$state = $entry[ $key ];
						} else {
							$normalized = $this->normalize_bool_string( (string) $entry[ $key ] );
							if ( null !== $normalized ) {
								$state = $normalized;
							}
						}
						break;
					}
				}
			}
		}

		/*
		 * Staleness threshold: if the latest state is "blocked" but older
		 * than 6 hours, treat it as unblocked. This prevents orphaned
		 * entries in the JSON (where a state:false was never recorded)
		 * from keeping the bypass active indefinitely.
		 */
		$max_stale_seconds = (int) apply_filters( 'ayudawp_blg_stale_threshold', 6 * HOUR_IN_SECONDS );
		if ( true === $state && null !== $max_ts && ( time() - $max_ts ) > $max_stale_seconds ) {
			$state = false;
		}

		return $state;
	}
}
