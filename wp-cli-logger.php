<?php

class SWPD_WP_CLI_Logger extends WP_CLI\Loggers\Regular {
	/**
	 * Write an message to STDERR, prefixed with "Error: ".
	 * Append debug backtrace for easier tracking.
	 *
	 * @param string $message Message to write.
	 */
	public function error( $message ) {
		$this->_line( $message, 'Error', '%R', STDERR );
		$this->_line( wp_debug_backtrace_summary(), 'Backtrace', '%R', STDERR );
	}
}
