<?php
/**
 * Early Bootstrap Timer for Sandbox WP Debugger.
 *
 * Include this file in wp-config.php to start collecting timing data earlier.
 * Example usage in wp-config.php:
 *
 * // Optional: Enable early bootstrap timing collection
 * if ( file_exists( WP_CONTENT_DIR . '/mu-plugins/00-sandbox-helper/sandbox-wp-debugger/early-bootstrap-timer.php' ) ) {
 *     require_once WP_CONTENT_DIR . '/mu-plugins/00-sandbox-helper/sandbox-wp-debugger/early-bootstrap-timer.php';
 * }
 *
 * This will start collecting resource usage, stream operations, and autoload data
 * much earlier in the WordPress bootstrap process.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_INSTALLING' ) ) {
	// Only run if we're in a WordPress context
	return;
}

// Prevent multiple inclusions
if ( defined( 'SWPD_EARLY_BOOTSTRAP_LOADED' ) ) {
	return;
}
define( 'SWPD_EARLY_BOOTSTRAP_LOADED', true );

// Initialize global storage for early timing data
$GLOBALS['swpd_early_constructor_time'] = microtime( true );

// Collect baseline resource usage data as early as possible
if ( function_exists( 'getrusage' ) ) {
	$GLOBALS['swpd_baseline_rusage']       = getrusage();
	$GLOBALS['swpd_baseline_child_rusage'] = getrusage( 1 ); // RUSAGE_CHILDREN
}

// Note: Filesystem/stream operations tracking is disabled since we can't set
// global stream notifications in wp-config.php context.

// Initialize autoloading performance tracking data
$GLOBALS['swpd_autoload_data']       = array(
	'total_time'             => 0,
	'call_count'             => 0,
	'success_count'          => 0,
	'classes_loaded'         => array(),
	'failed_attempts'        => array(),
	'autoloader_performance' => array(),
);
$GLOBALS['swpd_autoload_start_time'] = null;

// Stream-related functions removed since global stream notifications
// cannot be set up in wp-config.php context.

/**
 * Autoloader wrapper that times all autoloading operations.
 *
 * @param string $class_name The class name to autoload.
 *
 * @return bool True if class was loaded, false otherwise.
 */
function swpd_early_timed_autoload_wrapper( string $class_name ): bool {
	// Prevent infinite recursion and skip our own classes.
	if ( strpos( $class_name, 'SWPD\\' ) === 0 ) {
		return false;
	}

	// Skip if class already exists (avoid duplicate tracking).
	if ( class_exists( $class_name, false ) || interface_exists( $class_name, false ) || trait_exists( $class_name, false ) ) {
		return true;
	}

	$GLOBALS['swpd_autoload_start_time'] = microtime( true );
	++$GLOBALS['swpd_autoload_data']['call_count'];

	// Get all registered autoloaders except our wrapper.
	$autoloaders  = spl_autoload_functions();
	$class_loaded = false;

	// Handle case where no other autoloaders are registered.
	if ( empty( $autoloaders ) || ( 1 === count( $autoloaders ) && 'swpd_early_timed_autoload_wrapper' === $autoloaders[0] ) ) {
		swpd_early_track_failed_autoload( $class_name );
		return false;
	}

	foreach ( $autoloaders as $autoloader ) {
		// Skip our own wrapper to prevent infinite recursion.
		if ( 'swpd_early_timed_autoload_wrapper' === $autoloader ) {
			continue;
		}

		// Try this autoloader.
		if ( is_callable( $autoloader ) ) {
			// Temporarily remove our wrapper to avoid recursive calls.
			spl_autoload_unregister( 'swpd_early_timed_autoload_wrapper' );

			try {
				// Call the autoloader (most return void, some return boolean).
				$result = call_user_func( $autoloader, $class_name );

				// Re-register our wrapper immediately.
				spl_autoload_register( 'swpd_early_timed_autoload_wrapper', true, true );

				// Check if class was actually loaded (more reliable than return value).
				if ( class_exists( $class_name, false ) || interface_exists( $class_name, false ) || trait_exists( $class_name, false ) ) {
					$class_loaded = true;
					swpd_early_track_successful_autoload( $class_name, $autoloader );
					break;
				}
			} catch ( Exception $e ) {
				// Re-register our wrapper even on exception.
				spl_autoload_register( 'swpd_early_timed_autoload_wrapper', true, true );
				// Continue to try other autoloaders.
				continue;
			} catch ( Error $e ) {
				// Handle PHP 7+ Error objects as well.
				spl_autoload_register( 'swpd_early_timed_autoload_wrapper', true, true );
				continue;
			}
		}
	}

	// Track the result.
	if ( ! $class_loaded ) {
		swpd_early_track_failed_autoload( $class_name );
	}

	return $class_loaded;
}

