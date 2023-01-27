<?php
/**
 * Plugin Name:     Sandbox WP Debugger
 * Plugin URI:      https://github.com/Automattic/sandbox-wp-debugger
 * Description:     Adds some advanced debug techniques to your sandbox
 * Author:          @david-binda
 * Text Domain:     sandbox-wp-debugger
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Sandbox_Wp_Debugger
 */

define( 'SWPD_DIR_PATH', plugin_dir_path( __FILE__ ) );

// Required files.
require_once SWPD_DIR_PATH . 'helper-functions.php';
require_once SWPD_DIR_PATH . 'class-base.php';

// Set up modules.
foreach ( glob( SWPD_DIR_PATH . '/modules/*.php' ) as $swpd_module ) {
	if ( file_exists( $swpd_module ) ) {
		require_once $swpd_module;
	}
}

unset( $swpd_module ); // No longer needed.
