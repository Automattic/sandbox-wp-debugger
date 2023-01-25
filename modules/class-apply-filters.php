<?php
/**
 * Apply Filters Debugger.
 */

namespace SWPD;

/**
 * SWPD\Apply_Filters Class.
 */
class Apply_Filters extends Base {

	/**
	 * The hook name to debug.
	 *
	 * @var string
	 */
	private $filter_to_debug = null;

	/**
	 * Only show when filter values change, defaults to false.
	 *
	 * @var bool
	 */
	private $only_changed = false;

	/**
	 * The filter's previous value.
	 *
	 * @var string
	 */
	private $swpd_filter_prev_value = null;

	/**
	 * The time the filter started.
	 *
	 * @var string
	 */
	private $time_start = null;

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 *
	 * @param string $filter       The hook name to debug.
	 * @param bool   $only_changed Only show when filter values change, defaults to false.
	 */
	public function __construct( string $filter, $only_changed = false ) {
		$this->filter_to_debug = $filter;
		$this->only_changed    = $only_changed;

		add_filter( 'all', array( $this, 'filter_all' ), 10, 2 );

		$this->time_start = microtime( true );
	}

	/**
	 * Callback to the all hook to add our custom callbacks.
	 *
	 * @param  string $action The hook name being run.
	 * @param  mixed  $value  The filtered value, if it is a filter.
	 *
	 * @return mixed          The filtered value, if it is a filter.
	 */
	public function filter_all( string $action, $value = null ) {
		if ( current_filter() === $this->filter_to_debug ) {
			$this->add_debugging( $value );
		}
		return $value;
	}

	/**
	 * Adds debugging to the selected hook.
	 *
	 * @param mixed $value The filtered value, if it is a filter.
	 *
	 * @return mixed       The filtered value, if it is a filter.
	 */
	public function add_debugging( $value ) {
		global $wp_filter;
		$this->swpd_filter_prev_value = $value;
		if ( true === array_key_exists( $this->filter_to_debug, $wp_filter ) ) {
			$filters                                        = $wp_filter[ $this->filter_to_debug ]->callbacks;
			$wp_filter[ $this->filter_to_debug ]->callbacks = array();
			$i = 0;
			foreach ( $filters as $priority => $thes_ ) {
				foreach ( $thes_ as $idx => $the_ ) {
					$wp_filter[ $this->filter_to_debug ]->callbacks[ $priority ][ $idx ] = $the_;
					$wp_filter[ $this->filter_to_debug ]->callbacks[ $priority ][ _wp_filter_build_unique_id( $this->filter_to_debug, array( $this, 'debug' ), $priority ) . ( $i++ ) ] = array(
						'function'      => function( $value ) use ( $idx, $priority, $the_ ) {
							$this->debug( $value, $idx, $the_, $priority );
							return $value; },
						'accepted_args' => 1,
					);
				}
			}
		}
	}

	/**
	 * Calls our debugging callback added to the selected hook.
	 *
	 * @param mixed $value    The filtered value, if it is a filter.
	 * @param mixed $idx      The index in the global $wp_filters we are in.
	 * @param mixed $the_     I have no idea.
	 * @param int   $priority The current hook priority.
	 *
	 * @return mixed           The filtered value, if it is a filter.
	 */
	public function debug( $value, $idx, $the_, $priority ) {
		if ( false === $this->only_changed || $this->swpd_filter_prev_value !== $value ) {
			$data = array(
				'initial value' => $this->swpd_filter_prev_value,
				'value'         => $value,
				'idx'           => $idx,
				'the_'          => $the_,
				'defined_in'    => $this->defined_in( $the_ ),
				'priority'      => $priority,
				'time'          => microtime( true ) - $this->time_start,
			);

			$this->log( data: $data );
		}

		$this->time_start             = microtime( true );
		$this->swpd_filter_prev_value = $value;

		return $value;
	}

	/**
	 * Determines the function the filter was defined in.
	 *
	 * @param  array $the_ I have no idea.
	 *
	 * @return string      The filename and line where the function was called.
	 */
	public function defined_in( $the_ ) {
		$return = 'N/A';

		// Handle function and closures.
		if ( true === is_string( $the_['function'] ) || true === is_a( $the_['function'], '\Closure' ) ) {
			$the_reflection = new ReflectionFunction( $the_['function'] );
			$return         = $the_reflection->getFileName() . ' L# ' . $the_reflection->getStartLine();
		}

		// Handle class' methods.
		if ( true === is_array( $the_['function'] ) ) {
			$the_reflection = new ReflectionMethod( $the_['function'][0], $the_['function'][1] );
			$return         = $the_reflection->getFileName() . ' L# ' . $the_reflection->getStartLine();
		}

		return $return;
	}
}

/**
 * Registers a new filter debugger.
 *
 * @param  string $filter_to_debug The hook name to debug.
 * @param  bool   $only_changed    Only show when filter values change, defaults to false.
 *
 * @return void
 */
function swpd_apply_filter_debug( $filter_to_debug, $only_changed = false ) {
	new SWPD\Apply_Filters( $filter_to_debug, $only_changed );
}
