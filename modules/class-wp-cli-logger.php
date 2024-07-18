<?php
/**
 * WP-CLI Logger.
 */

namespace SWPD;

if ( true === defined( 'WP_CLI' ) && \WP_CLI ) {

	/**
	 * SWPD\WP_CLI_Logger Class.
	 */
	class WP_CLI_Logger extends \WP_CLI\Loggers\Regular { // phpcs:ignore VIPServices.Namespaces.GlobalNamespace.GlobalNamespace, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace
		/**
		 * Write an message to STDERR, prefixed with "Error: ".
		 * Append debug backtrace for easier tracking.
		 *
		 * @param mixed $message Message to write.
		 *
		 * @return void
		 */
		public function error( mixed $message ): void {
			$this->_line( $message, 'Error', '%R', STDERR );
			$this->_line( wp_debug_backtrace_summary(), 'Backtrace', '%R', STDERR ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_wp_debug_backtrace_summary
		}
	}

	\WP_CLI::set_logger( new \SWPD\WP_CLI_Logger( true ) );
}
