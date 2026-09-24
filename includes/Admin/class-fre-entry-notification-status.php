<?php
/**
 * What the Entries screen's Email column is allowed to say.
 *
 * The column reports one thing only: what THIS plugin did with the form's own
 * notification. It has no way to know whether some other plugin emailed the
 * team, so it must never imply that it does.
 *
 * Until 1.12.0 it showed a bare grey dash for "nothing recorded", which covers
 * two very different situations: the form's notification is switched off, or it
 * is on and no send was recorded. On a site where another plugin sends the team
 * email — the sensible setup, so the team gets one email instead of two — every
 * entry showed that dash, and it reads as a failure. That cost a real site an
 * afternoon: the owner saw a dash against a genuine lead and concluded no
 * submissions were reaching anyone. They were; a workflow had delivered them.
 *
 * Two changes close that gap:
 *
 * 1. "Off" and "Not sent" are now separate states, so a switched-off
 *    notification reads as a setting rather than a fault.
 * 2. `pforms_entry_notification_status` lets whatever actually sends the email
 *    say so. Anything it reports is rendered ATTRIBUTED and never as this
 *    plugin's own green tick, because we are repeating someone else's claim.
 *
 * This file names no other plugin and requires none.
 *
 * @package PromptlessForms\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves and renders an entry's notification status.
 */
class PForms_Entry_Notification_Status {

    /** This plugin sent it, and recorded that it did. */
    const STATE_SENT = 'sent';

    /** This plugin tried to send it and failed. */
    const STATE_FAILED = 'failed';

    /** The form's own notification is switched off. Nothing was attempted. */
    const STATE_OFF = 'off';

    /** The notification is on, but nothing was recorded for this entry. */
    const STATE_NOT_SENT = 'not_sent';

    /** Another plugin reports it handled this entry. Always attributed. */
    const STATE_EXTERNAL = 'external';

    /**
     * Form settings already looked up this request, keyed by form id.
     *
     * The Entries screen renders 20+ rows that usually share a handful of
     * forms; the registry caches too, but this keeps the hot path free of
     * repeated lookups.
     *
     * @var array<string, bool|null>
     */
    private static $notification_enabled = array();

    /**
     * Work out what to say about one entry.
     *
     * @param array $entry Entry row, as the list table and detail view hold it.
     * @return array {
     *     @type string $state       One of the STATE_* constants.
     *     @type string $label       Short text for the column.
     *     @type string $description Longer text for the tooltip / detail view.
     *     @type string $source      Who reported it; empty when that is us.
     *     @type string $url         Optional link to the reporter's own record.
     * }
     */
    public static function for_entry( $entry ) {
        $status = self::own_status( $entry );

        /**
         * Let whatever actually notified the team say so.
         *
         * This plugin sends a form's own notification and records the result.
         * A site can switch that off and have another plugin send instead —
         * a workflow engine, a CRM bridge, a custom integration. Without this
         * filter the column can only report "nothing here", which reads as a
         * fault rather than as a different sender.
         *
         * Return the array unchanged to leave the column alone. To claim an
         * entry, return `state` = 'external' with a `label` (shown in the
         * column) and a `source` (the plugin's own name, shown to explain who
         * is claiming it). `description` and `url` are optional; `url` should
         * point at your own record of the send, so someone can check it.
         *
         * What you return is rendered as YOUR claim, never as this plugin's:
         * it is attributed on screen and never given the green "Sent" tick,
         * because Promptless Forms cannot verify a send it did not make.
         *
         * @since 1.12.0
         *
         * @param array $status The status this plugin worked out. See the
         *                      return shape of PForms_Entry_Notification_Status::for_entry().
         * @param array $entry  The entry row.
         */
        $filtered = apply_filters( 'pforms_entry_notification_status', $status, $entry );

        return self::normalise( $filtered, $status );
    }

    /**
     * The status from this plugin's own records alone.
     *
     * @param array $entry Entry row.
     * @return array
     */
    private static function own_status( $entry ) {
        if ( ! empty( $entry['notification_sent'] ) ) {
            return self::status(
                self::STATE_SENT,
                __( 'Sent', 'promptless-forms' ),
                __( 'Promptless Forms handed this notification to the mail server.', 'promptless-forms' )
            );
        }

        // Failed only when a send was attempted: every failure path records
        // notification_error. The column defaults to 0, so a form with
        // notifications off used to show every entry as Failed.
        if ( ! empty( $entry['notification_error'] ) ) {
            return self::status( self::STATE_FAILED, __( 'Failed', 'promptless-forms' ), (string) $entry['notification_error'] );
        }

        if ( false === self::notification_enabled( isset( $entry['form_id'] ) ? $entry['form_id'] : '' ) ) {
            return self::status(
                self::STATE_OFF,
                __( 'Off', 'promptless-forms' ),
                __( 'This form\'s own email notification is turned off, so Promptless Forms sent nothing. Anything else that emails your team does not report here.', 'promptless-forms' )
            );
        }

        return self::status(
            self::STATE_NOT_SENT,
            __( 'Not sent', 'promptless-forms' ),
            __( 'No notification was recorded for this entry.', 'promptless-forms' )
        );
    }

