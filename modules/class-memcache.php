<?php
/**
 * Memcache Object Cache debugger.
 */

namespace SWPD;

/**
 * SWPD\Memcache Class.
 */
class Memcache extends Base {

	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'Memcache Object Cache';

	public static bool $details = false;

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct( $details = false ) {
		add_action( 'shutdown', array( $this, 'memcache_debug' ), PHP_INT_MAX );

		self::$details = $details;
	}

	/**
	 * Outputs memcache stats at the end of a request.
	 *
	 * A lot of this is borrowed from the stats in https://github.com/Automattic/wp-memcached.
	 *
	 * @return void
	 */
	public function memcache_debug(): void {
		global $wp_object_cache;

		$total_memcache_time = 'Total query time: ' . number_format_i18n( sprintf( '%0.1f', $wp_object_cache->time_total * 1000 ), 1 ) . ' ms';
		$total_memcache_size = 'Total size: ' . size_format( $wp_object_cache->size_total, 2 );

		$memcache_stats = array();

		// BEGIN Methods and Calls.

		foreach ( $wp_object_cache->stats as $stat => $n ) {
			if ( empty( $n ) ) {
				continue;
			}

			$memcache_stats[] = sprintf( '%s %s', $stat, $n );
		}

		$data = array_map(
			function( $key, $value ) {
				return array( $key, $value );
			},
			array_keys( $wp_object_cache->stats ),
			$wp_object_cache->stats
		);
		$data = array_merge( array( array( 'Method', 'Calls' ) ), $data );

		$calls_table = $this->array_to_ascii_table( $data );

		// BEGIN Groups.

		$groups = array_keys( $wp_object_cache->group_ops );
		usort( $groups, 'strnatcasecmp' );

		$active_group = $groups[0];
		// Always show `slow-ops` first.
		if ( in_array( 'slow-ops', $groups ) ) {
			$slow_ops_key = array_search( 'slow-ops', $groups );
			$slow_ops     = $groups[ $slow_ops_key ];
			unset( $groups[ $slow_ops_key ] );
			array_unshift( $groups, $slow_ops );
			$active_group = 'slow-ops';
		}

		$total_ops    = 0;
		$group_titles = array();
		$groups_table = array( array( 'Group Name', 'Ops', 'Size', 'Time' ) );

		foreach ( $groups as $group ) {
			$group_name = $group;
			$group_ops  = count( $wp_object_cache->group_ops[ $group ] );

			if ( empty( $group_name ) ) {
				$group_name = 'default';
			}

			$group_size = size_format(
				array_sum(
					array_map(
						function ( $op ) {
							return $op[2];
						},
						$wp_object_cache->group_ops[ $group ]
					)
				),
				2
			);

			$group_time = number_format_i18n(
				sprintf(
					'%0.1f',
					array_sum(
						array_map(
							function ( $op ) {
								return $op[3];
							},
							$wp_object_cache->group_ops[ $group ]
						)
					) * 1000
				),
				1
			);

			$total_ops             += $group_ops;
			$group_title            = "{$group_name} [$group_ops][$group_size][{$group_time} ms]";
			$group_titles[ $group ] = $group_title;

			$groups_table[] = array(
				$group_name,
				$group_ops,
				$group_size,
				$group_time . 'ms',
			);
		}

		$groups_table = $this->array_to_ascii_table( $groups_table );

		// BEGIN Group Details.

		if ( true === self::$details ) {
			foreach ( $groups as $group ) {
				$group_name = $group;
				if ( empty( $group_name ) ) {
					$group_name = 'default';
				}

				$group_ops_line = '';
				foreach ( $wp_object_cache->group_ops[ $group ] as $index => $arr ) {
					$group_ops_line .= sprintf( '%3d ', $index );
					$group_ops_line .= $this->get_group_ops_line( $index, $arr );
				}

				$group_details_table[] = array(
					trim( $group_titles[ $group ] ),
					$group_ops_line,
				);

			}

			$group_detail_output = sprintf( "=== Details for Groups ===\n\n" );

			foreach ( $group_details_table as $group_detail ) {
				$group_detail_output .= sprintf( "%s ↴ \n%s\n\n", $group_detail[0], $group_detail[1] );
			}
		}

		$this->log(
			message: 'Memcache Stats: ' . $total_memcache_time . ' | ' . $total_memcache_size . PHP_EOL . PHP_EOL . $calls_table . PHP_EOL . $groups_table . PHP_EOL . $group_detail_output,
			backtrace: false
		);
	}

	/**
	 * Get the memcached Group Ops line.
	 *
	 * @param  mixed $index Unknown. The Index of something.
	 * @param  array $arr   Unknown. The array of Group data.
	 *
	 * @return string        The Group Ops line.
	 */
	public function get_group_ops_line( $index, $arr ): string {
		// operation.
		$line = "{$arr[0]} ";

		// key.
		$json_encoded_key = wp_json_encode( $arr[1] );
		$line            .= $json_encoded_key . ' ';

		// comment.
		if ( ! empty( $arr[4] ) ) {
			$line .= "{$arr[4]} ";
		}

		// size.
		if ( isset( $arr[2] ) ) {
			$line .= '(' . size_format( $arr[2], 2 ) . ') ';
		}

		// time.
		if ( isset( $arr[3] ) ) {
			$line .= '(' . number_format_i18n( sprintf( '%0.1f', $arr[3] * 1000 ), 1 ) . ' ms)' . PHP_EOL;
		}

		// backtrace.
		$bt_link = '';
		if ( isset( $arr[6] ) ) {
			$key_hash = md5( $index . $json_encoded_key );
			$bt_link .= $arr[6];
		}

		return $line;
	}

	/**
	 * Unicode safe version of str_pad()
	 *
	 * @see https://stackoverflow.com/a/73692927
	 *
	 * @param  string $string     The input string.
	 * @param  int    $length     The length to pad the input string to.
	 * @param  string $pad_string The string to pad the input string with.
	 *
	 * @return string             The padded string.
	 */
	public function unicode_safe_str_pad( string $string, int $length, string $pad_string = ' ' ): string {
		$lines   = explode( "\n", $string );
		$lengths = array_map( 'strlen', $lines );

		$max_length = max( $lengths );

		$times = $length - mb_strlen( $string ) >= 0 ? $length - mb_strlen( $string ) : 0;
		$times = $length - $max_length;
		return $string . str_repeat( $pad_string, $times );
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

}
