<?php
/**
 * Plugin Name: Bypass LaLigaGate
 * Plugin URI: https://mantenimiento.ayudawp.com
 * Description: Gestiona automáticamente el proxy de Cloudflare durante los bloqueos de IP por partidos de fútbol en España, alternando entre Proxied (CDN) y DNS Only.
 * Version: 1.2.1
 * Author: Mantenimiento WordPress
 * Author URI: https://mantenimiento.ayudawp.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bypass-laligagate
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 * @package Bypass_LaLigaGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AYUDAWP_BLG_VERSION', '1.2.1' );
define( 'AYUDAWP_BLG_FILE', __FILE__ );
define( 'AYUDAWP_BLG_DIR', plugin_dir_path( __FILE__ ) );
define( 'AYUDAWP_BLG_URL', plugin_dir_url( __FILE__ ) );
define( 'AYUDAWP_BLG_CRON_HOOK', 'ayudawp_blg_check_status' );

/* Tres opciones independientes en wp_options para evitar sobreescrituras */
define( 'AYUDAWP_BLG_OPT_CONFIG', 'ayudawp_blg_settings' );   /* Credenciales, intervalos, registros seleccionados */
define( 'AYUDAWP_BLG_OPT_DNS', 'ayudawp_blg_dns_cache' );     /* Cache de registros DNS de Cloudflare */
define( 'AYUDAWP_BLG_OPT_STATE', 'ayudawp_blg_bypass_state' ); /* Estado del bypass, ultima comprobacion */

require_once AYUDAWP_BLG_DIR . 'includes/class-cloudflare-api.php';
require_once AYUDAWP_BLG_DIR . 'includes/class-block-checker.php';
require_once AYUDAWP_BLG_DIR . 'includes/class-cron-manager.php';
require_once AYUDAWP_BLG_DIR . 'includes/class-email-notifier.php';
require_once AYUDAWP_BLG_DIR . 'includes/class-admin-page.php';
require_once AYUDAWP_BLG_DIR . 'includes/class-ajax-handler.php';

function ayudawp_blg_init() {
	$cron = new AyudaWP_BLG_Cron_Manager();
	$cron->register_hooks();
	if ( is_admin() ) {
		$admin = new AyudaWP_BLG_Admin_Page();
		$admin->register_hooks();
		$ajax = new AyudaWP_BLG_Ajax_Handler();
		$ajax->register_hooks();
	}
}
add_action( 'plugins_loaded', 'ayudawp_blg_init' );

/* ---- Activacion ---- */
function ayudawp_blg_activate() {
	if ( false === get_option( AYUDAWP_BLG_OPT_CONFIG ) ) {
		update_option( AYUDAWP_BLG_OPT_CONFIG, ayudawp_blg_default_config() );
	}
	if ( false === get_option( AYUDAWP_BLG_OPT_STATE ) ) {
		update_option( AYUDAWP_BLG_OPT_STATE, ayudawp_blg_default_state(), false );
	}
	if ( ! wp_next_scheduled( AYUDAWP_BLG_CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, 'ayudawp_blg_interval', AYUDAWP_BLG_CRON_HOOK );
	}
	set_transient( 'ayudawp_blg_activated', 1, 60 );
}
register_activation_hook( __FILE__, 'ayudawp_blg_activate' );

/* ---- Desactivacion: limpia cron y restaura proxy ---- */
function ayudawp_blg_deactivate() {
	$ts = wp_next_scheduled( AYUDAWP_BLG_CRON_HOOK );
	if ( $ts ) {
		wp_unschedule_event( $ts, AYUDAWP_BLG_CRON_HOOK );
	}
	$cfg = ayudawp_blg_get_config();
	$dns = ayudawp_blg_get_dns_cache();
	if ( ! empty( $cfg['selected_records'] ) && ! empty( $cfg['cf_api_key'] ) ) {
		$api = new AyudaWP_BLG_Cloudflare_API( $cfg );
		foreach ( $cfg['selected_records'] as $rid ) {
			$rec = ayudawp_blg_find_record( $dns, $rid );
			if ( $rec ) {
				$api->set_proxy_status( $rec, true );
			}
		}
	}
}
register_deactivation_hook( __FILE__, 'ayudawp_blg_deactivate' );

/* ---- Aviso tras activar ---- */
function ayudawp_blg_activation_notice() {
	if ( ! get_transient( 'ayudawp_blg_activated' ) ) {
		return;
	}
	delete_transient( 'ayudawp_blg_activated' );
	$url = admin_url( 'tools.php?page=bypass-laligagate' );
	printf(
		'<div class="notice notice-info is-dismissible"><p><strong>Bypass LaLigaGate</strong> activado. <a href="%s">Configura las credenciales de Cloudflare y los registros DNS</a> para empezar.</p></div>',
		esc_url( $url )
	);
}
add_action( 'admin_notices', 'ayudawp_blg_activation_notice' );

