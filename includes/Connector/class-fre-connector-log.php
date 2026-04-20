<?php
/**
 * Cowork connector call log.
 *
 * A small ring buffer of recent connector REST requests, persisted as a single
 * non-autoloaded WordPress option. Used by the preflight diagnostic to surface
 * recent activity when debugging a misbehaving connector remotely — operators
 * can see the last few requests and their outcomes without enabling general WP
 * debug logging.
 *
 * Deliberately bounded to a small number of recent entries (50) and a single
 * option to keep the storage footprint trivial. Anything more elaborate
 * (per-request bodies, query parameter capture, indexed search) would warrant
 * a dedicated DB table — out of scope for v1.
 *
 * The log is opt-out via the `fre_connector_log_enabled` filter. Sites
 * concerned about even the small wp_options write per request can disable it
 * entirely without affecting connector functionality.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Connector call log accessor and writer.
 */
class FRE_Connector_Log {

    /**
     * Option key storing the ring buffer.
     *
     * Stored with autoload=false because connector requests are a hot path on
     * sites that use the API actively, and we don't want this option in every
     * frontend page load's autoload payload.
     *
     * @var string
     */
    const OPTION_KEY = 'fre_connector_call_log';

    /**
     * Maximum entries retained. Older entries are evicted FIFO.
     *
     * @var int
     */
    const MAX_ENTRIES = 50;

    /**
     * Append a single call record to the ring buffer.
     *
     * @param array $entry {
     *     @type int    $ts        Unix timestamp.
     *     @type string $method    HTTP method (GET, POST, PATCH, DELETE).
     *     @type string $route     REST route (e.g. /fre/v1/connector/forms).
     *     @type int    $user_id   Authenticated user ID, 0 if not logged in.
     *     @type int    $status    HTTP status of the response.
     *     @type int    $duration  Duration in milliseconds.
     * }
     */
    public static function record( array $entry ) {
        /**
         * Filter to disable connector logging entirely.
         *
         * Default true. Sites that want zero log overhead can return false here
         * via mu-plugin or theme functions.php.
         *
         * @param bool $enabled Whether to log this call.
         */
        if ( ! apply_filters( 'fre_connector_log_enabled', true ) ) {
            return;
        }

        $log = self::get_all();

        // Normalize/clamp the entry so callers can't bloat the log with
        // huge values.
        $log[] = array(
            'ts'       => isset( $entry['ts'] ) ? (int) $entry['ts'] : time(),
            'method'   => isset( $entry['method'] ) ? substr( (string) $entry['method'], 0, 10 ) : '',
            'route'    => isset( $entry['route'] ) ? substr( (string) $entry['route'], 0, 200 ) : '',
            'user_id'  => isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0,
            'status'   => isset( $entry['status'] ) ? (int) $entry['status'] : 0,
            'duration' => isset( $entry['duration'] ) ? (int) $entry['duration'] : 0,
        );

        // Evict oldest entries when over the cap.
        if ( count( $log ) > self::MAX_ENTRIES ) {
            $log = array_slice( $log, -self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $log, false );
    }

    /**
     * Read the entire log.
     *
     * @return array
     */
    public static function get_all() {
        $log = get_option( self::OPTION_KEY, array() );
        return is_array( $log ) ? $log : array();
    }

    /**
     * Get the last N entries, newest first.
     *
     * @param int $count How many entries to return.
     * @return array
     */
    public static function get_recent( $count = 5 ) {
        $log = self::get_all();
        if ( empty( $log ) ) {
            return array();
        }

        $count = max( 1, (int) $count );
        return array_reverse( array_slice( $log, -$count ) );
    }

    /**
     * Empty the log. Called from uninstall and exposed for testing.
     */
    public static function clear() {
        delete_option( self::OPTION_KEY );
    }
}
