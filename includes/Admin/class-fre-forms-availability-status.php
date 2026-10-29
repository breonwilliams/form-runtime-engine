<?php
/**
 * Availability status on the Forms list (wp-admin).
 *
 * What the form owner sees in the Forms list's Status column: whether the
 * form is open, why it is closed, its dates in the site timezone with the
 * zone named, and — for a form with an entry cap, or one that has had a cap
 * (Q-010: it keeps counting) — the places taken against the cap, with the
 * Adjust count control (Q-001) whose handler is
 * PForms_Forms_Manager::ajax_set_place_count().
 *
 * Read-only apart from that control: the rules themselves are edited in the
 * form JSON like every other setting (Q-002).
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds and renders a stored form's availability status for the admin.
 */
class PForms_Forms_Availability_Status {

    /**
     * Describe a stored form's availability for the admin.
     *
     * @param string   $form_id Form ID.
     * @param array    $record  Stored form record (PForms_Forms_Repository).
     * @param int|null $now     Moment to decide at (unix); null = now.
     * @return array {
     *     @type bool     $open       Whether submissions are accepted.
     *     @type string   $reason     PForms_Availability reason.
     *     @type string   $label      "Open", or "Closed: <why>".
     *     @type string[] $details    Dates and the count, one line each.
     *     @type int|null $cap        max_entries, null = no cap.
     *     @type int|null $count      Places taken; null when the form does not count.
     *     @type bool     $adjustable Whether the count control is offered.
     * }
     */
    public static function describe( $form_id, array $record, $now = null ) {
        $form_id = (string) $form_id;
        $config  = isset( $record['config'] ) && is_string( $record['config'] ) ? json_decode( $record['config'], true ) : null;
        $config  = is_array( $config ) ? $config : array();

        $context = array( 'form_id' => $form_id );
        if ( null !== $now ) {
            $context['now'] = (int) $now;
        }

        $decision = PForms_Availability::check( $config, $context );
        $now      = (int) $decision['now'];

        $configured = isset( $config['settings']['availability'] ) && is_array( $config['settings']['availability'] )
            ? $config['settings']['availability']
            : array();

        $cap   = isset( $decision['cap'] ) ? (int) $decision['cap'] : null;
        $count = null;
        if ( null !== $cap ) {
            $count = (int) $decision['count'];
        } elseif ( '' !== $form_id ) {
            // Q-010: a form that has had a cap keeps counting while it is off.
            $counter = new PForms_Place_Counter();
            if ( $counter->exists( $form_id ) ) {
                $count = $counter->get( $form_id );
            }
        }

        $details = array();

        $opens_at = isset( $decision['opens_at'] ) ? $decision['opens_at'] : null;
        if ( null !== $opens_at && $now < $opens_at ) {
            /* translators: %s: opening date and time with the timezone, e.g. "March 15, 2027 at 9:00 am NZDT" */
            $details[] = sprintf( __( 'Opens %s', 'promptless-forms' ), self::format_moment( $opens_at ) );
        }

        $closes_at = isset( $decision['closes_at'] ) ? $decision['closes_at'] : null;
        if ( null !== $closes_at ) {
            $details[] = self::closing_line( $closes_at, $now, $configured['closes_at'] ?? null );
        }

        if ( null !== $cap ) {
            $details[] = sprintf(
                /* translators: 1: places taken, 2: the entry cap (max_entries) */
                _n( '%1$s of %2$s place taken', '%1$s of %2$s places taken', $cap, 'promptless-forms' ),
                number_format_i18n( $count ),
                number_format_i18n( $cap )
            );
        } elseif ( null !== $count ) {
            $details[] = sprintf(
                /* translators: %s: places taken while the form has no entry cap */
                __( '%s counted (no entry limit set)', 'promptless-forms' ),
                number_format_i18n( $count )
            );
        }

        return array(
            'open'       => (bool) $decision['open'],
            'reason'     => (string) $decision['reason'],
            'label'      => self::state_label( (bool) $decision['open'], (string) $decision['reason'] ),
            'details'    => $details,
            'cap'        => $cap,
            'count'      => $count,
            'adjustable' => null !== $count,
        );
    }

