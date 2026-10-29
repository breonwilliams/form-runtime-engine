<?php
/**
 * Form availability: is this form accepting submissions right now?
 *
 * The ONE decision point for a form's open date, close date and entry cap.
 * Both submission paths and the renderer are meant to call
 * PForms_Availability::check() and nothing else, so a reviewer finds every
 * caller with one grep (`PForms_Availability::check(`).
 *
 * Settings live under the form's `settings.availability` object:
 *
 *   "availability": {
 *     "opens_at":            "2027-03-15 09:00",   // or "2027-03-15"
 *     "closes_at":           "2027-03-20",         // or "2027-03-20 17:00"
 *     "max_entries":         30,                   // whole number >= 0
 *     "closed_message":      "Registration has closed.",
 *     "before_open_message": "Registration opens on 15 March."
 *   }
 *
 * Every key is optional. Absent (or JSON null) means "not set":
 *   - no opens_at  -> open from the start;
 *   - no closes_at -> never closes by date;
 *   - no max_entries -> UNLIMITED. This is the only way to say "no limit":
 *     0 means zero places (closed), and a string such as "unlimited" or ""
 *     is refused at save time.
 *   - no closed_message / before_open_message -> the built-in defaults.
 *
 * Dates are wall-clock times in the SITE timezone (wp_timezone()), never UTC
 * or server time. Two formats are accepted:
 *   - "YYYY-MM-DD HH:MM": that exact minute. The form opens AT opens_at
 *     (inclusive) and is closed FROM closes_at (17:00 means 16:59:59 is the
 *     last open second).
 *   - "YYYY-MM-DD": opens_at means 00:00 of that day; closes_at means the end
 *     of that day — 23:59:59 is still open, 00:00 the next day is closed.
 * A close moment at or before the open moment is refused at save time (so a
 * same-day date-only window, 00:00 to the end of that day, is valid).
 *
 * The place count comes from PForms_Place_Counter (read only for a capped
 * form with a known ID); tests and callers that already hold it can inject it
 * through $context['count'].
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form availability decision point.
 */
class PForms_Availability {

    /**
     * The settings.availability keys and their "not set" values. Used by
     * PForms_Registry::$default_settings to normalise every form.
     *
     * @var array
     */
    const DEFAULTS = array(
        'opens_at'            => null,
        'closes_at'           => null,
        'max_entries'         => null,
        'closed_message'      => null,
        'before_open_message' => null,
    );

    /**
     * Reasons a result can carry. Filters may add their own.
     */
    const REASON_OPEN             = 'open';
    const REASON_NOT_YET_OPEN     = 'not_yet_open';
    const REASON_CLOSED_DATE      = 'closed_date';
    const REASON_CAP_REACHED      = 'cap_reached';
    const REASON_INVALID_SETTINGS = 'invalid_settings';
    const REASON_FORM_NOT_FOUND   = 'form_not_found';

    /**
     * Accepted date formats (PHP DateTime syntax), most specific first.
     */
    const FORMAT_DATETIME = 'Y-m-d H:i';
    const FORMAT_DATE     = 'Y-m-d';

