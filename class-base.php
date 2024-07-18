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
	 * @var object
	 */
	private static $instance;

	/**
	 * Initiate an instance of the class if it doesn't exist
	 *
	 * @return object
	 */
	public static function init(): object {
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
			dbg_function: $this->debugger_name,
			message:      $message,
			data:         $data,
			debug_data:   $debug_data,
			backtrace:    $backtrace
		);
	}


	/**
	 * Unicode safe version of str_pad()
	 *
	 * @see https://stackoverflow.com/a/73692927
	 *
	 * @param  string $input      The input string.
	 * @param  int    $length     The length to pad the input string to.
	 * @param  string $pad_string The string to pad the input string with.
	 *
	 * @return string             The padded string.
	 */
	public function unicode_safe_str_pad( string $input, int $length, string $pad_string = ' ' ): string {
		$lines   = explode( "\n", $input );
		$lengths = array_map( 'strlen', $lines );

		$max_length = max( $lengths );

		$times = $length - mb_strlen( $input ) >= 0 ? $length - mb_strlen( $input ) : 0;
		$times = $length - $max_length;
		return $input . str_repeat( $pad_string, $times );
	}

	/**
	 * Builds a simple ASCII table out of data.
	 *
	 * @see https://stackoverflow.com/a/73692927
	 *
	 * @param  array $rows Array of rows to build a table with.
	 *
	 * @return string       The built ASCII table.
	 */
	public function array_to_ascii_table( array $rows = array() ): string {
		if ( count( $rows ) === 0 ) {
			return '';
		}

		$widths = array();

		foreach ( $rows as $cells ) {
			foreach ( $cells as $j => $cell ) {
				$width = mb_strlen( $cell ) + 2;
				if ( ( $width ) >= ( $widths[ $j ] ?? 0 ) ) {
					$widths[ $j ] = $width;
				}
			}
		}

		$horizontal_bar = str_repeat( '─', array_sum( $widths ) + count( $widths ) - 1 );
		$top_bar        = sprintf( '┌%s┐', $horizontal_bar );
		$middle_bar     = sprintf( '├%s┤', $horizontal_bar );
		$bottom_bar     = sprintf( '└%s┘', $horizontal_bar );

		$result[] = $top_bar;

		foreach ( $rows as $i => $cells ) {
			$result[] = sprintf(
				'│%s│',
				implode(
					'│',
					array_map(
						function ( $cell, $wall ): string {
							return $this->unicode_safe_str_pad( " {$cell} ", $wall );
						},
						$cells,
						$widths
					)
				)
			);
			if ( 0 === $i ) {
				$result[] = $middle_bar;
			}
		}
		$result[] = $bottom_bar;

		return implode( PHP_EOL, $result );
	}

	/**
	 * Converts an array to a text list.
	 *
	 * This method takes an array as input and converts it into a comma-separated text list.
	 *
	 * @param array $arr The array to be converted.
	 *
	 * @return string The comma-separated text list.
	 */
	public function array_to_text_list( array $arr ): string {
		// Find the longest key length.
		$max_key_length = 0;
		foreach ( $arr as $key => $value ) {
			$max_key_length = max( $max_key_length, strlen( $key ) );
		}

		// Convert array to the desired format.
		$result = '';
		foreach ( $arr as $key => $value ) {
			$result .= sprintf( "%-{$max_key_length}s: %s\n", $key, $value );
		}

		return $result;
	}

	/**
	 * Prevents cloning the singleton instance.
	 *
	 * @return void
	 */
	private function __clone() {}
}