/**
 * Tracks successful autoload operations.
 *
 * @param string $class_name The successfully loaded class name.
 * @param mixed  $autoloader The autoloader that succeeded.
 *
 * @return void
 */
function swpd_early_track_successful_autoload( string $class_name, $autoloader ): void {
	// Safety check for timing.
	if ( null === $GLOBALS['swpd_autoload_start_time'] ) {
		return;
	}

	$elapsed_time = microtime( true ) - $GLOBALS['swpd_autoload_start_time'];

	// Sanity check: ignore extremely long operations (likely indicates measurement error).
	if ( $elapsed_time > 10.0 ) {
		return;
	}

	$GLOBALS['swpd_autoload_data']['total_time'] += $elapsed_time;
	++$GLOBALS['swpd_autoload_data']['success_count'];
	$GLOBALS['swpd_autoload_data']['classes_loaded'][ $class_name ] = $elapsed_time;

	// Categorize the autoloader.
	$autoloader_type = swpd_early_categorize_autoloader( $autoloader );

	if ( ! isset( $GLOBALS['swpd_autoload_data']['autoloader_performance'][ $autoloader_type ] ) ) {
		$GLOBALS['swpd_autoload_data']['autoloader_performance'][ $autoloader_type ] = array(
			'time'  => 0,
			'calls' => 0,
		);
	}

	$GLOBALS['swpd_autoload_data']['autoloader_performance'][ $autoloader_type ]['time'] += $elapsed_time;
	++$GLOBALS['swpd_autoload_data']['autoloader_performance'][ $autoloader_type ]['calls'];

	// Reset timing.
	$GLOBALS['swpd_autoload_start_time'] = null;
}

/**
 * Tracks failed autoload attempts.
 *
 * @param string $class_name The class that failed to load.
 *
 * @return void
 */
function swpd_early_track_failed_autoload( string $class_name ): void {
	// Safety check for timing.
	if ( null === $GLOBALS['swpd_autoload_start_time'] ) {
		return;
	}

	$elapsed_time = microtime( true ) - $GLOBALS['swpd_autoload_start_time'];

	// Sanity check: ignore extremely long operations.
	if ( $elapsed_time > 10.0 ) {
		return;
	}

	$GLOBALS['swpd_autoload_data']['total_time']                    += $elapsed_time;
	$GLOBALS['swpd_autoload_data']['failed_attempts'][ $class_name ] = $elapsed_time;

	// Reset timing.
	$GLOBALS['swpd_autoload_start_time'] = null;
}

/**
 * Categorizes an autoloader by type.
 *
 * @param mixed $autoloader The autoloader callable.
 *
 * @return string Autoloader category.
 */
function swpd_early_categorize_autoloader( $autoloader ): string {
	if ( is_array( $autoloader ) && isset( $autoloader[0] ) ) {
		$class_name = is_object( $autoloader[0] ) ? get_class( $autoloader[0] ) : $autoloader[0];

		if ( strpos( $class_name, 'Composer\\Autoload\\ClassLoader' ) !== false ) {
			return 'composer';
		}

		if ( strpos( $class_name, 'WP_' ) === 0 || strpos( $class_name, 'WordPress' ) !== false ) {
			return 'WordPress';
		}
	}

	if ( is_string( $autoloader ) ) {
		if ( strpos( $autoloader, 'wp_' ) === 0 || strpos( $autoloader, 'WordPress' ) !== false ) {
			return 'WordPress';
		}
	}

	return 'custom';
}

// Note: HTTP request timing can't be set up here since WordPress functions
// like add_filter() are not available in wp-config.php context.
// HTTP timing will be handled by the Timers class once WordPress loads.

// Set up autoloading tracking
spl_autoload_register( 'swpd_early_timed_autoload_wrapper', true, true );
