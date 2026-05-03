<?php
/**
 * Cowork connector authentication and authorization.
 *
 * Implements the three-concentric-check permission stack documented in
 * docs/CONNECTOR_SPEC.md §4:
 *
 *   1. Connector enabled toggle      — else 403 connector_disabled
 *   2. is_user_logged_in()            — else 401 rest_not_logged_in
 *   3. current_user_can(fre_manage_forms) — else 403 rest_forbidden
 *
 * Plus an orthogonal fourth check for entry-read endpoints only:
 *
 *   4. Entry-read toggle              — else 403 entry_access_disabled
 *
 * And per-user per-route rate limiting via WP transients. Limits are
 * documented in CONNECTOR_SPEC.md §6 and configured in self::RATE_LIMITS.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Connector auth and rate-limit enforcement.
 */
class FRE_Connector_Auth {

    /**
     * Per-route-per-user rate limits, in requests per minute.
     *
     * Route keys match the $route parameter passed by the API layer when it
     * builds its permission_callback closures — they are semantic identifiers
     * chosen for readability, not REST path segments.
     *
     * Keep in sync with docs/CONNECTOR_SPEC.md §6.
     *
     * @var array
     */
    const RATE_LIMITS = array(
        'preflight'          => 60,
        'list_forms'         => 60,
        'get_form'           => 60,
        'create_form'        => 10,
        'update_form'        => 10,
        'delete_form'        => 5,
        'list_entries'       => 60,
        'get_entry'          => 60,
        'delete_entry'       => 5,
        'submit_dry_run'     => 30,
        'submit_live'        => 5,
    );

    /**
     * Default limit when the route key is unknown — intentionally strict.
     *
     * Reaching this branch means a new route was wired up but its rate-limit
     * entry was not added to self::RATE_LIMITS. The fallback keeps the site
     * safe while the omission is noticed and fixed.
     *
     * @var int
     */
    const DEFAULT_RATE_LIMIT = 10;

    /**
     * Build the standard permission callback for a connector route.
     *
     * Returns a closure suitable for passing to register_rest_route as the
     * `permission_callback`. The closure runs the three-check stack and the
     * rate-limit check for the given route key.
     *
     * Usage:
     *   register_rest_route( 'fre/v1', '/connector/forms', array(
     *       'methods'             => 'GET',
     *       'callback'            => array( $this, 'handle_list_forms' ),
     *       'permission_callback' => FRE_Connector_Auth::build_callback( 'list_forms' ),
     *   ) );
     *
     * @param string $route_key     Route identifier used for rate-limit bucketing.
     *                              Must exist in self::RATE_LIMITS or the default
     *                              rate limit applies.
     * @param bool   $requires_entry_read Whether this route is gated by the
     *                              entry-read toggle (only /entries endpoints
     *                              should pass true).
     * @return callable
     */
    public static function build_callback( $route_key, $requires_entry_read = false ) {
        return function ( $request ) use ( $route_key, $requires_entry_read ) {
            return self::run_permission_stack( $route_key, $requires_entry_read, $request );
        };
    }

