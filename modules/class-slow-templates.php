<?php
/**
 * Sandbox WP Debugger Helper to output the 10 slowest hooks.
 */

namespace SWPD;

/**
 * SWPD\Slow_Templates Class.
 */
class Slow_Templates extends Base {
	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'Slow Templates';

	/**
	 * An array of timers and timer data.
	 *
	 * @var array
	 */
	public array $timers = array();

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		add_action( 'wp_before_load_template', array( $this, 'start_template_timer' ), 10, 3 );
		add_action( 'wp_after_load_template', array( $this, 'stop_template_timer' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'shutdown' ), PHP_INT_MAX );
	}

	/**
	 * Starts the timer when a template part has started loading.
	 *
	 * @param string $template_file  The path to the template file.
	 * @param bool   $load_once      Whether the template should be loaded just once.
	 * @param array  $args           An array of possible arguments for the template part.
	 */
	public function start_template_timer( $template_file, $load_once, $args ) {
		// Remove the theme and child theme directory from the template file path.
		$template_file_relative = str_replace( array( get_template_directory() . '/', get_stylesheet_directory() . '/' ), '', $template_file );

		// Get the path information.
		$path_info = pathinfo( $template_file_relative );

		// Reconstruct the file path without the extension.
		$template_file_relative = ( '.' !== $path_info['dirname'] ? $path_info['dirname'] . '/' : '' ) . $path_info['filename'];

		// Create the unique key for this template part.
		$key = $template_file_relative; // . ':' . md5( serialize( $args ) );

		// Start the timer and store the start time in the global array.
		$this->timers[ $key ] = array(
			'name'       => $template_file_relative,
			'start_time' => microtime( true ),
		);

	}

	/**
	 * Stops the timer when a template part has finished loading, and stores the time taken.
	 *
	 * @param string $template_file  The path to the template file.
	 * @param bool   $load_once      Whether the template should be loaded just once.
	 * @param array  $args           An array of possible arguments for the template part.
	 */
	public function stop_template_timer( $template_file, $load_once, $args ) {
		// Remove the theme and child theme directory from the template file path.
		$template_file_relative = str_replace( array( get_template_directory() . '/', get_stylesheet_directory() . '/' ), '', $template_file );

		// Get the path information.
		$path_info = pathinfo( $template_file_relative );

		// Reconstruct the file path without the extension.
		$template_file_relative = ( '.' !== $path_info['dirname'] ? $path_info['dirname'] . '/' : '' ) . $path_info['filename'];

		// Create the unique key for this template part.
		$key = $template_file_relative;

		// Check if we have a start time for this template part.
		if ( isset( $this->timers[ $key ] ) ) {
			// Record the stop time and calculate the time taken in milliseconds.
			$this->timers[ $key ]['stop_time']                  = microtime( true );
			$this->timers[ $key ]['execution_time']             = ( $this->timers[ $key ]['stop_time'] - $this->timers[ $key ]['start_time'] );
			$this->timers[ $key ]['wp_debug_backtrace_summary'] = wp_debug_backtrace_summary( ignore_class: null, skip_frames: 0, pretty: false ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_wp_debug_backtrace_summary

			$this->timers[ $key . ':' . md5( serialize( $args ) ) ] = $this->timers[ $key ]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			unset( $this->timers[ $key ] );
		}
	}

	/**
	 * Shutdown hook to gather the timer data and output to SWPD.
	 *
	 * @return void
	 */
	public function shutdown(): void {
		$timers = array();

		// Copy the timing data array.
		$timing_data = $this->timers;

		// Sort the timing data by execution time in descending order.
		usort(
			$timing_data,
			function( $a, $b ) {
				if ( ! isset( $a['execution_time'] ) || ! isset( $b['execution_time'] ) ) {
					return 0;
				}
				return $b['execution_time'] <=> $a['execution_time'];
			}
		);

		$count = 0;
		// Log the data.
		foreach ( $timing_data as $data ) {
			if ( isset( $data['execution_time'] ) ) {
				if ( $count < 10 ) {
					foreach ( $data['wp_debug_backtrace_summary'] as $backtrace_line ) {
						if (
							str_starts_with( $backtrace_line, 'include(' ) ||
							str_starts_with( $backtrace_line, 'require(' ) ||
							str_starts_with( $backtrace_line, 'require_once(' )
						) {
							$calling_file = $backtrace_line;
							break;
						}
					}

					$calling_file = str_replace(
						array(
							'require(\'',
							'require_once(\'',
							'include(\'',
							'\')',
						),
						'',
						$calling_file
					);

					$timers[] = sprintf(
						'Template: %s, Time Taken %s, Called: %s',
						$data['name'],
						$this->human_time( $data['execution_time'] ),
						$calling_file
					);
					$count++;
				}
			}
		}

		$this->log( 'Top 10 Slowest Templates', $timers );
	}

	/**
	 * Change the resolution of the timer.
	 *
	 * @param  int    $time       Time taken in nanoseconds.
	 * @param  string $resolution Resolution to output. Currently accepts 'ms' and 'ns'.
	 *
	 * @return float              Time taken in the new resolution.
	 */
	public function change_resolution( int $time, string $resolution = 'ms' ): float {
		switch ( $resolution ) {
			case 'ns':
				$time = $time;
				break;
			case 'ms':
				$time /= 1e+6;
				break;
			default:
				return (float) $time;
		}

		return (float) round( $time, 3 );
	}

	/**
	 * Converts the input time in seconds to a human-readable format.
	 * If the time is one second or more, it is reported in seconds.
	 * If the time is less than one second, it is reported in milliseconds.
	 *
	 * @param float $seconds The time in seconds.
	 *
	 * @return string The time in a human-readable format.
	 */
	public function human_time( $seconds ) {
	if ( $seconds >= 1 ) {
			return number_format( $seconds, 3 ) . 's';
		} elseif ( $seconds >= 1e-3 ) {
			return number_format( $seconds * 1e3, 3 ) . 'ms';
		} elseif ( $seconds >= 1e-6 ) {
			return number_format( $seconds * 1e6, 3 ) . 'μs';
		} else {
			return number_format( $seconds * 1e9, 3 ) . 'ns';
		}
	}

}
