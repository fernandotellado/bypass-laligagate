<?php
/**
 * Uninstall script.
 *
 * @package Bypass_LaLigaGate
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ayudawp_blg_config = get_option( 'ayudawp_blg_settings', array() );

/* Only delete data if the user opted in (default: yes) */
$ayudawp_blg_delete = isset( $ayudawp_blg_config['delete_data'] ) ? (int) $ayudawp_blg_config['delete_data'] : 1;

if ( $ayudawp_blg_delete ) {
	delete_option( 'ayudawp_blg_settings' );
	delete_option( 'ayudawp_blg_dns_cache' );
	delete_option( 'ayudawp_blg_bypass_state' );
	delete_option( 'ayudawp_blg_block_log' );
	delete_option( 'ayudawp_blg_own_ips' );
}
