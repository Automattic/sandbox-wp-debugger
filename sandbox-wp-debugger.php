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

/**
 * Uncomment any of these to enable their respective debugging tools.
 *
 * They are disabled by default because enabling many of them at once will
 * generate a lot of noise in the error logs. You probably don't need them
 * all at once anyway. But some might work well together like the REST API
 * and slow queries.
 *
 * Alternatively, use these new classes anywhere else to load them up.
 *
 * phpcs:disable Squiz.Commenting.InlineComment.NoSpaceBefore,Squiz.PHP.CommentedOutCode.Found,Squiz.Commenting.InlineComment.InvalidEndChar
 */

//new SWPD\Batcache_Debug();
//new SWPD\DebugBar_REST_API();
//new SWPD\Memcache();
//new SWPD\Redirect_Canonical();
//new SWPD\REST_Requests();
//new SWPD\Slow_Bulk_Update();
//new SWPD\Slow_Hooks();
//new SWPD\Slow_Post_Save();
//new SWPD\Slow_Queries();
//new SWPD\WP_Redirect();
//new SWPD\Slow_Templates();
//new SWPD\Timers();