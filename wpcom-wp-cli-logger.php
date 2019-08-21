<?php

class SWPD_WPCOM_WP_CLI_Logger {

	function __construct( $in_color ) {
		$this->in_color = $in_color;
	}

	protected function write( $handle, $str ) {
		fwrite( $handle, $str );
	}

	private function _line( $message, $label, $color, $handle = STDOUT ) {
		$label = \cli\Colors::colorize( "$color$label:%n", $this->in_color );
		$this->write( $handle, "$label $message\n" );
		$this->write( $handle, 'Backtrace: ' . wp_debug_backtrace_summary()."\n" );
	}

	function info( $message ) {
		$this->write( STDOUT, $message . "\n" );
	}

	function success( $message ) {
		$this->_line( $message, 'Success', '%G' );
	}

	function warning( $message ) {
		$this->_line( $message, 'Warning', '%C', STDERR );
	}

	function error( $message ) {
		$this->_line( $message, 'Error', '%R', STDERR );
	}
}
