<?php
/**
 * CronPulse_Alerts
 *
 * Tracks consecutive failures and overdue duration per hook, and sends an
 * email/webhook notification once a configured threshold is crossed.
 * Evaluated as a side effect of normal site traffic (init) and of cron
 * runs completing (log_execution), so it works even if no one opens the
 * dashboard and even if WP-Cron itself is disabled.
 *
 * @package CronPulse
 */
defined( 'ABSPATH' ) || exit;

class CronPulse_Alerts {

	/**
	 * Registers the settings-save handler and the SMTP/From-address filters.
	 */
	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'maybe_save_settings' ] );
		add_action( 'phpmailer_init', [ __CLASS__, 'configure_smtp' ] );
		add_filter( 'wp_mail_from', [ __CLASS__, 'filter_mail_from' ] );
		add_filter( 'wp_mail_from_name', [ __CLASS__, 'filter_mail_from_name' ] );
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	public static function get_settings(): array {
		$defaults = [
			'enabled'           => false,
			'failure_threshold' => 3,
			'overdue_minutes'   => 30,
			'email'             => '',
			'webhook'           => '',
			'log_retention'     => CRONPULSE_LOG_LIMIT,
			'per_job'           => [],
			'smtp_enabled'      => false,
			'smtp_host'         => '',
			'smtp_port'         => 587,
			'smtp_encryption'   => 'tls', // 'none' | 'ssl' | 'tls'
			'smtp_username'     => '',
			'smtp_password'     => '',
			'smtp_from_email'   => '',
			'smtp_from_name'    => '',
		];

		$settings = get_option( CRONPULSE_OPTION_ALERTS, [] );

		return wp_parse_args( is_array( $settings ) ? $settings : [], $defaults );
	}

	/**
	 * Resolve the effective thresholds for a hook: its own override, if one
	 * is configured, otherwise the global defaults.
	 */
	public static function get_thresholds_for( string $hook ): array {
		$settings = self::get_settings();
		$override = $settings['per_job'][ $hook ] ?? [];

		return [
			'failure_threshold' => $override['failure_threshold'] ?? $settings['failure_threshold'],
			'overdue_minutes'   => $override['overdue_minutes'] ?? $settings['overdue_minutes'],
		];
	}

	/**
	 * Handles the Settings tab form submission: verifies the nonce and
	 * capability, then persists the sanitized fields to CRONPULSE_OPTION_ALERTS.
	 */
	public static function maybe_save_settings(): void {
		if ( empty( $_POST['cronpulse_alerts_submit'] ) ) {
			return;
		}

		check_admin_referer( 'cronpulse_save_alerts', 'cronpulse_alerts_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'cronpulse' ) );
		}

		$email     = sanitize_email( wp_unslash( $_POST['cronpulse_alert_email'] ?? '' ) );
		$from_email = sanitize_email( wp_unslash( $_POST['cronpulse_smtp_from_email'] ?? '' ) );

		// Password field is left blank in the form on every page load (see
		// render_settings_tab()) so it never round-trips through HTML source.
		// An empty submission means "unchanged", not "clear it".
		$existing_password   = self::get_settings()['smtp_password'];
		$submitted_password  = sanitize_text_field( wp_unslash( $_POST['cronpulse_smtp_password'] ?? '' ) );
		$password            = '' === $submitted_password ? $existing_password : $submitted_password;

		$encryption = sanitize_key( wp_unslash( $_POST['cronpulse_smtp_encryption'] ?? 'tls' ) );
		if ( ! in_array( $encryption, [ 'none', 'ssl', 'tls' ], true ) ) {
			$encryption = 'tls';
		}

		update_option( CRONPULSE_OPTION_ALERTS, [
			'enabled'           => ! empty( $_POST['cronpulse_alert_enabled'] ),
			'failure_threshold' => max( 1, absint( $_POST['cronpulse_failure_threshold'] ?? 3 ) ),
			'overdue_minutes'   => max( 1, absint( $_POST['cronpulse_overdue_minutes'] ?? 30 ) ),
			'email'             => is_email( $email ) ? $email : '',
			'webhook'           => esc_url_raw( wp_unslash( $_POST['cronpulse_alert_webhook'] ?? '' ) ),
			'log_retention'     => min( 5000, max( 10, absint( $_POST['cronpulse_log_retention'] ?? CRONPULSE_LOG_LIMIT ) ) ),
			'per_job'           => self::parse_overrides_from_post(),
			'smtp_enabled'      => ! empty( $_POST['cronpulse_smtp_enabled'] ),
			'smtp_host'         => sanitize_text_field( wp_unslash( $_POST['cronpulse_smtp_host'] ?? '' ) ),
			'smtp_port'         => max( 1, min( 65535, absint( $_POST['cronpulse_smtp_port'] ?? 587 ) ) ),
			'smtp_encryption'   => $encryption,
			'smtp_username'     => sanitize_text_field( wp_unslash( $_POST['cronpulse_smtp_username'] ?? '' ) ),
			'smtp_password'     => $password,
			'smtp_from_email'   => is_email( $from_email ) ? $from_email : '',
			'smtp_from_name'    => sanitize_text_field( wp_unslash( $_POST['cronpulse_smtp_from_name'] ?? '' ) ),
		], false );

		$redirect = add_query_arg( 'updated', '1', admin_url( 'tools.php?page=cronpulse' ) ) . '#cp-alerts';
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Rebuild the per-job override map from the settings form's parallel
	 * arrays (existing rows) plus the single "add new" row, if filled in.
	 *
	 * Nonce verification happens in maybe_save_settings() before this is ever
	 * called — phpcs can't see across the function boundary, hence the
	 * disable block below rather than a duplicate check here.
	 */
	private static function parse_overrides_from_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$per_job = [];

		$hooks    = isset( $_POST['cronpulse_override_hook'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['cronpulse_override_hook'] ) ) : [];
		$failures = isset( $_POST['cronpulse_override_failure'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['cronpulse_override_failure'] ) ) : [];
		$overdues = isset( $_POST['cronpulse_override_overdue'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['cronpulse_override_overdue'] ) ) : [];
		$removed  = isset( $_POST['cronpulse_override_remove'] ) ? array_flip( array_map( 'absint', wp_unslash( (array) $_POST['cronpulse_override_remove'] ) ) ) : [];

		foreach ( $hooks as $i => $hook ) {
			if ( isset( $removed[ $i ] ) || empty( $hook ) ) {
				continue;
			}

			$per_job[ $hook ] = [
				'failure_threshold' => max( 1, $failures[ $i ] ?? 3 ),
				'overdue_minutes'   => max( 1, $overdues[ $i ] ?? 30 ),
			];
		}

		$new_hook = isset( $_POST['cronpulse_new_override_hook'] ) ? sanitize_key( wp_unslash( $_POST['cronpulse_new_override_hook'] ) ) : '';
		if ( ! empty( $new_hook ) ) {
			$per_job[ $new_hook ] = [
				'failure_threshold' => max( 1, absint( $_POST['cronpulse_new_override_failure'] ?? 3 ) ),
				'overdue_minutes'   => max( 1, absint( $_POST['cronpulse_new_override_overdue'] ?? 30 ) ),
			];
		}

		return $per_job;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Routes wp_mail() through user-supplied SMTP credentials instead of the
	 * server's default PHP mail()/sendmail — no third-party plugin involved,
	 * just configuring the PHPMailer instance WordPress already bundles.
	 *
	 * From-address override is handled separately via the wp_mail_from /
	 * wp_mail_from_name filters (see below), NOT here — wp_mail() calls
	 * $phpmailer->setFrom() with its own default *before* phpmailer_init
	 * fires. If that default happens to be invalid (e.g. a local dev
	 * hostname with no TLD, producing something like wordpress@localhost),
	 * PHPMailer throws right there and this hook never even runs. Setting
	 * the From address earlier, via the filters, avoids that entirely.
	 *
	 * @param PHPMailer $phpmailer Passed by reference by the phpmailer_init action.
	 */
	public static function configure_smtp( $phpmailer ): void {
		$settings = self::get_settings();

		if ( empty( $settings['smtp_enabled'] ) || empty( $settings['smtp_host'] ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host     = $settings['smtp_host'];
		$phpmailer->Port     = $settings['smtp_port'];
		$phpmailer->SMTPAuth = ! empty( $settings['smtp_username'] );
		$phpmailer->Username = $settings['smtp_username'];
		$phpmailer->Password = $settings['smtp_password'];

		// PHPMailer's own default is 300s, comfortably longer than most
		// hosts' PHP max_execution_time — a wrong host/port would hang the
		// request into an uncatchable timeout fatal instead of failing
		// cleanly. 15s is enough for a real connection attempt to succeed.
		$phpmailer->Timeout = 15;

		// Captures the actual SMTP conversation (connection, TLS, AUTH,
		// server responses) to the debug log — far more useful than a
		// generic failure when something like the host or port is wrong.
		$phpmailer->SMTPDebug   = 2; // Client/server messages; not raw socket data.
		$phpmailer->Debugoutput = static function ( $str ) {
			CronPulse_Debug_Log::log_smtp_line( $str );
		};

		if ( 'none' === $settings['smtp_encryption'] ) {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure = $settings['smtp_encryption']; // 'ssl' or 'tls'
		}
	}

	/**
	 * Runs before wp_mail()'s own setFrom() call, so an invalid default
	 * (e.g. wordpress@a-local-hostname-with-no-tld) never reaches PHPMailer
	 * in the first place. Once SMTP is on, prefer the site's admin email
	 * (already validated by WordPress itself) over WP's own fragile default
	 * even if no explicit From Email override was set — most SMTP providers
	 * reject or rewrite an unverified sender anyway.
	 */
	public static function filter_mail_from( string $email ): string {
		$settings = self::get_settings();

		if ( empty( $settings['smtp_enabled'] ) ) {
			return $email;
		}

		if ( ! empty( $settings['smtp_from_email'] ) ) {
			return $settings['smtp_from_email'];
		}

		$admin_email = get_option( 'admin_email' );

		return $admin_email ?: $email;
	}

	/**
	 * Mirrors filter_mail_from() for the From Name, same SMTP-enabled gate.
	 *
	 * @param string $name Default From name wp_mail() would otherwise use.
	 * @return string
	 */
	public static function filter_mail_from_name( string $name ): string {
		$settings = self::get_settings();

		if ( empty( $settings['smtp_enabled'] ) ) {
			return $name;
		}

		return $settings['smtp_from_name'] ?: get_bloginfo( 'name' );
	}

	// -------------------------------------------------------------------------
	// Evaluation
	// -------------------------------------------------------------------------

	/**
	 * Called on every page load for every scheduled hook. Tracks how long a
	 * hook has been continuously overdue (wall-clock minutes, not a "check"
	 * counter — those would scale with site traffic rather than real time).
	 */
	public static function evaluate_overdue( string $hook, int $next_run ): void {
		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}

		$threshold = self::get_thresholds_for( $hook )['overdue_minutes'];

		$streaks = get_option( CRONPULSE_OPTION_STREAKS, [] );
		$entry   = $streaks[ $hook ] ?? self::default_streak();

		if ( $next_run < time() ) {
			if ( null === $entry['overdue_since'] ) {
				$entry['overdue_since'] = time();
			}

			$minutes_overdue = ( time() - $entry['overdue_since'] ) / 60;

			if ( $minutes_overdue >= $threshold && ! $entry['overdue_alerted'] ) {
				self::notify( $hook, 'overdue', [ 'minutes' => (int) $minutes_overdue ] );
				$entry['overdue_alerted'] = true;
			}
		} else {
			$was_alerted              = $entry['overdue_alerted'];
			$entry['overdue_since']   = null;
			$entry['overdue_alerted'] = false;

			if ( $was_alerted ) {
				self::notify( $hook, 'recovery', [ 'previous_type' => 'overdue' ] );
			}
		}

		$streaks[ $hook ] = $entry;
		update_option( CRONPULSE_OPTION_STREAKS, $streaks, false );
	}

	/**
	 * Called every time a run is logged. Tracks real consecutive failures —
	 * unlike overdue, every call here corresponds to an actual execution.
	 */
	public static function evaluate_failure( string $hook, string $status ): void {
		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}

		$threshold = self::get_thresholds_for( $hook )['failure_threshold'];

		$streaks = get_option( CRONPULSE_OPTION_STREAKS, [] );
		$entry   = $streaks[ $hook ] ?? self::default_streak();

		$is_failure = in_array( $status, [ 'fatal', 'incomplete', 'stuck' ], true );

		if ( $is_failure ) {
			$entry['failure_streak']++;

			if ( $entry['failure_streak'] >= $threshold && ! $entry['failure_alerted'] ) {
				self::notify( $hook, 'failure', [ 'count' => $entry['failure_streak'], 'status' => $status ] );
				$entry['failure_alerted'] = true;
			}
		} else {
			$was_alerted              = $entry['failure_alerted'];
			$entry['failure_streak']  = 0;
			$entry['failure_alerted'] = false;

			if ( $was_alerted ) {
				self::notify( $hook, 'recovery', [ 'previous_type' => 'failure' ] );
			}
		}

		$streaks[ $hook ] = $entry;
		update_option( CRONPULSE_OPTION_STREAKS, $streaks, false );
	}

	/**
	 * Called on every page load for every recurring hook. Detects when a hook
	 * is running less often than its schedule dictates — e.g. an hourly job
	 * that only ran once in the last three hours. Complements overdue detection
	 * (which flags a job that hasn't run yet) by catching jobs that run but not
	 * at the expected frequency.
	 *
	 * Only evaluates hooks with a known positive interval. One-time events
	 * (interval = 0) and hooks with no execution history are skipped.
	 *
	 * @param string $hook     The scheduled hook name.
	 * @param int    $interval The hook's recurrence interval in seconds.
	 */
	public static function evaluate_missed( string $hook, int $interval ): void {
		if ( $interval <= 0 ) {
			return;
		}

		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}

		// Look back 3 full intervals (capped at 7 days to keep the window sane
		// for weekly or longer schedules).
		$window       = min( 3 * $interval, 7 * DAY_IN_SECONDS );
		$window_start = time() - $window;
		$expected     = 3; // Always 3 because window = 3 * interval.

		$log         = CronPulse_Cron_Tracker::get_log();
		$has_history = false;
		$actual      = 0;

		foreach ( $log as $entry ) {
			if ( ( $entry['hook'] ?? '' ) !== $hook ) {
				continue;
			}

			$has_history = true;

			if ( $entry['timestamp'] >= $window_start && 'success' === $entry['status'] ) {
				$actual++;
			}
		}

		// No history at all — job just scheduled or log was cleared. Skip to
		// avoid a false-positive on first activation.
		if ( ! $has_history ) {
			return;
		}

		$streaks = get_option( CRONPULSE_OPTION_STREAKS, [] );
		$entry   = $streaks[ $hook ] ?? self::default_streak();

		if ( $actual < 2 ) {
			// Ran 0 or 1 times when 3 were expected — fire alert once.
			if ( ! $entry['missed_alerted'] ) {
				self::notify( $hook, 'missed', [
					'actual'   => $actual,
					'expected' => $expected,
					'window'   => $window,
				] );
				$entry['missed_alerted'] = true;
			}
		} else {
			// Frequency is back to normal — send recovery if we had alerted.
			if ( $entry['missed_alerted'] ) {
				self::notify( $hook, 'recovery', [ 'previous_type' => 'missed' ] );
			}
			$entry['missed_alerted'] = false;
		}

		$streaks[ $hook ] = $entry;
		update_option( CRONPULSE_OPTION_STREAKS, $streaks, false );
	}

	/**
	 * Acknowledge the current incident for a hook without disabling alerts
	 * globally — reuses the existing *_alerted guards, so no new notification
	 * fires until this streak clears and a fresh one starts.
	 */
	public static function snooze( string $hook ): void {
		$streaks = get_option( CRONPULSE_OPTION_STREAKS, [] );
		$entry   = $streaks[ $hook ] ?? self::default_streak();

		$entry['failure_alerted'] = true;
		$entry['overdue_alerted'] = true;
		$entry['missed_alerted']  = true;

		$streaks[ $hook ] = $entry;
		update_option( CRONPULSE_OPTION_STREAKS, $streaks, false );
	}

	/**
	 * Zero-value streak entry for a hook that hasn't been tracked yet.
	 *
	 * @return array
	 */
	private static function default_streak(): array {
		return [
			'overdue_since'   => null,
			'overdue_alerted' => false,
			'failure_streak'  => 0,
			'failure_alerted' => false,
			'missed_alerted'  => false,
		];
	}

	// -------------------------------------------------------------------------
	// Notification
	// -------------------------------------------------------------------------

	private static function notify( string $hook, string $type, array $context ): void {
		$settings  = self::get_settings();
		$site      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$dashboard = admin_url( 'tools.php?page=cronpulse' );
		$last_run  = CronPulse_Cron_Tracker::get_last_run( $hook );

		// $details drives both the email's row table and the webhook's
		// Slack/Discord fields — one source of truth instead of building
		// the same facts twice in two different shapes.
		if ( 'overdue' === $type ) {
			$minutes = (int) $context['minutes'];
			$overdue = human_time_diff( time() - ( $minutes * MINUTE_IN_SECONDS ), time() );

			$subject = sprintf( '[Cron Pulse] %s is overdue on %s', $hook, $site );
			$plain   = sprintf(
				"The cron hook \"%s\" has been overdue for more than %s.\n\nSite: %s\nDashboard: %s",
				$hook,
				$overdue,
				$site,
				$dashboard
			);
			$short = '🟠 ' . sprintf(
				/* translators: 1: cron hook name, 2: site domain, 3: human-readable overdue duration, e.g. "2 hours" */
				__( '%1$s is overdue on %2$s — overdue by %3$s', 'cronpulse' ),
				$hook,
				$site,
				$overdue
			);

			$badge_color = '#996800';
			$badge_label = __( 'Overdue', 'cronpulse' );
			$heading     = __( 'Cron job hasn\'t run on schedule', 'cronpulse' );
			$intro       = sprintf(
				/* translators: %s = human-readable duration, e.g. "2 hours" */
				__( 'This hook was due to run over %s ago and still hasn\'t fired.', 'cronpulse' ),
				$overdue
			);

			$details = [
				[ 'label' => __( 'Overdue for', 'cronpulse' ), 'value' => $overdue ],
				[
					'label' => __( 'Alert threshold', 'cronpulse' ),
					'value' => sprintf(
						/* translators: %d = minutes */
						__( '%d minutes', 'cronpulse' ),
						self::get_thresholds_for( $hook )['overdue_minutes']
					),
				],
			];
			if ( $last_run ) {
				$details[] = [
					'label' => __( 'Last execution', 'cronpulse' ),
					'value' => sprintf(
						/* translators: 1: execution status, 2: relative time, e.g. "2 hours ago" */
						__( '%1$s, %2$s', 'cronpulse' ),
						ucfirst( $last_run['status'] ),
						CronPulse_Admin_Page::format_time( (int) $last_run['timestamp'] )
					),
				];
			}
			$details[] = [ 'label' => __( 'Site', 'cronpulse' ), 'value' => $site ];
		} elseif ( 'recovery' === $type ) {
			$previous_type = (string) ( $context['previous_type'] ?? 'failure' );
			if ( 'overdue' === $previous_type ) {
				$prev_label = __( 'overdue', 'cronpulse' );
			} elseif ( 'missed' === $previous_type ) {
				$prev_label = __( 'missing scheduled runs', 'cronpulse' );
			} else {
				$prev_label = __( 'failing', 'cronpulse' );
			}

			$subject = sprintf( '[Cron Pulse] %s has recovered on %s', $hook, $site );
			$plain   = sprintf(
				"The cron hook \"%s\" has recovered and is running successfully again.\n\nSite: %s\nDashboard: %s",
				$hook,
				$site,
				$dashboard
			);
			$short = '🟢 ' . sprintf(
				/* translators: 1: cron hook name, 2: site domain */
				__( '%1$s has recovered on %2$s', 'cronpulse' ),
				$hook,
				$site
			);

			$badge_color = '#00a32a';
			$badge_label = __( 'Recovered', 'cronpulse' );
			$heading     = __( 'Cron job has recovered', 'cronpulse' );
			$intro       = sprintf(
				/* translators: %s = "failing" or "overdue" */
				__( 'This hook was previously %s and is now running successfully again.', 'cronpulse' ),
				$prev_label
			);

			$details = [];
			if ( $last_run ) {
				$details[] = [ 'label' => __( 'Last status', 'cronpulse' ), 'value' => ucfirst( $last_run['status'] ) ];
				$details[] = [ 'label' => __( 'Recovered at', 'cronpulse' ), 'value' => CronPulse_Admin_Page::format_time( (int) $last_run['timestamp'] ) ];
			}
			$details[] = [ 'label' => __( 'Site', 'cronpulse' ), 'value' => $site ];
		} elseif ( 'missed' === $type ) {
			$actual   = (int) $context['actual'];
			$expected = (int) $context['expected'];
			$window   = (int) $context['window'];
			$duration = human_time_diff( time() - $window, time() );

			$subject = sprintf( '[Cron Pulse] %s is missing scheduled runs on %s', $hook, $site );
			$plain   = sprintf(
				"The cron hook \"%s\" ran only %d time(s) in the last %s. Expected %d runs based on its schedule.\n\nSite: %s\nDashboard: %s",
				$hook,
				$actual,
				$duration,
				$expected,
				$site,
				$dashboard
			);
			$short = '🟡 ' . sprintf(
				/* translators: 1: cron hook name, 2: site domain, 3: actual count, 4: expected count */
				__( '%1$s missing runs on %2$s — %3$d of %4$d expected', 'cronpulse' ),
				$hook,
				$site,
				$actual,
				$expected
			);

			$badge_color = '#b45309';
			$badge_label = __( 'Missed Runs', 'cronpulse' );
			$heading     = __( 'Cron job is missing scheduled runs', 'cronpulse' );
			$intro       = sprintf(
				/* translators: 1: actual run count, 2: human-readable duration, 3: expected count */
				__( 'This hook ran only %1$d time(s) in the last %2$s. Expected %3$d runs based on its schedule.', 'cronpulse' ),
				$actual,
				$duration,
				$expected
			);

			$details = [
				[
					'label' => __( 'Runs in window', 'cronpulse' ),
					'value' => sprintf(
						/* translators: 1: actual count, 2: expected count, 3: human-readable duration */
						__( '%1$d of %2$d expected (last %3$s)', 'cronpulse' ),
						$actual,
						$expected,
						$duration
					),
				],
			];
			if ( $last_run ) {
				$details[] = [ 'label' => __( 'Last execution', 'cronpulse' ), 'value' => CronPulse_Admin_Page::format_time( (int) $last_run['timestamp'] ) ];
			}
			$details[] = [ 'label' => __( 'Site', 'cronpulse' ), 'value' => $site ];
		} else {
			$count     = (int) $context['count'];
			$status    = (string) $context['status'];
			$threshold = self::get_thresholds_for( $hook )['failure_threshold'];

			$subject = sprintf( '[Cron Pulse] %s is failing on %s', $hook, $site );
			$plain   = sprintf(
				"The cron hook \"%s\" has failed %d times in a row (last status: %s).\n\nSite: %s\nDashboard: %s",
				$hook,
				$count,
				$status,
				$site,
				$dashboard
			);
			$short = '🔴 ' . sprintf(
				/* translators: 1: cron hook name, 2: site domain, 3: number of consecutive failures */
				__( '%1$s is failing on %2$s — %3$d in a row', 'cronpulse' ),
				$hook,
				$site,
				$count
			);

			$badge_color = '#d63638';
			$badge_label = __( 'Failing', 'cronpulse' );
			$heading     = __( 'Cron job is failing', 'cronpulse' );
			$intro       = sprintf(
				/* translators: %d = number of consecutive failures */
				__( 'This hook has now failed %d times in a row.', 'cronpulse' ),
				$count
			);

			$details = [
				[
					'label' => __( 'Consecutive failures', 'cronpulse' ),
					'value' => sprintf(
						/* translators: 1: number of consecutive failures, 2: configured alert threshold */
						__( '%1$d (alert threshold: %2$d)', 'cronpulse' ),
						$count,
						$threshold
					),
				],
				[ 'label' => __( 'Last status', 'cronpulse' ), 'value' => ucfirst( $status ) ],
			];
			if ( $last_run && ! empty( $last_run['message'] ) ) {
				$details[] = [ 'label' => __( 'Last error', 'cronpulse' ), 'value' => (string) $last_run['message'], 'code' => true ];
			}
			if ( $last_run ) {
				$details[] = [ 'label' => __( 'Last attempt', 'cronpulse' ), 'value' => CronPulse_Admin_Page::format_time( (int) $last_run['timestamp'] ) ];
			}
			$details[] = [ 'label' => __( 'Site', 'cronpulse' ), 'value' => $site ];
		}

		$rows = '';
		foreach ( $details as $detail ) {
			$rows .= self::render_email_row( $detail['label'], $detail['value'], ! empty( $detail['code'] ) );
		}

		$html = self::render_email_html(
			$badge_label,
			$badge_color,
			$heading,
			$hook,
			$intro,
			$rows,
			$dashboard,
			__( 'Open Dashboard', 'cronpulse' ),
			$site
		);

		$email = $settings['email'] ?: get_option( 'admin_email' );
		if ( $email ) {
			self::send_and_log( $email, $subject, $html, $plain, $type );
		}

		if ( ! empty( $settings['webhook'] ) ) {
			$payload = self::build_webhook_payload( $type, $badge_color, $heading, $hook, $intro, $details, $dashboard, $short, $plain );

			wp_remote_post( $settings['webhook'], [
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => [ 'Content-Type' => 'application/json' ],
				'body'     => wp_json_encode( $payload ),
			] );
		}
	}

	// -------------------------------------------------------------------------
	// Email template
	// -------------------------------------------------------------------------

	/**
	 * One label/value row for the details table inside render_email_html().
	 * $code wraps the value in a dark monospace block — used for raw error
	 * output, mirroring the Email Debug Log's own styling.
	 */
	public static function render_email_row( string $label, string $value, bool $code = false ): string {
		$value_html = $code
			? '<code style="display:block;background:#1d2327;color:#c3c4c7;border-radius:4px;padding:10px 12px;font-family:Menlo,Consolas,monospace;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-word;">' . esc_html( $value ) . '</code>'
			: esc_html( $value );

		return '<tr>'
			. '<td style="padding:10px 0;border-bottom:1px solid #f0f0f1;color:#787c82;font-size:13px;vertical-align:top;width:140px;">' . esc_html( $label ) . '</td>'
			. '<td style="padding:10px 0;border-bottom:1px solid #f0f0f1;color:#1d2327;font-size:13px;vertical-align:top;">' . $value_html . '</td>'
			. '</tr>';
	}

	/**
	 * A self-contained HTML email — inline styles and a table-based layout
	 * throughout, since email clients (Gmail especially) strip <style>
	 * blocks unpredictably and have inconsistent flexbox/grid support.
	 *
	 * @param string $rows Pre-built <tr> markup from render_email_row() — the
	 *                     only parameter here that's trusted HTML rather than
	 *                     a plain string this method escapes itself.
	 */
	public static function render_email_html(
		string $badge_label,
		string $badge_color,
		string $heading,
		string $hook,
		string $intro,
		string $rows,
		string $cta_url,
		string $cta_label,
		string $site
	): string {
		$hook_html = '' !== $hook
			? '<tr><td style="padding:0 28px 4px;"><code style="display:inline-block;background:#f0f0f1;color:#1d2327;border-radius:4px;padding:4px 10px;font-family:Menlo,Consolas,monospace;font-size:13px;word-break:break-all;">' . esc_html( $hook ) . '</code></td></tr>'
			: '';

		return '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f1;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px;border:1px solid #c3c4c7;">
<tr><td style="padding:24px 28px 0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
<td style="font-size:14px;font-weight:700;color:#1d2327;">' . esc_html__( 'Cron Pulse', 'cronpulse' ) . '</td>
<td align="right"><span style="display:inline-block;background:' . esc_attr( $badge_color ) . ';color:#ffffff;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;border-radius:10px;padding:3px 10px;">' . esc_html( $badge_label ) . '</span></td>
</tr></table>
</td></tr>
<tr><td style="padding:20px 28px 4px;"><div style="font-size:18px;font-weight:600;color:#1d2327;">' . esc_html( $heading ) . '</div></td></tr>
' . $hook_html . '
<tr><td style="padding:14px 28px 4px;color:#3c434a;font-size:14px;line-height:1.6;">' . esc_html( $intro ) . '</td></tr>
<tr><td style="padding:8px 28px 4px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr>
<tr><td style="padding:24px 28px 28px;"><a href="' . esc_url( $cta_url ) . '" style="display:inline-block;background:#2271b1;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:11px 22px;border-radius:4px;">' . esc_html( $cta_label ) . '</a></td></tr>
<tr><td style="padding:16px 28px;border-top:1px solid #f0f0f1;color:#787c82;font-size:12px;line-height:1.6;">' . sprintf(
			/* translators: %s = site domain, wrapped in <strong> */
			__( 'Sent by Cron Pulse for %s. Manage alert thresholds, snooze incidents, or turn this off entirely from the Settings tab on your dashboard.', 'cronpulse' ),
			'<strong>' . esc_html( $site ) . '</strong>'
		) . '</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
	}

	/**
	 * Build a JSON-ready webhook payload that renders as a colored card in
	 * both Slack ("attachments") and Discord ("embeds"), on top of the
	 * original flat fields kept for any other, custom-built endpoint.
	 *
	 * @param array<int, array{label: string, value: string, code?: bool}> $details
	 */
	public static function build_webhook_payload(
		string $type,
		string $color,
		string $title,
		string $hook,
		string $intro,
		array $details,
		string $cta_url,
		string $short_text,
		string $plain
	): array {
		$slack_fields   = [];
		$discord_fields = [];

		foreach ( $details as $detail ) {
			$is_code = ! empty( $detail['code'] );
			// Discord hard-caps embed field values at 1024 characters; a long
			// stack trace would otherwise make the whole webhook call fail
			// silently (it's fired with 'blocking' => false).
			$value = mb_substr( (string) $detail['value'], 0, 950 );
			$value = $is_code ? "```{$value}```" : $value;

			$slack_fields[]   = [ 'title' => $detail['label'], 'value' => $value, 'short' => ! $is_code ];
			$discord_fields[] = [ 'name' => $detail['label'], 'value' => $value, 'inline' => ! $is_code ];
		}

		$card_title = '' !== $hook ? $hook : $title;

		return [
			'plugin'      => 'cronpulse',
			'hook'        => $hook,
			'type'        => $type,
			'site'        => home_url(),
			'message'     => $plain,
			'text'        => $short_text,
			'content'     => $short_text,
			// Legacy Slack "attachments" — still the simplest way to get a
			// colored card with structured fields from an incoming webhook.
			'attachments' => [
				[
					'fallback'   => $short_text,
					'color'      => $color,
					'title'      => $card_title,
					'title_link' => $cta_url,
					'text'       => $intro,
					'fields'     => $slack_fields,
					'footer'     => 'Cron Pulse',
					'ts'         => time(),
				],
			],
			'embeds'      => [
				[
					'title'       => $card_title,
					'url'         => $cta_url,
					'description' => $intro,
					'color'       => hexdec( ltrim( $color, '#' ) ),
					'fields'      => $discord_fields,
					'footer'      => [ 'text' => 'Cron Pulse' ],
					'timestamp'   => gmdate( 'c' ),
				],
			],
		];
	}

	/**
	 * Number of email log entries to keep. Not user-configurable — email
	 * volume is naturally much lower than cron-execution volume, so a fixed
	 * cap is enough; no need to duplicate the log_retention setting for it.
	 */
	private const EMAIL_LOG_LIMIT = 50;

	/**
	 * Send via wp_mail() and record the outcome (including the real PHPMailer
	 * error on failure, captured via wp_mail_failed) in the email log.
	 *
	 * @param string $html_body  Sent as the message body (HTML).
	 * @param string $plain_body Set as PHPMailer's AltBody — the fallback an
	 *                           HTML-incapable mail client renders instead.
	 */
	public static function send_and_log( string $to, string $subject, string $html_body, string $plain_body, string $type = 'alert' ): bool {
		$settings = self::get_settings();

		CronPulse_Debug_Log::write( sprintf(
			'Sending "%s" to %s (type: %s, transport: %s)',
			$subject,
			$to,
			$type,
			! empty( $settings['smtp_enabled'] ) ? 'SMTP (' . $settings['smtp_host'] . ':' . $settings['smtp_port'] . ')' : 'default mail()'
		) );

		$error    = null;
		$capture  = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error;
		};
		$set_html = static function () {
			return 'text/html';
		};
		$set_alt  = static function ( $phpmailer ) use ( $plain_body ) {
			$phpmailer->AltBody = $plain_body;
		};

		add_action( 'wp_mail_failed', $capture );
		add_filter( 'wp_mail_content_type', $set_html );
		add_action( 'phpmailer_init', $set_alt );

		$sent = wp_mail( $to, $subject, $html_body );

		remove_action( 'wp_mail_failed', $capture );
		remove_filter( 'wp_mail_content_type', $set_html );
		remove_action( 'phpmailer_init', $set_alt );

		CronPulse_Debug_Log::write( sprintf(
			'Result: %s%s',
			$sent ? 'SENT' : 'FAILED',
			$error ? ' — ' . $error->get_error_message() : ''
		) );

		self::log_email( $to, $subject, $type, $sent ? 'sent' : 'failed', $error ? $error->get_error_message() : null );

		return $sent;
	}

	private static function log_email( string $to, string $subject, string $type, string $status, ?string $error = null ): void {
		$log = get_option( CRONPULSE_OPTION_EMAIL_LOG, [] );

		if ( ! is_array( $log ) ) {
			$log = [];
		}

		array_unshift( $log, [
			'to'        => $to,
			'subject'   => $subject,
			'type'      => $type,
			'status'    => $status,
			'error'     => $error,
			'timestamp' => time(),
		] );

		if ( count( $log ) > self::EMAIL_LOG_LIMIT ) {
			$log = array_slice( $log, 0, self::EMAIL_LOG_LIMIT );
		}

		update_option( CRONPULSE_OPTION_EMAIL_LOG, $log, false );
	}

	/**
	 * Return all email log entries (newest first).
	 */
	public static function get_email_log(): array {
		$log = get_option( CRONPULSE_OPTION_EMAIL_LOG, [] );
		return is_array( $log ) ? $log : [];
	}

	public static function clear_email_log(): void {
		update_option( CRONPULSE_OPTION_EMAIL_LOG, [], false );
	}

	// -------------------------------------------------------------------------
	// Settings UI
	// -------------------------------------------------------------------------

	private static function render_overrides_table( array $per_job ): void {
		$all_hooks = array_values( array_unique( array_map(
			static function ( $job ) {
				return $job['hook'];
			},
			CronPulse_Admin_Page::get_jobs()
		) ) );
		$available = array_values( array_diff( $all_hooks, array_keys( $per_job ) ) );
		?>
		<table class="wp-list-table widefat striped" style="max-width:700px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Hook', 'cronpulse' ); ?></th>
					<th><?php esc_html_e( 'Failure threshold', 'cronpulse' ); ?></th>
					<th><?php esc_html_e( 'Overdue minutes', 'cronpulse' ); ?></th>
					<th><?php esc_html_e( 'Remove', 'cronpulse' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $per_job ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No overrides configured — all jobs use the global thresholds above.', 'cronpulse' ); ?></td></tr>
			<?php endif; ?>
			<?php $i = 0; foreach ( $per_job as $hook => $override ) : ?>
				<tr>
					<td>
						<code><?php echo esc_html( $hook ); ?></code>
						<input type="hidden" name="cronpulse_override_hook[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $hook ); ?>" />
					</td>
					<td><input type="number" min="1" name="cronpulse_override_failure[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $override['failure_threshold'] ); ?>" class="small-text" /></td>
					<td><input type="number" min="1" name="cronpulse_override_overdue[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $override['overdue_minutes'] ); ?>" class="small-text" /></td>
					<td><input type="checkbox" name="cronpulse_override_remove[]" value="<?php echo esc_attr( $i ); ?>" /></td>
				</tr>
			<?php $i++; endforeach; ?>
				<tr>
					<td>
						<select name="cronpulse_new_override_hook">
							<option value=""><?php esc_html_e( '— Add a hook —', 'cronpulse' ); ?></option>
							<?php foreach ( $available as $hook ) : ?>
								<option value="<?php echo esc_attr( $hook ); ?>"><?php echo esc_html( $hook ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><input type="number" min="1" name="cronpulse_new_override_failure" value="3" class="small-text" /></td>
					<td><input type="number" min="1" name="cronpulse_new_override_overdue" value="30" class="small-text" /></td>
					<td>—</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public static function render_settings_tab(): void {
		$settings = self::get_settings();
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'cronpulse_save_alerts', 'cronpulse_alerts_nonce' ); ?>
			<input type="hidden" name="cronpulse_alerts_submit" value="1" />

			<!-- Two-column layout: cards on the left, webhook help box pinned top-right -->
			<div class="cp-settings-layout">
			<div class="cp-settings-main">
			<div class="cp-settings-cards">

				<!-- Card 1: Alert Rules -->
				<div class="cp-settings-card">
					<div class="cp-settings-card-header">
						<span class="dashicons dashicons-bell"></span>
						<h2><?php esc_html_e( 'Alert Rules', 'cronpulse' ); ?></h2>
					</div>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="cronpulse-alert-enabled"><?php esc_html_e( 'Enable alerts', 'cronpulse' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="cronpulse-alert-enabled" name="cronpulse_alert_enabled" value="1" <?php checked( $settings['enabled'] ); ?> />
									<?php esc_html_e( 'Notify me when a job is failing or overdue', 'cronpulse' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cronpulse-failure-threshold"><?php esc_html_e( 'Failure threshold', 'cronpulse' ); ?></label>
							</th>
							<td>
								<input type="number" min="1" id="cronpulse-failure-threshold" name="cronpulse_failure_threshold" value="<?php echo esc_attr( $settings['failure_threshold'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Alert after this many consecutive failed runs for a job.', 'cronpulse' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cronpulse-overdue-minutes"><?php esc_html_e( 'Overdue threshold (minutes)', 'cronpulse' ); ?></label>
							</th>
							<td>
								<input type="number" min="1" id="cronpulse-overdue-minutes" name="cronpulse_overdue_minutes" value="<?php echo esc_attr( $settings['overdue_minutes'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Alert when a job has been overdue for longer than this.', 'cronpulse' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cronpulse-alert-email"><?php esc_html_e( 'Notification email', 'cronpulse' ); ?></label>
							</th>
							<td>
								<input type="email" id="cronpulse-alert-email" name="cronpulse_alert_email" value="<?php echo esc_attr( $settings['email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'cronpulse' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cronpulse-log-retention"><?php esc_html_e( 'Log retention', 'cronpulse' ); ?></label>
							</th>
							<td>
								<input type="number" min="10" max="5000" id="cronpulse-log-retention" name="cronpulse_log_retention" value="<?php echo esc_attr( $settings['log_retention'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Number of execution log entries to keep (10–5000).', 'cronpulse' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Card 2: SMTP -->
				<div class="cp-settings-card">
					<div class="cp-settings-card-header">
						<span class="dashicons dashicons-email-alt"></span>
						<h2><?php esc_html_e( 'Email Delivery (SMTP)', 'cronpulse' ); ?></h2>
					</div>
					<div class="cp-settings-card-body">
						<p class="description"><?php esc_html_e( 'Routes alert emails through your own mail provider instead of the server\'s default mail() — no separate SMTP plugin needed.', 'cronpulse' ); ?></p>
					</div>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="cronpulse-smtp-enabled"><?php esc_html_e( 'Use SMTP', 'cronpulse' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="cronpulse-smtp-enabled" name="cronpulse_smtp_enabled" value="1" <?php checked( $settings['smtp_enabled'] ); ?> />
									<?php esc_html_e( 'Send through the SMTP server below', 'cronpulse' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cronpulse-smtp-host"><?php esc_html_e( 'SMTP Host', 'cronpulse' ); ?></label>
							</th>
							<td>
								<input type="text" id="cronpulse-smtp-host" name="cronpulse_smtp_host" value="<?php echo esc_attr( $settings['smtp_host'] ); ?>" class="regular-text" placeholder="smtp.example.com" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cronpulse-smtp-port"><?php esc_html_e( 'Port / Encryption', 'cronpulse' ); ?></label>
							</th>
							<td>
								<div class="cp-port-row">
									<input type="number" min="1" max="65535" id="cronpulse-smtp-port" name="cronpulse_smtp_port" value="<?php echo esc_attr( $settings['smtp_port'] ); ?>" class="small-text" />
									<select id="cronpulse-smtp-encryption" name="cronpulse_smtp_encryption">
										<option value="tls" <?php selected( $settings['smtp_encryption'], 'tls' ); ?>>TLS</option>
										<option value="ssl" <?php selected( $settings['smtp_encryption'], 'ssl' ); ?>>SSL</option>
										<option value="none" <?php selected( $settings['smtp_encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'cronpulse' ); ?></option>
									</select>
								</div>
								<p class="description"><?php esc_html_e( 'TLS on port 587 is the most common modern setup.', 'cronpulse' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cronpulse-smtp-username"><?php esc_html_e( 'Username', 'cronpulse' ); ?></label>
							</th>
							<td>
								<input type="text" id="cronpulse-smtp-username" name="cronpulse_smtp_username" value="<?php echo esc_attr( $settings['smtp_username'] ); ?>" class="regular-text" autocomplete="off" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cronpulse-smtp-password"><?php esc_html_e( 'Password', 'cronpulse' ); ?></label>
							</th>
							<td>
								<input type="password" id="cronpulse-smtp-password" name="cronpulse_smtp_password" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $settings['smtp_password'] ? esc_attr__( '•••••••• (leave blank to keep)', 'cronpulse' ) : ''; ?>" />
								<p class="description"><?php esc_html_e( 'Left blank on every page load. Leave it blank when saving to keep the current password.', 'cronpulse' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cronpulse-smtp-from-email"><?php esc_html_e( 'From Email', 'cronpulse' ); ?></label>
							</th>
							<td>
								<input type="email" id="cronpulse-smtp-from-email" name="cronpulse_smtp_from_email" value="<?php echo esc_attr( $settings['smtp_from_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Optional. Many providers require this to match a verified address.', 'cronpulse' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cronpulse-smtp-from-name"><?php esc_html_e( 'From Name', 'cronpulse' ); ?></label>
							</th>
							<td>
								<input type="text" id="cronpulse-smtp-from-name" name="cronpulse_smtp_from_name" value="<?php echo esc_attr( $settings['smtp_from_name'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Test', 'cronpulse' ); ?></th>
							<td>
								<button type="button" class="button cp-test-email"><?php esc_html_e( 'Send Test Email', 'cronpulse' ); ?></button>
								<p class="description"><?php esc_html_e( 'Save settings first, then confirm delivery works.', 'cronpulse' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Card 3: Webhook -->
				<div class="cp-settings-card">
					<div class="cp-settings-card-header">
						<span class="dashicons dashicons-admin-links"></span>
						<h2><?php esc_html_e( 'Webhook', 'cronpulse' ); ?></h2>
					</div>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="cronpulse-alert-webhook"><?php esc_html_e( 'Webhook URL', 'cronpulse' ); ?></label>
							</th>
							<td>
								<input type="url" id="cronpulse-alert-webhook" name="cronpulse_alert_webhook" value="<?php echo esc_attr( $settings['webhook'] ); ?>" class="regular-text" placeholder="https://" />
								<p class="description"><?php esc_html_e( 'Slack, Discord, or your own endpoint. Save first, then test.', 'cronpulse' ); ?></p>
								<button type="button" class="button cp-test-webhook" style="margin-top:8px;"><?php esc_html_e( 'Send Test Webhook', 'cronpulse' ); ?></button>
							</td>
						</tr>
					</table>
				</div>

				<!-- Card 4: Per-Job Overrides -->
				<div class="cp-settings-card">
					<div class="cp-settings-card-header">
						<span class="dashicons dashicons-admin-tools"></span>
						<h2><?php esc_html_e( 'Per-Job Overrides', 'cronpulse' ); ?></h2>
					</div>
					<div class="cp-settings-card-body">
						<p class="description"><?php esc_html_e( 'Override the failure/overdue thresholds above for specific hooks — e.g. a tighter threshold for a payment hook than for a daily cleanup job.', 'cronpulse' ); ?></p>
						<?php self::render_overrides_table( $settings['per_job'] ); ?>
					</div>
				</div>

			</div><!-- .cp-settings-cards -->
			</div><!-- .cp-settings-main -->

			<div class="cp-settings-sidebar">
				<?php self::render_webhook_help_box(); ?>
			</div>

			</div><!-- .cp-settings-layout -->

			<?php submit_button( __( 'Save Settings', 'cronpulse' ) ); ?>
		</form>
		<?php
	}

	/**
	 * A "how do I get a webhook URL" cheat sheet for the most common targets.
	 * Lives in its own method since it's purely presentational, no settings
	 * logic involved.
	 */
	private static function render_webhook_help_box(): void {
		?>
		<div class="cp-help-box">
			<h3>
				<span class="dashicons dashicons-admin-links"></span>
				<?php esc_html_e( 'Setting Up a Webhook', 'cronpulse' ); ?>
			</h3>
			<p><?php esc_html_e( 'A webhook lets Cron Pulse notify Slack, Discord, or your own server the moment a job starts failing or running too late — paste the URL into the Webhook URL field on the left.', 'cronpulse' ); ?></p>

			<h4>Slack</h4>
			<ol>
				<li><?php esc_html_e( 'Go to api.slack.com/apps and create (or open) an app', 'cronpulse' ); ?></li>
				<li><?php esc_html_e( 'Enable "Incoming Webhooks", then "Add New Webhook to Workspace"', 'cronpulse' ); ?></li>
				<li><?php esc_html_e( 'Pick a channel and copy the Webhook URL it gives you', 'cronpulse' ); ?></li>
			</ol>

			<h4>Discord</h4>
			<ol>
				<li><?php esc_html_e( 'In your server: Server Settings → Integrations → Webhooks', 'cronpulse' ); ?></li>
				<li><?php esc_html_e( '"New Webhook" — name it and pick a channel', 'cronpulse' ); ?></li>
				<li><?php esc_html_e( 'Click "Copy Webhook URL"', 'cronpulse' ); ?></li>
			</ol>

			<h4><?php esc_html_e( 'Your Own Endpoint', 'cronpulse' ); ?></h4>
			<p><?php esc_html_e( 'Receives an HTTP POST, Content-Type: application/json:', 'cronpulse' ); ?></p>
			<pre class="cp-help-code">{
"plugin": "cronpulse",
"hook": "my_cron_hook",
"type": "failure",
"site": "https://example.com",
"message": "...",
"text": "...",
"content": "..."
}
</pre>
			<p class="description"><?php esc_html_e( '"text" and "content" are included so the same payload works as-is with Slack and Discord — safe to ignore them if you only need the structured fields.', 'cronpulse' ); ?></p>

			<p class="description"><?php esc_html_e( 'Once saved, use "Send Test Webhook" above to confirm it actually arrives.', 'cronpulse' ); ?></p>
		</div>
		<?php
	}
}