/* ---- Enlace Ajustes en la lista de plugins ---- */
function ayudawp_blg_plugin_action_links( $links ) {
	$url = admin_url( 'tools.php?page=bypass-laligagate' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">Ajustes</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ayudawp_blg_plugin_action_links' );

/* ---- Helpers: configuracion ---- */
function ayudawp_blg_default_config() {
	return array(
		'auth_type'           => 'global',
		'cf_email'            => '',
		'cf_api_key'          => '',
		'cf_zone_id'          => '',
		'check_interval'      => 15,
		'cooldown'            => 60,
		'selected_records'    => array(),
		'cron_secret'         => wp_generate_password( 32, false, false ),
		'min_isps'            => 1,
		'email_notifications' => 1,
		'delete_data'         => 1,
	);
}

function ayudawp_blg_get_config() {
	return wp_parse_args( get_option( AYUDAWP_BLG_OPT_CONFIG, array() ), ayudawp_blg_default_config() );
}

function ayudawp_blg_save_config( $config ) {
	return update_option( AYUDAWP_BLG_OPT_CONFIG, $config );
}

/* ---- Helpers: estado del bypass ---- */
function ayudawp_blg_default_state() {
	return array(
		'bypass_active'   => 0,
		'bypass_since'    => 0,
		'blocks_ended_at' => 0,
		'manual_override' => 0,
		'last_check'      => '',
		'last_check_ts'   => 0,
		'last_status'     => 'NO',
	);
}

function ayudawp_blg_get_state() {
	return wp_parse_args( get_option( AYUDAWP_BLG_OPT_STATE, array() ), ayudawp_blg_default_state() );
}

function ayudawp_blg_save_state( $state ) {
	return update_option( AYUDAWP_BLG_OPT_STATE, $state, false );
}

/* ---- Helpers: cache DNS ---- */
function ayudawp_blg_get_dns_cache() {
	$c = get_option( AYUDAWP_BLG_OPT_DNS, array() );
	return is_array( $c ) ? $c : array();
}

function ayudawp_blg_save_dns_cache( $records ) {
	return update_option( AYUDAWP_BLG_OPT_DNS, $records, false );
}

/* ---- Helpers: busqueda de registros ---- */
function ayudawp_blg_find_record( $records, $record_id ) {
	foreach ( $records as $r ) {
		if ( isset( $r['id'] ) && $r['id'] === $record_id ) {
			return $r;
		}
	}
	return null;
}

/* ---- Helpers: diagnostico del cron ---- */
/**
 * Build a diagnostic snapshot of the recurring cron, used by the admin UI and
 * the AJAX handler. Centralised so staleness is computed the same way
 * everywhere.
 *
 * Keys:
 *   next_ts           unix ts of next scheduled run (0 if not scheduled)
 *   next_human        human-readable date (or empty)
 *   last_check_ts     unix ts of last successful run (0 if never)
 *   seconds_since     elapsed seconds since last_check_ts (0 if never)
 *   interval_secs     configured interval in seconds
 *   scheduled         whether the event is scheduled at all
 *   stale             last check older than 2x interval (bool)
 *   overdue           next run is in the past by more than 5 min (bool)
 *   unhealthy         scheduled==false || stale || overdue
 */
function ayudawp_blg_get_cron_diagnostics() {
	$cfg            = ayudawp_blg_get_config();
	$state          = ayudawp_blg_get_state();
	$interval_secs  = max( 5, min( 60, intval( $cfg['check_interval'] ) ) ) * MINUTE_IN_SECONDS;
	$next_ts        = wp_next_scheduled( AYUDAWP_BLG_CRON_HOOK );
	$next_ts        = $next_ts ? intval( $next_ts ) : 0;
	$last_check_ts  = intval( $state['last_check_ts'] );
	$now            = time();
	$seconds_since  = $last_check_ts > 0 ? max( 0, $now - $last_check_ts ) : 0;
	$scheduled      = $next_ts > 0;
	$stale          = $last_check_ts > 0 && $seconds_since > ( 2 * $interval_secs );
	$overdue        = $scheduled && ( $now - $next_ts ) > ( 5 * MINUTE_IN_SECONDS );

	$next_human = '';
	if ( $scheduled ) {
		$next_human = wp_date( 'Y-m-d H:i:s', $next_ts );
	}

	return array(
		'next_ts'       => $next_ts,
		'next_human'    => $next_human,
		'last_check_ts' => $last_check_ts,
		'seconds_since' => $seconds_since,
		'interval_secs' => $interval_secs,
		'scheduled'     => $scheduled,
		'stale'         => $stale,
		'overdue'       => $overdue,
		'unhealthy'     => ( ! $scheduled ) || $stale || $overdue,
	);
}
