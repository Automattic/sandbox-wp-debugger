<?php
/**
 * Memcache Object Cache debugger.
 */

declare(strict_types=1);

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

	/**
	 * Specifies whether to include group details in the debug output.
	 *
	 * @var bool $group_details
	 */
	public static bool $group_details = false;

	/**
	 * Specifies whether to include method details in the debug output.
	 *
	 * @var bool $method_details
	 */
	public static bool $method_details = false;

	/**
	 * Class constructor; set up all of the necessary WordPress hooks.
	 *
	 * @param bool $group_details  Optional. Whether to include group details in the debug output. Default false.
	 * @param bool $method_details Optional. Whether to include method details in the debug output. Default true.
	 */
	public function __construct( bool $group_details = false, bool $method_details = true ) {
		add_action( 'shutdown', array( $this, 'memcache_debug' ), PHP_INT_MAX );

		self::$group_details  = $group_details;
		self::$method_details = $method_details;
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

		$total_memcache_time  = 'Total query time: ' . number_format_i18n( sprintf( '%0.1f', $wp_object_cache->time_total * 1000 ), 1 ) . ' ms';
		$total_memcache_size  = 'Total size: ' . size_format( $wp_object_cache->size_total, 2 );
		$method_detail_output = '';
		$group_detail_output  = '';

		$memcache_stats = array();

		// BEGIN Methods and Calls.
		foreach ( $wp_object_cache->stats as $stat => $n ) {
			if ( empty( $n ) ) {
				continue;
			}

			$memcache_stats[] = sprintf( '%s %s', $stat, $n );
		}

		$data = array_map(
			function ( $method, $calls ) {
				return array( $method, $calls );
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

		if ( in_array( 'slow-ops', $groups ) ) { // Always show `slow-ops` first.
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
		if ( true === self::$group_details ) {
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

		// BEGIN Method Details.
		if ( true === self::$method_details ) {
			$method_detail_output .= PHP_EOL . sprintf( "=== Details for Methods ===\n\n" );
			$method_detail_output .= $this->output_memcached_method_tables( $wp_object_cache->group_ops );
		}

		$this->log(
			message: 'Memcache Stats: ' . $total_memcache_time . ' | ' . $total_memcache_size . PHP_EOL . PHP_EOL . $calls_table . PHP_EOL . $groups_table . PHP_EOL . $method_detail_output . PHP_EOL . $group_detail_output,
			backtrace: false
		);
	}

	/**
	 * Get the memcached Group Ops line.
	 *
	 * @param int|string $index Unknown. The Index of something.
	 * @param array      $arr   Unknown. The array of Group data.
	 *
	 * @return string      The Group Ops line.
	 */
	public function get_group_ops_line( int|string $index, array $arr ): string {
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
	 * Outputs Memcached method tables.
	 *
	 * This function processes the provided data to group and aggregate Memcached
	 * operations by method and group name. It then generates and returns an ASCII
	 * table representation of the aggregated data for each method.
	 *
	 * @param array $data The data to be processed. Should be $wp_object_cache->group_ops.
	 *
	 * @return string The ASCII table representation of the aggregated data for each method.
	 */
	public function output_memcached_method_tables( array $data ): string {
		$return = '';
		// Initialize an array to hold grouped data.
		$grouped_data = array();

		// Iterate through each data entry.
		foreach ( $data as $group_name => $entries ) {
			foreach ( $entries as $entry ) {
				list( $method, $key, $hits, $time, $size ) = $entry;

				// Initialize the group if not set.
				if ( ! isset( $grouped_data[ $method ][ $group_name ] ) ) {
					$grouped_data[ $method ][ $group_name ] = array(
						'ops'  => 0,
						'size' => 0,
						'time' => 0,
					);
				}

				// Aggregate data for each group.
				$grouped_data[ $method ][ $group_name ]['ops']  += 1;
				$grouped_data[ $method ][ $group_name ]['size'] += (int) $size;
				$grouped_data[ $method ][ $group_name ]['time'] += (float) $time;
			}
		}

		// Output tables for each method.
		foreach ( $grouped_data as $method => $groups ) {
			$rows = array();
			// Header row.
			$rows[] = array( 'Group Name', 'Ops', 'Size', 'Time' );

			foreach ( $groups as $group_name => $metrics ) {
				$ops    = $metrics['ops'];
				$size   = size_format( $metrics['size'] ); // Use WordPress function to format size.
				$time   = number_format_i18n( $metrics['time'], 1 ) . 'ms'; // Use WordPress function to format time.
				$rows[] = array( $group_name, (string) $ops, $size, $time );
			}

			// Display the table for the current method.
			$return .= "($method)\n";
			$return .= $this->array_to_ascii_table( $rows ) . PHP_EOL . PHP_EOL;
		}

		return $return;
	}
}
