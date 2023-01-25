<?php
/**
 * Batcache Debugger.
 */

namespace SWPD;

/**
 * SWPD\Batcache_Debug Class.
 */
class Batcache_Debug extends Base {

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		if ( ! class_exists( '\batcache' ) ) {
			return;
		}

		// Ignore WPCOM and sandbox AJAX actions.
		if ( true === array_key_exists( 'action', $_GET ) && true === in_array( $_GET['action'], array( 'o2_read', 'pickup_debug', 'o2_userdata' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( false === empty( $_GET ) && true === empty( array_diff( array_keys( $_GET ), array( 'get-xpost-data', 'doing_wp_cron' ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		// Ignore WPCOM REST API.
		if ( isset( $_SERVER['HTTP_HOST'] ) && 'public-api.wordpress.com' === $_SERVER['HTTP_HOST'] ) {
			return;
		}

		// Ignore static files.
		$http_https         = isset( $_SERVER['HTTPS'] ) ? ( $_SERVER['HTTPS'] ? 'https' : 'http' ) : 'http'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$http_host          = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( $_SERVER['HTTP_HOST'] ) : '';
		$request_uri        = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( $_SERVER['REQUEST_URI'] ) : '';
		$request_url        = $http_https . $request_uri . $request_uri;
		$parsed_request_url = wp_parse_url( $request_url );
		if ( '.js' === substr( $parsed_request_url['path'], -3 ) ) {
			return;
		}

		$message = array();

		if ( false === empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message[] = sprintf( 'The request would bypass Batcache due to following query args: %s.', implode( ', ', array_keys( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// Compose diff of default and total list of ignored args.
		global $__batcacheignore_args, $__batcacheignore_defaults, $__batcacheignore_by_host;

		$client_specific_ignored_args = array();
		$globally_ignored_args        = array();

		foreach ( $__batcacheignore_args as $ignored_arg ) {
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
			$message[] = sprintf( 'Batcached is whitelisting following client specific params: %s', implode( ', ', $client_specific_ignored_args ) );
		}
		if ( false === empty( $globally_ignored_args ) ) {
			$message[] = sprintf( 'Batcache is whitelisting following global/default params: %s', implode( ',', $globally_ignored_args ) );
		}

		if ( false === empty( $message ) ) {
			swpd_log( 'Batcache', join( PHP_EOL, $message ), array( 'Request' => $request_url ) );
		}
	}
}

new SWPD\Batcache_Debug();
