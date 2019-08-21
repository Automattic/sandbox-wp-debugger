<?php

class SWPD_Slow_Bulk_Update extends SlowQueries {

	public $debugger_name = 'slow bulk update';

	public function __construct() {
		if ( false === defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}
		add_filter( 'wp_redirect', array( $this, 'wp_redirect_filter' ), -99999, 1 );
	}

	public function is_post_save() {
		$is_post_save = false;
		if( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $is_post_save;
		}
		if ( true === isset( $_REQUEST['bulk_edit'] ) && 'Update' === $_REQUEST['bulk_edit'] ) {
			$is_post_save = true;
		}
		return $is_post_save;
	}

	public function wp_redirect_filter( $location ) {
		if ( true === $this->is_post_save() ) {
			$this->log();
		}
		return $location;
	}
}

new SWPD_Slow_Bulk_Update();
