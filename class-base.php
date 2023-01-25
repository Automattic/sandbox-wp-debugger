<?php
/**
 * Sandbox WP Debugger Helper Base Class
 */

namespace SWPD;

/**
 * SWPD_Base Class.
 */
class Base {
	/**
	 * Class instance.
	 *
	 * @var Singleton
	 */
	private static $instance;

	/**
	 * Initiate an instance of the class if it doesn't exist
	 *
	 * @return Singleton
	 */
	public static function init() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Logs data to the error log via swpd_log().
	 *
	 * @param  string       $message    The message being sent.
	 * @param  array        $data       An associative array of data to output.
	 * @param  array        $debug_data An associative array of extra data to output.
	 * @param  bool|boolean $backtrace  Output a backtrace, default to false.
	 *
	 * @return void
	 */
	public function log( string $message = '', array $data = array(), array $debug_data = array(), bool $backtrace = false ): void {
		\swpd_log(
			function:   $this->debugger_name,
			message:    $message,
			data:       $data,
			debug_data: $debug_data,
			backtrace:  false
		);
	}

	/**
	 * Prevents cloning the singleton instance.
	 *
	 * @return void
	 */
	private function __clone() {}
}
