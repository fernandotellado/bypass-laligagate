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
		add_action( AYUDAWP_BLG_SUMMARY_HOOK, array( $this, 'run_summary' ) );
		add_action( 'init', array( $this, 'maybe_process_external_cron' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( 'init', array( $this, 'ensure_summary_scheduled' ) );
		add_action( 'update_option_' . AYUDAWP_BLG_OPT_CONFIG, array( $this, 'maybe_reschedule' ), 10, 2 );
		add_action( 'update_option_' . AYUDAWP_BLG_OPT_CONFIG, array( $this, 'maybe_reschedule_summary' ), 10, 2 );
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
		$schedules['ayudawp_blg_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Semanal (Bypass LaLigaGate)',
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

		$prev_status = isset( $state['last_status'] ) ? $state['last_status'] : 'NO';

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

		/*
		 * Record block-state transitions into the episode log. This happens
		 * regardless of manual override: the log tracks reality (what La Liga
		 * is doing), not whether the proxy was actually flipped.
		 */
		$this->record_block_transition( $prev_status, $blocks_active, intval( $status['blocked_isps'] ?? 0 ), $now );

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

	/**
	 * Append/close block-log episodes based on the last_status transition.
	 *
	 *   NO -> SI: open a new episode at $now
	 *   SI -> NO: close the latest open episode at $now
	 *   SI -> SI: bump isps_max on the open episode if higher
	 */
	private function record_block_transition( $prev_status, $blocks_active, $blocked_isps, $now ) {
		$was_blocked = ( 'SI' === $prev_status );

		if ( ! $was_blocked && $blocks_active ) {
			ayudawp_blg_append_block_episode( array(
				'start'    => $now,
				'end'      => 0,
				'isps_max' => $blocked_isps,
			) );
			return;
		}

		if ( $was_blocked && ! $blocks_active ) {
			$log = ayudawp_blg_get_block_log();
			for ( $i = count( $log ) - 1; $i >= 0; $i-- ) {
				if ( empty( $log[ $i ]['end'] ) ) {
					$log[ $i ]['end'] = $now;
					ayudawp_blg_save_block_log( $log );
					return;
				}
			}
			/* No open episode found: synthesise one so the report isn't blank */
			ayudawp_blg_append_block_episode( array(
				'start'    => $now - MINUTE_IN_SECONDS,
				'end'      => $now,
				'isps_max' => $blocked_isps,
			) );
			return;
		}

		if ( $was_blocked && $blocks_active && $blocked_isps > 0 ) {
			$log = ayudawp_blg_get_block_log();
			for ( $i = count( $log ) - 1; $i >= 0; $i-- ) {
				if ( empty( $log[ $i ]['end'] ) ) {
					if ( $blocked_isps > intval( $log[ $i ]['isps_max'] ?? 0 ) ) {
						$log[ $i ]['isps_max'] = $blocked_isps;
						ayudawp_blg_save_block_log( $log );
					}
					return;
				}
			}
		}
	}

	/**
	 * Build the UTC timestamp for the first run of the summary cron so it
	 * fires at the requested local hour (site timezone, same logic WP uses
	 * for scheduled posts). If that local instant is already past today,
	 * push it to tomorrow (daily) or one week ahead (weekly).
	 */
	private function calculate_summary_first_run( $freq, $time_hhmm ) {
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', (string) $time_hhmm, $m ) ) {
			$m = array( '', '10', '00' );
		}
		$tz  = wp_timezone();
		try {
			$dt = new DateTimeImmutable( 'today ' . intval( $m[1] ) . ':' . str_pad( $m[2], 2, '0', STR_PAD_LEFT ), $tz );
		} catch ( Exception $e ) {
			$dt = new DateTimeImmutable( 'today 10:00', $tz );
		}
		$now_ts = time();
		$target = $dt->getTimestamp();
		if ( $target <= $now_ts ) {
			$target += ( 'daily' === $freq ) ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
		}
		return $target;
	}

	/**
	 * Watchdog: ensure the summary event is scheduled when enabled, gone
	 * when disabled. Runs on every init; wp_next_scheduled() short-circuits.
	 */
	public function ensure_summary_scheduled() {
		$cfg = ayudawp_blg_get_config();
		if ( empty( $cfg['summary_enabled'] ) ) {
			return;
		}
		if ( wp_next_scheduled( AYUDAWP_BLG_SUMMARY_HOOK ) ) {
			return;
		}
		$freq     = ( 'daily' === $cfg['summary_frequency'] ) ? 'daily' : 'weekly';
		$schedule = ( 'daily' === $freq ) ? 'daily' : 'ayudawp_blg_weekly';
		wp_schedule_event( $this->calculate_summary_first_run( $freq, $cfg['summary_time'] ), $schedule, AYUDAWP_BLG_SUMMARY_HOOK );
	}

	public function maybe_reschedule_summary( $old_value, $new_value ) {
		$old_enabled = ! empty( $old_value['summary_enabled'] ) ? 1 : 0;
		$new_enabled = ! empty( $new_value['summary_enabled'] ) ? 1 : 0;
		$old_freq    = isset( $old_value['summary_frequency'] ) ? (string) $old_value['summary_frequency'] : '';
		$new_freq    = isset( $new_value['summary_frequency'] ) ? (string) $new_value['summary_frequency'] : '';
		$old_time    = isset( $old_value['summary_time'] ) ? (string) $old_value['summary_time'] : '';
		$new_time    = isset( $new_value['summary_time'] ) ? (string) $new_value['summary_time'] : '';

		if ( $old_enabled === $new_enabled && $old_freq === $new_freq && $old_time === $new_time ) {
			return;
		}

		$ts = wp_next_scheduled( AYUDAWP_BLG_SUMMARY_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, AYUDAWP_BLG_SUMMARY_HOOK );
		}
		if ( $new_enabled ) {
			$freq     = ( 'daily' === $new_freq ) ? 'daily' : 'weekly';
			$schedule = ( 'daily' === $freq ) ? 'daily' : 'ayudawp_blg_weekly';
			wp_schedule_event( $this->calculate_summary_first_run( $freq, $new_time ), $schedule, AYUDAWP_BLG_SUMMARY_HOOK );
		}
	}

	/**
	 * Compose and send the periodic summary email for the last 24h (daily)
	 * or 7 days (weekly), using only the block-log (not the cooldown window).
	 */
	public function run_summary() {
		$cfg = ayudawp_blg_get_config();
		if ( empty( $cfg['summary_enabled'] ) ) {
			return;
		}

		$freq         = ( 'daily' === $cfg['summary_frequency'] ) ? 'daily' : 'weekly';
		$now          = time();
		$period_end   = $now;
		$period_start = $period_end - ( 'daily' === $freq ? DAY_IN_SECONDS : WEEK_IN_SECONDS );

		/* Defensive dedup: refuse to re-send for a window we just covered */
		$last_end   = intval( $cfg['summary_last_sent_period_end_ts'] ?? 0 );
		$dedup_gap  = ( 'daily' === $freq ? 12 * HOUR_IN_SECONDS : 3 * DAY_IN_SECONDS );
		if ( $last_end > 0 && ( $period_end - $last_end ) < $dedup_gap ) {
			return;
		}

		$log      = ayudawp_blg_get_block_log();
		$episodes = array();
		$longest  = 0;
		$total    = 0;
		foreach ( $log as $ep ) {
			$start = intval( $ep['start'] ?? 0 );
			$end   = intval( $ep['end'] ?? 0 );
			if ( $start <= 0 ) {
				continue;
			}
			if ( $end <= 0 ) {
				$end = $now;
			}
			if ( $end <= $period_start || $start >= $period_end ) {
				continue;
			}
			$clip_start = max( $start, $period_start );
			$clip_end   = min( $end, $period_end );
			$dur        = max( 0, $clip_end - $clip_start );
			$total     += $dur;
			if ( $dur > $longest ) {
				$longest = $dur;
			}
			$episodes[] = array(
				'start'    => $clip_start,
				'end'      => $clip_end,
				'duration' => $dur,
				'isps_max' => intval( $ep['isps_max'] ?? 0 ),
			);
		}

		$stats = array(
			'count'         => count( $episodes ),
			'total_seconds' => $total,
			'longest'       => $longest,
		);

		$notifier = new AyudaWP_BLG_Email_Notifier();
		$notifier->notify_summary( $freq, $period_start, $period_end, $episodes, $stats );

		$cfg['summary_last_sent_period_end_ts'] = $period_end;
		ayudawp_blg_save_config( $cfg );
	}
}
