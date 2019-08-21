<?php

class SlowQueries {

	public function render_sql_query_summary() {
		global $wpdb;
		$query_types = array();
		$query_type_counts = array();
		if ( is_array($wpdb->queries) ) {
			$count = count($wpdb->queries);
			for ( $i = 0; $i < $count; ++$i ) {
				$query = array_key_exists( 'query', $wpdb->queries[$i] ) ? $wpdb->queries[$i]['query'] : $wpdb->queries[$i][0];
				$query = trim( preg_replace( '#connection: dbh_.+$#','', $query ) );
				$query = preg_replace( "#\s+#", ' ', $query );
				$query = str_replace( '\"', '', $query );
				$query = str_replace( "\'", '', $query );
				$query = preg_replace( '#wp_\d+_#', 'wp_?_', $query );
				$query = preg_replace( "#'[^']*'#", "'?'", $query );
				$query = preg_replace( '#"[^"]*"#', "'?'", $query );
				$query = preg_replace( "#in ?\([^)]*\)#i", 'in(?)', $query);
				$query = preg_replace( "#= ?\d+ ?#", "= ? ", $query );
				$query = preg_replace( "#\d+(, ?)?#", '?\1', $query);
				$query = preg_replace( "#/\*.*\*/$#", '', $query );

				$query = preg_replace( "#\s+#", ' ', $query );
				if ( !isset( $query_types[$query] ) )
					$query_types[$query] = 0;
				if ( !isset( $query_type_counts[$query] ) )
					$query_type_counts[$query] = 0;
				$query_type_counts[$query]++;
				$query_types[$query] += array_key_exists( 'elapsed', $wpdb->queries[$i] ) ? $wpdb->queries[$i]['elapsed'] : $wpdb->queries[$i][1];
			}
		}

		arsort( $query_types );
		$out = '';
		$count = 0;
		$max_time_len = 0;
		foreach( $query_types as $q => $t ) {
			$count++;
			$max_time_len = max($max_time_len, strlen(sprintf('%0.2f', $t * 1000)));
			$out .= sprintf(
				"%s queries for %sms » %s\r\n",
				str_pad( $query_type_counts[$q], 5, ' ', STR_PAD_LEFT ),
				str_pad( sprintf('%0.2f', $t * 1000), $max_time_len, ' ', STR_PAD_LEFT ),
				$q
			);
		}
		return $out;
	}

	public function render_sql_queries() {
		global $wpdb, $wp_object_cache, $timestart;

		$out = '';
		$total_time = 0;

		if ( !empty($wpdb->queries) ) {

			$counter = 0;

			foreach ( $wpdb->queries as $q ) {
				unset($query, $elapsed, $affected_rows, $host, $microtime, $debug, $dbhname, $dataset, $callback_result, $connection);
				extract($q);

				if ( false === isset( $query ) ) {
					$query = $q[0];
				}
				if ( false === isset( $elapsed ) ){
					$elapsed = $q[1];
				}
				if ( false === isset( $debug ) ) {
					$debug = $q[2];
				}

				$total_time += $elapsed;
				if ( ++$counter > 500 ) {
					continue;
				}

				// ts is the absolute time at which each query was executed
				if ( true === isset( $microtime ) ) {
					$ts = explode( ' ', $microtime );
					$ts = $ts[0] + $ts[1];
				} else {
					$ts = 0;
				}

				$query = esc_html($query);

				// $dbhname, $host, $port, $name, $tcp, $elapsed
				if ( isset($connection['elapsed']) ) {
					$connected = "Connected {$connection['dbhname']} to {$connection['host']}:{$connection['port']} ({$connection['name']}) in ".sprintf('%0.2f', 1000*$connection['elapsed'])."ms";
				} else if ( true === isset( $connection ) ) {
					$connected = "Reused connection to {$connection['dbhname']} ({$connection['name']})";
				} else {
					$connected = '';
				}

				$debug = wp_strip_all_tags( $debug );
				$out .= "$query \n $connected $debug #{$counter} (" . number_format(sprintf('%0.1f', $elapsed * 1000), 1, '.', ',') . "ms @ " . sprintf( '%0.2f', 1000 * ( $ts - $timestart ) ) . "ms)\n\n";
			}
		}

		$num_queries = '';
		if ( $wpdb->num_queries ) {
			$num_queries = 'Total Queries:' . number_format( $wpdb->num_queries ) . " | ";
		}
		$query_time = 'Total query time:' . number_format(sprintf('%0.1f', $total_time * 1000), 1) . "ms | ";
		$memory_usage = 'Peak Memory Used:' . number_format( memory_get_peak_usage( ) ) . " bytes | ";
		if ( true === property_exists( $wp_object_cache, 'time_total' ) ) {
			$memcache_time = 'Total memcache query time:' .
			                 number_format( sprintf( '%0.1f', $wp_object_cache->time_total * 1000 ), 1, '.', ',' ) . "ms \n\n";
		} else {
			$memcache_time = '';
		}

		$out = $num_queries . $query_time . $memory_usage . $memcache_time . $out;

		$out = apply_filters( 'swpdb-render-sql-queries-output', $out );

		return $out;
	}

