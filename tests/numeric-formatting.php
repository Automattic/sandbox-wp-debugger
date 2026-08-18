<?php
/**
 * Regression tests for numeric values received as strings from WordPress instrumentation.
 */

declare(strict_types=1);

function add_action(): void {
}

function wp_parse_args( array $args, array $defaults ): array {
	return array_merge( $defaults, $args );
}

function wp_strip_all_tags( string $value ): string {
	return strip_tags( $value );
}

function apply_filters( string $hook_name, string $value ): string {
	return $value;
}

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}

function size_format( int|float|string $bytes, int $decimals = 0 ): string {
	return number_format( (float) $bytes, $decimals ) . ' B';
}

function number_format_i18n( int|float $number, int $decimals = 0 ): string {
	return number_format( $number, $decimals );
}

function swpd_test_assert_query_order( string $output, array $table_names, string $sort_mode ): void {
	$last_position = -1;
	foreach ( $table_names as $table_name ) {
		$position = strpos( $output, $table_name );
		if ( false === $position || $position <= $last_position ) {
			throw new RuntimeException( "Unexpected {$sort_mode} query order." );
		}
		$last_position = $position;
	}
}

require_once dirname( __DIR__ ) . '/class-base.php';
require_once dirname( __DIR__ ) . '/modules/class-memcache.php';
require_once dirname( __DIR__ ) . '/modules/class-slow-queries.php';

$wpdb = (object) array(
	'num_queries' => 1,
	'queries'     => array(
		array(
			'query'     => 'SELECT 1',
			'elapsed'   => '0.00004',
			'timestamp' => '0 0',
			'debug'     => '',
		),
	),
);

$wp_object_cache = (object) array(
	'time_total' => '0.00004',
);
$timestart       = 0.0;

$slow_queries = new SWPD\Slow_Queries(
	array(
		'debug' => true,
	)
);
$query_output = $slow_queries->render_sql_queries();

if ( ! str_contains( $query_output, 'Total query time:0.0ms' ) ) {
	throw new RuntimeException( 'Slow query time was not formatted as expected.' );
}

if ( ! str_contains( $query_output, 'Total memcache query time:0.0ms' ) ) {
	throw new RuntimeException( 'Memcache time in the slow query report was not formatted as expected.' );
}

$memcache     = new SWPD\Memcache();
$group_output = $memcache->get_group_ops_line( 0, array( 'get', 'key', 1, '0.00004', '' ) );

if ( ! str_contains( $group_output, '(0.0 ms)' ) ) {
	throw new RuntimeException( 'Memcache operation time was not formatted as expected.' );
}

$wpdb->num_queries = 3;
$wpdb->queries     = array(
	array(
		'query'      => 'SELECT * FROM charlie_table',
		'elapsed'    => '0.001',
		'debug'      => 'beta_trace',
		'connection' => array( 'dbhname' => 'beta_connection', 'name' => 'primary' ),
	),
	array(
		'query'      => 'SELECT * FROM alpha_table',
		'elapsed'    => '0.003',
		'debug'      => 'charlie_trace',
		'connection' => array( 'dbhname' => 'charlie_connection', 'name' => 'primary' ),
	),
	array(
		'query'      => 'SELECT * FROM bravo_table',
		'elapsed'    => '0.002',
		'debug'      => 'alpha_trace',
		'connection' => array( 'dbhname' => 'alpha_connection', 'name' => 'primary' ),
	),
);

$sort_expectations = array(
	'execution'  => array( 'charlie_table', 'alpha_table', 'bravo_table' ),
	'time'       => array( 'alpha_table', 'bravo_table', 'charlie_table' ),
	'query'      => array( 'alpha_table', 'bravo_table', 'charlie_table' ),
	'backtrace'  => array( 'bravo_table', 'charlie_table', 'alpha_table' ),
	'connection' => array( 'bravo_table', 'charlie_table', 'alpha_table' ),
);

foreach ( $sort_expectations as $sort_mode => $expected_order ) {
	$slow_queries = new SWPD\Slow_Queries( array( 'sort' => $sort_mode ) );
	swpd_test_assert_query_order( $slow_queries->render_sql_queries(), $expected_order, $sort_mode );
}

$slow_queries = new SWPD\Slow_Queries( array( 'sort' => 'invalid' ) );
swpd_test_assert_query_order( $slow_queries->render_sql_queries(), $sort_expectations['execution'], 'fallback' );

echo "Debugger regression tests passed.\n";
