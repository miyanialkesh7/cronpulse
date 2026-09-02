<?php
/**
 * CronPulse_Site_Health
 *
 * Registers a Site Health check so failing or overdue cron jobs surface
 * on Tools → Site Health alongside other system-level warnings — no need
 * to visit the Cron Pulse dashboard to know something is wrong.
 */
defined( 'ABSPATH' ) || exit;

class CronPulse_Site_Health {

	public static function init(): void {
		add_filter( 'site_status_tests', [ __CLASS__, 'register_tests' ] );
	}

	/**
	 * Register the cron-health test as a direct (synchronous) Site Health check.
	 *
	 * @param array $tests Existing registered tests.
	 * @return array
	 */
	public static function register_tests( array $tests ): array {
		$tests['direct']['cronpulse_cron_status'] = [
			'label' => __( 'Cron job health', 'cronpulse' ),
			'test'  => [ __CLASS__, 'get_result' ],
		];

		$tests['direct']['cronpulse_cron_source'] = [
			'label' => __( 'Cron scheduling method', 'cronpulse' ),
			'test'  => [ __CLASS__, 'get_cron_source_result' ],
		];

		return $tests;
	}

	/**
	 * Build and return the Site Health result for the cron scheduling method in use.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_cron_source_result(): array {
		$using_system_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$dashboard_url     = admin_url( 'tools.php?page=cronpulse' );
		$actions           = '<a href="' . esc_url( $dashboard_url ) . '">' . __( 'Open Cron Pulse dashboard', 'cronpulse' ) . '</a>';

		if ( $using_system_cron ) {
			return [
				'label'       => __( 'System cron is configured', 'cronpulse' ),
				'status'      => 'good',
				'badge'       => [ 'label' => __( 'Performance', 'cronpulse' ), 'color' => 'blue' ],
				'description' => '<p>' . __( 'DISABLE_WP_CRON is set, so cron jobs are handled by a real system cron rather than page-visit-triggered pseudo-cron. This is the recommended setup for reliable job execution.', 'cronpulse' ) . '</p>',
				'actions'     => $actions,
				'test'        => 'cronpulse_cron_source',
			];
		}

		$cron_cmd = '*/5 * * * * curl -s "' . site_url( 'wp-cron.php?doing_wp_cron' ) . '" > /dev/null 2>&1';

		return [
			'label'       => __( 'WP pseudo-cron is active', 'cronpulse' ),
			'status'      => 'recommended',
			'badge'       => [ 'label' => __( 'Performance', 'cronpulse' ), 'color' => 'orange' ],
			'description' => '<p>' . __( 'WP pseudo-cron relies on site visitors to trigger scheduled jobs. On low-traffic sites, jobs may run late or not at all.', 'cronpulse' ) . '</p>' .
				'<p>' . __( 'To switch to a real system cron: add <code>define( \'DISABLE_WP_CRON\', true );</code> to wp-config.php, then add this crontab entry:', 'cronpulse' ) . '</p>' .
				'<p><code>' . esc_html( $cron_cmd ) . '</code></p>',
			'actions'     => $actions,
			'test'        => 'cronpulse_cron_source',
		];
	}

	/**
	 * Build and return the Site Health result for the current cron job state.
	 *
	 * Priority: critical (any failing) → recommended (any overdue) → good.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_result(): array {
		$jobs    = CronPulse_Admin_Page::get_jobs();
		$total   = count( $jobs );
		$failing = 0;
		$overdue = 0;

		foreach ( $jobs as $job ) {
			if ( 'failing' === $job['status'] ) {
				$failing++;
			} elseif ( 'overdue' === $job['status'] ) {
				$overdue++;
			}
		}

		$dashboard_url = admin_url( 'tools.php?page=cronpulse' );
		$actions       = '<a href="' . esc_url( $dashboard_url ) . '">' . __( 'Open Cron Pulse dashboard', 'cronpulse' ) . '</a>';

		if ( $failing > 0 ) {
			return [
				'label'       => sprintf(
					/* translators: %d = number of failing cron jobs */
					_n( '%d cron job is failing', '%d cron jobs are failing', $failing, 'cronpulse' ),
					$failing
				),
				'status'      => 'critical',
				'badge'       => [ 'label' => __( 'Performance', 'cronpulse' ), 'color' => 'red' ],
				'description' => '<p>' . sprintf(
					/* translators: 1: failing job count, 2: total job count */
					__( '%1$d of %2$d scheduled jobs have failed recently. Failing cron jobs can affect email delivery, payment processing, and other background tasks.', 'cronpulse' ),
					$failing,
					$total
				) . '</p>',
				'actions'     => $actions,
				'test'        => 'cronpulse_cron_status',
			];
		}

		if ( $overdue > 0 ) {
			return [
				'label'       => sprintf(
					/* translators: %d = number of overdue cron jobs */
					_n( '%d cron job is overdue', '%d cron jobs are overdue', $overdue, 'cronpulse' ),
					$overdue
				),
				'status'      => 'recommended',
				'badge'       => [ 'label' => __( 'Performance', 'cronpulse' ), 'color' => 'orange' ],
				'description' => '<p>' . sprintf(
					/* translators: 1: overdue job count, 2: total job count */
					__( '%1$d of %2$d scheduled jobs are overdue — they were due to run but have not fired yet. This often happens on low-traffic sites where WP-Cron relies on page visits to trigger.', 'cronpulse' ),
					$overdue,
					$total
				) . '</p>',
				'actions'     => $actions,
				'test'        => 'cronpulse_cron_status',
			];
		}

		return [
			'label'       => $total > 0
				? sprintf(
					/* translators: %d = number of monitored cron jobs */
					_n( 'All %d cron job is running on schedule', 'All %d cron jobs are running on schedule', $total, 'cronpulse' ),
					$total
				)
				: __( 'No scheduled cron jobs found', 'cronpulse' ),
			'status'      => 'good',
			'badge'       => [ 'label' => __( 'Performance', 'cronpulse' ), 'color' => 'blue' ],
			'description' => '<p>' . ( $total > 0
				? sprintf(
					/* translators: %d = number of monitored cron jobs */
					_n( 'Cron Pulse is monitoring %d scheduled job and all are healthy.', 'Cron Pulse is monitoring %d scheduled jobs and all are healthy.', $total, 'cronpulse' ),
					$total
				)
				: __( 'Cron Pulse found no scheduled cron jobs on this site.', 'cronpulse' )
			) . '</p>',
			'actions'     => $total > 0 ? $actions : '',
			'test'        => 'cronpulse_cron_status',
		];
	}
}