    /**
     * Render the Status cell for one stored form.
     *
     * @param string   $form_id Form ID.
     * @param array    $record  Stored form record.
     * @param int|null $now     Moment to decide at (unix); null = now.
     * @return string HTML.
     */
    public static function render( $form_id, array $record, $now = null ) {
        $status = self::describe( $form_id, $record, $now );
        $slug   = sanitize_key( $form_id );

        $html  = '<div class="fre-availability-status" data-open="' . ( $status['open'] ? '1' : '0' ) . '" data-reason="' . esc_attr( $status['reason'] ) . '">';
        $html .= '<strong class="fre-availability-state">' . esc_html( $status['label'] ) . '</strong>';

        foreach ( $status['details'] as $detail ) {
            $html .= '<br><span class="description fre-availability-detail">' . esc_html( $detail ) . '</span>';
        }

        if ( $status['adjustable'] ) {
            $panel = 'fre-place-count-' . $slug;
            $input = 'fre-place-count-input-' . $slug;

            $html .= '<br><button type="button" class="button-link fre-place-count-toggle" aria-expanded="false" aria-controls="' . esc_attr( $panel ) . '">'
                . esc_html__( 'Adjust count', 'promptless-forms' ) . '</button>';
            $html .= '<div id="' . esc_attr( $panel ) . '" class="fre-place-count-control hidden" data-form-id="' . esc_attr( $form_id ) . '">';
            $html .= '<label for="' . esc_attr( $input ) . '">' . esc_html__( 'Places taken', 'promptless-forms' ) . '</label> ';
            $html .= '<input type="number" min="0" step="1" class="small-text fre-place-count-input" id="' . esc_attr( $input ) . '" value="' . esc_attr( (string) (int) $status['count'] ) . '"> ';
            $html .= '<button type="button" class="button button-small fre-place-count-save">' . esc_html__( 'Save count', 'promptless-forms' ) . '</button> ';
            $html .= '<button type="button" class="button-link fre-place-count-reset">' . esc_html__( 'Reset to 0', 'promptless-forms' ) . '</button>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * A moment in the site timezone, zone named (Q-007, A-003):
     * "March 15, 2027 at 9:00 am NZDT".
     *
     * @param int $timestamp Moment (unix).
     * @return string
     */
    public static function format_moment( $timestamp ) {
        $timezone    = wp_timezone();
        $date_format = get_option( 'date_format' );
        $time_format = get_option( 'time_format' );

        return sprintf(
            /* translators: 1: date, 2: time, 3: timezone label (e.g. "NZDT", "UTC+5:30"), all in the site timezone */
            __( '%1$s at %2$s %3$s', 'promptless-forms' ),
            wp_date( $date_format ? $date_format : 'F j, Y', $timestamp, $timezone ),
            wp_date( $time_format ? $time_format : 'g:i a', $timestamp, $timezone ),
            PForms_Availability::timezone_label( $timestamp, $timezone )
        );
    }

    /**
     * "Open", or "Closed: <why>".
     *
     * @param bool   $open   Whether open.
     * @param string $reason Reason.
     * @return string
     */
    private static function state_label( $open, $reason ) {
        if ( $open ) {
            return __( 'Open', 'promptless-forms' );
        }

        switch ( $reason ) {
            case PForms_Availability::REASON_NOT_YET_OPEN:
                return __( 'Closed: not open yet', 'promptless-forms' );
            case PForms_Availability::REASON_CLOSED_DATE:
                return __( 'Closed: past its closing date', 'promptless-forms' );
            case PForms_Availability::REASON_CAP_REACHED:
                return __( 'Closed: entry limit reached', 'promptless-forms' );
            case PForms_Availability::REASON_INVALID_SETTINGS:
                return __( 'Closed: availability settings are invalid', 'promptless-forms' );
            default:
                /* translators: %s: machine-readable reason given by a pforms_form_availability filter */
                return sprintf( __( 'Closed: by a custom rule (%s)', 'promptless-forms' ), $reason );
        }
    }

    /**
     * The closing-date line. A bare-date closes_at closes at the end of that
     * day, so it is named as that day rather than as 12:00 am the next.
     *
     * @param int         $closes_at  First closed moment (unix).
     * @param int         $now        Moment decided at.
     * @param string|null $configured The closes_at value as written.
     * @return string
     */
    private static function closing_line( $closes_at, $now, $configured ) {
        $past = $now >= $closes_at;

        if ( is_string( $configured ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $configured ) ) {
            $timezone    = wp_timezone();
            $date_format = get_option( 'date_format' );
            $last_second = $closes_at - 1;
            $day         = wp_date( $date_format ? $date_format : 'F j, Y', $last_second, $timezone );
            $zone        = PForms_Availability::timezone_label( $last_second, $timezone );

            return $past
                /* translators: 1: closing date, 2: timezone label, in the site timezone */
                ? sprintf( __( 'Closed at the end of %1$s (%2$s)', 'promptless-forms' ), $day, $zone )
                /* translators: 1: closing date, 2: timezone label, in the site timezone */
                : sprintf( __( 'Closes at the end of %1$s (%2$s)', 'promptless-forms' ), $day, $zone );
        }

        return $past
            /* translators: %s: closing date and time with the timezone */
            ? sprintf( __( 'Closed %s', 'promptless-forms' ), self::format_moment( $closes_at ) )
            /* translators: %s: closing date and time with the timezone */
            : sprintf( __( 'Closes %s', 'promptless-forms' ), self::format_moment( $closes_at ) );
    }
}