	/**
	 * This is a copy of core's wpdb::get_table_from_query which is protected and can't otherwise be used
	 *
	 * @see https://core.trac.wordpress.org/browser/tags/4.7/src/wp-includes/wp-db.php#L3022
	 */
	public function get_table_from_query( $query ) {
		// Remove characters that can legally trail the table name.
		$query = rtrim( $query, ';/-#' );

		// Allow (select...) union [...] style queries. Use the first query's table name.
		$query = ltrim( $query, "\r\n\t (" );

		// Strip everything between parentheses except nested selects.
		$query = preg_replace( '/\((?!\s*select)[^(]*?\)/is', '()', $query );

		// Quickly match most common queries.
		if ( preg_match( '/^\s*(?:'
		                 . 'SELECT.*?\s+FROM'
		                 . '|INSERT(?:\s+LOW_PRIORITY|\s+DELAYED|\s+HIGH_PRIORITY)?(?:\s+IGNORE)?(?:\s+INTO)?'
		                 . '|REPLACE(?:\s+LOW_PRIORITY|\s+DELAYED)?(?:\s+INTO)?'
		                 . '|UPDATE(?:\s+LOW_PRIORITY)?(?:\s+IGNORE)?'
		                 . '|DELETE(?:\s+LOW_PRIORITY|\s+QUICK|\s+IGNORE)*(?:.+?FROM)?'
		                 . ')\s+((?:[0-9a-zA-Z$_.`-]|[\xC2-\xDF][\x80-\xBF])+)/is', $query, $maybe ) ) {
			return str_replace( '`', '', $maybe[1] );
		}

		// SHOW TABLE STATUS and SHOW TABLES WHERE Name = 'wp_posts'
		if ( preg_match( '/^\s*SHOW\s+(?:TABLE\s+STATUS|(?:FULL\s+)?TABLES).+WHERE\s+Name\s*=\s*("|\')((?:[0-9a-zA-Z$_.-]|[\xC2-\xDF][\x80-\xBF])+)\\1/is', $query, $maybe ) ) {
			return $maybe[2];
		}

		// SHOW TABLE STATUS LIKE and SHOW TABLES LIKE 'wp\_123\_%'
		// This quoted LIKE operand seldom holds a full table name.
		// It is usually a pattern for matching a prefix so we just
		// strip the trailing % and unescape the _ to get 'wp_123_'
		// which drop-ins can use for routing these SQL statements.
		if ( preg_match( '/^\s*SHOW\s+(?:TABLE\s+STATUS|(?:FULL\s+)?TABLES)\s+(?:WHERE\s+Name\s+)?LIKE\s*("|\')((?:[\\\\0-9a-zA-Z$_.-]|[\xC2-\xDF][\x80-\xBF])+)%?\\1/is', $query, $maybe ) ) {
			return str_replace( '\\_', '_', $maybe[2] );
		}

		// Big pattern for the rest of the table-related queries.
		if ( preg_match( '/^\s*(?:'
		                 . '(?:EXPLAIN\s+(?:EXTENDED\s+)?)?SELECT.*?\s+FROM'
		                 . '|DESCRIBE|DESC|EXPLAIN|HANDLER'
		                 . '|(?:LOCK|UNLOCK)\s+TABLE(?:S)?'
		                 . '|(?:RENAME|OPTIMIZE|BACKUP|RESTORE|CHECK|CHECKSUM|ANALYZE|REPAIR).*\s+TABLE'
		                 . '|TRUNCATE(?:\s+TABLE)?'
		                 . '|CREATE(?:\s+TEMPORARY)?\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?'
		                 . '|ALTER(?:\s+IGNORE)?\s+TABLE'
		                 . '|DROP\s+TABLE(?:\s+IF\s+EXISTS)?'
		                 . '|CREATE(?:\s+\w+)?\s+INDEX.*\s+ON'
		                 . '|DROP\s+INDEX.*\s+ON'
		                 . '|LOAD\s+DATA.*INFILE.*INTO\s+TABLE'
		                 . '|(?:GRANT|REVOKE).*ON\s+TABLE'
		                 . '|SHOW\s+(?:.*FROM|.*TABLE)'
		                 . ')\s+\(*\s*((?:[0-9a-zA-Z$_.`-]|[\xC2-\xDF][\x80-\xBF])+)\s*\)*/is', $query, $maybe ) ) {
			return str_replace( '`', '', $maybe[1] );
		}

		return false;
	}

	public function log() {
		swpd_log( $this->debugger_name, $this->render_sql_queries() . 'Query Summary: ' . "\n" . $this->render_sql_query_summary(), null, array( 'Post' => $this->get_post_id() ), false );
	}

	private function get_post_id() {
		if ( isset( $_GET['post'] ) ) {
			$post_id = (int) $_GET['post'];
		} elseif ( isset( $_POST['post_ID'] ) ) {
			$post_id = (int) $_POST['post_ID'];
		} else {
			$post_id = 0;
		}
		return $post_id;
	}
}