    /**
     * Decide whether a form is accepting submissions.
     *
     * @param string|array $form    Form ID (looked up in the runtime registry)
     *                              or a form configuration array.
     * @param array        $context {
     *     Optional.
     *
     *     @type int    $now     Unix timestamp to decide at. Default time().
     *     @type int    $count   Places already taken. Default: the form's
     *                           PForms_Place_Counter count (capped forms only;
     *                           0 for an uncapped form).
     *     @type string $form_id Form ID, when $form is a config array.
     * }
     * @return array {
     *     @type bool     $open      Whether submissions are accepted.
     *     @type string   $reason    Machine-readable reason (REASON_* or a
     *                               filter's own).
     *     @type string   $message   What to show the visitor ('' when open).
     *     @type string   $form_id   Form ID ('' if unknown).
     *     @type int|null $opens_at  First open moment (unix), null if none.
     *     @type int|null $closes_at First closed moment (unix), null if none.
     *     @type int|null $cap       max_entries, null = unlimited.
     *     @type int      $count     Places taken, as used for the decision.
     *     @type int      $now       The moment decided at.
     * }
     */
    public static function check( $form, array $context = array() ) {
        $form_id = isset( $context['form_id'] ) ? (string) $context['form_id'] : '';
        $config  = null;

        if ( is_array( $form ) ) {
            $config = $form;
        } elseif ( is_string( $form ) && '' !== $form ) {
            $form_id = $form;
            $config  = function_exists( 'pforms' ) ? pforms()->registry->get( $form ) : null;
        }

        $now   = isset( $context['now'] ) ? (int) $context['now'] : time();
        $count = isset( $context['count'] ) ? max( 0, (int) $context['count'] ) : 0;

        $result = array(
            'open'      => true,
            'reason'    => self::REASON_OPEN,
            'message'   => '',
            'form_id'   => $form_id,
            'opens_at'  => null,
            'closes_at' => null,
            'cap'       => null,
            'count'     => $count,
            'now'       => $now,
        );

        $availability = is_array( $config ) && isset( $config['settings']['availability'] )
            ? $config['settings']['availability']
            : null;

        if ( ! is_array( $config ) ) {
            $result['open']    = false;
            $result['reason']  = self::REASON_FORM_NOT_FOUND;
            $result['message'] = self::default_closed_message();
        } elseif ( array() !== self::validate( $availability ) ) {
            // Only reachable by a PHP-registered config: both save paths
            // refuse these values. Fail closed rather than guess, so a typo
            // can never open a form to everyone.
            self::log_invalid_once( $form_id, $availability );
            $result['open']    = false;
            $result['reason']  = self::REASON_INVALID_SETTINGS;
            $result['message'] = self::closed_message( $availability );
        } else {
            $availability = is_array( $availability ) ? $availability : array();

            $opens_at  = self::to_timestamp( $availability['opens_at'] ?? null, 'open' );
            $closes_at = self::to_timestamp( $availability['closes_at'] ?? null, 'close' );
            $cap       = isset( $availability['max_entries'] ) ? (int) $availability['max_entries'] : null;

            if ( null !== $cap && ! isset( $context['count'] ) ) {
                $count           = self::places_taken( $form_id );
                $result['count'] = $count;
            }

            $result['opens_at']  = $opens_at;
            $result['closes_at'] = $closes_at;
            $result['cap']       = $cap;

            if ( null !== $opens_at && $now < $opens_at ) {
                $result['open']    = false;
                $result['reason']  = self::REASON_NOT_YET_OPEN;
                $result['message'] = self::before_open_message( $availability, $opens_at );
            } elseif ( null !== $closes_at && $now >= $closes_at ) {
                $result['open']    = false;
                $result['reason']  = self::REASON_CLOSED_DATE;
                $result['message'] = self::closed_message( $availability );
            } elseif ( null !== $cap && $count >= $cap ) {
                $result['open']    = false;
                $result['reason']  = self::REASON_CAP_REACHED;
                $result['message'] = self::closed_message( $availability );
            }
        }

        /**
         * Filter whether a form is accepting submissions.
         *
         * Later rules (a waitlist, per-email limits, "N places left") plug in
         * here. Return the array with `open`, `reason` and `message` changed
         * to close an open form or open a closed one.
         *
         * @param array      $result  The decision (see PForms_Availability::check()).
         * @param array|null $config  The form configuration, null if not found.
         * @param array      $context The context passed to check().
         */
        $filtered = apply_filters( 'pforms_form_availability', $result, $config, $context );

        return self::normalize_result( $filtered, $result );
    }

    /**
     * The refusal for a submission whose place claim was refused: the form is
     * full, whatever an earlier check() said.
     *
     * check() is a read and can be stale (another request took the last place
     * since); the atomic claim in PForms_Place_Counter is what holds the cap.
     * When the claim says no, this builds the same decision check() gives a
     * full form, so the visitor sees the form's closed message. Deliberately
     * NOT filtered: the claim, not a filter, has the last word on the count.
     *
     * @param array $form_config Form configuration.
     * @param array $base        The check() result the claim was attempted under.
     * @return array A closed decision with reason cap_reached.
     */
    public static function full( array $form_config, array $base = array() ) {
        $availability = isset( $form_config['settings']['availability'] ) ? $form_config['settings']['availability'] : null;

        $result            = array_merge(
            array(
                'form_id'   => '',
                'opens_at'  => null,
                'closes_at' => null,
                'cap'       => null,
                'count'     => 0,
                'now'       => time(),
            ),
            $base
        );
        $result['open']    = false;
        $result['reason']  = self::REASON_CAP_REACHED;
        $result['message'] = self::closed_message( $availability );
        if ( null !== $result['cap'] ) {
            $result['count'] = (int) $result['cap'];
        }

        return $result;
    }

