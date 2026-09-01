<?php
/**
 * Resolver for the site's own public IPv4 addresses.
 *
 * Needed by the "solo mi IP" detection mode: to know whether La Liga is
 * blocking *this* site we first need the addresses the world sees for it,
 * which for a proxied record are Cloudflare anycast IPs and not the record's
 * own content (that is the origin).
 *
 * Resolution goes through DNS-over-HTTPS on purpose. gethostbyname() uses the
 * hosting resolver, and on shared hosting that resolver very often answers the
 * site's own domain with the local server IP (split-horizon DNS from cPanel and
 * friends), which would silently make the whole feature useless. DoH always
 * gives the public answer.
 *
 * @package Bypass_LaLigaGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AyudaWP_BLG_Own_IP_Resolver
 */
class AyudaWP_BLG_Own_IP_Resolver {

	/**
	 * How long a resolution stays valid before we look it up again.
	 * Cloudflare does re-assign zone IPs from time to time, just not often.
	 */
	const TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * DoH endpoints, tried in order. Both speak the JSON flavour.
	 *
	 * @var array
	 */
	private $doh = array(
		'doh-cloudflare' => 'https://cloudflare-dns.com/dns-query',
		'doh-google'     => 'https://dns.google/resolve',
	);

	/**
	 * Hostnames of the records the plugin manages that are currently proxied.
	 *
	 * Only proxied records are usable: a DNS Only record already points at the
	 * origin, so resolving it would poison the cache with an address that can
	 * never appear on a block list. AAAA is skipped because hayahora.futbol
	 * only tracks IPv4 (see is_verifiable_record()).
	 *
	 * @param array      $records      DNS records as cached from Cloudflare.
	 * @param array|null $selected_ids Record IDs the user manages, or null for
	 *                                 every proxied record in the zone.
	 * @return array Unique hostnames.
	 */
	public function get_proxied_hosts( $records, $selected_ids ) {
		$hosts = array();

		if ( ! is_array( $records ) ) {
			return $hosts;
		}

		$only_selected = is_array( $selected_ids );

		foreach ( $records as $rec ) {
			if ( $only_selected && ( empty( $rec['id'] ) || ! in_array( $rec['id'], $selected_ids, true ) ) ) {
				continue;
			}
			if ( empty( $rec['proxied'] ) || empty( $rec['name'] ) ) {
				continue;
			}
			if ( ! self::is_verifiable_record( $rec ) ) {
				continue;
			}
			$hosts[ strtolower( $rec['name'] ) ] = true;
		}

		return array_keys( $hosts );
	}

	/**
	 * Whether a record's public address can be checked against the block lists.
	 *
	 * hayahora.futbol publishes IPv4 only, so an AAAA record can never be
	 * matched: there is no list to match it against. The admin UI flags these
	 * so nobody assumes an AAAA-only setup is being verified.
	 *
	 * @param array $record DNS record.
	 * @return bool
	 */
	public static function is_verifiable_record( $record ) {
		$type = strtoupper( $record['type'] ?? '' );
		return in_array( $type, array( 'A', 'CNAME' ), true );
	}

	/**
	 * Refresh the cached IPs if the hostname set changed or the TTL expired.
	 *
	 * @param array $records      DNS records as cached from Cloudflare.
	 * @param array $selected_ids Record IDs the user manages.
	 * @param bool  $force        Ignore the TTL.
	 * @return array The (possibly unchanged) cache.
	 */
	public function maybe_refresh( $records, $selected_ids, $force = false ) {
		$cache = ayudawp_blg_get_own_ips_cache();
		$hosts = $this->get_proxied_hosts( $records, $selected_ids );

		if ( empty( $hosts ) && empty( $cache['ips'] ) ) {
			/*
			 * Nothing selected is proxied and we have never resolved anything.
			 * This is what happens when somebody turns the mode on while the
			 * bypass is already running, and without a way out it deadlocks:
			 * no proxied record to resolve, so no IPs, so the check degrades to
			 * global, so the bypass stays on, so no record goes back to proxied.
			 *
			 * Any other proxied record in the zone breaks the loop, because
			 * Cloudflare assigns its anycast addresses per zone and every
			 * proxied record in it resolves to the same ones.
			 */
			$hosts = $this->get_proxied_hosts( $records, null );
		}

		if ( empty( $hosts ) ) {
			/* Still nothing resolvable; keep whatever we already knew. */
			return $cache;
		}

		$known   = array_keys( is_array( $cache['hosts'] ) ? $cache['hosts'] : array() );
		$changed = ( array_diff( $hosts, $known ) || array_diff( $known, $hosts ) );
		$expired = ( time() - intval( $cache['updated'] ) ) > self::TTL;

		if ( ! $force && ! $changed && ! $expired && ! empty( $cache['ips'] ) ) {
			return $cache;
		}

		return $this->refresh( $hosts );
	}

