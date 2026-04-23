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
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( 'update_option_' . AYUDAWP_BLG_OPT_CONFIG, array( $this, 'maybe_reschedule' ), 10, 2 );
	}

	/**
	 * Watchdog: re-schedule the recurring event if it went missing.
	 *
	 * The plugin's activation hook only runs on explicit activate, so if the
	 * event ever gets unscheduled (a manual wp cron event delete, a broken
	 * update, another plugin clearing crons…) it would stay gone forever.
	 * This check is cheap and idempotent: wp_next_scheduled() short-circuits
	 * when the event already exists.
	 */
	public function ensure_scheduled() {
		if ( wp_next_scheduled( AYUDAWP_BLG_CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + 60, 'ayudawp_blg_interval', AYUDAWP_BLG_CRON_HOOK );
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
		$status  = $checker->check_status( intval( $cfg['min_isps'] ) );

		$now                    = time();
		$state['last_check']    = current_time( 'mysql' );
		$state['last_check_ts'] = $now;

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

		/*
		 * Cooldown is measured from the moment the blocks ended, not from
		 * when the bypass started. Otherwise, if a block period lasts longer
		 * than the configured cooldown, the proxy would restore instantly
		 * when the match ends, leaving no grace window at all.
		 */
		$was_active      = ! empty( $state['bypass_active'] );
		$blocks_ended_at = intval( $state['blocks_ended_at'] );
		$cooldown_secs   = max( 5, intval( $cfg['cooldown'] ) ) * MINUTE_IN_SECONDS;

		$should_disable = false;
		if ( $blocks_active ) {
			$should_disable = true;
			$state['blocks_ended_at'] = 0;
		} elseif ( $was_active ) {
			if ( $blocks_ended_at <= 0 ) {
				/* First check after blocks cleared: start cooldown now */
				$state['blocks_ended_at'] = $now;
				$blocks_ended_at          = $now;
				$should_disable           = true;
			} elseif ( ( $now - $blocks_ended_at ) < $cooldown_secs ) {
				$should_disable = true;
			}
		}

		$desired_proxy = ! $should_disable;

		/*
		 * Fetch DNS once to know current state, apply changes, then re-fetch
		 * only if we actually changed anything so the admin cache reflects
		 * the post-update state. Otherwise the DNS table in the settings
		 * page would stay stuck on the pre-change state until somebody
		 * pressed "Probar conexión y cargar DNS" again.
		 */
		$fresh   = $api->fetch_dns_records();
		$changed = false;

		foreach ( $cfg['selected_records'] as $rid ) {
			$rec = ayudawp_blg_find_record( $fresh, $rid );
			if ( ! $rec ) {
				continue;
			}
			$result = $api->set_proxy_status( $rec, $desired_proxy );
			if ( ! empty( $result['success'] ) && empty( $result['skipped'] ) ) {
				$changed = true;
			}
		}

		if ( $changed ) {
			$updated = $api->fetch_dns_records();
			if ( ! empty( $updated ) ) {
				$fresh = $updated;
			}
		}

		if ( ! empty( $fresh ) ) {
			ayudawp_blg_save_dns_cache( $fresh );
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
			$state['bypass_active']   = 0;
			$state['bypass_since']    = 0;
			$state['blocks_ended_at'] = 0;
		}

		ayudawp_blg_save_state( $state );

		/* Send email notifications on state changes */
		if ( ! empty( $cfg['email_notifications'] ) ) {
			$notifier = new AyudaWP_BLG_Email_Notifier();
			if ( $state_changed_to_active ) {
				$notifier->notify_proxy_disabled();
			} elseif ( $state_changed_to_inactive ) {
				$notifier->notify_proxy_restored();
			}
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
