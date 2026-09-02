<?php
/**
 * CronPulse_Ajax_Handler
 *
 * Handles AJAX requests for:
 *  - cronpulse_run_now        — manually trigger a cron hook
 *  - cronpulse_clear_log      — wipe the execution log
 *  - cronpulse_unschedule     — remove a scheduled event from WP-Cron
 *  - cronpulse_snooze         — acknowledge the current failing/overdue incident for a hook
 *  - cronpulse_test_email     — send a test alert email using the saved settings
 *  - cronpulse_test_webhook   — send a test alert payload to the saved webhook URL
 *  - cronpulse_clear_email_log — wipe the email log
 *  - cronpulse_clear_debug_log — wipe the SMTP debug log
 *
 * @package CronPulse
 */
defined( 'ABSPATH' ) || exit;

class CronPulse_Ajax_Handler {

	public static function init(): void {
		add_action( 'wp_ajax_cronpulse_run_now',         [ __CLASS__, 'handle_run_now' ] );
		add_action( 'wp_ajax_cronpulse_clear_log',       [ __CLASS__, 'handle_clear_log' ] );
		add_action( 'wp_ajax_cronpulse_unschedule',      [ __CLASS__, 'handle_unschedule' ] );
		add_action( 'wp_ajax_cronpulse_snooze',          [ __CLASS__, 'handle_snooze' ] );
		add_action( 'wp_ajax_cronpulse_test_email',      [ __CLASS__, 'handle_test_email' ] );
		add_action( 'wp_ajax_cronpulse_test_webhook',    [ __CLASS__, 'handle_test_webhook' ] );
		add_action( 'wp_ajax_cronpulse_clear_email_log', [ __CLASS__, 'handle_clear_email_log' ] );
		add_action( 'wp_ajax_cronpulse_clear_debug_log', [ __CLASS__, 'handle_clear_debug_log' ] );
	}

	// -------------------------------------------------------------------------

