<?php
/**
 * Plugin Name: Cron Pulse
 * Plugin URI:  https://wordpress.org/plugins/cronpulse/
 * Description: A visual dashboard to monitor, debug, and manually trigger WordPress cron jobs. See schedules, last run times, execution duration, and pass/fail status at a glance.
 * Version:     1.1.1
 * Author:      Farhan Ali
 * Author URI:  https://farhanali.me
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cronpulse
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package CronPulse
 */

defined( 'ABSPATH' ) || exit;

define( 'CRONPULSE_VERSION', '1.1.1' );
define( 'CRONPULSE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRONPULSE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CRONPULSE_OPTION_LOG', 'cronpulse_execution_log' );
define( 'CRONPULSE_LOG_LIMIT', 200 ); // Default log retention; overridable via the Settings tab.
define( 'CRONPULSE_OPTION_ALERTS', 'cronpulse_alert_settings' ); // Also holds general settings, e.g. log retention.
define( 'CRONPULSE_OPTION_STREAKS', 'cronpulse_alert_streaks' );
define( 'CRONPULSE_OPTION_EMAIL_LOG', 'cronpulse_email_log' );

require_once CRONPULSE_PLUGIN_DIR . 'includes/class-debug-log.php';
require_once CRONPULSE_PLUGIN_DIR . 'includes/class-cron-tracker.php';
require_once CRONPULSE_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once CRONPULSE_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once CRONPULSE_PLUGIN_DIR . 'includes/class-alerts.php';
require_once CRONPULSE_PLUGIN_DIR . 'includes/class-admin-bar.php';
require_once CRONPULSE_PLUGIN_DIR . 'includes/class-rest.php';
require_once CRONPULSE_PLUGIN_DIR . 'includes/class-site-health.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once CRONPULSE_PLUGIN_DIR . 'includes/class-cli.php';
}

/**
 * Bootstrap on plugins_loaded so all cron hooks are registered.
 */
add_action( 'plugins_loaded', function () {
	CronPulse_Cron_Tracker::init();
	CronPulse_Admin_Page::init();
	CronPulse_Ajax_Handler::init();
	CronPulse_Alerts::init();
	CronPulse_Admin_Bar::init();
	CronPulse_REST_Controller::init();
	CronPulse_Site_Health::init();
} );

/**
 * Activation: create the log option.
 */
register_activation_hook( __FILE__, function () {
	if ( false === get_option( CRONPULSE_OPTION_LOG ) ) {
		add_option( CRONPULSE_OPTION_LOG, array(), '', false );
	}
} );

/**
 * Deactivation: nothing destructive — keep logs.
 */
register_deactivation_hook( __FILE__, '__return_true' );

register_uninstall_hook( __FILE__, 'cronpulse_uninstall' );

/**
 * Uninstall: clean up stored data.
 *
 * @return void
 */
function cronpulse_uninstall() {
	delete_option( CRONPULSE_OPTION_LOG );
	delete_option( CRONPULSE_OPTION_ALERTS );
	delete_option( CRONPULSE_OPTION_STREAKS );
	delete_option( CRONPULSE_OPTION_EMAIL_LOG );

	// Also removes the SMTP debug log file and its .htaccess/index.php guards
	// under wp-content/uploads/cronpulse-logs/ — not just the options table.
	CronPulse_Debug_Log::delete_dir();
}
