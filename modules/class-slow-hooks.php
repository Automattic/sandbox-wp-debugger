<?php
/**
 * Sandbox WP Debugger Helper to output the 10 slowest hooks.
 */

namespace SWPD;

/**
 * SWPD\Slow_Hooks Class.
 */
class Slow_Hooks extends Base {
	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'Slow Hooks';

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
		add_action( 'all', array( $this, 'all_hooks' ), 10 );
		add_action( 'shutdown', array( $this, 'shutdown' ), PHP_INT_MAX );
	}

	/**
	 * Runs for every single hook to start or restart a timer.
	 *
	 * @param  mixed $value If it's a filter, the value being filtered.
	 *
	 * @return mixed        If it's a filter, the value being filtered, unchanged.
	 */
	public function all_hooks( mixed $value ): mixed {
		global $wp_current_filter;

		// Grab the current filter out of the list of running fitlers.
		$current_filter = $wp_current_filter[ count( $wp_current_filter ) - 1 ] ?? 'UNKNOWN';

		if ( is_array( $this->timers ) && isset( $this->timers[ $current_filter ] ) && 'running' === $this->timers[ $current_filter ]['status'] ) {
			// The filter is currently already running. Are we nested? Let's restart the timer just in case.
			$this->timers[ $current_filter ]['time'] += hrtime( true ) - $this->timers[ $current_filter ]['start'];
			$this->timers[ $current_filter ]['start'] = hrtime( true );
		} else {
			// Starting a new filter timer.
			$this->timers[ $current_filter ]['start']  = hrtime( true );
			$this->timers[ $current_filter ]['status'] = 'running';
			$this->timers[ $current_filter ]['time']   = isset( $this->timers[ $current_filter ]['time'] ) ? $this->timers[ $current_filter ]['time'] : 0;

			// We only want to add one stop per filter run.
			if ( ! has_action( $current_filter, 'vip_hook_timer_stop' ) ) {
				// And to make sure we capture the very last shutdown, let's run it at one less than max.
				add_action( $current_filter, array( $this, 'hook_timer_stop' ), PHP_INT_MAX - 1, 1 );
			}
		}
		return $value;
	}

	/**
	 * Shutdown hook to gather the timer data and output to SWPD.
	 *
	 * @return void
	 */
	public function shutdown(): void {
		$timers = array();
		foreach ( $this->timers as $hook_name => $vip_filter_timer ) {
			$timers[ $hook_name ] = $this->change_resolution( $vip_filter_timer['time'] );
		}
		asort( $timers );

		$timers = array_slice(
			array: $timers,
			offset: count( $timers ) - 10,
			length: 10,
			preserve_keys: true
		);

		foreach ( $timers as $hook => $time_ms ) {
			$timers[ $hook ] = $time_ms . 'ms';
		}

		$this->log( 'Top 10 Slowest Hooks', $timers );
	}

	/**
	 * Stops the current hook's timer and records the time taken.
	 *
	 * @param  mixed ...$value If it's a filter, the value being filtered.
	 *
	 * @return mixed           If it's a filter, the value being filtered, unchanged
	 */
	public function hook_timer_stop( ...$value ): mixed {
		global $wp_current_filter;

		$current_filter = $wp_current_filter[ count( $wp_current_filter ) - 1 ] ?? 'UNKNOWN';

		if ( is_array( $this->timers ) && 'running' === $this->timers[ $current_filter ]['status'] ) {
			$this->timers[ $current_filter ]['time']  += hrtime( true ) - $this->timers[ $current_filter ]['start'];
			$this->timers[ $current_filter ]['status'] = 'stopped';
			unset( $this->timers[ $current_filter ]['start'] );
		}

		// Wut? Why?
		if ( ! isset( $value[0] ) ) {
			if ( empty( $value[0] ) ) {
				return null;
			}
		}

		// Don't EVER change!
		if ( is_array( $value ) && null === $value[0] ) {
			return null;
		}

		return $value[0];
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

}
