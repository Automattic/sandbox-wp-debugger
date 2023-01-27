<?php
/**
 * WPCOM WP-CLI Logger.
 *
 * phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
 */

namespace SWPD;

if ( true === defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * SWPD\WPCOM_WP_CLI_Logger Class.
	 */
	class WPCOM_WP_CLI_Logger {

		/**
		 * Class constructor.
		 *
		 * @param bool $in_color Output in color.
		 */
		public function __construct( $in_color ) {
			$this->in_color = $in_color;
		}

		/**
		 * Write to a file handle.
		 *
		 * @param  resource $stream A file system pointer resource that is typically created using fopen().
		 * @param  string   $data   The string that is to be written.
		 *
		 * @return bool|int         Returns the number of bytes written, or false on failure.
		 */
		protected function write( $stream, $data ) {
			return fwrite( $stream, $str ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
		}

		/**
		 * Writes an output line to a resource handle.
		 *
		 * @param  string   $message Message to display.
		 * @param  string   $label   Message display level.
		 * @param  bool     $color   Output to color.
		 * @param  resource $handle  A file system pointer resource that is typically created using fopen().
		 *
		 * @return void
		 */
		private function _line( $message, $label, $color, $handle = STDOUT ): void {
			$label = \cli\Colors::colorize( "$color$label:%n", $this->in_color );
			$this->write( $handle, "$label $message" . PHP_EOL );
			$this->write( $handle, 'Backtrace: ' . wp_debug_backtrace_summary() . PHP_EOL ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_wp_debug_backtrace_summary
		}

		/**
		 * Outputs an INFO message.
		 *
		 * @param  string $message Message to display.
		 *
		 * @return void
		 */
		public function info( string $message = '' ): void {
			$this->write( STDOUT, $message . PHP_EOL );
		}

		/**
		 * Outputs a SUCCESS message.
		 *
		 * @param  string $message Message to display.
		 *
		 * @return void
		 */
		public function success( string $message = '' ): void {
			$this->_line( $message, 'Success', '%G' );
		}

		/**
		 * Outputs a WARNING message.
		 *
		 * @param  string $message Message to display.
		 *
		 * @return void
		 */
		public function warning( string $message = '' ): void {
			$this->_line( $message, 'Warning', '%C', STDERR );
		}

		/**
		 * Outputs an ERROR message.
		 *
		 * @param  string $message Message to display.
		 *
		 * @return void
		 */
		public function error( string $message = '' ): void {
			$this->_line( $message, 'Error', '%R', STDERR );
		}
	}

	if ( ! version_compare( WP_CLI_VERSION, '1.0.0', '>=' ) ) {
		\WP_CLI::set_logger( new WPCOM_WP_CLI_Logger( true ) );
	}
}
