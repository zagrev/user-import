<?php
/**
 * Application-level logger for the DMBC Tools plugin.
 *
 * This is a standalone PHP logging utility that follows a lightweight
 * Log4j-style API while integrating with WordPress/PHP logging.
 *
 * @package DmbcTools
 */

declare(strict_types=1);

if ( ! \defined( 'ABSPATH' ) ) {
	print 'ABSPATH is not defined . This file( ' . __FILE__ . ' ) should not be accessed directly . ' . PHP_EOL;
	exit;
}

/**
 * Lightweight application logger inspired by Log4j levels.
 */
final class DmbcLogger {
	public const int LEVEL_OFF   = 0;
	public const int LEVEL_FATAL = 10;
	public const int LEVEL_ERROR = 20;
	public const int LEVEL_WARN  = 30;
	public const int LEVEL_INFO  = 40;
	public const int LEVEL_DEBUG = 50;
	public const int LEVEL_ALL   = 10000;

	public static function get_instance(): DmbcLogger {
		static $instance = null;
		if ( $instance === null ) {
			$instance = new self();
		}
		return $instance;
	}
	
	/**
	 * The channel is the logger name (or object or filename)
	 *
	 * @var string
	 */
	private string $channel;
	/**
	 * The current log level.
	 *
	 * @var int
	 */
	private int $log_level;
	/**
	 * The custom handler for log records.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $handler = null;

	/**
	 * Create a logger instance.
	 *
	 * @param string $channel Logical channel for the log output.
	 * @param int    $log_level The initial log level.
	 */
	public function __construct( string $channel = 'user-import', int $log_level = self::LEVEL_WARN ) {
		$this->channel   = trim( $channel ) !== '' ? trim( $channel ) : 'user-import';
		$this->log_level = $log_level;
	}

	/**
	 * Get the log level for this logger instance.
	 *
	 * @return int The current log level.
	 */
	public function get_level(): int {
		return $this->log_level;
	}

	/**
	 * Set the log level for this logger instance.
	 *
	 * @param int $log_level The new log level.
	 * @return void
	 */
	public function set_level( int $log_level ): void {
		$this->log_level = $log_level;
	}

	/**
	 * Set a custom handler callback for log records.
	 *
	 * @param callable|null $handler Handler invoked with the log entry array.
	 * @return void
	 */
	public function set_handler( ?callable $handler ): void {
		$this->handler = $handler;
	}

	/**
	 * Log a debug record.
	 *
	 * @param string $message Message text.
	 * @param array  $context Structurally formatted values.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context );
	}

	/**
	 * Log an info record.
	 *
	 * @param string $message Message text.
	 * @param array  $context Structurally formatted values.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Log a warning record.
	 *
	 * @param string $message Message text.
	 * @param array  $context Structurally formatted values.
	 * @return void
	 */
	public function warn( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_WARN, $message, $context );
	}

	/**
	 * Log an error record.
	 *
	 * @param string $message Message text.
	 * @param array  $context Structurally formatted values.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Log a message at the given level.
	 *
	 * @param int    $level Log level.
	 * @param string $message Message text.
	 * @param array  $context Structured context values.
	 * @return void
	 */
	public function log( int $level, string $message, array $context = array() ): void {
		// If the requested log level is more important (or equally important) to the log level, then log it.
		if ( $this->log_level >= $level ) {

			$entry = array(
				'time'    => gmdate( 'c' ),
				'channel' => $this->channel,
				'level'   => $this->level_to_string( $level ),
				'message' => $this->interpolate( $message, $context ),
				'context' => $context,
			);

			if ( is_callable( $this->handler ) ) {
				( $this->handler )( $entry );
			}

			$this->write_to_wordpress( $entry );
		}
	}

	/**
	 * Convert the log level to a human readable string.
	 *
	 * @param int $level Log level.
	 * @return string Human readable log level.
	 */
	private function level_to_string( int $level ): string {
		switch ( $level ) {
			case self::LEVEL_DEBUG:
				return 'Debug';
			case self::LEVEL_INFO:
				return 'Info';
			case self::LEVEL_WARN:
				return 'Warn';
			case self::LEVEL_ERROR:
				return 'Error';
			default:
				return '';
		}
	}

	/**
	 * Interpolate placeholders like {user} with matching context values.
	 *
	 * @param string $message Message template.
	 * @param array  $context Context data.
	 * @return string
	 */
	private function interpolate( string $message, array $context ): string {
		if ( empty( $context ) ) {
			return $message;
		}

		foreach ( $context as $key => $value ) {
			$message = str_replace( '{' . $key . '}', (string) $value, $message );
		}

		return $message;
	}

	/**
	 * Write the entry to WordPress and PHP logging infrastructure.
	 *
	 * @param array $entry Log entry.
	 * @return void
	 */
	private function write_to_wordpress( array $entry ): void {
		$level_string = $entry['level'];
		$message      = sprintf(
			'[%s] [%s] %s',
			$this->channel,
			$level_string,
			$entry['message']
		);

		if ( ! empty( $entry['context'] ) ) {
			$message .= ' ' . \wp_json_encode( $entry['context'] );
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			try {
				\wc_get_logger()->log( $level_string, $message, array( 'source' => $this->channel ) );
				return;
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			} catch ( \Throwable $exception ) {
				// Catch and ignore exceptions. Fall back to PHP error_log and WordPress debug logging.
			}
		}

		if ( function_exists( '\error_log' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( $message );
		}
	}

	/**
	 * Map internal logger levels to WooCommerce log levels when available.
	 *
	 * @param string $level Internal level.
	 * @return string
	 */
	private function to_wc_level( string $level ): string {
		switch ( $level ) {
			case self::LEVEL_DEBUG:
				return 'debug';
			case self::LEVEL_INFO:
				return 'info';
			case self::LEVEL_WARN:
				return 'warning';
			case self::LEVEL_ERROR:
				return 'error';
			default:
				return 'info';
		}
	}
}
