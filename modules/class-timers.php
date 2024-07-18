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

	/**
	 * Represents the early timer.
	 *
	 * @var mixed $early_timer
	 */
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
		$this->early_timer = microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ?? 0;
	}

	/**
	 * Adds Sandbox WP Debugger support to output all SQL Queries.
	 *
	 * @return void
	 */
	public function late_shutdown(): void {
		/**
		 * Docs:
		 *
		 * @see https://github.com/johnbillion/query-monitor/blob/4dbdd30f599a432e430be31e7501d5831417d2ae/collectors/overview.php#L62
		 */
		$late_timer = microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ?? 0;

		if ( function_exists( 'memory_get_peak_usage' ) ) {
			$memory = memory_get_peak_usage();
		} elseif ( function_exists( 'memory_get_usage' ) ) {
			$memory = memory_get_usage();
		} else {
			$memory = 0;
		}

		$time_limit = (int) ini_get( 'max_execution_time' );
		$time_start = (float) $_SERVER['REQUEST_TIME_FLOAT'] ?? 0;

		if ( ! empty( $time_limit ) ) {
			$time_usage = ( 100 / $time_limit ) * $late_timer;
		} else {
			$time_usage = 0;
		}

		$memory_limit       = ini_get( 'memory_limit' ) ? ini_get( 'memory_limit' ) : '0';
		$memory_limit_bytes = (float) $memory_limit;
		if ( $memory_limit_bytes ) {
			$last = strtolower( substr( $memory_limit, -1 ) );
			$pos  = strpos( ' kmg', $last, 1 );
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

		$message = sprintf(
			'Page Generation: %s, Shutdown: %s, Memory: %s/%s (%s%%)',
			self::human_time( $this->early_timer ),
			self::human_time( $late_timer - $this->early_timer ),
			size_format( $memory ),
			size_format( $memory_limit ),
			number_format( $memory_usage, 2 ),
		);
		$this->log(
			message: $message,
			backtrace: false
		);
	}

	/**
	 * Converts a given number of seconds into a human-readable time format.
	 *
	 * @param int $seconds The number of seconds to convert.
	 *
	 * @return string The human-readable time format.
	 */
	public function human_time( int $seconds ): string {
		if ( $seconds >= 1 ) {
			return number_format( $seconds, 3 ) . 's';
		} else {
			return number_format( $seconds * 1000, 3 ) . 'ms';
		}
	}
}