    /**
     * Validate a settings.availability value.
     *
     * Shared by the save-time validator (PForms_JSON_Schema_Validator) and
     * check(), so what is refused at save is exactly what check() distrusts.
     *
     * @param mixed $availability The settings.availability value (null = absent).
     * @return string[] Error messages; empty when valid.
     */
    public static function validate( $availability ) {
        if ( null === $availability ) {
            return array();
        }

        if ( ! is_array( $availability ) ) {
            return array(
                __( 'settings.availability must be an object, e.g. {"closes_at": "2027-03-15 17:00", "max_entries": 30}.', 'promptless-forms' ),
            );
        }

        $errors = array();

        foreach ( array_keys( $availability ) as $key ) {
            if ( ! array_key_exists( $key, self::DEFAULTS ) ) {
                $errors[] = sprintf(
                    /* translators: 1: unknown key, 2: allowed keys */
                    __( 'settings.availability has an unknown setting "%1$s". Allowed: %2$s.', 'promptless-forms' ),
                    (string) $key,
                    implode( ', ', array_keys( self::DEFAULTS ) )
                );
            }
        }

        if ( isset( $availability['max_entries'] ) ) {
            $cap = $availability['max_entries'];
            if ( ! is_int( $cap ) || $cap < 0 ) {
                $errors[] = sprintf(
                    /* translators: %s: the configured value */
                    __( 'settings.availability.max_entries must be a whole number of 0 or more (got %s). 0 means no places, so the form is closed; to have no limit, leave max_entries out.', 'promptless-forms' ),
                    self::describe( $cap )
                );
            }
        }

        $parsed = array();
        foreach ( array( 'opens_at' => 'open', 'closes_at' => 'close' ) as $key => $edge ) {
            if ( ! isset( $availability[ $key ] ) ) {
                continue;
            }
            if ( null === self::parse_local( $availability[ $key ] ) ) {
                $errors[] = sprintf(
                    /* translators: 1: setting name, 2: the configured value */
                    __( 'settings.availability.%1$s must be a date "YYYY-MM-DD" or a date and time "YYYY-MM-DD HH:MM" in the site timezone (got %2$s).', 'promptless-forms' ),
                    $key,
                    self::describe( $availability[ $key ] )
                );
                continue;
            }
            $parsed[ $key ] = self::to_timestamp( $availability[ $key ], $edge );
        }

        if ( isset( $parsed['opens_at'], $parsed['closes_at'] ) && $parsed['closes_at'] <= $parsed['opens_at'] ) {
            $errors[] = sprintf(
                /* translators: 1: closes_at value, 2: opens_at value */
                __( 'settings.availability.closes_at (%1$s) must be after settings.availability.opens_at (%2$s).', 'promptless-forms' ),
                (string) $availability['closes_at'],
                (string) $availability['opens_at']
            );
        }

        foreach ( array( 'closed_message', 'before_open_message' ) as $key ) {
            if ( isset( $availability[ $key ] )
                && ( ! is_string( $availability[ $key ] ) || '' === trim( $availability[ $key ] ) ) ) {
                $errors[] = sprintf(
                    /* translators: %s: setting name */
                    __( 'settings.availability.%s must be non-empty text. Leave it out to use the built-in message.', 'promptless-forms' ),
                    $key
                );
            }
        }

        return $errors;
    }

    /**
     * Forms whose invalid settings were already logged in this request.
     *
     * @var array<string,bool>
     */
    private static $logged_invalid = array();

    /**
     * Log a form's invalid settings once per request per form.
     *
     * check() runs on every submission and every render, so an invalid
     * PHP-registered config would otherwise write the same error each time
     * it is asked about within one request.
     *
     * @param string $form_id      Form ID ('' when unknown).
     * @param mixed  $availability The invalid settings.availability value.
     * @return void
     */
    private static function log_invalid_once( $form_id, $availability ) {
        $key = $form_id . '|' . md5( serialize( $availability ) );  // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a cache key, never unserialized.
        if ( isset( self::$logged_invalid[ $key ] ) ) {
            return;
        }
        self::$logged_invalid[ $key ] = true;

        PForms_Logger::error(
            'Form availability settings are invalid; the form is treated as closed.',
            array(
                'form_id' => $form_id,
                'errors'  => self::validate( $availability ),
            )
        );
    }

    /**
     * Places already taken for a form.
     *
     * Read through PForms_Place_Counter (SQL on its own row, never the
     * options/transient/object-cache APIs). A config array passed without a
     * form ID has nothing to count against, so it reports 0.
     *
     * @param string $form_id Form ID.
     * @return int
     */
    private static function places_taken( $form_id ) {
        if ( '' === $form_id ) {
            return 0;
        }

        return ( new PForms_Place_Counter() )->get( $form_id );
    }

