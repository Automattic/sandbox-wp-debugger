<?php
/**
 * Sandbox WP Debugger Helper to output data about remote requests.
 */

namespace SWPD;

/**
 * SWPD\Remote_Requests Class.
 */
class Remote_Requests extends Base {
	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'Remote Requests';

	/**
	 * An array of timers and timer data.
	 *
	 * @var array
	 */
	public array $timers = array();

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		add_filter( 'http_response', array( $this, 'http_response' ), 5, 3 );
	}

	public function http_response( $response, $parsed_args, $url ) {
		$log = array();

		$info = '== Basic Info ==' . PHP_EOL;
		$info .= $this->array_to_text_list(
			array(
				'URL' => $url,
				'Method' => $parsed_args['method'],
				'Response Code' => $response['response']['code'],
				'Bytes Received' => strlen( $response['http_response']->get_data() ),
			)
		) . PHP_EOL;

		$log[] = $info;

		$parsed_parsed_args = $parsed_args;
		unset( $parsed_parsed_args['headers'], $parsed_parsed_args['cookies'] );
		$parsed_parsed_args = array_filter( $parsed_parsed_args ); // Remove empty items.

		$args = '== Args ==' . PHP_EOL;
		$args .= $this->array_to_text_list( (array) $parsed_parsed_args ) . PHP_EOL;

		$log[] = $args;

		if ( ! empty( $response['http_response'] ) ) {
			$headers = '== Headers ==' . PHP_EOL;
			$headers .= $this->array_to_text_list( (array) $response['http_response']->get_headers()->getAll() ) . PHP_EOL;

			$log[] = $headers;
		}

		$this->log( 'Remote Request', $log );

		return $response;
	}

	public function array_to_text_list( $arr ) {
		// Find the longest key length
		$max_key_length = 0;
		foreach ($arr as $key => $value) {
			$max_key_length = max($max_key_length, strlen($key));
		}

		// Convert array to the desired format
		$result = '';
		foreach ($arr as $key => $value) {
			$result .= sprintf("%-{$max_key_length}s: %s\n", $key, $value);
		}

		return $result;
	}
}
