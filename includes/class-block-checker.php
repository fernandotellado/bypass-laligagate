<?php
/**
 * Block status checker.
 *
 * Resolves whether there are active football-related IP blocks in Spain by
 * consulting hayahora.futbol.
 *
 * The plain-text endpoints are the primary source: blocked-any.txt is the
 * union of every ISP and there is one file per ISP, so a full per-ISP
 * breakdown costs six small requests. Empty it is 0 bytes; at the busiest
 * moment measured so far (22 Aug 2026, 886 addresses blocked at once) it would
 * be around 13 KB. The historical JSON is a fallback only: it grows without
 * bound, already weighs 2.7 MB, and decoding it costs about eleven times that
 * in RAM.
 *
 * Two things this class refuses to do, both because a wrong "no blocks" takes
 * the site down while a wrong "blocked" only costs the CDN:
 *
 *   - Believe a stale source. An empty list from a generator that stopped
 *     hours ago is silence, not an answer, so it is reported as an error and
 *     the caller keeps the current state.
 *   - Decode the JSON when there is no room for it. Better an error the plugin
 *     handles than a fatal that kills the cron run halfway through.
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
	 * Transient that keeps the JSON fallback from running on every cycle.
	 */
	const JSON_COOLDOWN_KEY = 'ayudawp_blg_json_cooldown';

	/**
	 * How much bigger than the raw JSON the decoded arrays are. Measured on
	 * PHP 8.5 with the 30 Aug 2026 dataset: 2.69 MB of text became 28.7 MB of
	 * arrays. Rounded up, because being wrong the other way is a fatal error.
	 */
	const JSON_DECODE_FACTOR = 12;

	/**
	 * Remote JSON endpoint URL (full history, 2.7 MB on 30 Aug 2026 and growing).
	 *
	 * @var string
	 */
	private $json_url = 'https://hayahora.futbol/estado/data.json';

	/**
	 * Base URL for the plain-text endpoints.
	 *
	 * @var string
	 */
	private $txt_base = 'https://hayahora.futbol/estado/';

	/**
	 * Per-ISP TXT files, slug => label as hayahora.futbol names them.
	 *
	 * @var array
	 */
	private $isp_files = array(
		'movistar' => 'Movistar',
		'vodafone' => 'Vodafone',
		'orange'   => 'Orange',
		'masmovil' => 'Masmovil',
		'digi'     => 'DIGI',
	);

	/**
	 * Check whether there are blocks that should trigger the bypass.
	 *
	 * Two detection modes:
	 *
	 *   global — any blocked IP anywhere counts. This is what the plugin has
	 *            always done and it errs heavily on the safe side: since the
	 *            season started there are blocked IPs during most match
	 *            windows, so the bypass stays on for hours whether or not this
	 *            particular site is among the collateral damage.
	 *
	 *   own_ip — only blocks hitting one of this site's own public IPs count.
	 *            Precise, but it depends on those IPs being known: the caller
	 *            passes them in and we fall back to global detection when the
	 *            list is empty, rather than reporting a comfortable "no blocks"
	 *            we cannot back up.
	 *
	 * The per-ISP breakdown is only fetched when it changes an outcome
	 * (min_isps > 1) or when it is worth showing (own_ip mode, where knowing
	 * which operators block you is the actionable part).
	 *
	 * Filters, all of them optional:
	 *   ayudawp_blg_txt_url        blocked-any.txt endpoint
	 *   ayudawp_blg_isp_txt_url    per-ISP endpoint, receives the slug
	 *   ayudawp_blg_json_url       JSON fallback endpoint
	 *   ayudawp_blg_source         force 'txt' or 'json'
	 *   ayudawp_blg_max_data_age   how old the data may be, 3 h by default,
	 *                              0 to accept any age
	 *   ayudawp_blg_json_cooldown  minimum gap between JSON fallbacks, 1 h
	 *   ayudawp_blg_txt_max_bytes  hard cap on a TXT response, 1 MB
	 *   ayudawp_blg_json_max_bytes hard cap on the JSON response, 12 MB
	 *
	 * @param array $args {
	 *     @type int    $min_isps Minimum ISPs with blocks to trigger. Default 1.
	 *     @type string $mode     'global' or 'own_ip'. Default 'global'.
	 *     @type array  $own_ips  This site's public IPv4 addresses.
	 * }
	 * @return array{blocked: bool, last_update: string, last_update_ts: int, error: string, error_kind: string, blocked_isps: int, blocked_ips: int, isp_names: array, matched: array, mode: string, source: string}
	 */
	public function check_status( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'min_isps' => 1,
			'mode'     => 'global',
			'own_ips'  => array(),
		) );

		$min_isps = max( 1, intval( $args['min_isps'] ) );
		$own_ips  = is_array( $args['own_ips'] ) ? array_values( array_unique( $args['own_ips'] ) ) : array();
		$degraded = false;

		$mode = ( 'own_ip' === $args['mode'] ) ? 'own_ip' : 'global';
		if ( 'own_ip' === $mode && empty( $own_ips ) ) {
			/* Asked for precision we cannot deliver: better a false alarm than a missed block. */
			$mode     = 'global';
			$degraded = true;
		}

		$source = (string) apply_filters( 'ayudawp_blg_source', 'txt', $min_isps );

		if ( 'json' === $source ) {
			$result = $this->check_via_json( $min_isps, $mode, $own_ips );
		} else {
			$result = $this->check_via_txt( $min_isps, $mode, $own_ips );
			if ( '' !== $result['error'] && 'stale' !== $result['error_kind'] ) {
				/*
				 * A hosting glitch on the TXT files should not blind us. Stale
				 * data is a different matter: the same process writes both
				 * formats, so if the TXT stopped the JSON stopped too, and it
				 * would cost 2.7 MB to find that out.
				 */
				$result           = $this->check_via_json( $min_isps, $mode, $own_ips );
				$result['source'] = $result['source'] . '+txt-fallback';
			}
		}

		if ( $degraded ) {
			$result['source'] = $result['source'] . '+sin-ips';
		}

		return $result;
	}

	/**
	 * Empty result skeleton.
	 *
	 * @param string $mode   Detection mode in effect.
	 * @param string $source Source label.
	 * @return array
	 */
	private function blank_result( $mode, $source ) {
		return array(
			'blocked'        => false,
			'last_update'    => '',
			'last_update_ts' => 0,
			'error'          => '',
			'error_kind'     => '',
			'blocked_isps'   => 0,
			'blocked_ips'    => 0,
			'isp_names'      => array(),
			'matched'        => array(),
			'mode'           => $mode,
			'source'         => $source,
		);
	}

	/**
	 * Check using the plain-text endpoints.
	 *
	 * blocked-any.txt is the union of every operator, so an IP missing from it
	 * is blocked nowhere and there is no reason to fetch the five per-ISP
	 * files. That short-circuit keeps the common case at a single 3 KB request.
	 *
	 * @param int    $min_isps Minimum ISPs with blocks to trigger.
	 * @param string $mode     'global' or 'own_ip'.
	 * @param array  $own_ips  This site's public IPv4 addresses.
	 * @return array
	 */
	private function check_via_txt( $min_isps, $mode, $own_ips ) {
		$result = $this->blank_result( $mode, 'txt' );

		$url   = (string) apply_filters( 'ayudawp_blg_txt_url', $this->txt_base . 'blocked-any.txt' );
		$fetch = $this->fetch_ip_list( $url );

		if ( '' !== $fetch['error'] ) {
			$result['error'] = $fetch['error'];
			return $result;
		}

		$result['last_update']    = $fetch['last_update'];
		$result['last_update_ts'] = $fetch['last_update_ts'];

		$stale = $this->staleness_error( $fetch['last_update_ts'] );
		if ( '' !== $stale ) {
			$result['error']      = $stale;
			$result['error_kind'] = 'stale';
			return $result;
		}

		/* No Last-Modified to judge by. Not knowing the age is not the same as
		   knowing the data is fresh, so say so instead of staying quiet. */
		$undated = ( 0 === $fetch['last_update_ts'] );
		if ( $undated ) {
			$result['source'] = 'txt (sin fecha)';
		}

		$candidates = ( 'own_ip' === $mode )
			? array_values( array_intersect( $own_ips, $fetch['ips'] ) )
			: $fetch['ips'];

		if ( empty( $candidates ) ) {
			return $result;
		}

		$result['blocked_ips'] = count( $candidates );
		if ( 'own_ip' === $mode ) {
			$result['matched'] = $candidates;
		}

		/* Only pay for the per-ISP breakdown when it decides something or informs the user. */
		if ( $min_isps <= 1 && 'own_ip' !== $mode ) {
			$result['blocked'] = true;
			return $result;
		}

		$isps = $this->count_isps_via_txt( $candidates, $mode );

		if ( ! $isps['ok'] ) {
			/*
			 * The union says there are blocks but the breakdown is unavailable.
			 * Treating that as "not blocked" would drop the bypass during a real
			 * block, so we trigger and flag the uncertainty in the source label.
			 */
			$result['blocked'] = true;
			$result['source']  = 'txt (ISPs sin determinar)' . ( $undated ? ' (sin fecha)' : '' );
			return $result;
		}

		$result['blocked_isps'] = $isps['count'];
		$result['isp_names']    = $isps['names'];
		$result['blocked']      = ( $isps['count'] >= $min_isps );

		return $result;
	}

	/**
	 * Decide whether a Last-Modified is too old to be treated as an answer.
	 *
	 * hayahora.futbol regenerates these files continuously (minutes apart, with
	 * or without blocks), so a timestamp hours old means the generator stopped,
	 * not that Spain went quiet. Believing it would restore the proxy in the
	 * middle of a match, which is the one failure this plugin exists to avoid.
	 *
	 * A missing header is NOT treated as stale: not being able to tell is not
	 * evidence of anything, and turning it into an error would take the plugin
	 * offline the day the endpoints move behind a CDN that drops the header.
	 *
	 * @param int $ts Unix timestamp from Last-Modified, 0 when absent.
	 * @return string Error message, empty when the data is usable.
	 */
	private function staleness_error( $ts ) {
		if ( $ts <= 0 ) {
			return '';
		}

		$max_age = (int) apply_filters( 'ayudawp_blg_max_data_age', 3 * HOUR_IN_SECONDS );
		if ( $max_age <= 0 ) {
			return '';
		}

		$age = time() - $ts;
		if ( $age <= $max_age ) {
			return '';
		}

		return sprintf(
			'Los datos de hayahora.futbol no se actualizan desde hace %s, no se puede saber si hay bloqueos.',
			human_time_diff( $ts, time() )
		);
	}

	/**
	 * Count how many operators are blocking, fetching one TXT file per ISP.
	 *
	 * @param array  $candidates IPs that matter (all blocked IPs, or just ours).
	 * @param string $mode       'global' or 'own_ip'.
	 * @return array{count: int, names: array, ok: bool}
	 */
	private function count_isps_via_txt( $candidates, $mode ) {
		$names  = array();
		$any_ok = false;

		foreach ( $this->isp_files as $slug => $label ) {
			$url   = (string) apply_filters( 'ayudawp_blg_isp_txt_url', $this->txt_base . 'blocked-' . $slug . '.txt', $slug );
			$fetch = $this->fetch_ip_list( $url );

			if ( '' !== $fetch['error'] ) {
				continue;
			}

			$any_ok = true;

			$hits = ( 'own_ip' === $mode )
				? array_intersect( $candidates, $fetch['ips'] )
				: $fetch['ips'];

			if ( ! empty( $hits ) ) {
				$names[] = $label;
			}
		}

		return array(
			'count' => count( $names ),
			'names' => $names,
			'ok'    => $any_ok,
		);
	}

	/**
	 * Fetch one TXT endpoint and parse it into a list of IPs.
	 *
	 * Lines are validated as IPv4 because a hosting error page served with a
	 * 200 would otherwise parse as a very long list of blocked addresses.
	 *
	 * @param string $url Endpoint URL.
	 * @return array{ips: array, last_update: string, last_update_ts: int, error: string}
	 */
	private function fetch_ip_list( $url ) {
		$out = array(
			'ips'            => array(),
			'last_update'    => '',
			'last_update_ts' => 0,
			'error'          => '',
		);

		$response = wp_remote_get( $url, array(
			'timeout'             => 10,
			'redirection'         => 3,
			/* The busiest moment measured so far would be ~13 KB; a megabyte is
			   already far past anything these files can legitimately be. */
			'limit_response_size' => (int) apply_filters( 'ayudawp_blg_txt_max_bytes', MB_IN_BYTES ),
			'user-agent'          => 'BypassLaLigaGate/' . AYUDAWP_BLG_VERSION . '; ' . home_url( '/' ),
		) );

		if ( is_wp_error( $response ) ) {
			$out['error'] = $response->get_error_message();
			return $out;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$out['error'] = 'HTTP ' . $code;
			return $out;
		}

		$body  = (string) wp_remote_retrieve_body( $response );
		$lines = preg_split( '/\r\n|\r|\n/', trim( $body ) );

		if ( is_array( $lines ) ) {
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line && filter_var( $line, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					$out['ips'][] = $line;
				}
			}
		}

		$last_modified = wp_remote_retrieve_header( $response, 'last-modified' );
		if ( ! empty( $last_modified ) ) {
			$ts = strtotime( $last_modified );
			if ( false !== $ts ) {
				$out['last_update']    = gmdate( 'c', $ts );
				$out['last_update_ts'] = (int) $ts;
			}
		}

		return $out;
	}

	/**
	 * Full check against the JSON endpoint. Fallback only.
	 *
	 * This file is the whole history and it only grows: measured at 2.7 MB on
	 * 30 Aug 2026, 11.970 entries, and json_decode() turns that into about
	 * 29 MB of PHP arrays, an eleven-fold expansion. On a shared host with a
	 * 64 MB limit and a normal plugin stack already loaded, that is a fatal
	 * error inside a cron run, which is a worse outcome than not knowing.
	 *
	 * Hence three guards that the TXT path does not need: a hard response-size
	 * limit, a memory check before decoding, and a cooldown so a persistent TXT
	 * outage cannot turn into a 2.7 MB download every few minutes, forever, for
	 * every site running this plugin. hayahora.futbol asks users of these
	 * endpoints to be good citizens and that request is easy to honour.
	 *
	 * @param int    $min_isps Minimum ISPs with blocks to trigger.
	 * @param string $mode     'global' or 'own_ip'.
	 * @param array  $own_ips  This site's public IPv4 addresses.
	 * @return array
	 */
	private function check_via_json( $min_isps, $mode, $own_ips ) {
		$result = $this->blank_result( $mode, 'json' );

		$cooldown = (int) apply_filters( 'ayudawp_blg_json_cooldown', HOUR_IN_SECONDS );
		if ( $cooldown > 0 && get_transient( self::JSON_COOLDOWN_KEY ) ) {
			$result['error'] = 'El respaldo JSON ya se consultó hace poco; se mantiene el estado actual.';
			return $result;
		}

		$url      = (string) apply_filters( 'ayudawp_blg_json_url', $this->json_url );
		$response = wp_remote_get( $url, array(
			'timeout'             => 20,
			'redirection'         => 3,
			'limit_response_size' => (int) apply_filters( 'ayudawp_blg_json_max_bytes', 12 * MB_IN_BYTES ),
			'user-agent'          => 'BypassLaLigaGate/' . AYUDAWP_BLG_VERSION . '; ' . home_url( '/' ),
		) );

		if ( $cooldown > 0 ) {
			set_transient( self::JSON_COOLDOWN_KEY, 1, $cooldown );
		}

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			return $result;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$result['error'] = 'HTTP ' . $code;
			return $result;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		$no_room = $this->memory_shortfall( strlen( $body ) );
		if ( '' !== $no_room ) {
			$result['error'] = $no_room;
			return $result;
		}

		$json = json_decode( $body, true );
		unset( $body );

		if ( ! is_array( $json ) ) {
			$result['error'] = 'Respuesta JSON no válida';
			return $result;
		}

		if ( ! empty( $json['lastUpdate'] ) && is_string( $json['lastUpdate'] ) ) {
			$result['last_update'] = $json['lastUpdate'];
			$ts                    = strtotime( $json['lastUpdate'] );
			if ( false !== $ts ) {
				$result['last_update_ts'] = (int) $ts;
				$stale                    = $this->staleness_error( (int) $ts );
				if ( '' !== $stale ) {
					$result['error']      = $stale;
					$result['error_kind'] = 'stale';
					return $result;
				}
			}
		}

		$breakdown              = $this->analyse_json( $json, $mode, $own_ips );
		$result['blocked_isps'] = count( $breakdown['isps'] );
		$result['isp_names']    = array_values( $breakdown['isps'] );
		$result['blocked_ips']  = count( $breakdown['ips'] );
		$result['blocked']      = ( $result['blocked_isps'] >= $min_isps );

		if ( 'own_ip' === $mode ) {
			$result['matched'] = array_values( $breakdown['ips'] );
		}

		return $result;
	}

	/**
	 * Refuse to decode a payload that will not fit in the memory left.
	 *
	 * A fatal error here is not just a failed check: it aborts the whole cron
	 * run, so anything scheduled after this plugin's hook never executes either.
	 *
	 * An unlimited memory_limit (-1) means there is nothing to compare against,
	 * so the decode goes ahead.
	 *
	 * The limit is a parameter so the harness can exercise the rejecting branch:
	 * wp-cli runs with memory_limit=-1 and refuses ini_set(), so a test that
	 * read the limit from the environment could only ever see "there is room"
	 * and would report a pass without having tried anything.
	 *
	 * @param int      $bytes Size of the raw JSON body.
	 * @param int|null $limit Memory limit in bytes, null to read it from PHP.
	 * @return string Error message, empty when there is room.
	 */
	private function memory_shortfall( $bytes, $limit = null ) {
		if ( null === $limit ) {
			$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		}
		if ( $limit <= 0 ) {
			return '';
		}

		$needed    = $bytes * self::JSON_DECODE_FACTOR;
		$available = $limit - memory_get_usage( true );

		if ( $needed < $available ) {
			return '';
		}

		return sprintf(
			'El respaldo JSON (%s) no cabe en la memoria disponible (%s); se mantiene el estado actual.',
			size_format( $bytes ),
			size_format( max( 0, $available ) )
		);
	}

	/**
	 * Walk the JSON dataset and collect currently-blocked IPs and ISPs.
	 *
	 * @param array  $json    Decoded JSON data.
	 * @param string $mode    'global' or 'own_ip'.
	 * @param array  $own_ips This site's public IPv4 addresses.
	 * @return array{isps: array, ips: array}
	 */
	private function analyse_json( $json, $mode, $own_ips ) {
		$isps = array();
		$ips  = array();

		$entries = null;
		foreach ( array( 'data', 'ips', 'ip', 'results' ) as $key ) {
			if ( isset( $json[ $key ] ) && is_array( $json[ $key ] ) ) {
				$entries = $json[ $key ];
				break;
			}
		}

		if ( ! is_array( $entries ) ) {
			return array( 'isps' => $isps, 'ips' => $ips );
		}

		foreach ( $entries as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			/* Both {"data":[{"ip":…}]} and {"1.2.3.4":{…}} shapes. */
			$ip = ! empty( $entry['ip'] ) ? (string) $entry['ip'] : ( is_string( $key ) ? $key : '' );
			if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				continue;
			}

			if ( 'own_ip' === $mode && ! in_array( $ip, $own_ips, true ) ) {
				continue;
			}

			if ( true !== $this->extract_blocked_from_object( $entry ) ) {
				continue;
			}

			$ips[ $ip ] = $ip;

			$isp = ! empty( $entry['isp'] ) ? trim( (string) $entry['isp'] ) : '';
			if ( '' !== $isp ) {
				$isps[ strtolower( $isp ) ] = $isp;
			}
		}

		/*
		 * A dataset without ISP labels still tells us something is blocked;
		 * count it as one operator so min_isps=1 keeps working.
		 */
		if ( empty( $isps ) && ! empty( $ips ) ) {
			$isps['unknown'] = 'desconocido';
		}

		return array( 'isps' => $isps, 'ips' => $ips );
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
