<?php
/**
 * ES Queries Debugger.
 */

namespace SWPD;

/**
 * SWPD\ES_Queries Class.
 */
class ES_Queries extends Base {
	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'Elasticsearch Queries';

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
			'debug' => false,
			'limit' => -1,
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
		$message = '';
		$queries = array_values(
			array_filter(
				ep_get_query_log(),
				function ( $query ) {
					return false !== stripos( $query['url'], '_search' );
				}
			)
		);

		$mapped_queries = array_map(
			function ( $query ) {
				// The happy path: we sanitize query response, and if a body is empty we populate an empty object.
				if ( is_array( $query['request'] ) ) {
					$query['request']['body'] = \Automattic\VIP\Search\Dev_Tools\sanitize_query_response( json_decode( $query['request']['body'] ) ?? (object) array() );
					// Network error.
				} elseif ( is_wp_error( $query['request'] ) ) {
					$query['request'] = array(
						'body'     => array(
							'took'  => intval( ( $query['time_finish'] - $query['time_start'] ) * 1000 ),
							'error' => $query['request'],
						),
						'response' => array(
							'code'    => 'timeout',
							'message' => 'Request failure',
						),
					);
					// Handle any other weirdness by including catch all.
				} else {
					$query['request'] = array(
						'body'     => array(
							'took'  => intval( ( $query['time_finish'] - $query['time_start'] ) * 1000 ),
							'error' => 'Unknown error, please contact VIP for further investigation',
						),
						'response' => array(
							'code'    => 'unknown',
							'message' => 'Request failure',
						),
					);
				}

				$query['args']['body'] = json_decode( $query['args']['body'], true );
				$query['args']['body'] = array_merge( array( 'profile' => false ), $query['args']['body'] );

				/**
				 * We only want to show booleans (either true or false) or other values that would cast to boolean true (non-empty strings, arrays and non-0 ints),
				 * Because the full list of core query arguments is > 60 elements long and it doesn't look good on the frontend.
				 */
				$query['query_args'] = array_filter(
					$query['query_args'],
					function ( $v ) {
						return is_bool( $v ) || ( ! is_bool( $v ) && $v );
					}
				);
				return $query;
			},
			$queries
		);

		$search_instance = \Automattic\VIP\Search\Search::instance();
		$is_rate_limited = \Automattic\VIP\Search\Search::is_rate_limited() || $search_instance->queue->is_indexing_ratelimited();
		if ( $is_rate_limited ) {
			$rate_limit   = array( 'search: ' . ( \Automattic\VIP\Search\Search::is_rate_limited() ? sprintf( 'yes (%d of %d)', \Automattic\VIP\Search\Search::get_query_count(), \Automattic\VIP\Search\Search::$max_query_count ) : 'no' ) );
			$rate_limit[] = 'indexing: ' . ( $search_instance->queue->is_indexing_ratelimited() ? 'yes' : 'no' );
		} else {
			$rate_limit = 'no';
		}

		$concurrent_requests = 0;
		if ( is_callable( array( $search_instance->concurrency_limiter, 'get_backend' ) ) ) {
			if ( isset( $search_instance->concurrency_limiter ) && is_callable( $search_instance->concurrency_limiter->get_backend() ) ) {
				$concurrent_requests = $search_instance->concurrency_limiter->get_backend()->get_value();
			}
		}

		$indexable_post_types = array_values( \ElasticPress\Indexables::factory()->get( 'post' )->get_indexable_post_types() );
		$indexable_post_stati = array_values( \ElasticPress\Indexables::factory()->get( 'post' )->get_indexable_post_status() );
		$meta_key_allow_list  = \Automattic\VIP\Search\Dev_Tools\get_meta_for_all_indexable_post_types();

		$message .= 'ES Query Count: ' . count( $mapped_queries ) . PHP_EOL;
		$message .= 'Rate Limited: ' . $rate_limit . PHP_EOL;
		$message .= 'Concurrent Requests: ' . $concurrent_requests . PHP_EOL;
		$message .= 'Indexable Post Types: ' . implode( ', ', $indexable_post_types ) . PHP_EOL;
		$message .= 'Indexable Post Stati: ' . implode( ', ', $indexable_post_stati ) . PHP_EOL;
		$message .= 'Meta Key Allow List: ' . implode( ', ', $meta_key_allow_list ) . PHP_EOL;
		$message .= PHP_EOL;