	public static function handle_run_now(): void {
		check_ajax_referer( 'cronpulse_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'cronpulse' ) ], 403 );
			return;
		}

		$hook = sanitize_key( wp_unslash( $_POST['hook'] ?? '' ) );
		if ( empty( $hook ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid hook name.', 'cronpulse' ) ] );
			return;
		}

		// Whitelist: only allow hooks that are currently registered in WP-Cron.
		if ( ! in_array( $hook, self::get_registered_cron_hooks(), true ) ) {
			wp_send_json_error( [ 'message' => __( 'Hook is not a registered cron job.', 'cronpulse' ) ] );
			return;
		}

		$raw_args = sanitize_text_field( wp_unslash( $_POST['args'] ?? '' ) );
		$args     = [];
		if ( ! empty( $raw_args ) ) {
			$decoded = json_decode( $raw_args, true );
			if ( is_array( $decoded ) ) {
				$args = self::sanitize_args( $decoded );
			}
		}

		// Capture any output or PHP errors the hook emits.
		$captured_errors = [];
		set_error_handler( static function ( int $errno, string $errstr, string $errfile, int $errline ) use ( &$captured_errors ): bool { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			$labels = [
				E_WARNING         => 'Warning',
				E_NOTICE          => 'Notice',
				E_USER_WARNING    => 'Warning',
				E_USER_NOTICE     => 'Notice',
				E_USER_ERROR      => 'Error',
				E_DEPRECATED      => 'Deprecated',
				E_USER_DEPRECATED => 'Deprecated',
			];
			$label            = $labels[ $errno ] ?? 'Error';
			$captured_errors[] = sprintf( '[%s] %s in %s on line %d', $label, $errstr, $errfile, $errline );
			return true; // Suppress default PHP output.
		}, E_ALL & ~E_STRICT );

		ob_start();
		$exception = null;
		$start     = microtime( true );

		try {
			do_action_ref_array( $hook, $args );
		} catch ( \Throwable $e ) {
			$exception = $e;
		} finally {
			$duration = (int) round( ( microtime( true ) - $start ) * 1000 );
			$output   = (string) ob_get_clean();
			restore_error_handler();
		}

		$parts = [];
		if ( '' !== $output ) {
			$parts[] = $output;
		}
		if ( ! empty( $captured_errors ) ) {
			$parts[] = implode( "\n", $captured_errors );
		}
		if ( null !== $exception ) {
			$parts[] = sprintf( 'Exception: %s', $exception->getMessage() );
		}
		$combined = implode( "\n", $parts );

		if ( null !== $exception ) {
			CronPulse_Cron_Tracker::log_execution( $hook, 'fatal', $duration, $exception->getMessage() );
			wp_send_json_error( [
				'message'  => sprintf(
					/* translators: 1: cron hook name, 2: exception message */
					__( 'Hook "%1$s" threw an exception: %2$s', 'cronpulse' ),
					esc_html( $hook ),
					esc_html( $exception->getMessage() )
				),
				'duration' => $duration,
				'output'   => $combined,
			] );
			return;
		}

		CronPulse_Cron_Tracker::log_execution( $hook, 'success', $duration );

		wp_send_json_success( [
			'message'  => sprintf(
				/* translators: 1: cron hook name, 2: execution time in milliseconds */
				__( 'Hook "%1$s" fired in %2$d ms.', 'cronpulse' ),
				esc_html( $hook ),
				$duration
			),
			'duration' => $duration,
			'output'   => $combined,
		] );
	}

	// -------------------------------------------------------------------------

	public static function handle_unschedule(): void {
		check_ajax_referer( 'cronpulse_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'cronpulse' ) ], 403 );
			return;
		}

		$hook      = sanitize_key( wp_unslash( $_POST['hook'] ?? '' ) );
		$timestamp = absint( $_POST['timestamp'] ?? 0 );
		$sig       = sanitize_text_field( wp_unslash( $_POST['sig'] ?? '' ) );

		if ( empty( $hook ) || empty( $timestamp ) || empty( $sig ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid job.', 'cronpulse' ) ] );
			return;
		}

		// Look up the event's own stored args rather than trusting anything
		// reconstructed client-side — wp_unschedule_event() matches on the
		// exact args array, and a JSON round-trip can change scalar types.
		$crons = _get_cron_array();
		if ( empty( $crons[ $timestamp ][ $hook ][ $sig ] ) ) {
			wp_send_json_error( [ 'message' => __( 'That job is no longer scheduled.', 'cronpulse' ) ] );
			return;
		}

		$args   = $crons[ $timestamp ][ $hook ][ $sig ]['args'] ?? [];
		$result = wp_unschedule_event( $timestamp, $hook, $args );

		if ( false === $result || is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not unschedule the job.', 'cronpulse' ) ] );
			return;
		}

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: %s = cron hook name */
				__( 'Unscheduled "%s".', 'cronpulse' ),
				esc_html( $hook )
			),
		] );
	}

	// -------------------------------------------------------------------------

	public static function handle_snooze(): void {
		check_ajax_referer( 'cronpulse_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'cronpulse' ) ], 403 );
			return;
		}

		$hook = sanitize_key( wp_unslash( $_POST['hook'] ?? '' ) );
		if ( empty( $hook ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid hook name.', 'cronpulse' ) ] );
			return;
		}

		CronPulse_Alerts::snooze( $hook );

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: %s = cron hook name */
				__( 'Alerts snoozed for "%s" until the next new incident.', 'cronpulse' ),
				esc_html( $hook )
			),
		] );
	}

	// -------------------------------------------------------------------------

	public static function handle_test_email(): void {
		check_ajax_referer( 'cronpulse_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'cronpulse' ) ], 403 );
			return;
		}

		// A bad SMTP host/port/cert can throw in places wp_mail() itself
		// doesn't catch — without this, that breaks the JSON response and
		// the browser just shows a generic "request failed" message instead
		// of the real reason. Logged detail is in the Email Debug Log either way.
		try {
			$settings = CronPulse_Alerts::get_settings();
			$to       = $settings['email'] ?: get_option( 'admin_email' );

			// Checked once and appended to whichever message goes out below —
			// otherwise the debug log just stays silently empty with no clue
			// that it's a permissions problem rather than nothing happening.
			$log_warning = CronPulse_Debug_Log::is_writable()
				? ''
				: ' ' . sprintf(
					/* translators: %s = full server file path */
					__( '(Note: the debug log directory is not writable — %s — so no debug detail was recorded for this attempt.)', 'cronpulse' ),
					esc_html( CronPulse_Debug_Log::get_dir() )
				);

			$site      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$dashboard = admin_url( 'tools.php?page=cronpulse' );

			$plain = sprintf(
				"This is a test email from Cron Pulse to confirm your email settings are working.\n\nSite: %s\nDashboard: %s",
				$site,
				$dashboard
			);

			$rows = CronPulse_Alerts::render_email_row( __( 'Site', 'cronpulse' ), $site );

			$html = CronPulse_Alerts::render_email_html(
				__( 'Test', 'cronpulse' ),
				'#00a32a',
				__( 'Your email settings are working', 'cronpulse' ),
				'',
				__( 'This is a test email from Cron Pulse. If you can read this, alert emails will reach you the same way.', 'cronpulse' ),
				$rows,
				$dashboard,
				__( 'Open Dashboard', 'cronpulse' ),
				$site
			);

			$sent = CronPulse_Alerts::send_and_log(
				$to,
				'[Cron Pulse] Test email',
				$html,
				$plain,
				'test'
			);

			if ( $sent ) {
				wp_send_json_success( [
					'message' => sprintf(
						/* translators: %s = recipient email address */
						__( 'Test email sent to %s. Check the Email Log tab to confirm.', 'cronpulse' ),
						esc_html( $to )
					) . $log_warning,
				] );
				return;
			}

			$log        = CronPulse_Alerts::get_email_log();
			$last_error = $log[0]['error'] ?? '';

			wp_send_json_error( [
				'message' => ( $last_error
					? sprintf(
						/* translators: %s = error message from the mail server */
						__( 'Failed to send: %s', 'cronpulse' ),
						esc_html( $last_error )
					)
					: __( 'wp_mail() returned false with no further detail. Check the Email Debug Log.', 'cronpulse' ) ) . $log_warning,
			] );
		} catch ( \Throwable $e ) {
			CronPulse_Debug_Log::write( 'EXCEPTION during test email: ' . $e->getMessage() );

			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s = exception message */
					__( 'Unexpected error: %s. See the Email Debug Log for detail.', 'cronpulse' ),
					esc_html( $e->getMessage() )
				),
			] );
		}
	}

	// -------------------------------------------------------------------------

	public static function handle_test_webhook(): void {
		check_ajax_referer( 'cronpulse_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'cronpulse' ) ], 403 );
			return;
		}

		$settings = CronPulse_Alerts::get_settings();
		$webhook  = $settings['webhook'];

		if ( empty( $webhook ) ) {
			wp_send_json_error( [ 'message' => __( 'No webhook URL is saved yet — save settings first.', 'cronpulse' ) ] );
			return;
		}

		$site      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$dashboard = admin_url( 'tools.php?page=cronpulse' );

		$plain = sprintf(
			/* translators: %s = site domain */
			__( 'This is a test alert from Cron Pulse on %s.', 'cronpulse' ),
			$site
		);
		$short = '🟢 ' . $plain;

		$payload = CronPulse_Alerts::build_webhook_payload(
			'test',
			'#00a32a',
			__( 'Cron Pulse test alert', 'cronpulse' ),
			'',
			__( 'If you can see this, webhook alerts are configured correctly.', 'cronpulse' ),
			[ [ 'label' => __( 'Site', 'cronpulse' ), 'value' => $site ] ],
			$dashboard,
			$short,
			$plain
		);

		$response = wp_remote_post( $webhook, [
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s = error message */
					__( 'Request failed: %s', 'cronpulse' ),
					esc_html( $response->get_error_message() )
				),
			] );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			wp_send_json_success( [
				/* translators: %d = HTTP status code */
				'message' => sprintf( __( 'Webhook responded with HTTP %d.', 'cronpulse' ), $code ),
			] );
		} else {
			wp_send_json_error( [
				/* translators: %d = HTTP status code */
				'message' => sprintf( __( 'Webhook responded with HTTP %d — check the endpoint.', 'cronpulse' ), $code ),
			] );
		}
	}

	// -------------------------------------------------------------------------

	public static function handle_clear_email_log(): void {
		check_ajax_referer( 'cronpulse_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'cronpulse' ) ], 403 );
			return;
		}

		CronPulse_Alerts::clear_email_log();
		wp_send_json_success( [ 'message' => __( 'Email log cleared.', 'cronpulse' ) ] );
	}

	// -------------------------------------------------------------------------

	public static function handle_clear_debug_log(): void {
		check_ajax_referer( 'cronpulse_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'cronpulse' ) ], 403 );
			return;
		}

		if ( CronPulse_Debug_Log::clear() ) {
			wp_send_json_success( [ 'message' => __( 'Debug log cleared.', 'cronpulse' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Could not clear the debug log file — check file permissions on wp-content/uploads/cronpulse-logs/.', 'cronpulse' ) ] );
		}
	}

	// -------------------------------------------------------------------------

	public static function handle_clear_log(): void {
		check_ajax_referer( 'cronpulse_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'cronpulse' ) ], 403 );
			return;
		}

		CronPulse_Cron_Tracker::clear_log();
		wp_send_json_success( [ 'message' => __( 'Log cleared.', 'cronpulse' ) ] );
	}

	// -------------------------------------------------------------------------

	/**
	 * Return a flat unique list of all hook names in the WP-Cron queue.
	 *
	 * @return string[]
	 */
	private static function get_registered_cron_hooks(): array {
		$crons = _get_cron_array();
		if ( empty( $crons ) || ! is_array( $crons ) ) {
			return [];
		}

		$hooks = [];
		foreach ( $crons as $events ) {
			if ( is_array( $events ) ) {
				foreach ( array_keys( $events ) as $hook ) {
					$hooks[] = $hook;
				}
			}
		}

		return array_unique( $hooks );
	}

	/**
	 * Recursively sanitize an args array received from the client.
	 * Scalar values are cast to string and sanitized; nested arrays are
	 * processed recursively. Non-scalar, non-array values are dropped.
	 *
	 * @param array $args
	 * @return array
	 */
	private static function sanitize_args( array $args ): array {
		$clean = [];
		foreach ( $args as $key => $value ) {
			$clean_key = sanitize_key( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $clean_key ] = self::sanitize_args( $value );
			} elseif ( is_scalar( $value ) ) {
				$clean[ $clean_key ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}
}
