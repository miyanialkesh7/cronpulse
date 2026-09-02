<?php
/**
 * CronPulse_Admin_Page
 *
 * Registers the wp-admin menu page and renders the cron dashboard.
 *
 * @package CronPulse
 */
defined( 'ABSPATH' ) || exit;

class CronPulse_Admin_Page {

	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	public static function register_menu(): void {
		add_management_page(
			__( 'Cron Pulse', 'cronpulse' ),
			__( 'Cron Pulse', 'cronpulse' ),
			'manage_options',
			'cronpulse',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'tools_page_cronpulse' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'cronpulse-admin',
			CRONPULSE_PLUGIN_URL . 'assets/admin.css',
			[],
			CRONPULSE_VERSION
		);
		wp_enqueue_script(
			'cronpulse-admin',
			CRONPULSE_PLUGIN_URL . 'assets/admin.js',
			[ 'jquery' ],
			CRONPULSE_VERSION,
			true
		);
		wp_localize_script( 'cronpulse-admin', 'cronpulseData', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cronpulse_nonce' ),
			'i18n'    => [
				'running'           => __( 'Running…', 'cronpulse' ),
				'success'           => __( 'Triggered successfully', 'cronpulse' ),
				'error'             => __( 'Error triggering job', 'cronpulse' ),
				'runNow'            => __( 'Run Now', 'cronpulse' ),
				'justNow'           => __( 'Just now', 'cronpulse' ),
				'confirmClear'      => __( 'Clear the entire execution log? This cannot be undone.', 'cronpulse' ),
				/* translators: %s = cron hook name */
				'confirmUnschedule' => __( 'Unschedule "%s"? If something else re-schedules it, it may come back.', 'cronpulse' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'cronpulse' ) );
		}

		$jobs           = self::get_jobs();
		$log            = CronPulse_Cron_Tracker::get_log();
		$email_log      = CronPulse_Alerts::get_email_log();
		$schedules      = wp_get_schedules();
		$alerts_enabled = CronPulse_Alerts::get_settings()['enabled'];

		// Summary counts
		$total   = count( $jobs );
		$overdue = 0;
		$failing = 0;
		$healthy = 0;
		$never   = 0;
		$oldest_overdue_since = null;

		foreach ( $jobs as $job ) {
			if ( 'overdue' === $job['status'] ) {
				$overdue++;
				if ( null === $oldest_overdue_since || $job['next_run'] < $oldest_overdue_since ) {
					$oldest_overdue_since = $job['next_run'];
				}
			} elseif ( 'failing' === $job['status'] ) {
				$failing++;
			} elseif ( 'healthy' === $job['status'] ) {
				$healthy++;
			} else {
				$never++;
			}
		}
		?>
		<div class="wrap cp-wrap">
			<h1 class="cp-title">
				<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Cron Pulse', 'cronpulse' ); ?>
			</h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag that toggles a static notice; nothing is processed or saved here.
			if ( isset( $_GET['updated'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'cronpulse' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $failing > 0 || $overdue > 0 ) : ?>
			<div class="cp-alert-banner" role="alert">
				<span class="dashicons dashicons-warning"></span>
				<span class="cp-alert-banner-text">
				<?php
				$parts = [];
				if ( $failing > 0 ) {
					$parts[] = sprintf(
						/* translators: %d = number of failing cron jobs */
						_n( '%d job failing', '%d jobs failing', $failing, 'cronpulse' ),
						$failing
					);
				}
				if ( $overdue > 0 ) {
					$parts[] = sprintf(
						/* translators: %d = number of overdue cron jobs */
						_n( '%d job overdue', '%d jobs overdue', $overdue, 'cronpulse' ),
						$overdue
					);
				}
				echo esc_html( implode( ', ', $parts ) ) . ' — ';
				?>
				<a href="#" class="cp-alert-banner-link" data-tab="jobs"><?php esc_html_e( 'View Jobs →', 'cronpulse' ); ?></a>
				</span>
				<button type="button" class="cp-alert-banner-dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'cronpulse' ); ?>">✕</button>
			</div>
			<?php endif; ?>

			<!-- Summary bar -->
			<div class="cp-summary-bar">
				<div class="cp-summary-card cp-total">
					<span class="cp-num"><?php echo esc_html( $total ); ?></span>
					<span class="cp-label"><?php esc_html_e( 'Total Jobs', 'cronpulse' ); ?></span>
				</div>
				<div class="cp-summary-card cp-healthy">
					<span class="cp-num"><?php echo esc_html( $healthy ); ?></span>
					<span class="cp-label"><?php esc_html_e( 'Healthy', 'cronpulse' ); ?></span>
				</div>
				<div class="cp-summary-card cp-overdue">
					<span class="cp-num"><?php echo esc_html( $overdue ); ?></span>
					<span class="cp-label"><?php esc_html_e( 'Overdue', 'cronpulse' ); ?></span>
					<?php if ( $oldest_overdue_since ) : ?>
					<span class="cp-summary-sub"><?php echo esc_html( sprintf(
						/* translators: %s = human-readable time, e.g. "2 hours" */
						__( 'since %s', 'cronpulse' ),
						human_time_diff( $oldest_overdue_since )
					) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="cp-summary-card cp-failing">
					<span class="cp-num"><?php echo esc_html( $failing ); ?></span>
					<span class="cp-label"><?php esc_html_e( 'Failing', 'cronpulse' ); ?></span>
				</div>
				<div class="cp-summary-card cp-never">
					<span class="cp-num"><?php echo esc_html( $never ); ?></span>
					<span class="cp-label"><?php esc_html_e( 'Never Run', 'cronpulse' ); ?></span>
				</div>
				<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div class="cp-notice-inline cp-ok">
					<span class="dashicons dashicons-yes-alt"></span>
					<?php esc_html_e( 'System cron is active — WP pseudo-cron is disabled.', 'cronpulse' ); ?>
				</div>
				<?php else : ?>
				<div class="cp-syscron-notice">
					<div class="cp-syscron-header">
						<span class="dashicons dashicons-clock"></span>
						<strong><?php esc_html_e( 'Pseudo-cron is active', 'cronpulse' ); ?></strong>
						<button type="button" class="cp-syscron-toggle" aria-expanded="false">
							<?php esc_html_e( 'Show recommendation', 'cronpulse' ); ?> <span class="cp-syscron-arrow">▾</span>
						</button>
					</div>
					<div class="cp-syscron-body" hidden>
						<p><?php esc_html_e( 'WP pseudo-cron relies on site visitors to trigger scheduled jobs. On low-traffic sites, jobs may run late or not at all. A real system cron fires on a fixed schedule regardless of traffic.', 'cronpulse' ); ?></p>
						<p><strong><?php esc_html_e( 'Step 1 — Add to wp-config.php:', 'cronpulse' ); ?></strong></p>
						<div class="cp-syscron-cmd-wrap">
							<code class="cp-syscron-cmd">define( 'DISABLE_WP_CRON', true );</code>
							<button type="button" class="cp-copy-cmd button-link" data-cmd="define( 'DISABLE_WP_CRON', true );"><?php esc_html_e( 'Copy', 'cronpulse' ); ?></button>
						</div>
						<p><strong><?php esc_html_e( 'Step 2 — Add a system crontab entry (every 5 minutes):', 'cronpulse' ); ?></strong></p>
						<?php
						$cron_url = esc_url( site_url( 'wp-cron.php?doing_wp_cron' ) );
						$cron_cmd = '*/5 * * * * curl -s "' . site_url( 'wp-cron.php?doing_wp_cron' ) . '" > /dev/null 2>&1';
						?>
						<div class="cp-syscron-cmd-wrap">
							<code class="cp-syscron-cmd"><?php echo esc_html( $cron_cmd ); ?></code>
							<button type="button" class="cp-copy-cmd button-link" data-cmd="<?php echo esc_attr( $cron_cmd ); ?>"><?php esc_html_e( 'Copy', 'cronpulse' ); ?></button>
						</div>
						<p class="cp-syscron-note"><?php esc_html_e( 'Run "crontab -e" on your server to add the entry. Adjust the path to curl if needed. Some managed hosts have a cron scheduler in their control panel.', 'cronpulse' ); ?></p>
					</div>
				</div>
				<?php endif; ?>
			</div>

			<?php echo self::render_schedule_strip( $jobs ); // phpcs:ignore WordPress.Security.EscapeOutput -- method returns pre-escaped HTML ?>

			<!-- Tabs -->
			<nav class="cp-tabs">
				<a href="#cp-jobs" class="cp-tab cp-tab-active" data-tab="jobs">
					<?php esc_html_e( 'Scheduled Jobs', 'cronpulse' ); ?>
					<?php if ( $failing + $overdue > 0 ) : ?>
					<span class="cp-badge cp-badge-alert"><?php echo esc_html( $failing + $overdue ); ?></span>
					<?php endif; ?>
				</a>
				<a href="#cp-log" class="cp-tab" data-tab="log">
					<?php esc_html_e( 'Execution Log', 'cronpulse' ); ?>
					<?php if ( ! empty( $log ) ) : ?>
						<span class="cp-badge"><?php echo esc_html( count( $log ) ); ?></span>
					<?php endif; ?>
				</a>
				<a href="#cp-email-log" class="cp-tab" data-tab="email-log">
					<?php esc_html_e( 'Email Log', 'cronpulse' ); ?>
					<?php if ( ! empty( $email_log ) ) : ?>
						<span class="cp-badge"><?php echo esc_html( count( $email_log ) ); ?></span>
					<?php endif; ?>
				</a>
				<a href="#cp-alerts" class="cp-tab" data-tab="alerts">
					<?php esc_html_e( 'Settings', 'cronpulse' ); ?>
				</a>
			</nav>

			<!-- Jobs tab -->
			<div id="cp-jobs" class="cp-tab-panel">
				<?php if ( empty( $jobs ) ) : ?>
					<p class="cp-empty"><?php esc_html_e( 'No scheduled cron jobs found.', 'cronpulse' ); ?></p>
				<?php else : ?>
				<div class="cp-search-bar">
					<input type="text" id="cp-search" placeholder="<?php esc_attr_e( 'Filter by hook name…', 'cronpulse' ); ?>" />
					<select id="cp-status-filter">
						<option value=""><?php esc_html_e( 'All statuses', 'cronpulse' ); ?></option>
						<option value="overdue"><?php esc_html_e( 'Overdue', 'cronpulse' ); ?></option>
						<option value="failing"><?php esc_html_e( 'Failing', 'cronpulse' ); ?></option>
						<option value="healthy"><?php esc_html_e( 'Healthy', 'cronpulse' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Never Run', 'cronpulse' ); ?></option>
					</select>
				</div>
				<table class="cp-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Status',   'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Hook',     'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Schedule', 'cronpulse' ); ?></th>
							<th class="cp-sortable" data-sort="next-run"><?php esc_html_e( 'Next Run', 'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Last Run', 'cronpulse' ); ?></th>
							<th class="cp-sortable" data-sort="duration"><?php esc_html_e( 'Duration', 'cronpulse' ); ?></th>
							<th class="cp-col-expand" aria-label="<?php esc_attr_e( 'Expand', 'cronpulse' ); ?>"></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $jobs as $job ) :
						$schedule_label = isset( $schedules[ $job['schedule'] ] )
							? $schedules[ $job['schedule'] ]['display']
							: ( $job['schedule'] ?: '—' );
						$last_run    = CronPulse_Cron_Tracker::get_last_run( $job['hook'] );
						$duration_ms = isset( $last_run['duration'] ) ? (int) $last_run['duration'] : -1;
						$duration    = $duration_ms >= 0 ? $duration_ms . ' ms' : '—';
						$sparkline   = self::render_sparkline( CronPulse_Cron_Tracker::get_recent_durations( $job['hook'], 10 ) );
						$needs_alert_action = $alerts_enabled && in_array( $job['status'], [ 'overdue', 'failing' ], true );
						$is_attention = in_array( $job['status'], [ 'overdue', 'failing' ], true );
						$error_msg   = ( $last_run && ! empty( $last_run['message'] ) ) ? (string) $last_run['message'] : '';
					?>
					<tr
						class="cp-row cp-job-row cp-status-<?php echo esc_attr( $job['status'] ); ?><?php echo $is_attention ? ' cp-job-row--open' : ''; ?>"
						data-hook="<?php echo esc_attr( $job['hook'] ); ?>"
						data-status="<?php echo esc_attr( $job['status'] ); ?>"
						data-next-run="<?php echo esc_attr( $job['next_run'] ); ?>"
						data-duration="<?php echo esc_attr( $duration_ms ); ?>"
					>
						<td>
							<span class="cp-chip cp-chip-<?php echo esc_attr( $job['status'] ); ?>"><?php echo esc_html( ucfirst( $job['status'] ) ); ?></span>
						</td>
						<td class="cp-hook">
							<code><?php echo esc_html( $job['hook'] ); ?></code>
							<?php if ( ! empty( $job['args'] ) ) : ?>
								<span class="cp-args" title="<?php echo esc_attr( wp_json_encode( $job['args'] ) ); ?>">+args</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $schedule_label ); ?></td>
						<td class="cp-next-run" data-timestamp="<?php echo esc_attr( $job['next_run'] ); ?>">
							<?php echo esc_html( self::format_time( (int) $job['next_run'] ) ); ?>
						</td>
						<td>
							<?php if ( $last_run ) :
								echo esc_html( self::format_time( (int) $last_run['timestamp'] ) );
							else : ?>
								<span class="cp-muted"><?php esc_html_e( 'Never', 'cronpulse' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<span class="cp-duration-text"><?php echo esc_html( $duration ); ?></span>
						</td>
						<td class="cp-col-expand">
							<span class="cp-expand-icon" aria-hidden="true">›</span>
						</td>
					</tr>
					<tr class="cp-job-detail cp-status-<?php echo esc_attr( $job['status'] ); ?>"<?php echo $is_attention ? '' : ' style="display:none;"'; ?>>
						<td colspan="7" class="cp-job-detail-cell">
							<div class="cp-job-detail-inner">
								<div class="cp-job-detail-meta">
									<?php if ( $last_run ) : ?>
									<span><strong><?php esc_html_e( 'Last run:', 'cronpulse' ); ?></strong> <?php echo esc_html( self::format_time( (int) $last_run['timestamp'] ) ); ?></span>
									<span><strong><?php esc_html_e( 'Status:', 'cronpulse' ); ?></strong> <?php echo esc_html( ucfirst( $last_run['status'] ) ); ?></span>
									<?php endif; ?>
									<span><strong><?php esc_html_e( 'Duration:', 'cronpulse' ); ?></strong> <?php echo esc_html( $duration ); ?>
										<?php echo $sparkline; // phpcs:ignore WordPress.Security.EscapeOutput -- built from numeric data ?>
									</span>
									<span><strong><?php esc_html_e( 'Schedule:', 'cronpulse' ); ?></strong> <?php echo esc_html( $schedule_label ); ?></span>
								</div>
								<?php if ( $error_msg ) : ?>
								<div class="cp-job-detail-error">
									<strong><?php esc_html_e( 'Last error:', 'cronpulse' ); ?></strong>
									<code class="cp-error-code"><?php echo esc_html( $error_msg ); ?></code>
								</div>
								<?php endif; ?>
								<div class="cp-job-detail-actions">
									<button
										class="button button-primary button-small cp-run-now"
										data-hook="<?php echo esc_attr( $job['hook'] ); ?>"
										data-args="<?php echo esc_attr( wp_json_encode( $job['args'] ) ); ?>"
									><?php esc_html_e( 'Run Now', 'cronpulse' ); ?></button>
									<?php if ( $needs_alert_action ) : ?>
									<button
										class="button button-small cp-snooze"
										data-hook="<?php echo esc_attr( $job['hook'] ); ?>"
									><?php esc_html_e( 'Snooze Alert', 'cronpulse' ); ?></button>
									<?php endif; ?>
									<button
										class="button button-small button-link-delete cp-unschedule"
										data-hook="<?php echo esc_attr( $job['hook'] ); ?>"
										data-timestamp="<?php echo esc_attr( $job['next_run'] ); ?>"
										data-sig="<?php echo esc_attr( $job['sig'] ); ?>"
									><?php esc_html_e( 'Unschedule', 'cronpulse' ); ?></button>
								</div>
							</div>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div id="cp-pagination" class="cp-pagination" style="display:none;">
					<button type="button" id="cp-prev-page" class="button button-small">‹ <?php esc_html_e( 'Previous', 'cronpulse' ); ?></button>
					<span id="cp-page-info"></span>
					<button type="button" id="cp-next-page" class="button button-small"><?php esc_html_e( 'Next', 'cronpulse' ); ?> ›</button>
				</div>
				<?php endif; ?>
			</div>

			<!-- Log tab -->
			<div id="cp-log" class="cp-tab-panel" style="display:none;">
				<?php if ( empty( $log ) ) : ?>
					<p class="cp-empty"><?php esc_html_e( 'No execution history yet. Run a cron job to start recording.', 'cronpulse' ); ?></p>
				<?php else : ?>
				<div class="cp-log-top-bar">
					<div class="cp-log-filter-strip" role="group" aria-label="<?php esc_attr_e( 'Filter by status', 'cronpulse' ); ?>">
						<button class="cp-log-filter is-active" data-filter=""><?php esc_html_e( 'All', 'cronpulse' ); ?></button>
						<button class="cp-log-filter" data-filter="success">✓ <?php esc_html_e( 'Success', 'cronpulse' ); ?></button>
						<button class="cp-log-filter" data-filter="fatal">✗ <?php esc_html_e( 'Failed', 'cronpulse' ); ?></button>
						<button class="cp-log-filter" data-filter="stuck">⚠ <?php esc_html_e( 'Stuck', 'cronpulse' ); ?></button>
					</div>
					<div class="cp-log-toolbar">
						<button class="button cp-clear-log"><?php esc_html_e( 'Clear Log', 'cronpulse' ); ?></button>
						<span class="cp-log-count">
							<?php
							echo esc_html( sprintf(
								/* translators: 1: number of log entries stored, 2: configured retention limit */
								__( '%1$d entries (newest first, max %2$d)', 'cronpulse' ),
								count( $log ),
								CronPulse_Alerts::get_settings()['log_retention']
							) );
							?>
						</span>
					</div>
				</div>
				<table class="cp-table cp-log-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Status',   'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Hook',     'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Ran At',   'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'cronpulse' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $log as $entry ) :
						if ( ! is_array( $entry )
							|| empty( $entry['hook'] )
							|| empty( $entry['status'] )
							|| empty( $entry['timestamp'] )
						) {
							continue;
						}
						$entry_status    = sanitize_key( $entry['status'] );
						$entry_hook      = (string) $entry['hook'];
						$entry_timestamp = (int) $entry['timestamp'];
						$entry_duration_raw = isset( $entry['duration'] ) ? absint( $entry['duration'] ) : 0;
						$entry_duration  = isset( $entry['duration'] ) ? absint( $entry['duration'] ) . ' ms' : '—';
						$entry_message   = isset( $entry['message'] ) ? (string) $entry['message'] : '';
						$log_filter_key  = in_array( $entry_status, [ 'fatal', 'incomplete' ], true ) ? 'fatal' : $entry_status;
					?>
					<tr class="cp-row cp-log-row cp-status-<?php echo esc_attr( $entry_status ); ?>" data-log-status="<?php echo esc_attr( $log_filter_key ); ?>">
						<td>
							<span class="cp-chip cp-chip-<?php echo esc_attr( $entry_status ); ?>"><?php echo esc_html( ucfirst( $entry_status ) ); ?></span>
						</td>
						<td><code><?php echo esc_html( $entry_hook ); ?></code></td>
						<td><?php echo esc_html( self::format_time( $entry_timestamp ) ); ?></td>
						<td class="cp-duration-cell" data-duration="<?php echo esc_attr( $entry_duration_raw ); ?>">
							<span class="cp-duration-text"><?php echo esc_html( $entry_duration ); ?></span>
							<span class="cp-duration-bar" aria-hidden="true"></span>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div id="cp-log-pagination" class="cp-pagination" style="display:none;">
					<button type="button" id="cp-log-prev" class="button button-small">‹ <?php esc_html_e( 'Previous', 'cronpulse' ); ?></button>
					<span id="cp-log-page-info"></span>
					<button type="button" id="cp-log-next" class="button button-small"><?php esc_html_e( 'Next', 'cronpulse' ); ?> ›</button>
				</div>
				<?php endif; ?>
			</div>

			<!-- Email Log tab -->
			<div id="cp-email-log" class="cp-tab-panel" style="display:none;">
				<!--
					Plain flex row, top-aligned — the table and the debug log
					are two independent, separately-boxed containers sitting
					side by side in the same row, not one nested inside the
					other's box. The display:none/block toggle for tabs stays
					on #cp-email-log itself; this inner wrapper is always
					display:flex via CSS, so there's no ambiguity about
					whether jQuery's show() restores "flex" correctly.
				-->
				<div class="cp-email-log-row">
				<div class="cp-email-log-section">
				<?php if ( empty( $email_log ) ) : ?>
					<p class="cp-empty"><?php esc_html_e( 'No emails sent yet. Alert emails (and test emails) will show up here.', 'cronpulse' ); ?></p>
				<?php else : ?>
				<div class="cp-log-toolbar">
					<button class="button cp-clear-email-log"><?php esc_html_e( 'Clear Email Log', 'cronpulse' ); ?></button>
					<span class="cp-log-count">
						<?php
						echo esc_html( sprintf(
							/* translators: %d = number of email log entries stored */
							__( '%d entries (newest first, max 50)', 'cronpulse' ),
							count( $email_log )
						) );
						?>
					</span>
				</div>
				<table class="cp-table cp-email-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Status',  'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'To',      'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Type',    'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Sent At', 'cronpulse' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $email_log as $entry ) :
						if ( ! is_array( $entry ) || empty( $entry['to'] ) || empty( $entry['timestamp'] ) ) {
							continue;
						}
						$entry_status  = isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'failed';
						$entry_to      = (string) $entry['to'];
						$entry_subject = isset( $entry['subject'] ) ? (string) $entry['subject'] : '';
						$entry_type    = isset( $entry['type'] ) ? (string) $entry['type'] : '';
						$entry_error   = isset( $entry['error'] ) ? (string) $entry['error'] : '';
						$chip_status   = 'sent' === $entry_status ? 'success' : 'error';
						$has_error     = '' !== $entry_error;
					?>
					<tr class="cp-row<?php echo $has_error ? ' cp-email-row--expandable' : ''; ?>" <?php echo $has_error ? 'role="button" tabindex="0" aria-expanded="false"' : ''; ?>>
						<td>
							<span class="cp-chip cp-chip-<?php echo esc_attr( $chip_status ); ?>"><?php echo esc_html( ucfirst( $entry_status ) ); ?></span>
						</td>
						<td><?php echo esc_html( $entry_to ); ?></td>
						<td>
							<?php echo esc_html( $entry_subject ); ?>
							<?php if ( $has_error ) : ?>
								<span class="cp-expand-hint" aria-hidden="true">▾ <?php esc_html_e( 'error', 'cronpulse' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $entry_type ); ?></td>
						<td><?php echo esc_html( self::format_time( (int) $entry['timestamp'] ) ); ?></td>
					</tr>
					<?php if ( $has_error ) : ?>
					<tr class="cp-email-error-row" style="display:none;">
						<td colspan="5" class="cp-email-error-cell">
							<strong><?php esc_html_e( 'Error:', 'cronpulse' ); ?></strong>
							<?php echo esc_html( $entry_error ); ?>
						</td>
					</tr>
					<?php endif; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div id="cp-email-log-pagination" class="cp-pagination" style="display:none;">
					<button type="button" id="cp-email-log-prev" class="button button-small">‹ <?php esc_html_e( 'Previous', 'cronpulse' ); ?></button>
					<span id="cp-email-log-page-info"></span>
					<button type="button" id="cp-email-log-next" class="button button-small"><?php esc_html_e( 'Next', 'cronpulse' ); ?> ›</button>
				</div>
				<?php endif; ?>
				</div><!-- .cp-email-log-section -->

				<div class="cp-debug-log-section">
				<h2 class="cp-debug-log-heading"><?php esc_html_e( 'Email Debug Log', 'cronpulse' ); ?></h2>
				<?php
				$debug_log_contents = CronPulse_Debug_Log::get_contents();
				$debug_log_path     = CronPulse_Debug_Log::get_path();
				$file_readable      = CronPulse_Debug_Log::file_is_readable();
				$diagnostics        = CronPulse_Debug_Log::get_diagnostics();
				?>
				<details class="cp-debug-diagnostics">
					<summary><?php esc_html_e( 'Diagnostics (if this looks empty but you know there\'s content)', 'cronpulse' ); ?></summary>
					<ul>
						<li>
							<strong><?php esc_html_e( 'Path:', 'cronpulse' ); ?></strong>
							<?php echo esc_html( $diagnostics['path'] ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Exists:', 'cronpulse' ); ?></strong>
							<?php echo $diagnostics['exists'] ? esc_html__( 'yes', 'cronpulse' ) : esc_html__( 'no', 'cronpulse' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Readable:', 'cronpulse' ); ?></strong>
							<?php
							if ( null === $diagnostics['readable'] ) {
								echo '—';
							} else {
								echo $diagnostics['readable'] ? esc_html__( 'yes', 'cronpulse' ) : esc_html__( 'no', 'cronpulse' );
							}
							?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Size:', 'cronpulse' ); ?></strong>
							<?php
							if ( null === $diagnostics['size'] ) {
								echo '—';
							} else {
								echo esc_html( number_format_i18n( $diagnostics['size'] ) . ' bytes' );
							}
							?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Last modified:', 'cronpulse' ); ?></strong>
							<?php echo null === $diagnostics['modified'] ? '—' : esc_html( $diagnostics['modified'] ); ?>
						</li>
					</ul>
					<p class="description"><?php esc_html_e( 'If "Exists" and "Size" here don\'t match what you see via FTP/file manager, reload this page — that would point to a caching issue rather than the file itself.', 'cronpulse' ); ?></p>
				</details>
				<p class="description">
					<?php
					echo esc_html(
						/* translators: %s = full server file path to the debug log */
						sprintf( __( 'The raw SMTP conversation for each send attempt — connection, TLS, auth, server responses. Credentials are never written here. File: %s', 'cronpulse' ), $debug_log_path )
					);
					?>
				</p>
				<?php if ( ! CronPulse_Debug_Log::is_writable() ) : ?>
					<div class="cp-notice-inline cp-warn">
						<span class="dashicons dashicons-warning"></span>
						<?php
						echo esc_html(
							/* translators: %s = directory path */
							sprintf( __( 'This directory is not writable — %s — so nothing can be recorded here until that\'s fixed.', 'cronpulse' ), CronPulse_Debug_Log::get_dir() )
						);
						?>
					</div>
				<?php elseif ( false === $file_readable ) : ?>
					<div class="cp-notice-inline cp-warn">
						<span class="dashicons dashicons-warning"></span>
						<?php
						echo esc_html(
							/* translators: %s = full file path */
							sprintf( __( 'The log file exists but this request can\'t read it — %s. Often means it was created by a different system user (e.g. a cron job run via SSH/WP-CLI) than the one serving this page. Check the file\'s owner and permissions.', 'cronpulse' ), $debug_log_path )
						);
						?>
					</div>
				<?php endif; ?>
				<?php if ( empty( $debug_log_contents ) ) : ?>
					<p class="cp-empty"><?php esc_html_e( 'No debug output yet. Use "Send Test Email" on the Settings tab to generate some.', 'cronpulse' ); ?></p>
				<?php else : ?>
					<div class="cp-log-toolbar">
						<button class="button cp-clear-debug-log"><?php esc_html_e( 'Clear Debug Log', 'cronpulse' ); ?></button>
					</div>
					<pre class="cp-debug-log"><?php echo esc_html( $debug_log_contents ); ?></pre>
				<?php endif; ?>
				</div><!-- .cp-debug-log-section -->
				</div><!-- .cp-email-log-row -->
			</div>

			<!-- Alerts tab -->
			<div id="cp-alerts" class="cp-tab-panel" style="display:none;">
				<?php CronPulse_Alerts::render_settings_tab(); ?>
			</div>

		</div><!-- .cp-wrap -->
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Renders the 8-hour upcoming-schedule strip between the summary bar and
	 * the tabs. Each scheduled run within the window gets a dot positioned at
	 * its proportional offset along the timeline.
	 *
	 * @param array $jobs Already-computed jobs list from get_jobs().
	 * @return string Pre-escaped HTML.
	 */
	private static function render_schedule_strip( array $jobs ): string {
		$now    = time();
		$window = 8 * HOUR_IN_SECONDS;
		$end    = $now + $window;

		$strip_jobs = [];
		foreach ( $jobs as $job ) {
			$ts = (int) $job['next_run'];
			if ( $ts > $now && $ts <= $end ) {
				$strip_jobs[] = $job;
			}
		}

		if ( empty( $strip_jobs ) ) {
			return '';
		}

		$dots = '';
		foreach ( $strip_jobs as $job ) {
			$pct    = round( ( (int) $job['next_run'] - $now ) / $window * 100, 2 );
			$label  = esc_attr( $job['hook'] ) . ' — ' . esc_attr( self::format_time( (int) $job['next_run'] ) );
			$dots  .= sprintf(
				'<span class="cp-strip-dot cp-strip-dot--%1$s" style="left:%2$s%%" title="%3$s" aria-label="%3$s"></span>',
				esc_attr( $job['status'] ),
				esc_attr( (string) $pct ),
				$label
			);
		}

		$html  = '<div class="cp-schedule-strip" aria-label="' . esc_attr__( 'Upcoming runs in the next 8 hours', 'cronpulse' ) . '">';
		$html .= '<div class="cp-strip-labels">';
		$html .= '<span>' . esc_html__( 'Now', 'cronpulse' ) . '</span>';
		$html .= '<span>+2h</span><span>+4h</span><span>+6h</span>';
		$html .= '<span>+8h</span>';
		$html .= '</div>';
		$html .= '<div class="cp-strip-track">' . $dots . '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Build a flat list of all scheduled cron jobs with status.
	 * Public so CronPulse_CLI_Command can reuse the same status logic.
	 */
	public static function get_jobs(): array {
		$crons = _get_cron_array();
		if ( empty( $crons ) || ! is_array( $crons ) ) {
			return [];
		}

		$jobs = [];
		$now  = time();

		foreach ( $crons as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				foreach ( $events as $sig => $event ) {
					$last_run = CronPulse_Cron_Tracker::get_last_run( $hook );

					if ( (int) $timestamp < $now ) {
						$status = 'overdue';
					} elseif ( $last_run && in_array( $last_run['status'], [ 'fatal', 'incomplete', 'stuck' ], true ) ) {
						$status = 'failing';
					} elseif ( $last_run ) {
						$status = 'healthy';
					} else {
						$status = 'pending';
					}

					$jobs[] = [
						'hook'     => $hook,
						'next_run' => (int) $timestamp,
						'schedule' => $event['schedule'] ?? '',
						'args'     => $event['args'] ?? [],
						'sig'      => (string) $sig,
						'status'   => $status,
					];
				}
			}
		}

		usort( $jobs, static function ( $a, $b ) {
			$order = [ 'overdue' => 0, 'failing' => 1, 'pending' => 2, 'healthy' => 3 ];
			$oa    = $order[ $a['status'] ] ?? 4;
			$ob    = $order[ $b['status'] ] ?? 4;
			if ( $oa !== $ob ) {
				return $oa - $ob;
			}
			return $a['next_run'] <=> $b['next_run'];
		} );

		return $jobs;
	}

	/**
	 * Build a tiny inline SVG sparkline from a hook's recent durations, so a
	 * creeping-up execution time is visible before it becomes a timeout.
	 * Returns an empty string when there's not enough data for a trend line.
	 *
	 * @param int[] $durations Milliseconds, oldest first.
	 */
	private static function render_sparkline( array $durations ): string {
		if ( count( $durations ) < 2 ) {
			return '';
		}

		$width  = 60;
		$height = 18;
		$max    = max( $durations );
		$min    = min( $durations );
		$range  = max( 1, $max - $min ); // avoid div-by-zero when every run took the same time
		$count  = count( $durations );
		$step   = $width / ( $count - 1 );

		$points = [];
		foreach ( $durations as $i => $d ) {
			$x        = round( $i * $step, 1 );
			$y        = round( $height - ( ( $d - $min ) / $range ) * $height, 1 );
			$points[] = $x . ',' . $y;
		}

		return sprintf(
			'<svg class="cp-sparkline" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" aria-hidden="true"><polyline points="%3$s" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
			$width,
			$height,
			esc_attr( implode( ' ', $points ) )
		);
	}

	/**
	 * Format a Unix timestamp as a human-readable relative string.
	 */
	public static function format_time( int $timestamp ): string {
		$diff = time() - $timestamp;

		if ( abs( $diff ) < 60 ) {
			return __( 'Just now', 'cronpulse' );
		}

		$future = $timestamp > time();
		$label  = human_time_diff( $timestamp );

		if ( $future ) {
			/* translators: %s = human-readable time difference, e.g. "5 minutes" */
			return sprintf( __( 'in %s', 'cronpulse' ), $label );
		}

		/* translators: %s = human-readable time difference, e.g. "5 minutes" */
		return sprintf( __( '%s ago', 'cronpulse' ), $label );
	}
}
