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