	/**
	 * Resolve every hostname and store the result.
	 *
	 * A refresh that resolves nothing at all leaves the previous cache in
	 * place: an empty cache would silently drop the site back to global
	 * detection, and a transient DNS hiccup is not a reason to change how the
	 * plugin behaves.
	 *
	 * @param array $hosts Hostnames to resolve.
	 * @return array The stored cache.
	 */
	public function refresh( $hosts ) {
		$cache    = ayudawp_blg_get_own_ips_cache();
		$by_host  = array();
		$all      = array();
		$source   = '';

		foreach ( $hosts as $host ) {
			$res = $this->resolve( $host );
			if ( empty( $res['ips'] ) ) {
				continue;
			}
			$by_host[ $host ] = $res['ips'];
			$all              = array_merge( $all, $res['ips'] );
			if ( '' === $source ) {
				$source = $res['source'];
			}
		}

		if ( empty( $all ) ) {
			return $cache;
		}

		$cache = array(
			'ips'     => array_values( array_unique( $all ) ),
			'hosts'   => $by_host,
			'updated' => time(),
			'source'  => $source,
		);

		ayudawp_blg_save_own_ips_cache( $cache );

		return $cache;
	}

	/**
	 * Resolve one hostname to its public IPv4 addresses.
	 *
	 * @param string $host Hostname.
	 * @return array{ips: array, source: string}
	 */
	public function resolve( $host ) {
		$host = trim( (string) $host );
		if ( '' === $host ) {
			return array( 'ips' => array(), 'source' => '' );
		}

		foreach ( $this->doh as $label => $endpoint ) {
			$ips = $this->resolve_doh( $endpoint, $host );
			if ( ! empty( $ips ) ) {
				return array( 'ips' => $ips, 'source' => $label );
			}
		}

		/* Last resort: the hosting resolver, with all the caveats above. */
		if ( function_exists( 'gethostbynamel' ) ) {
			$ips = gethostbynamel( $host );
			if ( is_array( $ips ) ) {
				$ips = array_values( array_filter( $ips, array( $this, 'is_public_ipv4' ) ) );
				if ( ! empty( $ips ) ) {
					return array( 'ips' => $ips, 'source' => 'php' );
				}
			}
		}

		return array( 'ips' => array(), 'source' => '' );
	}

	/**
	 * Query one DNS-over-HTTPS endpoint for A records.
	 *
	 * @param string $endpoint DoH base URL.
	 * @param string $host     Hostname.
	 * @return array IPv4 addresses.
	 */
	private function resolve_doh( $endpoint, $host ) {
		$url = add_query_arg(
			array(
				'name' => rawurlencode( $host ),
				'type' => 'A',
			),
			$endpoint
		);

		$response = wp_remote_get( $url, array(
			'timeout'     => 8,
			'redirection' => 2,
			'headers'     => array( 'Accept' => 'application/dns-json' ),
			'user-agent'  => 'BypassLaLigaGate/' . AYUDAWP_BLG_VERSION . '; ' . home_url( '/' ),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['Answer'] ) || ! is_array( $body['Answer'] ) ) {
			return array();
		}

		$ips = array();
		foreach ( $body['Answer'] as $answer ) {
			/* Type 1 is A. CNAME answers (type 5) come in the same array and are ignored. */
			if ( ! is_array( $answer ) || 1 !== intval( $answer['type'] ?? 0 ) ) {
				continue;
			}
			$ip = trim( (string) ( $answer['data'] ?? '' ) );
			if ( $this->is_public_ipv4( $ip ) ) {
				$ips[ $ip ] = true;
			}
		}

		return array_keys( $ips );
	}

	/**
	 * Reject anything that cannot be a public IPv4 address.
	 *
	 * A private or reserved address here means the resolver answered with an
	 * internal view of the domain, which is precisely the shared-hosting case
	 * DoH is meant to avoid; caching it would make the site look permanently
	 * unblocked.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public function is_public_ipv4( $ip ) {
		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
