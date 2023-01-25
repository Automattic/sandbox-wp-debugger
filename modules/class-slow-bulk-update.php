<?php
/**
 * Slow Bulk Update Debugger.
 */

namespace SWPD;

/**
 * SWPD\Slow_Bulk_Update Class.
 */
class Slow_Bulk_Update extends Base {

	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public $debugger_name = 'Slow Bulk Update';

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
		if ( true === isset( $_REQUEST['bulk_edit'] ) && __( 'Update', 'default' ) === $_REQUEST['bulk_edit'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.WP.I18n.TextDomainMismatch
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

new SWPD\Slow_Bulk_Update();
