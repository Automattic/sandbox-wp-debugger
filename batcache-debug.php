<?php

class SWPD_Batcache_Debug {

	public function __construct() {
		// Not applicable on VIP Go.
		if ( defined( 'VIP_GO_ENV' ) ) {
			return;	
		}

		// Ignore WPCOM and sandbox AJAX actions.
		if ( true === array_key_exists( 'action', $_GET ) && true === in_array( $_GET['action'], array( 'o2_read', 'pickup_debug', 'o2_userdata' ) ) ) {
			return;	
		}
		if ( false === empty( $_GET ) && true === empty( array_diff( array_keys( $_GET ), array( 'get-xpost-data', 'doing_wp_cron' ) ) ) ) {
			return;	
		}
		// Ignore WPCOM REST API
		if ( 'public-api.wordpress.com' === $_SERVER['HTTP_HOST'] ) {
			return;	
		}

		// Ignore static files
		$request_url = ( $_SERVER['HTTPS'] ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$parsed_request_url = wp_parse_url( $request_url );
		if ( '.js' === substr( $parsed_request_url['path'], -3 ) ) {
			return;	
		}

		$message = array();

		if ( false === empty( $_GET ) ) {
			$message[] = sprintf( 'The request would bypass Batcache due to following query args: %s.', join( ', ', array_keys( $_GET ) ) );
		}

		// Compose diff of default and total list of ignored args.
		global $__batcacheignore_args, $__batcacheignore_defaults, $__batcacheignore_by_host;
		$client_specific_ignored_args = $globally_ignored_args = array();
		foreach( $__batcacheignore_args as $ignored_arg ) {
			if ( true === is_array( $__batcacheignore_defaults ) && false === in_array( $ignored_arg, $__batcacheignore_defaults, true ) ) {
				$client_specific_ignored_args[] = $ignored_arg;	
			}
			if ( true === is_array( $parsed_request_url['host'] ) && true === array_key_exists( $parsed_request_url['host'], $__batcacheignore_by_host ) && true === is_array( $__batcacheignore_by_host[ $parsed_request_url['host'] ] ) && true === in_array( $ignored_arg, $__batcacheignore_by_host[ $parsed_request_url['host'] ], true ) ) {
				$client_specific_ignored_args[] = $ignored_arg;	
			}
			if ( true === is_array( $__batcacheignore_defaults ) && true === in_array( $ignored_arg, $__batcacheignore_defaults ) ) {
				$globally_ignored_args[] = $ignored_arg;	
			}
		}
		
		if ( false === empty( $client_specific_ignored_args ) ) {
			$message[] = sprintf( 'Batcached is whitelisting following client specific params: %s', join( ', ', $client_specific_ignored_args ) );
		}
		if ( false === empty( $globally_ignored_args ) ) {
			$message[] = sprintf( 'Batcache is whitelisting following global/default params: %s', join( ',', $globally_ignored_args ) );
		}

		if ( false === empty( $message ) ) {
			swpd_log( 'Batcache', join( PHP_EOL , $message ), array( 'Request' => $request_url ) );
		}
	}
}

new SWPD_Batcache_Debug;