    /**
     * Is the form's own notification switched on?
     *
     * @param string $form_id Form id.
     * @return bool|null True/false, or null when the form is gone and we
     *                   cannot tell (a deleted form keeps its entries).
     */
    private static function notification_enabled( $form_id ) {
        $form_id = (string) $form_id;

        if ( '' === $form_id ) {
            return null;
        }

        if ( array_key_exists( $form_id, self::$notification_enabled ) ) {
            return self::$notification_enabled[ $form_id ];
        }

        $enabled = null;

        if ( function_exists( 'pforms' ) && isset( pforms()->registry ) ) {
            $config = pforms()->registry->get( $form_id );

            if ( is_array( $config ) ) {
                // A form with no notification block at all defaults to on,
                // the same default the submission handler applies.
                $enabled = ! isset( $config['settings']['notification']['enabled'] )
                    || ! empty( $config['settings']['notification']['enabled'] );
            }
        }

        self::$notification_enabled[ $form_id ] = $enabled;

        return $enabled;
    }

    /**
     * Build a status array.
     *
     * @param string $state       State constant.
     * @param string $label       Column text.
     * @param string $description Tooltip text.
     * @return array
     */
    private static function status( $state, $label, $description ) {
        return array(
            'state'       => $state,
            'label'       => $label,
            'description' => $description,
            'source'      => '',
            'url'         => '',
        );
    }

    /**
     * Make a filtered status safe to render.
     *
     * A filter that returns something unusable falls back to what we worked
     * out ourselves rather than blanking the column. An 'external' claim must
     * carry a label and a source, because the whole point is that the reader
     * can see who is claiming it.
     *
     * @param mixed $filtered What the filter returned.
     * @param array $fallback Our own status.
     * @return array
     */
    private static function normalise( $filtered, array $fallback ) {
        if ( ! is_array( $filtered ) || empty( $filtered['state'] ) ) {
            return $fallback;
        }

        $state = (string) $filtered['state'];
        $known = array( self::STATE_SENT, self::STATE_FAILED, self::STATE_OFF, self::STATE_NOT_SENT, self::STATE_EXTERNAL );

        if ( ! in_array( $state, $known, true ) ) {
            return $fallback;
        }

        $status = array(
            'state'       => $state,
            'label'       => isset( $filtered['label'] ) ? (string) $filtered['label'] : $fallback['label'],
            'description' => isset( $filtered['description'] ) ? (string) $filtered['description'] : '',
            'source'      => isset( $filtered['source'] ) ? (string) $filtered['source'] : '',
            'url'         => isset( $filtered['url'] ) ? (string) $filtered['url'] : '',
        );

        if ( self::STATE_EXTERNAL === $state && ( '' === trim( $status['label'] ) || '' === trim( $status['source'] ) ) ) {
            return $fallback;
        }

        // Only another plugin's claim carries a source, and a claim from
        // elsewhere is always the 'external' state — otherwise a filter could
        // borrow our green "Sent" tick for a send we never made.
        if ( self::STATE_EXTERNAL !== $state ) {
            $status['source'] = '';
            $status['url']    = '';
        }

        return $status;
    }

    /**
     * The Entries column cell.
     *
     * @param array $entry Entry row.
     * @return string HTML.
     */
    public static function column_html( $entry ) {
        $status = self::for_entry( $entry );
        $title  = self::tooltip( $status );

        switch ( $status['state'] ) {
            case self::STATE_SENT:
                return '<span class="dashicons dashicons-yes" style="color:#46b450;" title="' . esc_attr( $title ) . '"></span>';

            case self::STATE_FAILED:
                return '<span class="dashicons dashicons-warning" style="color:#d63638;" title="' . esc_attr( $title ) . '"></span>';

            case self::STATE_EXTERNAL:
                $text = '<span style="color:#50575e;">' . esc_html( $status['label'] ) . '</span>';

                if ( '' !== $status['url'] ) {
                    $text = '<a href="' . esc_url( $status['url'] ) . '">' . esc_html( $status['label'] ) . '</a>';
                }

                return '<span class="pforms-entry-notification--external" title="' . esc_attr( $title ) . '">' . $text . '</span>';

            case self::STATE_OFF:
                // Deliberately a word, not the dash this used to show: a dash
                // in a column of ticks reads as a failure.
                return '<span style="color:#787c82;" title="' . esc_attr( $title ) . '">' . esc_html__( 'Off', 'promptless-forms' ) . '</span>';

            default:
                return '<span class="dashicons dashicons-minus" style="color:#999;" title="' . esc_attr( $title ) . '"></span>';
        }
    }

    /**
     * Tooltip text, with the attribution when someone else is claiming it.
     *
     * @param array $status Status array.
     * @return string
     */
    public static function tooltip( array $status ) {
        $parts = array();

        if ( '' !== $status['description'] ) {
            $parts[] = $status['description'];
        }

        if ( self::STATE_EXTERNAL === $status['state'] && '' !== $status['source'] ) {
            $parts[] = sprintf(
                /* translators: %s: the name of the plugin reporting the send, e.g. "FlowMint Workflows". */
                __( 'Reported by %s. Promptless Forms did not send this and cannot confirm it.', 'promptless-forms' ),
                $status['source']
            );
        }

        return implode( ' ', $parts );
    }

    /**
     * Forget cached form settings. For tests, and for long-running processes.
     *
     * @return void
     */
    public static function flush_cache() {
        self::$notification_enabled = array();
    }
}
