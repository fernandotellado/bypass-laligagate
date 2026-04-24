<?php
/**
 * Uninstall script.
 *
 * @package Bypass_LaLigaGate
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$config = get_option( 'ayudawp_blg_settings', array() );

/* Only delete data if the user opted in (default: yes) */
$delete = isset( $config['delete_data'] ) ? (int) $config['delete_data'] : 1;

if ( $delete ) {
	delete_option( 'ayudawp_blg_settings' );
	delete_option( 'ayudawp_blg_dns_cache' );
	delete_option( 'ayudawp_blg_bypass_state' );
	delete_option( 'ayudawp_blg_block_log' );
}
