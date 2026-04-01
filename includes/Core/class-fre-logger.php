<?php
/**
 * FRE Logger - Centralized logging that respects WP_DEBUG settings.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger class for Form Runtime Engine.
 *
 * Provides centralized logging that only outputs when WP_DEBUG is enabled,
 * satisfying WordPress Plugin Check requirements.
 */
class FRE_Logger {

	/**
	 * Log a message if WP_DEBUG is enabled.
	 *
	 * @param string $message The message to log.
	 * @param string $level   Log level: 'info', 'warning', 'error'.
	 * @param array  $context Optional context data.
	 */
	public static function log( $message, $level = 'info', $context = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$prefix    = '[FRE ' . strtoupper( $level ) . ']';
		$formatted = $prefix . ' ' . $message;

		if ( ! empty( $context ) ) {
			$formatted .= ' | Context: ' . wp_json_encode( $context );
		}

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG_LOG is enabled.
			error_log( $formatted );
		}
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message The message to log.
	 * @param array  $context Optional context data.
	 */
	public static function info( $message, $context = array() ) {
		self::log( $message, 'info', $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message The message to log.
	 * @param array  $context Optional context data.
	 */
	public static function warning( $message, $context = array() ) {
		self::log( $message, 'warning', $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message The message to log.
	 * @param array  $context Optional context data.
	 */
	public static function error( $message, $context = array() ) {
		self::log( $message, 'error', $context );
	}

	/**
	 * Log a debug message (only in development).
	 *
	 * @param string $message The message to log.
	 * @param array  $context Optional context data.
	 */
	public static function debug( $message, $context = array() ) {
		self::log( $message, 'debug', $context );
	}
}