    /**
     * Parse a configured date string as a wall-clock time in the site zone.
     *
     * @param mixed $value Configured value.
     * @return array|null array( DateTimeImmutable, bool $has_time ), or null if malformed.
     */
    private static function parse_local( $value ) {
        if ( ! is_string( $value ) ) {
            return null;
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
            $format   = '!' . self::FORMAT_DATETIME;
            $has_time = true;
        } elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            $format   = '!' . self::FORMAT_DATE;
            $has_time = false;
        } else {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat( $format, $value, self::site_timezone() );

        // Round-trip check refuses overflow such as 2027-02-30 or 25:00.
        if ( false === $date || $date->format( $has_time ? self::FORMAT_DATETIME : self::FORMAT_DATE ) !== $value ) {
            return null;
        }

        return array( $date, $has_time );
    }

    /**
     * Resolve a configured date to the unix moment it takes effect.
     *
     * @param mixed  $value Configured value (null = not set).
     * @param string $edge  'open' (first open moment) or 'close' (first closed moment).
     * @return int|null
     */
    private static function to_timestamp( $value, $edge ) {
        if ( null === $value ) {
            return null;
        }

        $parsed = self::parse_local( $value );
        if ( null === $parsed ) {
            return null;
        }

        list( $date, $has_time ) = $parsed;

        // A bare close date closes at the end of that day: 23:59:59 is the
        // last open second, so the first closed moment is the next midnight.
        if ( 'close' === $edge && ! $has_time ) {
            $date = $date->modify( '+1 day' );
        }

        return $date->getTimestamp();
    }

    /**
     * The site timezone. The only zone this class compares in.
     *
     * @return DateTimeZone
     */
    private static function site_timezone() {
        return wp_timezone();
    }

    /**
     * The closed message: custom, or the built-in default.
     *
     * Covers both "full" and "past the close date" (Q-006).
     *
     * @param mixed $availability settings.availability.
     * @return string
     */
    private static function closed_message( $availability ) {
        if ( is_array( $availability ) && isset( $availability['closed_message'] )
            && is_string( $availability['closed_message'] ) && '' !== trim( $availability['closed_message'] ) ) {
            return $availability['closed_message'];
        }

        return self::default_closed_message();
    }

    /**
     * Built-in closed message.
     *
     * @return string
     */
    private static function default_closed_message() {
        return __( 'This form is no longer accepting submissions.', 'promptless-forms' );
    }

    /**
     * The before-open message: custom, or a default naming the opening date
     * and time in the site timezone (Q-006).
     *
     * @param array $availability settings.availability.
     * @param int   $opens_at     Opening moment (unix).
     * @return string
     */
    private static function before_open_message( array $availability, $opens_at ) {
        if ( isset( $availability['before_open_message'] ) && is_string( $availability['before_open_message'] )
            && '' !== trim( $availability['before_open_message'] ) ) {
            return $availability['before_open_message'];
        }

        $timezone    = self::site_timezone();
        $date_format = get_option( 'date_format' );
        $time_format = get_option( 'time_format' );

        return sprintf(
            /* translators: 1: opening date, 2: opening time, both in the site timezone */
            __( 'This form opens on %1$s at %2$s.', 'promptless-forms' ),
            wp_date( $date_format ? $date_format : 'F j, Y', $opens_at, $timezone ),
            wp_date( $time_format ? $time_format : 'g:i a', $opens_at, $timezone )
        );
    }

    /**
     * Keep a filtered result well-formed; fall back to the unfiltered one
     * when a callback returns something that is not a decision.
     *
     * @param mixed $filtered Filter output.
     * @param array $original Unfiltered decision.
     * @return array
     */
    private static function normalize_result( $filtered, array $original ) {
        if ( ! is_array( $filtered ) || ! array_key_exists( 'open', $filtered ) ) {
            return $original;
        }

        $result         = array_merge( $original, $filtered );
        $result['open'] = (bool) $result['open'];

        $result['reason'] = is_string( $result['reason'] ) && '' !== $result['reason']
            ? $result['reason']
            : ( $result['open'] ? self::REASON_OPEN : $original['reason'] );

        $result['message'] = is_string( $result['message'] ) ? $result['message'] : '';
        if ( ! $result['open'] && '' === trim( $result['message'] ) ) {
            $result['message'] = self::default_closed_message();
        }

        return $result;
    }

    /**
     * Describe a refused value for an error message.
     *
     * @param mixed $value Value.
     * @return string
     */
    private static function describe( $value ) {
        if ( is_string( $value ) ) {
            return '"' . $value . '"';
        }
        if ( is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }
        if ( is_scalar( $value ) ) {
            return (string) $value;
        }
        return gettype( $value );
    }
}
