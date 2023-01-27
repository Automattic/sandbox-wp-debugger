<?php
/**
 * SWPD Helper Functions.
 */

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
