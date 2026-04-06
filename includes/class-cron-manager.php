<?php
/**
 * WP-Cron manager with email notifications on state changes.
 *
 * @package Bypass_LaLigaGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AyudaWP_BLG_Cron_Manager {

	public function register_hooks() {
		add_filter( 'cron_schedules', array( $this, 'add_custom_interval' ) );
		add_action( AYUDAWP_BLG_CRON_HOOK, array( $this, 'run_check' ) );
		add_action( 'init', array( $this, 'maybe_process_external_cron' ) );
		add_action( 'update_option_' . AYUDAWP_BLG_OPT_CONFIG, array( $this, 'maybe_reschedule' ), 10, 2 );
	}

	public function add_custom_interval( $schedules ) {
		$cfg     = ayudawp_blg_get_config();
		$minutes = max( 5, min( 60, intval( $cfg['check_interval'] ) ) );
		$schedules['ayudawp_blg_interval'] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => sprintf( 'Cada %d minutos (Bypass LaLigaGate)', $minutes ),
		);
		return $schedules;
	}

	/**
	 * Main check. Notifies admin by email when proxy state changes.
	 */
	public function run_check() {
		$cfg   = ayudawp_blg_get_config();
		$state = ayudawp_blg_get_state();
		$api   = new AyudaWP_BLG_Cloudflare_API( $cfg );

		if ( ! $api->is_configured() || empty( $cfg['selected_records'] ) ) {
			return;
		}

		$checker = new AyudaWP_BLG_Block_Checker();
		$status  = $checker->check_status();

		$state['last_check'] = current_time( 'mysql' );

		if ( ! empty( $status['error'] ) ) {
			ayudawp_blg_save_state( $state );
			return;
		}

		$blocks_active        = $status['blocked'];
		$state['last_status'] = $blocks_active ? 'SI' : 'NO';

		/* Manual override: only update info, don't touch proxy */
		if ( ! empty( $state['manual_override'] ) ) {
			ayudawp_blg_save_state( $state );
			return;
		}

		/* Automatic logic */
		$was_active    = ! empty( $state['bypass_active'] );
		$bypass_since  = intval( $state['bypass_since'] );
		$cooldown_secs = max( 5, intval( $cfg['cooldown'] ) ) * MINUTE_IN_SECONDS;
		$now           = time();

		$should_disable = false;
		if ( $blocks_active ) {
			$should_disable = true;
		} elseif ( $was_active && $bypass_since > 0 && ( $now - $bypass_since ) < $cooldown_secs ) {
			$should_disable = true;
		}

		$desired_proxy = ! $should_disable;

		/* Fetch fresh DNS and apply changes */
		$fresh = $api->fetch_dns_records();
		if ( ! empty( $fresh ) ) {
			ayudawp_blg_save_dns_cache( $fresh );
		}

		foreach ( $cfg['selected_records'] as $rid ) {
			$rec = ayudawp_blg_find_record( $fresh, $rid );
			if ( $rec ) {
				$api->set_proxy_status( $rec, $desired_proxy );
			}
		}

		/* Detect state changes for email notifications */
		$state_changed_to_active   = false;
		$state_changed_to_inactive = false;

		if ( $should_disable ) {
			if ( ! $was_active ) {
				$state['bypass_since']   = $now;
				$state_changed_to_active = true;
			}
			$state['bypass_active'] = 1;
		} else {
			if ( $was_active ) {
				$state_changed_to_inactive = true;
			}
			$state['bypass_active'] = 0;
			$state['bypass_since']  = 0;
		}

		ayudawp_blg_save_state( $state );

		/* Send email notifications on state changes */
		$notifier = new AyudaWP_BLG_Email_Notifier();
		if ( $state_changed_to_active ) {
			$notifier->notify_proxy_disabled();
		} elseif ( $state_changed_to_inactive ) {
			$notifier->notify_proxy_restored();
		}
	}

	public function maybe_process_external_cron() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['bypass_blg_cron'] ) ) {
			return;
		}
		$cfg = ayudawp_blg_get_config();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		if ( empty( $cfg['cron_secret'] ) || ! hash_equals( $cfg['cron_secret'], $token ) ) {
			wp_die( 'Token no válido', 'Acceso denegado', array( 'response' => 403 ) );
		}
		$this->run_check();
		wp_die( 'Bypass LaLigaGate: cron OK', '', array( 'response' => 200 ) );
	}

	public function maybe_reschedule( $old_value, $new_value ) {
		$old_i = isset( $old_value['check_interval'] ) ? intval( $old_value['check_interval'] ) : 0;
		$new_i = isset( $new_value['check_interval'] ) ? intval( $new_value['check_interval'] ) : 0;
		if ( $old_i === $new_i ) {
			return;
		}
		$ts = wp_next_scheduled( AYUDAWP_BLG_CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, AYUDAWP_BLG_CRON_HOOK );
		}
		wp_schedule_event( time() + 30, 'ayudawp_blg_interval', AYUDAWP_BLG_CRON_HOOK );
	}
}
