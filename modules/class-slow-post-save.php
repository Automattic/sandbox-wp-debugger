<?php
/**
 * Slow Post Save Debugger.
 */

namespace SWPD;

/**
 * SWPD\Slow_Post_Save Class.
 */
class Slow_Post_Save extends Base {

	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public $debugger_name = 'slow post save';

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		if ( false === defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
		add_filter( 'wp_redirect', array( $this, 'wp_redirect_filter' ), PHP_INT_MIN, 1 );
	}

	/**
	 * Is the post being saved.
	 *
	 * @return boolean True if the post is being saved, otherwise false.
	 */
	public function is_post_save(): bool {
		$is_post_save = false;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $is_post_save;
		}

		if ( true === isset( $_POST['action'] ) && 'editpost' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$is_post_save = true;
		}

		return $is_post_save;
	}

	/**
	 * Collects data about the request for redirects.
	 *
	 * @param string $location The path or URL to redirect to.
	 *
	 * @return string The path or URL to redirect to.
	 */
	public function wp_redirect_filter( string $location ): string {
		if ( true === $this->is_post_save() ) {
			$this->log();
		}
		return $location;
	}
}

new SWPD\Slow_Post_Save();