		$query_count = count( $mapped_queries );
		for ( $i = 0; $i < $query_count; ++$i ) {
			$query    = wp_json_encode( $mapped_queries[ $i ]['args']['body'] );
			$time_ms  = round( ( $mapped_queries[ $i ]['time_finish'] - $mapped_queries[ $i ]['time_start'] ) * 1000, 2 );
			$message .= sprintf( 'Query %d: %s', $i + 1, self::highlight_json( $query, false ) ) . PHP_EOL;
			$message .= sprintf( 'Time %d: %s ms', $i + 1, $time_ms ) . PHP_EOL;
			$message .= sprintf( 'Hits %d: %s', $i + 1, $mapped_queries[ $i ]['request']['body']->hits->total->value ?? 'UNKNOWN' ) . PHP_EOL;
			$message .= sprintf( 'Backtrace %d: %s', $i + 1, self::highlight_backtrace( implode( ',', $mapped_queries[ $i ]['backtrace'] ) ) ) . PHP_EOL;
		}

		if ( $query_count > 0 ) {
			$this->log(
				message: $message,
			);
		}
	}

	/**
	 * Highlights a JSON string with ANSI color codes for terminal output.
	 *
	 * This function takes a JSON string, formats it with pretty print and
	 * unescaped slashes, and then applies ANSI color codes to different
	 * parts of the JSON for better readability in terminal.
	 *
	 * @param string $json The JSON string to be highlighted.
	 * @param bool   $pretty_print Whether to pretty print the JSON or not.
	 *
	 * @return string The highlighted JSON string with ANSI color codes.
	 */
	public function highlight_json( string $json, bool $pretty_print = true ): string {
		$colors = array(
			'brace'   => "\033[1;34m", // Blue for braces and brackets.
			'key'     => "\033[1;36m", // Cyan for keys.
			'string'  => "\033[1;33m", // Yellow for strings.
			'number'  => "\033[1;32m", // Green for numbers.
			'boolean' => "\033[1;35m", // Magenta for booleans.
			'null'    => "\033[1;31m", // Red for null.
			'reset'   => "\033[0m",
		);

		// Encode to ensure the JSON is valid, then decode it to highlight each part.
		if ( true === $pretty_print ) {
			$json = wp_json_encode( json_decode( $json, true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		} else {
			$json = wp_json_encode( json_decode( $json, true ) );
		}

		// Highlight braces and brackets.
		$json = preg_replace( '/([\{\}\[\]])/', $colors['brace'] . '$1' . $colors['reset'], $json );

		// Highlight keys (property names).
		$json = preg_replace( '/"([^"]+)"\s*:/', $colors['key'] . '"$1"' . $colors['reset'] . ':', $json );

		// Highlight strings (values).
		$json = preg_replace( '/:\s*"([^"]*)"/', ': ' . $colors['string'] . '"$1"' . $colors['reset'], $json );

		// Highlight numbers.
		$json = preg_replace( '/:\s*([0-9\.\-e]+)/i', ': ' . $colors['number'] . '$1' . $colors['reset'], $json );

		// Highlight booleans.
		$json = preg_replace( '/\b(true|false)\b/i', $colors['boolean'] . '$1' . $colors['reset'], $json );

		// Highlight null values.
		$json = preg_replace( '/\bnull\b/i', $colors['null'] . 'null' . $colors['reset'], $json );

		return $json;
	}

	/**
	 * Highlights specific parts of a backtrace string with ANSI color codes.
	 *
	 * This function applies different colors to class names, method names,
	 * function names, symbols (::, ->), and parameters in quotes within a
	 * backtrace string.
	 *
	 * @param string $backtrace The backtrace string to be highlighted.
	 * @return string The highlighted backtrace string.
	 */
	public function highlight_backtrace( string $backtrace ): string {
		$colors = array(
			'class'    => "\033[1;34m", // Blue for class names.
			'method'   => "\033[1;33m", // Yellow for method names.
			'function' => "\033[1;32m", // Green for function names.
			'symbol'   => "\033[1;36m", // Cyan for symbols.
			'param'    => "\033[1;35m", // Magenta for parameters in quotes.
			'reset'    => "\033[0m",    // Reset to default color.
		);

		// Split the backtrace string into parts by commas for individual processing.
		$parts = explode( ',', $backtrace );
		foreach ( $parts as &$part ) {
			// Process classes and methods with symbols (:: and ->).
			$part = preg_replace(
				'/([a-zA-Z_\\\]+)(->|::)([a-zA-Z_]+)/',
				"{$colors['class']}\\1{$colors['reset']}{$colors['symbol']}\\2{$colors['reset']}{$colors['method']}\\3{$colors['reset']}",
				$part
			);

			// Process standalone functions.
			$part = preg_replace(
				'/\b([a-zA-Z_]+)\b(?=\()/',
				"{$colors['function']}\\1{$colors['reset']}",
				$part
			);

			// Process quoted parameters.
			$part = preg_replace(
				"/'([^']*)'/",
				"{$colors['param']}'\\1'{$colors['reset']}",
				$part
			);
		}

		// Join all parts back together with commas and add a double reset at the end for consistency.
		return implode( ',', $parts ) . "\n{$colors['reset']}";
	}
}
