<?php
/**
 * Enable Debug Bar for REST API endpoints (add ?debug). Not for production.
 * e.g. http://local.wordpress.local/wp-json/wp/v2/media/?debug
 * Author: @trepmal
 */

class SWPD_DebugBar_REST_API {

	function __construct() {
		add_action( 'rest_api_init',          array( $this, 'rest_api_init' ) );
		add_filter( 'rest_pre_echo_response', array( $this, 'rest_pre_echo_response' ), 10, 3 );
	}

	/**
	 * load plugins, template if ?debug
	 * disable theme-y css/js, known cruft
	 */
	function rest_api_init( $wp_rest_server ) {
		if ( ! $this->is_rest_debug() ) {
			return;
		}

		// probably a bad shortcut, helps with authentication-required endpoints
		// as well as Debug Bar Console
		wp_signon();

		do_action( 'template_redirect' );

		foreach( [ 'style_loader_src', 'script_loader_src' ] as $hook ) {
			add_filter( $hook, function( $src, $handle ) {
				if ( false !== strpos( $src, '/themes/' ) ) {
					return false;
				}
				return $src;
			}, 10, 2 );
		}
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );

	}

	/**
	 * output json in html
	 */
	function rest_pre_echo_response( $result, $wp_rest_server, $request ) {
		if ( ! $this->is_rest_debug() ) {
			return $result;
		}

		$result = wp_json_encode( $result, JSON_PRETTY_PRINT );
		header('Content-type: text/html');

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
	 * helper for debugging arg
	 */
	function is_rest_debug() {
		return isset( $_GET['debug'] );
	}

}

new SWPD_DebugBar_REST_API();