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

require_once( SWPD_DIR_PATH . 'helper-functions.php' );

require_once( SWPD_DIR_PATH . 'wp-redirect.php' );
require_once( SWPD_DIR_PATH . 'apply-filters.php' );
require_once( SWPD_DIR_PATH . 'batcache-debug.php' );

if ( true === defined( 'WP_CLI' ) && WP_CLI ) {
	if ( version_compare( WP_CLI_VERSION, '1.0.0', '>=' ) ) {
		require_once( SWPD_DIR_PATH . 'wp-cli-logger.php' );
		WP_CLI::set_logger( new SWPD_WP_CLI_Logger( true ) );
	} else {
		require_once( SWPD_DIR_PATH . 'wpcom-wp-cli-logger.php' );
		WP_CLI::set_logger( new SWPD_WPCOM_WP_CLI_Logger( true ) );
	}
}

require_once( SWPD_DIR_PATH . 'SlowQueries.php' );
require_once( SWPD_DIR_PATH . 'slow-post-save.php' );
require_once( SWPD_DIR_PATH . 'slow-bulk-update.php' );
require_once( SWPD_DIR_PATH . 'debugbar-rest-api.php' );

function swpd_log( $function, $message, $data, $debug_data = array(), $backtrace = null ) {

	error_log( '== Sandbox WP Debug : ' . $function . ' debug ==' );
	error_log( $message );
	if ( true === is_array( $data ) && false === empty( $data ) ) {
		foreach ( $data as $key => $value ) {
			error_log( $key . ': ' . var_export( $value, true ) );
		}
	}
	if ( true === is_array( $debug_data ) && false === empty( $debug_data ) ) {
		error_log( '=== Aditional debug data: ===' );
		foreach( $debug_data as $key => $value ) {
			error_log( $key . ': ' . var_export( $value, true ) );
		}
	}
	error_log( 'Blog ID: ' . get_current_blog_id() );
	if ( null === $backtrace ) {
		$backtrace = wp_debug_backtrace_summary();
	}
	if ( false === empty( $backtrace ) ) {
		error_log( 'Backtrace: ' . $backtrace );
	}
	error_log( '== / ' . $function . ' ==' );
}
