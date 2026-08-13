<?php
/**
 * Hook do_action Debugger.
 */

declare(strict_types=1);

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
	private string $action_to_debug;

	/**
	 * A custom callback to run after each already registered callback.
	 *
	 * @var \Closure
	 */
	private \Closure $callback;

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 *
	 * @param string   $filter   The hook name to debug.
	 * @param callable $callback A custom callback to run after each already registered callback.
	 */
	public function __construct( string $filter, callable $callback ) {
		$this->action_to_debug = $filter;
		$this->callback        = \Closure::fromCallable( $callback );
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
	public function filter_all( string $action, mixed $value = null ): mixed {
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
	public function add_debugging( mixed $value ): mixed {
		global $wp_filter;

		if ( true === array_key_exists( $this->action_to_debug, $wp_filter ) ) {
			$filters = $wp_filter[ $this->action_to_debug ]->callbacks;

			$wp_filter[ $this->action_to_debug ]->callbacks = array();

			$i = 0;
			foreach ( $filters as $priority => $thes_ ) {
				foreach ( $thes_ as $idx => $the_ ) {
					$wp_filter[ $this->action_to_debug ]->callbacks[ $priority ][ $idx ] = $the_;
					$wp_filter[ $this->action_to_debug ]->callbacks[ $priority ][ _wp_filter_build_unique_id( $this->action_to_debug, array( $this, 'debug' ), $priority ) . ( $i++ ) ] = array(
						'function'      => function ( $value ) use ( $idx, $priority, $the_ ) {
							$this->debug( $value, $idx, $the_, $priority );
							return $value; },
						'accepted_args' => 1,
					);
				}
			}
		}

		return $value;
	}

	/**
	 * Calls our debugging callback added to the selected hook.
	 *
	 * @param mixed      $value    The filtered value, if it is a filter.
	 * @param int|string $idx      The callback ID in the global $wp_filter registry.
	 * @param array      $the_     The registered callback data.
	 * @param int        $priority The current hook priority.
	 *
	 * @return mixed           The filtered value, if it is a filter.
	 */
	public function debug( mixed $value, int|string $idx, array $the_, int $priority ): mixed {
		( $this->callback )( $value, $idx, $the_, $priority );
		return $value;
	}
}