    /**
     * Run the full permission stack.
     *
     * Separate from build_callback() so tests can call it directly without
     * building a closure.
     *
     * @param string          $route_key           Rate-limit bucket.
     * @param bool            $requires_entry_read Additional gate for /entries endpoints.
     * @param WP_REST_Request $request             REST request.
     * @return true|WP_Error True on pass, WP_Error on any check failure.
     */
    public static function run_permission_stack( $route_key, $requires_entry_read, $request ) {
        // Gate 1: connector enabled.
        if ( ! FRE_Connector_Settings::is_enabled() ) {
            return new WP_Error(
                'connector_disabled',
                __( 'The Claude Cowork connector is not enabled on this site. A site administrator can enable it under Form Entries → Claude Connection.', 'form-runtime-engine' ),
                array( 'status' => 403 )
            );
        }

        // Authentication: must be logged in (App Password grants a real user session).
        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'rest_not_logged_in',
                __( 'Authentication required. Use a WordPress Application Password generated through the Claude Connection admin page.', 'form-runtime-engine' ),
                array( 'status' => 401 )
            );
        }

        // Authorization: capability check.
        if ( ! current_user_can( FRE_Capabilities::MANAGE_FORMS ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'Your account does not have permission to manage forms through the connector.', 'form-runtime-engine' ),
                array( 'status' => 403 )
            );
        }

        // Gate 2: entry-read toggle (only for /entries routes).
        if ( $requires_entry_read && ! FRE_Connector_Settings::is_entry_read_enabled() ) {
            return new WP_Error(
                'entry_access_disabled',
                __( 'Entry read access is not enabled for the connector. A site administrator can enable it under Form Entries → Claude Connection.', 'form-runtime-engine' ),
                array( 'status' => 403 )
            );
        }

        // Rate limiting.
        $rate_result = self::enforce_rate_limit( $route_key, get_current_user_id() );
        if ( is_wp_error( $rate_result ) ) {
            return $rate_result;
        }

        return true;
    }

    /**
     * Enforce per-user per-route rate limiting.
     *
     * Uses a sliding-window bucket keyed by user and route, stored as a
     * transient. Each incremented call bumps the counter; when the counter
     * reaches the route's limit, the transient's remaining TTL becomes the
     * Retry-After value returned to the caller.
     *
     * Separate public method so external code (tests, future admin
     * diagnostics) can invoke it directly.
     *
     * @param string $route_key Route identifier.
     * @param int    $user_id   Authenticated user's ID.
     * @return true|WP_Error True when under limit, WP_Error 429 when exceeded.
     */
    public static function enforce_rate_limit( $route_key, $user_id ) {
        $limit = self::RATE_LIMITS[ $route_key ] ?? self::DEFAULT_RATE_LIMIT;

        $transient_key = 'fre_connector_rate_' . sanitize_key( $route_key ) . '_' . (int) $user_id;

        $current = get_transient( $transient_key );

        if ( false === $current ) {
            // First call in this window.
            set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
            return true;
        }

        $current = (int) $current;

        if ( $current >= $limit ) {
            // Compute remaining TTL so the response's retry_after is accurate.
            // WordPress transients don't expose TTL directly; we reconstruct it
            // from the option expiration stamp.
            $retry_after = self::get_transient_retry_after( $transient_key );

            return new WP_Error(
                'rate_limit_exceeded',
                sprintf(
                    /* translators: 1: limit per minute, 2: route identifier */
                    __( 'Rate limit exceeded for this connector endpoint (%1$d requests/minute on "%2$s"). Retry after a moment.', 'form-runtime-engine' ),
                    $limit,
                    $route_key
                ),
                array(
                    'status'      => 429,
                    'retry_after' => $retry_after,
                    'route'       => $route_key,
                    'limit'       => $limit,
                )
            );
        }

        // Under limit — increment.
        // We re-set to the same TTL window; this is a fixed-window counter,
        // not a sliding window. Simple and sufficient for these limits.
        set_transient( $transient_key, $current + 1, MINUTE_IN_SECONDS );
        return true;
    }

    /**
     * Get the remaining TTL for a transient, in seconds.
     *
     * WordPress does not expose this directly, so we read the underlying
     * option row. Returns 60 as a conservative fallback if lookup fails.
     *
     * @param string $transient_key Transient name (without `_transient_` prefix).
     * @return int Seconds until expiry.
     */
    private static function get_transient_retry_after( $transient_key ) {
        $timeout = (int) get_option( '_transient_timeout_' . $transient_key );
        if ( 0 === $timeout ) {
            return MINUTE_IN_SECONDS;
        }
        $remaining = $timeout - time();
        return $remaining > 0 ? $remaining : 1;
    }
}
