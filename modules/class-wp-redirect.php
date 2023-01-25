<?php
/**
 * WP Redirect Debugger.
 */

namespace SWPD;

/**
 * SWPD\WP_Redirect Class.
 */
class WP_Redirect extends Base {
	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'WP Redirect';

	/**
	 * Original HTTP Status.
	 *
	 * @var int
	 */
	public $original_status = null;

	/**
	 * Original HTTP Location.
	 *
	 * @var string
	 */
	public $original_location = null;

	/**
	 * Whether or not the redirect is considered safe.
	 *
	 * @var boolean
	 */
	public $wp_safe_redirect = false;

	/**
	 * Whitelisted hosts for safe redirects.
	 *
	 * @var array
	 */
	public $whitelisted_safe_redirect_hosts = array();

	/**
	 * The hostname during a safe redirect.
	 *
	 * @var string
	 */
	public $safe_redirect_host = null;

	/**
	 * Whether or not the redirect is a Legacy redirect.
	 *
	 * @var boolean
	 */
	public $is_legacy_redirect = false;

	/**
	 * The Post ID of the data object during a Legacy redirect.
	 *
	 * @var int
	 */
	public $legacy_redirect_post_id = null;

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		add_filter( 'allowed_redirect_hosts', array( $this, 'collect_safe_redirect_data' ), 10, PHP_INT_MAX );
		add_filter( 'wp_redirect', array( $this, 'collect_original_data' ), PHP_INT_MIN, 2 );
		add_filter( 'wpcom_legacy_redirector_request_path', array( $this, 'collect_legacy_redirector_data' ), 10, 1 );
		add_filter( 'wp_redirect_status', array( $this, 'report' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Collects data about safe redirects.
	 *
	 * @param string[] $hosts An array of allowed host names.
	 * @param string   $host  The host name of the redirect destination; empty string if not set.
	 *
	 * @return string[]       An array of allowed host names.
	 */
	public function collect_safe_redirect_data( array $hosts, string $host ): array {
		$this->wp_safe_redirect                = true;
		$this->whitelisted_safe_redirect_hosts = $hosts;
		$this->safe_redirect_host              = $host;

		return $hosts;
	}

	/**
	 * Collects data about the original request for redirects.
	 *
	 * @param string $location The path or URL to redirect to.
	 * @param int    $status   The HTTP response status code to use.
	 *
	 * @return string The path or URL to redirect to.
	 */
	public function collect_original_data( string $location, int $status ): string {
		$this->original_status   = $status;
		$this->original_location = $location;

		return $location;
	}

	/**
	 * Collects data about Legacy Redirects.
	 *
	 * @param  string $request_path The request path.
	 *
	 * @return mixed                The request path.
	 */
	public function collect_legacy_redirector_data( $request_path ) {
		if ( $request_path ) {
			$redirect_uri = WPCOM_Legacy_Redirector::get_redirect_uri( $request_path );
			if ( $redirect_uri ) {
				$this->is_legacy_redirect      = true;
				$url                           = $this->legacy_redirector_normalise_url( $request_path );
				$this->legacy_redirect_post_id = WPCOM_Legacy_Redirector::get_redirect_post_id( $url );
			}
		}
		return $request_path;
	}

	/**
	 * Normalizes URLs for Legacy Redirector.
	 *
	 * @param  string $url The redirection URL.
	 *
	 * @return string      The redirection URL.
	 */
	private function legacy_redirector_normalise_url( $url ) {
		$redirector_reflection = new ReflectionClass( 'WPCOM_Legacy_Redirector' );
		$method                = $redirector_reflection->getMethod( 'normalise_url' );
		$method->setAccessible( true );
		return $method->invokeArgs( null, array( $url ) );
	}

	/**
	 * Generates the SWPD report.
	 *
	 * @param  int    $status   The HTTP response status code to use.
	 * @param  string $location The path or URL to redirect to.
	 *
	 * @return int              The HTTP response status code to use.
	 */
	public function report( int $status, string $location ): int {
		if ( ! $location ) {
			$message = 'Not redirecting due to `$location` being empty|false|null';
		} elseif ( true === $this->wp_safe_redirect ) {
			$message  = 'Redirecting via wp_safe_redirect ...';
			$fallback = apply_filters( 'wp_safe_redirect_fallback', admin_url(), $status ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			if ( $location === $fallback ) {
				$message = sprintf( 'Likely a non whitelisted host ( %s ) for redirection. Redirecting to fallback: %s', $this->safe_redirect_host, $fallback );
			}
		} else {
			$message = 'Redirecting via wp_redirect ...';
		}

		$http_https   = isset( $_SERVER['HTTPS'] ) ? ( $_SERVER['HTTPS'] ? 'https' : 'http' ) : 'http'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$http_host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( $_SERVER['HTTP_HOST'] ) : '';
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( $_SERVER['REQUEST_URI'] ) : '';
		$query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( $_SERVER['QUERY_STRING'] ) : '';

		$variables  = array(
			'Request'                       => $http_https . '://' . $http_host . $request_uri . $query_string,
			'Location used for redirection' => wp_sanitize_redirect( $location ),
			'Status used for redirection'   => $status,
		);
		$debug_data = array(
			'Location before applying `wp_redirect` filters' => $this->original_location,
			'Location after applying `wp_redirect` filters' => $location,
			'Status before applying `wp_redirect_status` filters' => $this->original_status,
		);

		if ( true === $this->wp_safe_redirect ) {
			$debug_data = array_merge(
				array(
					'Whitelisted hosts for safe redirect' => $this->whitelisted_safe_redirect_hosts,
					'Original redirect location host'     => $this->safe_redirect_host,
				),
				$debug_data
			);
		}

		if ( true === $this->is_legacy_redirect ) {
			$debug_data = array_merge(
				array(
					'The redirect originates in the Legacy redirector. Post ID' => $this->legacy_redirect_post_id,
				),
				$debug_data
			);
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
		$backtrace = array_slice( swpd_debug_backtrace( true ), $slice );
		swpd_log( $used_redirection_function, $message, $variables, $debug_data, str_replace( ', apply_filters(\'wp_redirect_status\'), WP_Hook->apply_filters, SWPD_WP_Redirect->report', '', implode( ' ', $backtrace ) ) );

		return $status;
	}

}
