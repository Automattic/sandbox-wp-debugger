<?php
/**
 * Sandbox WP Debugger Helper for REST Requests.
 */

namespace SWPD;

/**
 * SWPD\REST_Requests Class.
 */
class REST_Requests extends Base {
	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'REST Requests';

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		add_filter( 'rest_pre_dispatch', array( $this, 'rest_pre_dispatch' ), PHP_INT_MIN, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'rest_post_dispatch' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Initiate debugging and timers.
	 *
	 * @param  mixed           $result  Response to replace the requested version with. Can be anything a normal endpoint can return, or null to not hijack the request.
	 * @param  WP_REST_Server  $server  Server instance.
	 * @param  WP_REST_Request $request Request used to generate the response.
	 *
	 * @return null                     Null to not hijack the filter.
	 */
	public function rest_pre_dispatch( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		global $swpd_timers_rest;

		if ( ! is_array( $swpd_timers_rest ) ) {
			$swpd_timers_rest = array();
		}

		$swpd_timers_rest[ $request->get_route() ] = hrtime( true );

		$data = array_merge(
			array(
				'Method' => $request->get_method(),
			),
			$request->get_params(),
		);

		$debug_data = array(
			'home_url' => home_url(),
			'site_url' => site_url(),
		);

		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
		// $this->log( 'Route: ' . $request->get_route(), $data, $debug_data );  // Uncomment this if you need pre-dispatch. For instance, if something else is returning pre-dispatch early.

		return null;
	}

	/**
	 * Calculate timers and finish debugging.
	 *
	 * @param  WP_HTTP_Response $result  Result to send to the client. Usually a WP_REST_Response.
	 * @param  WP_REST_Server   $server  Server instance.
	 * @param  WP_REST_Request  $request Request used to generate the response.
	 *
	 * @return WP_HTTP_Response          Unchanged $result.
	 */
	public function rest_post_dispatch( WP_HTTP_Response $result, WP_REST_Server $server, WP_REST_Request $request ): WP_HTTP_Response {
		global $swpd_timers_rest;

		$time  = hrtime( true ) - $swpd_timers_rest[ $request->get_route() ];
		$time /= 1e+6; // Convert from ns to ms.

		unset( $swpd_timers_rest[ $request->get_route() ] );

		$data = array_merge(
			array(
				'Time Taken' => round( $time, 3 ) . 'ms',
				'Method'     => $request->get_method(),
			),
			$request->get_params(),
		);

		$debug_data = array(
			'home_url' => home_url(),
			'site_url' => site_url(),
		);

		$this->log( 'Post Dispatch Route: ' . $request->get_route(), $data, $debug_data );

		return $result;
	}
}

new SWPD\REST_Requests();
