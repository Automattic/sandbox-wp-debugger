<?php
/**
 * Hook do_action Debugger.
 */

namespace SWPD;

/**
 * SWPD\Do_Action Class.
 */
class Do_Action extends Base {

	/**
	 * The hook name to debug.
	 *
	 * @var string
	 */
	private $action_to_debug = null;

	/**
	 * A custom callback to run after each already registered callback.
	 *
	 * @var mixed
	 */
	private $callback = null;

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 *
	 * @param string $filter   The hook name to debug.
	 * @param mixed  $callback A custom callback to run after each already registered callback.
	 */
	public function __construct( $filter, $callback ) {
		$this->action_to_debug = $filter;
		$this->callback        = $callback;
		add_filter( 'all', array( $this, 'filter_all' ), 10, 2 );
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
		if ( current_filter() === $this->action_to_debug ) {
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

		if ( true === array_key_exists( $this->action_to_debug, $wp_filter ) ) {
			$filters = $wp_filter[ $this->action_to_debug ]->callbacks;

			$wp_filter[ $this->action_to_debug ]->callbacks = array();

			$i = 0;
			foreach ( $filters as $priority => $thes_ ) {
				foreach ( $thes_ as $idx => $the_ ) {
					$wp_filter[ $this->action_to_debug ]->callbacks[ $priority ][ $idx ] = $the_;
					$wp_filter[ $this->action_to_debug ]->callbacks[ $priority ][ _wp_filter_build_unique_id( $this->action_to_debug, array( $this, 'debug' ), $priority ) . ( $i++ ) ] = array(
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
		( $this->callback )( $value, $idx, $the_, $priority );
		return $value;
	}

}

/**
 * Registers a new action debugger.
 *
 * @param  string $action_to_debug The hook name to debug.
 * @param  mixed  $callback        A custom callback to run after each already registered callback.
 *
 * @return void
 */
function swpd_do_action_debug( string $action_to_debug, $callback ): void {
	new SWPD\Do_Action( $action_to_debug, $callback );
}
