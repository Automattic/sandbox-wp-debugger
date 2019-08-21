<?php

function davidbinovec_debug_backtrace( $return = false ) {
    $backtrace = debug_backtrace();
    $output = array();
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
    error_log( join( $output, ' ' ) . ' ' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
}
