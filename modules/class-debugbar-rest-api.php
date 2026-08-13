<?php
/**
 * Enable Debug Bar for REST API endpoints (add ?debug). Not for production.
 * e.g. http://local.wordpress.local/wp-json/wp/v2/media/?debug
 * Author: @trepmal
 */

declare(strict_types=1);

namespace SWPD;

/**
 * SWPD\DebugBar_REST_API Class.
 */
class DebugBar_REST_API extends Base {

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		if ( ! class_exists( '\Debug_Bar' ) ) {
			return;
		}
		remove_filter( 'authenticate', 'wp_authenticate_application_password', 20, 3 );
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		add_filter( 'rest_pre_echo_response', array( $this, 'rest_pre_echo_response' ), 10, 3 );
	}

	/**
	 * Load plugins, template if `?debug` is added to URL.
	 * Disable theme-y css/js, known cruft.
	 *
	 * @param \WP_REST_Server $wp_rest_server Server object.
	 *
	 * @return void
	 */
	public function rest_api_init( \WP_REST_Server $wp_rest_server ): void {
		if ( ! $this->is_rest_debug() ) {
			return;
		}

		/*
		 * Probably a bad shortcut, helps with authentication-required endpoints
		 * as well as Debug Bar Console.
		 */
		wp_signon();

		do_action( 'template_redirect' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		foreach ( array( 'style_loader_src', 'script_loader_src' ) as $hook ) {
			add_filter(
				$hook,
				array( $this, 'filter_asset_source' ),
				10,
				2
			);
		}
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
	}

	/**
	 * Prevent theme assets from being included in the debug response.
	 *
	 * @param string|false $src    Asset URL.
	 * @param string       $handle Asset handle.
	 *
	 * @return string|false The unchanged URL, or false for theme assets.
	 */
	public function filter_asset_source( string|false $src, string $handle ): string|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( false !== $src && false !== strpos( $src, '/themes/' ) ) {
			return false;
		}

		return $src;
	}

	/**
	 * Output JSON in HTML.
	 *
	 * @param array            $result         Response data to send to the client.
	 * @param \WP_REST_Server  $wp_rest_server Server instance.
	 * @param \WP_REST_Request $request        Request used to generate the response.
	 *
	 * @return array Response data to send to the client when debugging is disabled.
	 */
	public function rest_pre_echo_response( array $result, \WP_REST_Server $wp_rest_server, \WP_REST_Request $request ): array {
		if ( ! $this->is_rest_debug() ) {
			return $result;
		}

		$result = wp_json_encode( $result, JSON_PRETTY_PRINT );
		header( 'Content-type: text/html' );

		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
		<?php wp_head(); ?>
</head>
<body>
	<pre><?php echo esc_html( $result ); ?></pre>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
		die();
	}

	/**
	 * Helper for debugging arg.
	 *
	 * @return bool
	 */
	public function is_rest_debug(): bool {
		return isset( $_GET['debug'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
