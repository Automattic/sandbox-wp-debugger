<?php
/**
 * WP-CLI Logger.
 */

namespace SWPD;

if ( true === defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * SWPD\WP_CLI_Logger Class.
	 */
	class WP_CLI_Logger extends \WP_CLI\Loggers\Regular {
		/**
		 * Write an message to STDERR, prefixed with "Error: ".
		 * Append debug backtrace for easier tracking.
		 *
		 * @param string $message Message to write.
		 */
		public function error( $message ) {
			$this->_line( $message, 'Error', '%R', STDERR );
			$this->_line( wp_debug_backtrace_summary(), 'Backtrace', '%R', STDERR ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_wp_debug_backtrace_summary
		}
	}

	if ( version_compare( WP_CLI_VERSION, '1.0.0', '>=' ) ) {
		\WP_CLI::set_logger( new WP_CLI_Logger( true ) );
	}
}
