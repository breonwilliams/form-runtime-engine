<?php
/**
 * Submission lock for Promptless Forms.
 *
 * One place that decides whether a submission is new, still being processed,
 * or already received — for both guards the submission handler runs:
 *
 *   - the idempotency guard, keyed on the submission id the browser sends
 *     (`_pforms_submission_id`), which makes a retry of the SAME attempt safe;
 *   - the duplicate guard, keyed on a hash of the submitted fields, which
 *     catches the same content sent twice within a short window.
 *
 * WHY THIS IS NOT THE TRANSIENT API
 *
 * Until 1.11.0 the duplicate guard wrote its row straight into wp_options
 * with SQL (for an atomic insert) but cleared it with delete_transient().
 * On a site with a persistent object cache (Redis, Memcached — standard on
 * managed hosting) delete_transient() only touches the cache, so the row
 * survived every failed attempt. A retry within the next minute was then
 * treated as a duplicate and answered with the success message while nothing
 * was saved. The visitor was told "Thanks — we have your request" and the
 * business never received it. Measured on a 725 Print Lab form: a refused
 * upload followed by a second tap showed the red error AND the green success
 * message, and no entry, email, webhook or workflow run existed.
 *
 * So every row here is written, read, updated and deleted through $wpdb and
 * nothing else. The row names deliberately keep the `_transient_pforms_*` /
 * `_transient_timeout_pforms_*` pair the 1.10.x guards used: uninstall already
 * removes them, core's expired-transient sweep can clean them on sites without
 * a persistent cache, and rows written by 1.10.x in the minute around an
 * upgrade are still understood. Never pass these keys to get_transient(),
 * set_transient() or delete_transient() — on a cached site those read and
 * write a different store.
 *
 * @package FormRuntimeEngine
 * @since   1.11.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Atomic claim / update / release of submission guard rows in wp_options.
 */
class PForms_Submission_Lock {

    /**
     * The submission is being processed by the request that claimed it.
     */
    const STATE_PROCESSING = 'processing';

    /**
     * The submission was received: the entry and its files are stored (or,
     * with entry storage off, the notification step was reached).
     */
    const STATE_COMPLETED = 'completed';

    /**
     * Row name prefix for the duplicate (content hash) guard.
     */
    const DUPLICATE_PREFIX = 'pforms_submission_';

    /**
     * Row name prefix for the idempotency (submission id) guard.
     */
    const IDEMPOTENCY_PREFIX = 'pforms_idempotent_';

    /**
     * One claim in this many also sweeps expired rows. Sites with a
     * persistent object cache never run core's expired-transient sweep over
     * wp_options, so without this the rows would accumulate forever.
     */
    const CLEANUP_ONE_IN = 50;

    /**
     * Key for the duplicate guard. Must stay identical to the 1.10.x hash so
     * rows written across an upgrade are recognised.
     *
     * @param string $form_id Form ID.
     * @param array  $data    Submitted data the duplicate check compares.
     * @return string
     */
    public static function duplicate_key( $form_id, array $data ) {
        return self::DUPLICATE_PREFIX . hash( 'sha256', $form_id . wp_json_encode( $data ) );
    }

    /**
     * Key for the idempotency guard. Same derivation as 1.10.x.
     *
     * @param string $form_id       Form ID.
     * @param string $submission_id Browser-generated submission UUID.
     * @return string
     */
    public static function idempotency_key( $form_id, $submission_id ) {
        return self::IDEMPOTENCY_PREFIX . hash( 'sha256', $form_id . '_' . $submission_id );
    }

