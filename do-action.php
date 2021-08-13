<?php
/**/
class SWPD_do_action {

	private $action_to_debug = null;

	private $callback = null;

	public function __construct( $filter, $callback ) {
		$this->action_to_debug = $filter;
		$this->callback = $callback;
		add_filter( 'all', array( $this, 'filter_all' ), 10, 2 );
	}

	public function filter_all( $action, $value = null ) {
		if ( current_filter() === $this->action_to_debug ) {
			$this->add_debugging( $value );
		}
		return $value;
	}

	public function add_debugging( $value ) {
		global $wp_filter;
		if ( true === array_key_exists( $this->action_to_debug, $wp_filter ) ) {
			$filters = $wp_filter[$this->action_to_debug]->callbacks;
			$wp_filter[$this->action_to_debug]->callbacks = array();
			$i = 0;
			foreach( $filters as $priority => $thes_ ) {
				foreach( $thes_ as $idx => $the_ ) {
					$wp_filter[$this->action_to_debug]->callbacks[$priority][$idx] = $the_;
					$wp_filter[$this->action_to_debug]->callbacks[$priority][ _wp_filter_build_unique_id( $this->action_to_debug, array( $this, 'debug' ), $priority ) . $i++ ] = array(
						'function' => function( $value ) use ( $idx, $priority, $the_ ) { $this->debug( $value, $idx, $the_, $priority ); return $value; },
						'accepted_args' => 1,
					);
				}
			}
		}
	}

	public function debug( $value, $idx, $the_, $priority ) {
			($this->callback)( $value, $idx, $the_, $priority );
			return $value;
	}

	public function defined_in( $the_ ) {
		$return = 'N/A';
		// Handle function and closures.
		if ( true === is_string( $the_['function'] ) || true === is_a( $the_['function'], '\Closure' ) ) {
			$the_reflection = new ReflectionFunction( $the_['function'] );
			$return = $the_reflection->getFileName() . '#L' . $the_reflection->getStartLine();
		}
		// Handle class' methods
		if ( true === is_array( $the_['function'] ) ) {
			$the_reflection = new ReflectionMethod( $the_['function'][0], $the_['function'][1] );
			$return = $the_reflection->getFileName() . '#L' . $the_reflection->getStartLine();
		}
		return $return;
	}

	public function log( $message, $data ) {
		error_log( $message . ' ' . var_export( $data, true ) );
		if ( true === defined( 'WP_CLI' ) && true === WP_CLI ) {
			WP_CLI::Line( $message . ' ' . var_export( $data, true ) );
		}
	}
}

function swpd_do_action_debug( $action_to_debug, $callback ) {
	new SWPD_do_action( $action_to_debug, $callback );
}
