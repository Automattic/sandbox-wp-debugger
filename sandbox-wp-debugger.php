<?php
/**
 * Plugin Name:     Sandbox WP Debugger
 * Plugin URI:      https://github.com/Automattic/sandbox-wp-debugger
 * Description:     Adds some advanced debug techniques to your sandbox
 * Author:          @david-binda
 * Text Domain:     sandbox-wp-debugger
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Sandbox_Wp_Debugger
 */

define( 'SWPD_DIR_PATH', plugin_dir_path( __FILE__ ) );

require_once SWPD_DIR_PATH . 'helper-functions.php';
require_once SWPD_DIR_PATH . 'wp-redirect.php';
require_once SWPD_DIR_PATH . 'apply-filters.php';
require_once SWPD_DIR_PATH . 'do-action.php';
require_once SWPD_DIR_PATH . 'batcache-debug.php';

if ( true === defined( 'WP_CLI' ) && WP_CLI ) {
	if ( version_compare( WP_CLI_VERSION, '1.0.0', '>=' ) ) {
		require_once SWPD_DIR_PATH . 'wp-cli-logger.php';
		WP_CLI::set_logger( new SWPD\WP_CLI_Logger( true ) );
	} else {
		require_once SWPD_DIR_PATH . 'wpcom-wp-cli-logger.php';
		WP_CLI::set_logger( new SWPD\WPCOM_WP_CLI_Logger( true ) );
	}
}

require_once SWPD_DIR_PATH . 'SlowQueries.php';
require_once SWPD_DIR_PATH . 'slow-post-save.php';
require_once SWPD_DIR_PATH . 'slow-bulk-update.php';
require_once SWPD_DIR_PATH . 'debugbar-rest-api.php';

/**
 * Generates debug data for output or error logging.
 *
 * @param  string $function   The type of debugging function that is running.
 * @param  string $message    A message to add to the debugging output.
 * @param  array  $data       A key-value array of data to add.
 * @param  array  $debug_data A secondary key-value array of data to add.
 * @param  mixed  $backtrace  Whether or not to include a backtrace. Setting to a string will include a custom backtrace.
 * @param  bool   $error_log  Send the output to error_log() as well. Defaults to true.
 *
 * @return string[]           An array of strings representing the debug data.
 */
function swpd_log( string $function = '', string $message = '', array $data = array(), $debug_data = array(), $backtrace = true, bool $error_log = true ): array {
	$output = array();

	$output[] = array( '== Sandbox WP Debug : ' . $function . ' Debug ==' );
	$output[] = array( $message );

	if ( true === is_array( $data ) && false === empty( $data ) ) {
		foreach ( $data as $key => $value ) {
			$output[] = array( $key . ': ' . var_export( $value, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
	}

	if ( true === is_array( $debug_data ) && false === empty( $debug_data ) ) {
		$output[] = array( '=== Aditional debug data: ===' );
		foreach ( $debug_data as $key => $value ) {
			$output[] = array( $key . ': ' . var_export( $value, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
	}

	$output[] = array( 'Blog ID: ' . get_current_blog_id() );

	if ( true === $backtrace ) {
		$backtrace = wp_debug_backtrace_summary(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_wp_debug_backtrace_summary
	}

	if ( is_string( $backtrace ) ) {
		$output[] = array( 'Backtrace: ' . $backtrace );
	}

	$output[] = array( '== / ' . $function . ' ==' );

	if ( true === $error_log ) {
		foreach ( $output as $line ) {
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	return $output;
}
