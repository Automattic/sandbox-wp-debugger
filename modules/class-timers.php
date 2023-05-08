<?php
/**
 * Slow Queries Debugger.
 */

namespace SWPD;

/**
 * SWPD\Timers Class.
 */
class Timers extends Base {
	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'Timers';

	public $early_timer;

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		add_action( 'shutdown', array( $this, 'early_shutdown' ), PHP_INT_MIN );
		add_action( 'shutdown', array( $this, 'late_shutdown' ), PHP_INT_MAX );
	}

	/**
	 * Adds Sandbox WP Debugger support to output all SQL Queries.
	 *
	 * @return void
	 */
	public function early_shutdown(): void {
		$this->early_timer = microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'];
	}

	/**
	 * Adds Sandbox WP Debugger support to output all SQL Queries.
	 *
	 * @return void
	 */
	public function late_shutdown(): void {
		//https://github.com/johnbillion/query-monitor/blob/4dbdd30f599a432e430be31e7501d5831417d2ae/collectors/overview.php#L62
		$late_timer = microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'];

		if ( function_exists( 'memory_get_peak_usage' ) ) {
			$memory = memory_get_peak_usage();
		} elseif ( function_exists( 'memory_get_usage' ) ) {
			$memory = memory_get_usage();
		} else {
			$memory = 0;
		}

		$time_limit = (int) ini_get( 'max_execution_time' );
		$time_start = $_SERVER['REQUEST_TIME_FLOAT'];

		if ( ! empty( $time_limit ) ) {
			$time_usage = ( 100 / $time_limit ) * $late_timer;
		} else {
			$time_usage = 0;
		}

		$memory_limit = ini_get( 'memory_limit' ) ?: '0';
		$memory_limit_bytes = (float) $memory_limit;
		if ( $memory_limit_bytes ) {
			$last = strtolower( substr( $memory_limit, -1 ) );
			$pos = strpos( ' kmg', $last, 1 );
			if ( $pos ) {
				$memory_limit_bytes *= pow( 1024, $pos );
			}
			$memory_limit_bytes = round( $memory_limit_bytes );
		}
		$memory_limit = $memory_limit_bytes;

		if ( $memory_limit > 0 ) {
			$memory_usage = ( 100 / $memory_limit ) * $memory;
		} else {
			$memory_usage = 0;
		}

		$message = sprintf( 'Time Taken: %s, Memory Used: %s', $time_usage, $memory_usage );
		/*$this->log(
			message: $message,
			backtrace: false
		);*/

		$message = sprintf( 'Page Generation: %s, Shutdown: %s', $this->early_timer, $late_timer );
		$this->log(
			message: $message,
			backtrace: false
		);
	}
}
