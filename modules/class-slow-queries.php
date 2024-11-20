<?php
/**
 * Slow Queries Debugger.
 */

namespace SWPD;

/**
 * SWPD\Slow_Queries Class.
 */
class Slow_Queries extends Base {
	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'SQL Queries';

	/**
	 * Arguments for the Slow Query debugger.
	 *
	 * @var array
	 */
	public array $args = array();

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 *
	 * @param array $args Arguments for the Slow Query debugger.
	 */
	public function __construct( array $args = array() ) {
		$defaults = array(
			'debug'   => false,
			'slow_ms' => false,
			'limit'   => -1,
		);

		$this->args = wp_parse_args( $args, $defaults );

		add_action( 'shutdown', array( $this, 'shutdown' ), PHP_INT_MAX );
	}

	/**
	 * Adds Sandbox WP Debugger support to output all SQL Queries.
	 *
	 * @return void
	 */
	public function shutdown(): void {
		$this->log(
			message: $this->render_sql_queries(),
		);
	}

	/**
	 * Renders SQL query summary and normalizes queries.
	 *
	 * @return string Debug data for summarized queries.
	 */
	public function render_sql_query_summary(): string {
		global $wpdb;
		$query_types       = array();
		$query_type_counts = array();
		if ( is_array( $wpdb->queries ) ) {
			$count = count( $wpdb->queries );
			for ( $i = 0; $i < $count; ++$i ) {
				$query = array_key_exists( 'query', $wpdb->queries[ $i ] ) ? $wpdb->queries[ $i ]['query'] : $wpdb->queries[ $i ][0];
				$query = $this->normalize_query( $query );

				if ( ! isset( $query_types[ $query ] ) ) {
					$query_types[ $query ] = 0;
				}
				if ( ! isset( $query_type_counts[ $query ] ) ) {
					$query_type_counts[ $query ] = 0;
				}
				++$query_type_counts[ $query ];
				$query_types[ $query ] += array_key_exists( 'elapsed', $wpdb->queries[ $i ] ) ? $wpdb->queries[ $i ]['elapsed'] : $wpdb->queries[ $i ][1];
			}
		}

		arsort( $query_types );
		$out          = '';
		$count        = 0;
		$max_time_len = 0;
		foreach ( $query_types as $q => $t ) {
			++$count;
			$max_time_len = max( $max_time_len, strlen( sprintf( '%0.2f', $t * 1000 ) ) );
			$out         .= sprintf(
				'%s queries for %sms » %s' . PHP_EOL,
				str_pad( $query_type_counts[ $q ], 5, ' ', STR_PAD_LEFT ),
				str_pad( sprintf( '%0.2f', $t * 1000 ), $max_time_len, ' ', STR_PAD_LEFT ),
				$q
			);
		}
		return $out;
	}

	/**
	 * Normalizes a SQL query and removes unique or identifying information.
	 *
	 * @param  string $query SQL query.
	 *
	 * @return string        Normalized SQL query.
	 */
	public function normalize_query( string $query = '' ): string {
		$query = trim( preg_replace( '#connection: dbh_.+$#', '', $query ) );
		$query = preg_replace( '#\s+#', ' ', $query );
		$query = str_replace( '\"', '', $query );
		$query = str_replace( "\'", '', $query );
		$query = preg_replace( '#wp_\d+_#', 'wp_?_', $query );
		$query = preg_replace( "#'[^']*'#", "'?'", $query );
		$query = preg_replace( '#"[^"]*"#', "'?'", $query );
		$query = preg_replace( '#in ?\([^)]*\)#i', 'in(?)', $query );
		$query = preg_replace( '#= ?\d+ ?#', '= ? ', $query );
		$query = preg_replace( '#\d+(, ?)?#', '?\1', $query );
		$query = preg_replace( '#/\*.*\*/$#', '', $query );
		$query = preg_replace( '#\s+#', ' ', $query );

		return $query;
	}

