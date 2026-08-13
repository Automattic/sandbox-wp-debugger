<?php
/**
 * Timers Debugger.
 *
 * This class will collect:
 * - Total atabase time
 * - Total object cache time
 * - Total HTTP request time
 * - Early shutdown time
 * - Late shutdown time
 * - Memory usage
 */

declare(strict_types=1);

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
	 * @var float $early_timer
	 */
	public float $early_timer = 0.0;

	/**
	 * Current HTTP request start time.
	 *
	 * @var float|null $http_start_time
	 */
	private ?float $http_start_time = null;

	/**
	 * Total HTTP request time accumulated.
	 *
	 * @var float $total_http_time
	 */
	private float $total_http_time = 0;

	/**
	 * Total number of HTTP requests made.
	 *
	 * @var int $http_request_count
	 */
	private int $http_request_count = 0;

	/**
	 * Total ElasticSearch request time accumulated.
	 *
	 * @var float $total_es_time
	 */
	private float $total_es_time = 0;

	/**
	 * Total number of ElasticSearch requests made.
	 *
	 * @var int $es_request_count
	 */
	private int $es_request_count = 0;

	/**
	 * Filesystem operations data.
	 *
	 * @var array $filesystem_operations
	 */
	private array $filesystem_operations = array();

	/**
	 * Stream notification tracking data.
	 *
	 * @var array $stream_contexts
	 */
	private array $stream_contexts = array();

	/**
	 * Whether to use stream notifications for HTTP tracking instead of WordPress hooks.
	 * Set to false since global stream notifications can't be reliably set.
	 *
	 * @var bool $use_stream_for_http
	 */
	private bool $use_stream_for_http = false;

	/**
	 * Autoloading performance tracking data.
	 *
	 * @var array $autoload_data
	 */
	private array $autoload_data = array(
		'total_time'             => 0,
		'call_count'             => 0,
		'success_count'          => 0,
		'classes_loaded'         => array(),
		'failed_attempts'        => array(),
		'autoloader_performance' => array(),
	);

	/**
	 * Current autoload operation start time.
	 *
	 * @var float|null $autoload_start_time
	 */
	private ?float $autoload_start_time = null;

	/**
	 * Baseline resource usage data from getrusage().
	 *
	 * @var array|null $baseline_rusage
	 */
	private ?array $baseline_rusage = null;

	/**
	 * Baseline resource usage data from getrusage(1) for child processes.
	 *
	 * @var array|null $baseline_child_rusage
	 */
	private ?array $baseline_child_rusage = null;

	/**
	 * Time when constructor runs (for bootstrap calculation).
	 *
	 * @var float|null $constructor_time
	 */
	private ?float $constructor_time = null;

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		// Check if early bootstrap timing data is available.
		if ( isset( $GLOBALS['swpd_early_constructor_time'] ) ) {
			$this->constructor_time = $GLOBALS['swpd_early_constructor_time'];
		} else {
			// Fallback: capture constructor time for bootstrap calculation.
			$this->constructor_time = microtime( true );
		}

		// Check if early baseline resource usage data is available.
		if ( isset( $GLOBALS['swpd_baseline_rusage'] ) && function_exists( 'getrusage' ) ) {
			$this->baseline_rusage       = $GLOBALS['swpd_baseline_rusage'];
			$this->baseline_child_rusage = $GLOBALS['swpd_baseline_child_rusage'];
		} elseif ( function_exists( 'getrusage' ) ) {
			// Fallback: collect baseline resource usage data as early as possible.
			$this->baseline_rusage       = getrusage();
			$this->baseline_child_rusage = getrusage( 1 ); // RUSAGE_CHILDREN.
		}

		add_action( 'shutdown', array( $this, 'early_shutdown' ), PHP_INT_MIN );
		add_action( 'shutdown', array( $this, 'late_shutdown' ), PHP_INT_MAX );

		// Hook into HTTP request lifecycle to track timing (only if not using streams).
		if ( ! $this->use_stream_for_http ) {
			add_filter( 'pre_http_request', array( $this, 'start_http_timing' ), 1, 3 );
			add_filter( 'http_response', array( $this, 'end_http_timing' ), 9999, 3 );
		}

		/*
		 * HTTP request tracking is handled entirely by this class since
		 * WordPress functions aren't available in wp-config.php context.
		 */

		/*
		 * Note: Filesystem/stream operations tracking is disabled since global
		 * stream notifications cannot be reliably set up.
		 */

		// Check if early autoloading tracking data is available.
		if ( isset( $GLOBALS['swpd_autoload_data'] ) ) {
			$this->autoload_data       = $GLOBALS['swpd_autoload_data'];
			$this->autoload_start_time = $GLOBALS['swpd_autoload_start_time'];
		} else {
			// Fallback: set up autoloading tracking.
			$this->setup_autoload_tracking();
		}

		// Auto-install early bootstrap timer on first run.
		$this->maybe_install_early_bootstrap_timer();
	}

	/**
	 * Captures the early shutdown time.
	 *
	 * @return void
	 */
	public function early_shutdown(): void {
		$this->early_timer = microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ?? 0;
	}

	/**
	 * Captures the late shutdown time.
	 *
	 * @return void
	 */
	public function late_shutdown(): void {
		/*
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

		global $wp_object_cache, $wpdb;

		$object_cache_display = 'N/A';
		if ( isset( $wp_object_cache->time_total ) ) {
			$object_cache_display = self::human_time( $wp_object_cache->time_total );
		}

		$database_time = 0;
		if ( ! empty( $wpdb->queries ) ) {
			foreach ( $wpdb->queries as $q ) {
				$elapsed        = array_key_exists( 'elapsed', $q ) ? $q['elapsed'] : $q[1];
				$database_time += $elapsed;
			}
		}

		// Calculate resource usage deltas.
		$cpu_user_time    = 0;
		$cpu_sys_time     = 0;
		$child_cpu_time   = 0;
		$max_rss          = 0;
		$page_faults      = 0;
		$block_input      = 0;
		$block_output     = 0;
		$context_switches = 0;

		if ( function_exists( 'getrusage' ) && null !== $this->baseline_rusage ) {
			$current_rusage = getrusage();

			// Calculate deltas (times are in microseconds, convert to seconds).
			$cpu_user_time = ( $current_rusage['ru_utime.tv_sec'] - $this->baseline_rusage['ru_utime.tv_sec'] ) +
							( $current_rusage['ru_utime.tv_usec'] - $this->baseline_rusage['ru_utime.tv_usec'] ) / 1000000;
			$cpu_sys_time  = ( $current_rusage['ru_stime.tv_sec'] - $this->baseline_rusage['ru_stime.tv_sec'] ) +
							( $current_rusage['ru_stime.tv_usec'] - $this->baseline_rusage['ru_stime.tv_usec'] ) / 1000000;

			// Other metrics (these are cumulative, so current values are meaningful).
			$max_rss          = $current_rusage['ru_maxrss']; // Maximum resident set size.
			$page_faults      = $current_rusage['ru_majflt'] - $this->baseline_rusage['ru_majflt']; // Major page faults.
			$block_input      = $current_rusage['ru_inblock'] - $this->baseline_rusage['ru_inblock']; // Block input operations.
			$block_output     = $current_rusage['ru_oublock'] - $this->baseline_rusage['ru_oublock']; // Block output operations.
			$context_switches = ( $current_rusage['ru_nvcsw'] - $this->baseline_rusage['ru_nvcsw'] ) +
								( $current_rusage['ru_nivcsw'] - $this->baseline_rusage['ru_nivcsw'] ); // Voluntary + involuntary.
		}

		// Calculate child process CPU time.
		if ( function_exists( 'getrusage' ) && null !== $this->baseline_child_rusage ) {
			$current_child_rusage = getrusage( 1 ); // RUSAGE_CHILDREN.

			// Calculate deltas for child processes (times are in microseconds, convert to seconds).
			$child_cpu_time = ( $current_child_rusage['ru_utime.tv_sec'] - $this->baseline_child_rusage['ru_utime.tv_sec'] ) +
								( $current_child_rusage['ru_utime.tv_usec'] - $this->baseline_child_rusage['ru_utime.tv_usec'] ) / 1000000 +
								( $current_child_rusage['ru_stime.tv_sec'] - $this->baseline_child_rusage['ru_stime.tv_sec'] ) +
								( $current_child_rusage['ru_stime.tv_usec'] - $this->baseline_child_rusage['ru_stime.tv_usec'] ) / 1000000;
			$child_cpu_time = max( 0.0, $child_cpu_time ); // Ensure non-negative.
		}

		// Collect ElasticSearch timing data.
		$this->collect_elasticsearch_timing();

		// Calculate total walltime and bootstrap time.
		$total_walltime = $late_timer;
		$bootstrap_time = 0;
		if ( null !== $this->constructor_time ) {
			$bootstrap_time = $this->constructor_time - (float) $_SERVER['REQUEST_TIME_FLOAT'];
		}

		$shutdown_time     = $late_timer - $this->early_timer;
		$object_cache_time = 0;
		if ( 'N/A' !== $object_cache_display ) {
			// Extract numeric value from object cache display.
			preg_match( '/^([\d.]+)/', $object_cache_display, $matches );
			$object_cache_time = isset( $matches[1] ) ? (float) $matches[1] : 0;
			// Convert ms to seconds if needed.
			if ( false !== strpos( $object_cache_display, 'ms' ) ) {
				$object_cache_time = $object_cache_time / 1000;
			}
		}

		// Calculate stream operations time by type.
		$stream_times  = array(
			'http'        => 0,
			'filesystem'  => 0,
			'php_stream'  => 0,
			'data_stream' => 0,
			'other'       => 0,
		);
		$stream_counts = array(
			'http'        => 0,
			'filesystem'  => 0,
			'php_stream'  => 0,
			'data_stream' => 0,
			'other'       => 0,
		);

		foreach ( $this->filesystem_operations as $op ) {
			if ( ! empty( $op['completed'] ) && isset( $op['start_time'], $op['end_time'], $op['stream_type'] ) ) {
				$elapsed_time = $op['end_time'] - $op['start_time'];
				$stream_type  = $op['stream_type'];

				if ( isset( $stream_times[ $stream_type ] ) ) {
					$stream_times[ $stream_type ] += $elapsed_time;
					++$stream_counts[ $stream_type ];
				}
			}
		}

		// For HTTP: use stream data if enabled, otherwise use WordPress hook data.
		$http_time  = $this->use_stream_for_http ? $stream_times['http'] : $this->total_http_time;
		$http_count = $this->use_stream_for_http ? $stream_counts['http'] : $this->http_request_count;

		// Get autoloading time.
		$autoload_time  = $this->autoload_data['total_time'];
		$autoload_count = $this->autoload_data['success_count'];

		// Calculate other/unaccounted time.
		$accounted_time = $database_time + $http_time + $this->total_es_time + $bootstrap_time +
							$cpu_user_time + $object_cache_time + $cpu_sys_time + $child_cpu_time + $shutdown_time +
							$stream_times['filesystem'] + $stream_times['php_stream'] + $stream_times['data_stream'] +
							$stream_times['other'] + $autoload_time;
		$other_time     = max( 0, $total_walltime - $accounted_time );

		// Build data array for table.
		$timing_data = array();

		if ( $database_time > 0 ) {
			$db_query_count = ! empty( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
			$timing_data[]  = array( 'Database (' . $db_query_count . ')', self::human_time( $database_time ), $database_time );
		}
		if ( $http_time > 0 ) {
			$timing_data[] = array( 'HTTP Requests (' . $http_count . ')', self::human_time( $http_time ), $http_time );
		}
		if ( $this->total_es_time > 0 ) {
			$timing_data[] = array( 'ElasticSearch (' . $this->es_request_count . ')', self::human_time( $this->total_es_time ), $this->total_es_time );
		}
		if ( $bootstrap_time > 0 ) {
			$timing_data[] = array( 'Bootstrap/Early', self::human_time( $bootstrap_time ), $bootstrap_time );
		}
		if ( $cpu_user_time > 0 ) {
			$timing_data[] = array( 'CPU User (PHP)', self::human_time( $cpu_user_time ), $cpu_user_time );
		}
		if ( $object_cache_time > 0 ) {
			$timing_data[] = array( 'Object Cache', self::human_time( $object_cache_time ), $object_cache_time );
		}
		if ( $cpu_sys_time > 0 ) {
			$timing_data[] = array( 'CPU System', self::human_time( $cpu_sys_time ), $cpu_sys_time );
		}
		if ( $child_cpu_time > 0 ) {
			$timing_data[] = array( 'CPU Child Processes', self::human_time( $child_cpu_time ), $child_cpu_time );
		}
		if ( $shutdown_time > 0 ) {
			$timing_data[] = array( 'Shutdown Processing', self::human_time( $shutdown_time ), $shutdown_time );
		}
		// Add detailed stream breakdowns.
		if ( $stream_times['filesystem'] > 0 ) {
			$timing_data[] = array( 'Filesystem I/O (' . $stream_counts['filesystem'] . ')', self::human_time( $stream_times['filesystem'] ), $stream_times['filesystem'] );
		}
		if ( $stream_times['php_stream'] > 0 ) {
			$timing_data[] = array( 'PHP Streams (' . $stream_counts['php_stream'] . ')', self::human_time( $stream_times['php_stream'] ), $stream_times['php_stream'] );
		}
		if ( $stream_times['data_stream'] > 0 ) {
			$timing_data[] = array( 'Data Streams (' . $stream_counts['data_stream'] . ')', self::human_time( $stream_times['data_stream'] ), $stream_times['data_stream'] );
		}
		if ( $stream_times['other'] > 0 ) {
			$timing_data[] = array( 'Other Streams (' . $stream_counts['other'] . ')', self::human_time( $stream_times['other'] ), $stream_times['other'] );
		}
		if ( $autoload_time > 0 ) {
			$timing_data[] = array( 'Class Autoloading (' . $autoload_count . ')', self::human_time( $autoload_time ), $autoload_time );
		}
		if ( $other_time > 0 ) {
			$timing_data[] = array( 'Other/Unaccounted', self::human_time( $other_time ), $other_time );
		}

		// Sort by time descending (highest first).
		usort(
			$timing_data,
			function ( $a, $b ) {
				return $b[2] <=> $a[2];
			}
		);

		// Convert to table format with percentages.
		$table_rows   = array();
		$table_rows[] = array( 'Component', 'Time', '% Total' ); // Header.

		foreach ( $timing_data as $row ) {
			$percentage   = $total_walltime > 0 ? ( $row[2] / $total_walltime ) * 100 : 0;
			$table_rows[] = array(
				$row[0],
				$row[1],
				number_format( $percentage, 1 ) . '%',
			);
		}

		// Generate ASCII table.
		$table = $this->array_to_ascii_table( $table_rows );

		// Build autoloader performance details.
		$autoloader_details = '';
		if ( ! empty( $this->autoload_data['autoloader_performance'] ) ) {
			$autoloader_parts = array();
			foreach ( $this->autoload_data['autoloader_performance'] as $type => $stats ) {
				$autoloader_parts[] = sprintf(
					'%s: %s (%d)',
					ucfirst( $type ),
					self::human_time( $stats['time'] ),
					$stats['calls']
				);
			}
			$autoloader_details = "\nAutoloaders: " . implode( ', ', $autoloader_parts );

			// Add failed attempts if any.
			$failed_count = count( $this->autoload_data['failed_attempts'] );
			if ( $failed_count > 0 ) {
				$autoloader_details .= sprintf( ', Failed: %d', $failed_count );
			}
		}

		// Get current URL.
		$current_url = $this->get_current_url();

		// Check if we're using early bootstrap timing data.
		$timing_precision = isset( $GLOBALS['swpd_early_constructor_time'] ) ? ' [Early Bootstrap Timing]' : '';

		// Add summary information.
		$summary = sprintf(
			"URL: %s%s\nTotal Walltime: %s\nMemory: %s/%s (%s%%), I/O: %d/%d, Context Switches: %d, Page Faults: %d%s",
			$current_url,
			$timing_precision,
			self::human_time( $total_walltime ),
			size_format( $memory ),
			size_format( $memory_limit ),
			number_format( $memory_usage, 2 ),
			$block_input,
			$block_output,
			$context_switches,
			$page_faults,
			$autoloader_details
		);

		$message = "\n" . $table . "\n" . $summary;

		$this->log(
			message: $message,
			backtrace: false
		);
	}

	/**
	 * Starts timing an HTTP request.
	 *
	 * @param false|array|\WP_Error $pre  Pre-filtered value. False if the request should proceed.
	 * @param array                 $args Request arguments.
	 * @param string                $url  Request URL.
	 *
	 * @return false|array|\WP_Error The pre-filtered value unchanged.
	 */
	public function start_http_timing( false|array|\WP_Error $pre, array $args, string $url ): false|array|\WP_Error {
		// Skip async requests since they don't affect walltime.
		if ( ! empty( $args['blocking'] ) && false === $args['blocking'] ) {
			return $pre;
		}

		// Skip ElasticSearch requests - they're tracked separately.
		if ( $this->is_elasticsearch_request( $url ) ) {
			return $pre;
		}

		// Only start timing if another filter hasn't short-circuited the request.
		if ( false === $pre ) {
			$this->http_start_time = microtime( true );
		}

		return $pre;
	}

	/**
	 * Ends timing an HTTP request and accumulates the elapsed time.
	 *
	 * @param array|\WP_Error $response HTTP response or WP_Error object.
	 * @param array           $args     Request arguments.
	 * @param string          $url      Request URL.
	 *
	 * @return array|\WP_Error The response unchanged.
	 */
	public function end_http_timing( array|\WP_Error $response, array $args, string $url ): array|\WP_Error {
		// Skip async requests.
		if ( ! empty( $args['blocking'] ) && false === $args['blocking'] ) {
			return $response;
		}

		// Skip ElasticSearch requests - they're tracked separately.
		if ( $this->is_elasticsearch_request( $url ) ) {
			return $response;
		}

		// Calculate elapsed time if we have a start time.
		if ( null !== $this->http_start_time ) {
			$elapsed_time           = microtime( true ) - $this->http_start_time;
			$this->total_http_time += $elapsed_time;
			++$this->http_request_count;
			$this->http_start_time = null; // Reset for next request.
		}

		return $response;
	}

	/**
	 * Sets up the global stream notification callback for filesystem operations.
	 *
	 * @return void
	 */
	private function setup_stream_notification_callback(): void {
		// Create a default stream context with our notification callback.
		$context = stream_context_create(
			array(),
			array(
				'notification' => array( $this, 'stream_notification_callback' ),
			)
		);

		/*
		 * Note: stream_context_set_default cannot set notification callbacks.
		 * Stream notifications need to be set on individual contexts.
		 * For now, we'll disable global stream monitoring in fallback mode.
		 */
	}

	/**
	 * Stream notification callback to track filesystem operations.
	 *
	 * @param int    $notification_code Notification code constant.
	 * @param int    $severity          Severity level.
	 * @param string $message           Notification message.
	 * @param int    $message_code      Message code.
	 * @param int    $bytes_transferred Number of bytes transferred.
	 * @param int    $bytes_max         Maximum bytes expected.
	 *
	 * @return void
	 */
	public function stream_notification_callback( int $notification_code, int $severity, string $message = '', int $message_code = 0, int $bytes_transferred = 0, int $bytes_max = 0 ): void {
		$operation_id = $this->get_stream_operation_id();

		switch ( $notification_code ) {
			case STREAM_NOTIFY_RESOLVE:
				$stream_type                                  = $this->categorize_stream_operation( $message );
				$this->filesystem_operations[ $operation_id ] = array(
					'type'        => 'resolve',
					'start_time'  => microtime( true ),
					'message'     => $message,
					'bytes_total' => 0,
					'is_http'     => $this->is_http_operation( $message ),
					'stream_type' => $stream_type,
				);
				break;

			case STREAM_NOTIFY_CONNECT:
				if ( isset( $this->filesystem_operations[ $operation_id ] ) ) {
					$this->filesystem_operations[ $operation_id ]['connect_time'] = microtime( true );
				}
				break;

			case STREAM_NOTIFY_FILE_SIZE_IS:
				if ( isset( $this->filesystem_operations[ $operation_id ] ) ) {
					$this->filesystem_operations[ $operation_id ]['file_size'] = $bytes_max;
				}
				break;

			case STREAM_NOTIFY_PROGRESS:
				if ( isset( $this->filesystem_operations[ $operation_id ] ) ) {
					$this->filesystem_operations[ $operation_id ]['bytes_transferred'] = $bytes_transferred;
					$this->filesystem_operations[ $operation_id ]['bytes_total']       = max(
						$this->filesystem_operations[ $operation_id ]['bytes_total'],
						$bytes_max
					);
				}
				break;

			case STREAM_NOTIFY_COMPLETED:
				if ( isset( $this->filesystem_operations[ $operation_id ] ) ) {
					$this->filesystem_operations[ $operation_id ]['end_time']  = microtime( true );
					$this->filesystem_operations[ $operation_id ]['completed'] = true;
				}
				break;
		}
	}

	/**
	 * Generates a unique operation ID for stream operations.
	 *
	 * @return string Operation ID.
	 */
	private function get_stream_operation_id(): string {
		// Use backtrace to create a unique identifier.
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 10 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$key_parts = array();

		foreach ( $backtrace as $frame ) {
			if ( isset( $frame['file'], $frame['line'] ) ) {
				$key_parts[] = basename( $frame['file'] ) . ':' . $frame['line'];
			}
		}

		return md5( implode( '|', $key_parts ) . microtime( true ) );
	}

	/**
	 * Determines if a stream operation is HTTP-related.
	 *
	 * @param string $message The operation message/URL.
	 *
	 * @return bool True if HTTP operation.
	 */
	private function is_http_operation( string $message ): bool {
		return strpos( $message, 'http://' ) === 0 || strpos( $message, 'https://' ) === 0;
	}

	/**
	 * Determines if an HTTP request URL is an ElasticSearch request.
	 *
	 * @param string $url The request URL.
	 *
	 * @return bool True if ElasticSearch request.
	 */
	private function is_elasticsearch_request( string $url ): bool {
		// Check for VIP ElasticSearch URLs: https://es-*.vipv2.net:*/vip-*/_search.
		return (bool) preg_match( '/^https:\/\/es-.*\.vipv2\.net:\d+\/vip-.*\/_search/', $url );
	}

	/**
	 * Collects ElasticSearch timing data from the query log.
	 *
	 * @return void
	 */
	private function collect_elasticsearch_timing(): void {
		// Only collect if ElasticPress is available.
		if ( ! function_exists( 'ep_get_query_log' ) ) {
			return;
		}

		$es_queries = array_values(
			array_filter(
				ep_get_query_log(),
				function ( $query ) {
					return false !== stripos( $query['url'], '_search' );
				}
			)
		);

		foreach ( $es_queries as $query ) {
			if ( isset( $query['time_start'], $query['time_finish'] ) ) {
				$elapsed_time         = $query['time_finish'] - $query['time_start'];
				$this->total_es_time += $elapsed_time;
				++$this->es_request_count;
			}
		}
	}

	/**
	 * Categorizes a stream operation by type.
	 *
	 * @param string $message The operation message/URL.
	 *
	 * @return string Stream type category.
	 */
	private function categorize_stream_operation( string $message ): string {
		if ( $this->is_http_operation( $message ) ) {
			return 'http';
		}

		if ( strpos( $message, 'file://' ) === 0 || ( strpos( $message, '/' ) === 0 && ! strpos( $message, '://' ) ) ) {
			return 'filesystem';
		}

		if ( strpos( $message, 'php://' ) === 0 ) {
			return 'php_stream';
		}

		if ( strpos( $message, 'data://' ) === 0 ) {
			return 'data_stream';
		}

		return 'other';
	}

	/**
	 * Sets up autoloading performance tracking.
	 *
	 * @return void
	 */
	private function setup_autoload_tracking(): void {
		// Register our wrapper as the first autoloader (highest priority).
		spl_autoload_register( array( $this, 'timed_autoload_wrapper' ), true, true );
	}

	/**
	 * Autoloader wrapper that times all autoloading operations.
	 *
	 * @param string $class_name The class name to autoload.
	 *
	 * @return bool True if class was loaded, false otherwise.
	 */
	public function timed_autoload_wrapper( string $class_name ): bool {
		// Prevent infinite recursion and skip our own classes.
		if ( strpos( $class_name, 'SWPD\\' ) === 0 ) {
			return false;
		}

		// Skip if class already exists (avoid duplicate tracking).
		if ( class_exists( $class_name, false ) || interface_exists( $class_name, false ) || trait_exists( $class_name, false ) ) {
			return true;
		}

		$this->autoload_start_time = microtime( true );
		++$this->autoload_data['call_count'];

		// Get all registered autoloaders except our wrapper.
		$autoloaders  = spl_autoload_functions();
		$class_loaded = false;

		// Handle case where no other autoloaders are registered.
		if ( empty( $autoloaders ) || ( 1 === count( $autoloaders ) && array( $this, 'timed_autoload_wrapper' ) === $autoloaders[0] ) ) {
			$this->track_failed_autoload( $class_name );
			return false;
		}

		foreach ( $autoloaders as $autoloader ) {
			// Skip our own wrapper to prevent infinite recursion.
			if ( array( $this, 'timed_autoload_wrapper' ) === $autoloader ) {
				continue;
			}

			// Try this autoloader.
			if ( is_callable( $autoloader ) ) {
				// Temporarily remove our wrapper to avoid recursive calls.
				spl_autoload_unregister( array( $this, 'timed_autoload_wrapper' ) );

				try {
					// Call the autoloader (most return void, some return boolean).
					$result = call_user_func( $autoloader, $class_name );

					// Re-register our wrapper immediately.
					spl_autoload_register( array( $this, 'timed_autoload_wrapper' ), true, true );

					// Check if class was actually loaded (more reliable than return value).
					if ( class_exists( $class_name, false ) || interface_exists( $class_name, false ) || trait_exists( $class_name, false ) ) {
						$class_loaded = true;
						$this->track_successful_autoload( $class_name, $autoloader );
						break;
					}
				} catch ( \Exception $e ) {
					// Re-register our wrapper even on exception.
					spl_autoload_register( array( $this, 'timed_autoload_wrapper' ), true, true );
					// Continue to try other autoloaders.
					continue;
				} catch ( \Error $e ) {
					// Handle PHP 7+ Error objects as well.
					spl_autoload_register( array( $this, 'timed_autoload_wrapper' ), true, true );
					continue;
				}
			}
		}

		// Track the result.
		if ( ! $class_loaded ) {
			$this->track_failed_autoload( $class_name );
		}

		return $class_loaded;
	}

	/**
	 * Tracks successful autoload operations.
	 *
	 * @param string   $class_name The successfully loaded class name.
	 * @param callable $autoloader The autoloader that succeeded.
	 *
	 * @return void
	 */
	private function track_successful_autoload( string $class_name, callable $autoloader ): void {
		// Safety check for timing.
		if ( null === $this->autoload_start_time ) {
			return;
		}

		$elapsed_time = microtime( true ) - $this->autoload_start_time;

		// Sanity check: ignore extremely long operations (likely indicates measurement error).
		if ( $elapsed_time > 10.0 ) {
			return;
		}

		$this->autoload_data['total_time'] += $elapsed_time;
		++$this->autoload_data['success_count'];
		$this->autoload_data['classes_loaded'][ $class_name ] = $elapsed_time;

		// Categorize the autoloader.
		$autoloader_type = $this->categorize_autoloader( $autoloader );

		if ( ! isset( $this->autoload_data['autoloader_performance'][ $autoloader_type ] ) ) {
			$this->autoload_data['autoloader_performance'][ $autoloader_type ] = array(
				'time'  => 0,
				'calls' => 0,
			);
		}

		$this->autoload_data['autoloader_performance'][ $autoloader_type ]['time'] += $elapsed_time;
		++$this->autoload_data['autoloader_performance'][ $autoloader_type ]['calls'];

		// Reset timing.
		$this->autoload_start_time = null;
	}

	/**
	 * Tracks failed autoload attempts.
	 *
	 * @param string $class_name The class that failed to load.
	 *
	 * @return void
	 */
	private function track_failed_autoload( string $class_name ): void {
		// Safety check for timing.
		if ( null === $this->autoload_start_time ) {
			return;
		}

		$elapsed_time = microtime( true ) - $this->autoload_start_time;

		// Sanity check: ignore extremely long operations.
		if ( $elapsed_time > 10.0 ) {
			return;
		}

		$this->autoload_data['total_time']                    += $elapsed_time;
		$this->autoload_data['failed_attempts'][ $class_name ] = $elapsed_time;

		// Reset timing.
		$this->autoload_start_time = null;
	}

	/**
	 * Categorizes an autoloader by type.
	 *
	 * @param callable $autoloader The autoloader callable.
	 *
	 * @return string Autoloader category.
	 */
	private function categorize_autoloader( callable $autoloader ): string {
		if ( is_array( $autoloader ) && isset( $autoloader[0] ) ) {
			$class_name = is_object( $autoloader[0] ) ? get_class( $autoloader[0] ) : $autoloader[0];

			if ( strpos( $class_name, 'Composer\\Autoload\\ClassLoader' ) !== false ) {
				return 'composer';
			}

			if ( strpos( $class_name, 'WP_' ) === 0 || strpos( $class_name, 'WordPress' ) !== false ) {
				return 'WordPress';
			}
		}

		if ( is_string( $autoloader ) ) {
			if ( strpos( $autoloader, 'wp_' ) === 0 || strpos( $autoloader, 'WordPress' ) !== false ) {
				return 'WordPress';
			}
		}

		return 'custom';
	}

	/**
	 * Gets the current URL from PHP superglobals.
	 *
	 * @return string The current URL or 'CLI' if running from command line.
	 */
	private function get_current_url(): string {
		// Check if running from CLI.
		if ( php_sapi_name() === 'cli' || ! isset( $_SERVER['HTTP_HOST'] ) ) {
			return 'CLI';
		}

		// Determine protocol.
		$protocol = 'http';
		if ( ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ||
			( ! empty( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] ) ||
			( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] )
		) {
			$protocol = 'https';
		}

		// Build URL.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return $protocol . '://' . $host . $uri;
	}

	/**
	 * Automatically installs the early bootstrap timer to wp-config.php on first run.
	 *
	 * @return void
	 */
	private function maybe_install_early_bootstrap_timer(): void {
		// Skip if already using early bootstrap timing.
		if ( isset( $GLOBALS['swpd_early_constructor_time'] ) ) {
			$this->log(
				message: 'Early bootstrap timer already active - skipping installation',
				backtrace: false
			);
			return;
		}

		// Find a writable wp-config.php file, checking VIP Go sandbox locations.
		$config_candidates = array(
			ABSPATH . 'wp-config.php',
			'/chroot/wp-config.php',
			'/chroot' . ABSPATH . 'wp-config.php',
			'/client-repo/vip-config/vip-config.php',
		);

		$wp_config_path  = null;
		$attempted_paths = array();

		foreach ( $config_candidates as $candidate_path ) {
			$attempted_paths[] = $candidate_path;
			if ( file_exists( $candidate_path ) && is_writable( $candidate_path ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable -- This sandbox debugger explicitly installs into a known config file.
				$wp_config_path = $candidate_path;
				$this->log(
					message: 'Found writable config file at: ' . $wp_config_path,
					backtrace: false
				);
				break;
			}
		}

		if ( null === $wp_config_path ) {
			$bash_oneliner = "sed -i '/<?php/a\\\\n// Auto-installed by Sandbox WP Debugger Timers class for precise performance tracking\\nif ( file_exists(  '\"'\"'/var/www/wp-content/mu-plugins/00-sandbox-helper/sandbox-wp-debugger/early-bootstrap-timer.php'\"'\"' ) ) {\\n\\trequire_once  '\"'\"'/var/www/wp-content/mu-plugins/00-sandbox-helper/sandbox-wp-debugger/early-bootstrap-timer.php'\"'\"';\\n}' /var/www/wp-config.php";

			$this->log(
				message: 'Cannot install early bootstrap timer: no writable config file found. Attempted paths: ' . implode( ', ', $attempted_paths ) . "\n\nTo install manually, run this bash command:\n" . $bash_oneliner,
				backtrace: false
			);
			return;
		}

		// Read current wp-config.php content.
		$wp_config_content = file_get_contents( $wp_config_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- This is a validated local config path.
		if ( false === $wp_config_content ) {
			$this->log(
				message: 'Cannot install early bootstrap timer: failed to read wp-config.php',
				backtrace: false
			);
			return;
		}

		// Check if early bootstrap timer is already installed.
		if ( strpos( $wp_config_content, 'early-bootstrap-timer.php' ) !== false ) {
			$this->log(
				message: 'Early bootstrap timer already installed in wp-config.php - skipping',
				backtrace: false
			);
			return;
		}

		/*
		 * Find the right place to insert the early bootstrap timer.
		 * Look for the end of the database configuration section.
		 */
		$patterns_to_find = array(
			'/\/\*\*#@-\*\//i',  // End of salts section.
			'/\$table_prefix\s*=/i',  // Table prefix line.
			'/(define\s*\(\s*["\']WP_DEBUG["\']|\$wp_debug)/i',  // WP_DEBUG definition.
		);

		$insert_position = false;
		foreach ( $patterns_to_find as $pattern ) {
			if ( preg_match( $pattern, $wp_config_content, $matches, PREG_OFFSET_CAPTURE ) ) {
				// Find the end of the line.
				$line_end        = strpos( $wp_config_content, "\n", $matches[0][1] );
				$insert_position = false !== $line_end ? $line_end + 1 : $matches[0][1] + strlen( $matches[0][0] );
				break;
			}
		}

		// If no good position found, insert after opening PHP tag.
		if ( false === $insert_position ) {
			if ( preg_match( '/<\?php\s*/', $wp_config_content, $matches, PREG_OFFSET_CAPTURE ) ) {
				$line_end        = strpos( $wp_config_content, "\n", $matches[0][1] );
				$insert_position = false !== $line_end ? $line_end + 1 : $matches[0][1] + strlen( $matches[0][0] );
			}
		}

		// Still no position? Give up.
		if ( false === $insert_position ) {
			$this->log(
				message: 'Cannot install early bootstrap timer: could not find suitable insertion point in wp-config.php',
				backtrace: false
			);
			return;
		}

		// Build the code to insert (adjust path based on config file location).
		$is_vip_config    = strpos( $wp_config_path, 'vip-config.php' ) !== false;
		$early_timer_code = "\n// Auto-installed by Sandbox WP Debugger Timers class for precise performance tracking\n";

		if ( $is_vip_config ) {
			// For vip-config.php, use absolute path since WP_CONTENT_DIR may not be defined yet.
			$early_timer_code .= "if ( file_exists( '/var/www/wp-content/mu-plugins/00-sandbox-helper/sandbox-wp-debugger/early-bootstrap-timer.php' ) ) {\n";
			$early_timer_code .= "\trequire_once '/var/www/wp-content/mu-plugins/00-sandbox-helper/sandbox-wp-debugger/early-bootstrap-timer.php';\n";
		} else {
			// For wp-config.php, use WP_CONTENT_DIR constant.
			$early_timer_code .= "if ( file_exists( '/var/www/wp-content/mu-plugins/00-sandbox-helper/sandbox-wp-debugger/early-bootstrap-timer.php' ) ) {\n";
			$early_timer_code .= "\trequire_once '/var/www/wp-content/mu-plugins/00-sandbox-helper/sandbox-wp-debugger/early-bootstrap-timer.php';\n";
		}
		$early_timer_code .= "}\n\n";

		// Insert the code.
		$new_content = substr_replace( $wp_config_content, $early_timer_code, $insert_position, 0 );

		// Write the modified content back.
		$bytes_written = file_put_contents( $wp_config_path, $new_content, LOCK_EX ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- This sandbox debugger explicitly installs into a known config file.
		if ( false !== $bytes_written ) {
			$this->log(
				message: sprintf(
					'Successfully auto-installed early bootstrap timer to %s (%d bytes written). Restart/refresh required to take effect.',
					basename( $wp_config_path ),
					$bytes_written
				),
				backtrace: false
			);
		} else {
			$this->log(
				message: 'Failed to write early bootstrap timer installation to ' . basename( $wp_config_path ) . ' - check file permissions',
				backtrace: false
			);
		}
	}

	/**
	 * Converts a given number of seconds into a human-readable time format.
	 *
	 * @param float $seconds The number of seconds to convert.
	 *
	 * @return string The human-readable time format.
	 */
	public static function human_time( float $seconds ): string {
		if ( $seconds >= 1 ) {
			return number_format( $seconds, 3 ) . 's';
		} else {
			return number_format( $seconds * 1000, 3 ) . 'ms';
		}
	}
}
