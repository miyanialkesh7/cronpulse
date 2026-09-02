<?php
/**
 * CronPulse_Cron_Tracker
 *
 * Wraps every registered cron hook to measure execution time and
 * record pass/fail status in the persistent log.
 *
 * @package CronPulse
 */
defined( 'ABSPATH' ) || exit;

class CronPulse_Cron_Tracker {

	/**
	 * Hooks registered for tracking in the current request.
	 *
	 * @var array<string, bool>
	 */
	private static array $tracked = [];

	/**
	 * Hooks currently executing in this request — removed once after() runs.
	 * Anything still here at shutdown started but never finished.
	 *
	 * @var array<string, bool>
	 */
	private static array $in_flight = [];

	/**
	 * Whether the shutdown handler has already been registered this request.
	 */
	private static bool $shutdown_registered = false;

	/**
	 * A start transient older than this (seconds) is presumed stuck — the
	 * process almost certainly died outright (OOM, host kill, crash) without
	 * ever reaching after() or the shutdown handler.
	 */
	private const STUCK_THRESHOLD = 120;

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_trackers' ], 1 );
	}

	/**
	 * Iterate all scheduled events and wrap their hooks.
	 *
	 * The before()/after() wrappers are only attached when wp_doing_cron() is
	 * true — i.e. a genuine WP-Cron spawn or `wp cron event run`. Attaching
	 * them unconditionally would log ANY invocation of a hook that happens to
	 * share a name with a scheduled event, including Run Now's own
	 * do_action_ref_array() call, producing a duplicate log entry for every
	 * manual trigger. Stuck-detection and overdue-alert evaluation still run
	 * on every page load regardless, since those need to work even when
	 * WP-Cron itself never fires (DISABLE_WP_CRON).
	 */
	public static function register_trackers(): void {
		$crons = _get_cron_array();
		if ( empty( $crons ) || ! is_array( $crons ) ) {
			return;
		}

		$is_cron        = wp_doing_cron();
		$missed_checked = [];

		foreach ( $crons as $timestamp => $cron_hooks ) {
			foreach ( $cron_hooks as $hook => $cron_data ) {
				self::check_stuck( $hook );
				CronPulse_Alerts::evaluate_overdue( $hook, (int) $timestamp );

				// evaluate_missed() is hook-level, not timestamp-level — only
				// run it once per hook even if it appears at multiple timestamps.
				if ( ! isset( $missed_checked[ $hook ] ) ) {
					$first_entry            = reset( $cron_data );
					$interval               = isset( $first_entry['interval'] ) ? (int) $first_entry['interval'] : 0;
					CronPulse_Alerts::evaluate_missed( $hook, $interval );
					$missed_checked[ $hook ] = true;
				}

				if ( ! $is_cron || isset( self::$tracked[ $hook ] ) ) {
					continue;
				}
				self::$tracked[ $hook ] = true;

				// Shared by reference between this pair of closures only, so
				// concurrent/overlapping runs of the same hook (e.g. two
				// overlapping WP-Cron spawns) can't clobber each other's
				// start time via the persistent transient.
				$start_time = null;

				// Add a high-priority action that fires BEFORE the real handler.
				add_action( $hook, static function () use ( $hook, &$start_time ) {
					$start_time = microtime( true );
					self::before( $hook, $start_time );
				}, -9999 );

				// Add a low-priority action that fires AFTER the real handler.
				add_action( $hook, static function () use ( $hook, &$start_time ) {
					self::after( $hook, $start_time );
				}, 9999 );
			}
		}
	}

	/**
	 * Runs on every page load. Catches the case the shutdown handler can't:
	 * a process killed outright leaves its start transient sitting there
	 * until it quietly expires. Flag it as stuck before that happens.
	 */
	private static function check_stuck( string $hook ): void {
		$key   = 'cronpulse_start_' . md5( $hook );
		$start = get_transient( $key );

		if ( false === $start ) {
			return;
		}

		$elapsed = microtime( true ) - (float) $start;
		if ( $elapsed < self::STUCK_THRESHOLD ) {
			return;
		}

		delete_transient( $key );
		self::log_execution( $hook, 'stuck', (int) round( $elapsed * 1000 ) );
	}

	/**
	 * Called immediately before the cron hook fires.
	 */
	public static function before( string $hook, float $start_time ): void {
		set_transient( 'cronpulse_start_' . md5( $hook ), $start_time, 300 );
		self::$in_flight[ $hook ] = true;

		// Registered once per request; catches fatals/timeouts that never reach after().
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( [ __CLASS__, 'handle_shutdown' ] );
		}
	}

	/**
	 * Called immediately after the cron hook fires.
	 */
	public static function after( string $hook, ?float $start_time ): void {
		unset( self::$in_flight[ $hook ] );

		delete_transient( 'cronpulse_start_' . md5( $hook ) );

		$duration = $start_time ? round( ( microtime( true ) - $start_time ) * 1000 ) : null; // ms

		self::log_execution( $hook, 'success', $duration );
	}

	/**
	 * Runs at PHP shutdown. Any hook still marked in-flight here started but
	 * never reached after() — it fataled, timed out, or the process was killed.
	 * Without this, that run would just vanish instead of being logged.
	 */
	public static function handle_shutdown(): void {
		if ( empty( self::$in_flight ) ) {
			return;
		}

		$error    = error_get_last();
		$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];
		$is_fatal = $error && in_array( $error['type'], $fatal_types, true );

		foreach ( array_keys( self::$in_flight ) as $hook ) {
			$key   = 'cronpulse_start_' . md5( $hook );
			$start = get_transient( $key );
			delete_transient( $key );

			$duration = $start ? round( ( microtime( true ) - (float) $start ) * 1000 ) : null;

			if ( $is_fatal ) {
				$message = sprintf( '%s in %s on line %d', $error['message'], $error['file'], $error['line'] );
				self::log_execution( $hook, 'fatal', $duration, $message );
			} else {
				self::log_execution( $hook, 'incomplete', $duration );
			}
		}
	}

	/**
	 * Log a cron execution entry.
	 *
	 * @param string      $hook
	 * @param string      $status   'success' | 'fatal' | 'incomplete' | 'stuck'
	 * @param int|null    $duration Milliseconds
	 * @param string|null $message  Error detail, only set for 'fatal'
	 */
	public static function log_execution( string $hook, string $status, ?int $duration, ?string $message = null ): void {
		$log = get_option( CRONPULSE_OPTION_LOG, [] );

		if ( ! is_array( $log ) ) {
			$log = [];
		}

		array_unshift( $log, [
			'hook'      => $hook,
			'status'    => $status,
			'duration'  => $duration,
			'message'   => $message,
			'timestamp' => time(),
		] );

		$limit = CronPulse_Alerts::get_settings()['log_retention'];
		if ( count( $log ) > $limit ) {
			$log = array_slice( $log, 0, $limit );
		}

		update_option( CRONPULSE_OPTION_LOG, $log, false );

		CronPulse_Alerts::evaluate_failure( $hook, $status );
	}

	/**
	 * Retrieve the last execution record for a given hook.
	 *
	 * @param string $hook
	 * @return array|null
	 */
	public static function get_last_run( string $hook ): ?array {
		$log = get_option( CRONPULSE_OPTION_LOG, [] );

		if ( ! is_array( $log ) ) {
			return null;
		}

		foreach ( $log as $entry ) {
			if ( isset( $entry['hook'] ) && $entry['hook'] === $hook ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Return all log entries (newest first).
	 *
	 * @return array
	 */
	public static function get_log(): array {
		$log = get_option( CRONPULSE_OPTION_LOG, [] );
		return is_array( $log ) ? $log : [];
	}

	/**
	 * Return the last $limit recorded durations for a hook, oldest first
	 * (left-to-right reading order for a sparkline). Skips runs that have
	 * no duration (e.g. a 'stuck' entry whose start time already expired).
	 *
	 * @param string $hook
	 * @param int    $limit
	 * @return int[] Milliseconds
	 */
	public static function get_recent_durations( string $hook, int $limit = 10 ): array {
		$durations = [];

		foreach ( self::get_log() as $entry ) {
			if ( ( $entry['hook'] ?? '' ) !== $hook || ! isset( $entry['duration'] ) || null === $entry['duration'] ) {
				continue;
			}

			$durations[] = (int) $entry['duration'];

			if ( count( $durations ) >= $limit ) {
				break;
			}
		}

		return array_reverse( $durations );
	}

	/**
	 * Clear the entire log.
	 */
	public static function clear_log(): void {
		update_option( CRONPULSE_OPTION_LOG, [], false );
	}
}