    /**
     * Claim a key for this request.
     *
     * Returns true when this request now owns the key (it was free, or its
     * previous holder's window had expired). Otherwise returns the record the
     * current holder left: array( 'state' => …, 'entry_id' => …, 'response' => … ).
     *
     * The claim is a single INSERT IGNORE, so two requests racing for the same
     * key cannot both win. Taking over an expired row is a compare-and-swap on
     * the old value for the same reason.
     *
     * @param string $key Guard key (without the _transient_ prefix).
     * @param int    $ttl Seconds the claim holds before another request may take it over.
     * @return true|array
     */
    public function claim( $key, $ttl ) {
        global $wpdb;

        if ( 1 === wp_rand( 1, self::CLEANUP_ONE_IN ) ) {
            $this->cleanup_expired();
        }

        $name    = '_transient_' . $key;
        $expires = time() + (int) $ttl;
        // The claim token makes every claim's value unique. Without it, taking
        // over an expired "processing" row would write an identical value, and
        // MySQL reports 0 changed rows for that, which reads as "lost the race".
        //
        // The expiry lives INSIDE the record so a claim is one atomic write. A
        // separate expiry row alone would leave a gap between the two writes in
        // which a second request saw "no expiry" and took the claim over.
        $value   = $this->encode( array( 'state' => self::STATE_PROCESSING, 'exp' => $expires, 'claim' => wp_generate_uuid4() ) );

        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            $value
        ) );

        if ( 1 === (int) $inserted ) {
            $this->write_expiry( $key, $expires );
            return true;
        }

        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $name
        ) );

        if ( null === $current ) {
            // Released between our INSERT and SELECT: try once more.
            $inserted = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $name,
                $value
            ) );
            if ( 1 === (int) $inserted ) {
                $this->write_expiry( $key, $expires );
                return true;
            }
            return array( 'state' => self::STATE_PROCESSING, 'entry_id' => 0, 'response' => null );
        }

        if ( $this->is_expired( $key, $current ) ) {
            $taken = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $value,
                $name,
                $current
            ) );
            if ( 1 === (int) $taken ) {
                $this->write_expiry( $key, $expires );
                return true;
            }
            $current = $wpdb->get_var( $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $name
            ) );
        }

        return $this->decode( $current );
    }

    /**
     * Replace the record on a key this request owns.
     *
     * @param string   $key    Guard key.
     * @param array    $record Record: state, and optionally entry_id / response.
     * @param int|null $ttl    New lifetime in seconds from now, or null to keep the current expiry.
     * @return bool
     */
    public function update( $key, array $record, $ttl = null ) {
        global $wpdb;

        if ( null !== $ttl ) {
            $record['exp'] = time() + (int) $ttl;
        } else {
            $current       = json_decode( (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                '_transient_' . $key
            ) ), true );
            $record['exp'] = is_array( $current ) && isset( $current['exp'] ) ? (int) $current['exp'] : time();
        }

        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
            $this->encode( $record ),
            '_transient_' . $key
        ) );

        $this->write_expiry( $key, $record['exp'] );

        return false !== $updated;
    }

    /**
     * Delete a key's rows so the next attempt starts clean.
     *
     * @param string $key Guard key.
     * @return void
     */
    public function release( $key ) {
        global $wpdb;

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
            '_transient_' . $key,
            '_transient_timeout_' . $key
        ) );
    }

    /**
     * Delete every expired guard row (both the record and its expiry row).
     *
     * @return int Rows deleted.
     */
    public function cleanup_expired() {
        global $wpdb;

        $deleted = 0;
        foreach ( array( self::DUPLICATE_PREFIX, self::IDEMPOTENCY_PREFIX ) as $prefix ) {
            $result = $wpdb->query( $wpdb->prepare(
                "DELETE a, b FROM {$wpdb->options} a
                 INNER JOIN {$wpdb->options} b
                    ON b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
                 WHERE a.option_name LIKE %s
                   AND CAST( b.option_value AS UNSIGNED ) < %d",
                $wpdb->esc_like( '_transient_' . $prefix ) . '%',
                time()
            ) );
            $deleted += (int) $result;
        }

        return $deleted;
    }

    /**
     * Whether a key's window has passed. The record's own expiry is
     * authoritative; the separate expiry row is consulted only for a 1.10.x
     * record, which carried none. A legacy record with no expiry row either is
     * treated as expired: nothing can say how long it was meant to hold.
     *
     * @param string $key Guard key.
     * @param string $raw The record's stored value.
     * @return bool
     */
    private function is_expired( $key, $raw ) {
        global $wpdb;

        $json = json_decode( (string) $raw, true );
        if ( is_array( $json ) && isset( $json['exp'] ) ) {
            return (int) $json['exp'] < time();
        }

        $expires = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            '_transient_timeout_' . $key
        ) );

        return null === $expires || (int) $expires < time();
    }

    /**
     * Write (or overwrite) a key's expiry row.
     *
     * @param string $key     Guard key.
     * @param int    $expires Unix time the claim expires.
     * @return void
     */
    private function write_expiry( $key, $expires ) {
        global $wpdb;

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            '_transient_timeout_' . $key,
            (int) $expires
        ) );
    }

    /**
     * Encode a record for storage.
     *
     * @param array $record Record.
     * @return string
     */
    private function encode( array $record ) {
        return (string) wp_json_encode( $record );
    }

    /**
     * Decode a stored record, including the two shapes 1.10.x wrote:
     * the duplicate guard stored its expiry time as a bare number, and the
     * idempotency guard stored a serialized array( 'status', 'response' ).
     *
     * @param string $raw Stored value.
     * @return array
     */
    private function decode( $raw ) {
        $record = array(
            'state'    => self::STATE_PROCESSING,
            'entry_id' => 0,
            'response' => null,
        );

        $json = json_decode( (string) $raw, true );
        if ( is_array( $json ) ) {
            if ( isset( $json['state'] ) && self::STATE_COMPLETED === $json['state'] ) {
                $record['state'] = self::STATE_COMPLETED;
            }
            $record['entry_id'] = isset( $json['entry_id'] ) ? (int) $json['entry_id'] : 0;
            $record['response'] = isset( $json['response'] ) && is_array( $json['response'] ) ? $json['response'] : null;
            return $record;
        }

        // 1.10.x idempotency row (written by set_transient on a site without
        // a persistent cache). Never unserialize objects out of the options table.
        if ( is_serialized( (string) $raw ) ) {
            $legacy = @unserialize( (string) $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
            if ( is_array( $legacy ) && isset( $legacy['status'] ) && 'completed' === $legacy['status'] && isset( $legacy['response'] ) && is_array( $legacy['response'] ) ) {
                $record['state']    = self::STATE_COMPLETED;
                $record['response'] = $legacy['response'];
            }
        }

        // A bare number is a 1.10.x duplicate row: "someone is on it".
        return $record;
    }
}