	/**
	 * Renders SQL query debug data.
	 *
	 * @return string Debug data for queries.
	 */
	public function render_sql_queries(): string {
		global $wpdb, $wp_object_cache, $timestart;

		$out        = '';
		$total_time = 0;

		if ( ! empty( $wpdb->queries ) ) {

			$counter = 0;

			foreach ( $wpdb->queries as $q ) {
				// phpcs:ignore WordPressVIPMinimum.Variables.VariableAnalysis.UndefinedUnsetVariable,VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedUnsetVariable
				unset( $query, $elapsed, $affected_rows, $host, $microtime, $debug, $dbhname, $dataset, $callback_result, $connection );
				extract( $q );

				if ( false === isset( $query ) ) {
					$query = $q[0];
				}
				if ( false === isset( $elapsed ) ) {
					$elapsed = $q[1];
				}
				if ( false === isset( $debug ) ) {
					$debug = $q[2];
				}

				$total_time += $elapsed;

				if ( $this->args['limit'] > 0 && ++$counter > $this->args['limit'] ) {
					continue;
				}

				// ts is the absolute time at which each query was executed.
				if ( true === isset( $microtime ) ) {
					$ts = explode( ' ', $microtime );
					$ts = $ts[0] + $ts[1];
				} else {
					$ts = 0;
				}

				// Gather data for the variables dbhname, host, port, name, tcp, and elapsed.
				if ( isset( $connection['elapsed'] ) ) {
					$connected = "Connected {$connection['dbhname']} to {$connection['host']}:{$connection['port']} ({$connection['name']}) in " . sprintf( '%0.2f', 1000 * $connection['elapsed'] ) . 'ms';
				} elseif ( true === isset( $connection ) ) {
					$connected = "Reused connection to {$connection['dbhname']} ({$connection['name']})";
				} else {
					$connected = '';
				}

				// Clean up the whitespace.
				$query = trim( preg_replace( '/\s+/', ' ', $query ) );

				// Add a semicolon to the end of the SQL if it doesn't have one.
				if ( ! str_ends_with( $query, ';' ) ) {
					$query .= ';';
				}

				if ( $this->args['debug'] ) {
					$debug = PHP_EOL . wp_strip_all_tags( "$connected $debug #{$counter} (" . number_format( sprintf( '%0.1f', $elapsed * 1000 ), 1, '.', ',' ) . 'ms @ ' . sprintf( '%0.2f', 1000 * ( $ts - $timestart ) ) . 'ms)' );
				} else {
					$debug = '';
				}

				// Only show slow queries they take longer than the slow_ms value.
				if ( false !== $this->args['slow_ms'] ) {
					$elapsed_ms = $elapsed * 1000;
					if ( $elapsed_ms < $this->args['slow_ms'] ) {
						continue;
					}
				}

				$out .= $this->highlight_sql( $query ) . $debug . PHP_EOL . PHP_EOL;
			}
		}

		$num_queries = '';
		if ( $wpdb->num_queries ) {
			$num_queries = 'Total Queries:' . number_format( $wpdb->num_queries ) . ' | ';
		}
		$query_time   = 'Total query time:' . number_format( sprintf( '%0.1f', $total_time * 1000 ), 1 ) . 'ms | ';
		$memory_usage = 'Peak Memory Used:' . number_format( memory_get_peak_usage() ) . ' bytes | ';
		if ( true === property_exists( $wp_object_cache, 'time_total' ) ) {
			$memcache_time = 'Total memcache query time:' . number_format( sprintf( '%0.1f', $wp_object_cache->time_total * 1000 ), 1, '.', ',' ) . 'ms' . PHP_EOL . PHP_EOL;
		} else {
			$memcache_time = '';
		}

		$out = $num_queries . $query_time . $memory_usage . $memcache_time . $out;

		$out = apply_filters( 'swpdb_render_sql_queries_output', $out );

		return $out;
	}

	/**
	 * Highlights SQL queries.
	 *
	 * This method takes a SQL query as input and returns the query with syntax highlighting applied.
	 *
	 * @param string $sql The SQL query to highlight.
	 *
	 * @return string The highlighted SQL query.
	 */
	public function highlight_sql( string $sql ): string {
		$keywords = array(
			'SELECT',
			'FROM',
			'WHERE',
			'AND',
			'OR',
			'INSERT',
			'INTO',
			'VALUES',
			'UPDATE',
			'SET',
			'DELETE',
			'CREATE',
			'TABLE',
			'ALTER',
			'DROP',
			'JOIN',
			'INNER',
			'LEFT',
			'RIGHT',
			'ON',
			'AS',
			'DISTINCT',
			'GROUP',
			'BY',
			'ORDER',
			'HAVING',
			'LIMIT',
			'OFFSET',
			'UNION',
			'ALL',
			'COUNT',
			'SUM',
			'AVG',
			'MIN',
			'MAX',
			'LIKE',
			'IN',
			'BETWEEN',
			'IS',
			'NULL',
			'NOT',
			'PRIMARY',
			'KEY',
			'FOREIGN',
			'REFERENCES',
			'DEFAULT',
			'AUTO_INCREMENT',
		);

		$functions = array(
			'COUNT',
			'SUM',
			'AVG',
			'MIN',
			'MAX',
			'NOW',
			'CURDATE',
			'CURTIME',
			'DATE',
			'TIME',
			'YEAR',
			'MONTH',
			'DAY',
			'HOUR',
			'MINUTE',
			'SECOND',
			'TIMESTAMP',
		);

		$colors = array(
			'keyword'  => "\033[1;34m", // Blue.
			'function' => "\033[1;32m", // Green.
			'string'   => "\033[1;33m", // Yellow.
			'comment'  => "\033[1;90m", // Bright Black (Gray).
			'reset'    => "\033[0m",
		);

		// Highlight comments.
		$sql = preg_replace( '/\/\*.*?\*\//s', $colors['comment'] . '$0' . $colors['reset'], $sql );

		// Highlight strings.
		$sql = preg_replace( '/\'[^\']*\'/', $colors['string'] . '$0' . $colors['reset'], $sql );

		// Highlight keywords.
		foreach ( $keywords as $keyword ) {
			$sql = preg_replace( '/\b' . $keyword . '\b/i', $colors['keyword'] . '$0' . $colors['reset'], $sql );
		}

		// Highlight functions.
		foreach ( $functions as $function ) {
			$sql = preg_replace( '/\b' . $function . '\b/i', $colors['function'] . '$0' . $colors['reset'], $sql );
		}

		return $sql;
	}
}
