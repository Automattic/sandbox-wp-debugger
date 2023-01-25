<?php
/**
 * SWPD Helper Functions.
 */

/**
 * Custom backtrace generator.
 *
 * @param  boolean $return Whether to return or echo, defaults to false (echo).
 *
 * @return array           An array of backtrace data.
 */
function swpd_debug_backtrace( bool $return = false ) {
	$backtrace = debug_backtrace(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
	$output    = array();

	foreach ( $backtrace as $call ) {
		$function = $call['function'];
		if ( __FUNCTION__ === $function ) {
			continue;
		}

		if ( true === in_array( $function, array( 'apply_filters', 'do_action', 'do_action_ref_array' ) ) && true !== array_key_exists( 'class', $call ) ) {
			$function .= sprintf( '( "%s" )', $call['args'][0] );
		}
		$file = defined( 'ABSPATH' ) ? str_replace( constant( 'ABSPATH' ), '', $call['file'] ) : $call['file'];
		array_push( $output, $function . ' ' . $file . ':' . $call['line'] );
	}
	if ( true === $return ) {
		return $output;
	}

	$http_host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( $_SERVER['HTTP_HOST'] ) : '';
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( $_SERVER['REQUEST_URI'] ) : '';

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( implode( ' ', $output ) . ' ' . $http_host . $request_uri );
}
