<?php
/**
 * Uninstall script.
 *
 * @package Bypass_LaLigaGate
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ayudawp_blg_settings' );
delete_option( 'ayudawp_blg_dns_cache' );
delete_option( 'ayudawp_blg_bypass_state' );
