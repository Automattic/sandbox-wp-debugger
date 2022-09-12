<?php

class SWPD_WP_Redirect {

	public $min_priority = -99999;
	public $max_priority = 99999;
	public $original_status = null;
	public $original_location = null;
	public $wp_safe_redirect = false;
	public $whitelisted_safe_redirect_hosts = array();
	public $safe_redirect_host = null;
	public $is_legacy_redirect = false;
	public $legacy_redirect_post_id = null;

	public function __construct( $args = array() ) {

		if ( true === isset( $args['min_priority'] ) ) {
			$this->min_priority = (int) $args['min_priority'];
		}
		if ( true === isset( $args['max_priority'] ) ) {
			$this->max_priority = (int) $args['max_priority'];
		}

		add_filter( 'allowed_redirect_hosts', array( $this, 'collect_safe_redirect_data' ), 10, $this->max_priority );
		add_filter( 'wp_redirect', array( $this, 'collect_original_data' ), $this->min_priority, 2 );
		add_filter( 'wp_redirect_status', array( $this, 'report' ), $this->max_priority, 2 );
		add_filter( 'wpcom_legacy_redirector_request_path', array( $this, 'collect_legacy_redirector_data' ), 10, 1 );
	}

	public function collect_safe_redirect_data( $whitelisted_hosts, $redirect_host ) {
		$this->wp_safe_redirect = true;
		$this->whitelisted_safe_redirect_hosts = $whitelisted_hosts;
		$this->safe_redirect_host = $redirect_host;
		return $whitelisted_hosts;
	}

	public function collect_original_data( $location, $status ) {
		$this->original_status = $status;
		$this->original_location = $location;
		return $location;
	}

	public function collect_legacy_redirector_data( $request_path ) {
		if ( $request_path ) {
			$redirect_uri = WPCOM_Legacy_Redirector::get_redirect_uri( $request_path );
			if ( $redirect_uri ) {
				$this->is_legacy_redirect = true;
				$url = $this->legacy_redirector_normalise_url( $request_path );
				$this->legacy_redirect_post_id = WPCOM_Legacy_Redirector::get_redirect_post_id( $url );
			}
		}
		return $request_path;
	}

	private function legacy_redirector_normalise_url( $url ) {
		$redirector_reflection = new ReflectionClass( 'WPCOM_Legacy_Redirector' );
		$method = $redirector_reflection->getMethod( 'normalise_url' );
		$method->setAccessible( true );
		return $method->invokeArgs( null, array( $url ) );
	}

	public function report( $status, $location ) {
		if ( ! $location ) {
			$message = 'Not redirecting due to `$location` being empty|false|null';
		} else if ( true === $this->wp_safe_redirect ) {
			$message = 'Redirecting via wp_safe_redirect ...';
			$fallback = apply_filters( 'wp_safe_redirect_fallback', admin_url(), $status );
			if ( $location === $fallback ) {
				$message = sprintf( 'Likely a non whitelisted host ( %s ) for redirection. Redirecting to fallback: %s', $this->safe_redirect_host, $fallback );
			}
		} else {
			$message = 'Redirecting via wp_redirect ...';
		}
		$variables = array(
			'Request' => ( $_SERVER['HTTPS'] ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . $_SERVER['QUERY_STRING'],
			'Location used for redirection' => wp_sanitize_redirect( $location ),
			'Status used for redirection' => $status
		);
		$debug_data = array(
			'Location before applying `wp_redirect` filters' => $this->original_location,
			'Location after applying `wp_redirect` filters' => $location,
			'Status before applying `wp_redirect_status` filters' => $this->original_status,
		);

		if ( true === $this->wp_safe_redirect ) {
			$debug_data = array_merge( array(
				'Whitelisted hosts for safe redirect' => $this->whitelisted_safe_redirect_hosts,
				'Original redirect location host' => $this->safe_redirect_host,
			), $debug_data );
		}

		if ( true === $this->is_legacy_redirect ) {
			$debug_data = array_merge( array(
				'The redirect originates in the Legacy redirector. Post ID' => $this->legacy_redirect_post_id,
			), $debug_data );
		}

		$used_redirection_function = 'wp_redirect';
		if ( true === $this->wp_safe_redirect ) {
			$used_redirection_function = 'wp_safe_redirect';
		}
		
		// Start the backtrace from the wp_redirect / wp_safe_redirect call for easy parsing.
		$slice = 3;
		if ( 'wp_safe_redirect' === $used_redirection_function ) {
			$slice++;	
		}
		$backtrace = array_slice( davidbinovec_debug_backtrace( true ), $slice );
		swpd_log( $used_redirection_function, $message, $variables, $debug_data, str_replace( ', apply_filters(\'wp_redirect_status\'), WP_Hook->apply_filters, SWPD_WP_Redirect->report', '', implode( ' ', $backtrace ) ) );

		return $status;
	}

}

new SWPD_WP_Redirect();
