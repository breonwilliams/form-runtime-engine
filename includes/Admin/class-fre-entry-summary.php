<?php
/**
 * Which stored answers identify an entry, and which are internal.
 *
 * The entries list titled each entry by its first two stored answers. A form
 * that asks about the project first and for the person last (the
 * Sparrowmere consultation form, 2026-10) listed every lead as "What kind of
 * project is it?: Patio or hardscape", so a business owner scanning leads
 * could not tell who was who. The summary now leads with the person: a name
 * field, then the first email field, then the first other answers in form
 * order.
 *
 * Keys that start with an underscore are internal metadata stored beside the
 * answers (the `_pforms_form_version` stamp), never something the visitor
 * typed. Every admin view of the answers skips them.
 *
 * @package FormRuntimeEngine
 * @since 1.12.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Entry summary helpers.
 */
class PForms_Entry_Summary {

    /**
     * Field keys read as the person's full name when no field says so
     * through autocomplete.
     *
     * @var string[]
     */
    const NAME_KEYS = array( 'name', 'full_name', 'fullname', 'your_name' );

    /**
     * Field keys read as the first / last part of a split name.
     *
     * @var string[]
     */
    const GIVEN_NAME_KEYS  = array( 'first_name', 'firstname', 'given_name' );
    const FAMILY_NAME_KEYS = array( 'last_name', 'lastname', 'family_name', 'surname' );

    /**
     * Whether a stored key is internal metadata rather than an answer.
     *
     * @param string|int $key Stored field key.
     * @return bool
     */
    public static function is_internal_key( $key ) {
        return 0 === strpos( (string) $key, '_' );
    }

    /**
     * Drop internal keys from a stored fields array.
     *
     * @param array $fields Stored fields (key => value).
     * @return array
     */
    public static function answers( array $fields ) {
        $answers = array();
        foreach ( $fields as $key => $value ) {
            if ( ! self::is_internal_key( $key ) ) {
                $answers[ $key ] = $value;
            }
        }
        return $answers;
    }

    /**
     * Choose the answers that summarise an entry.
     *
     * Returns up to $limit parts in display order. Each part is
     * `array( 'keys' => string[], 'label' => string|null )`: one key for an
     * ordinary answer, two for a split first/last name (shown joined, with
     * `label` set to "Name"). `label` null means "use the field's own label".
     * Only answers with a value are chosen.
     *
     * @param array $fields    Stored fields (key => value), in stored order.
     * @param array $field_map Field configs by key (may be empty for a
     *                         deleted form; key-based detection still works).
     * @param int   $limit     Maximum number of parts.
     * @return array[]
     */
    public static function pick( array $fields, array $field_map, $limit = 2 ) {
        $answers = array_filter( self::answers( $fields ), array( __CLASS__, 'has_value' ) );
        $parts   = array();
        $used    = array();

        $name = self::find_name( $answers, $field_map );
        if ( $name ) {
            $parts[] = $name;
            $used    = array_merge( $used, $name['keys'] );
        }

        $email = self::find_email( $answers, $field_map, $used );
        if ( null !== $email ) {
            $parts[] = array( 'keys' => array( $email ), 'label' => null );
            $used[]  = $email;
        }

        foreach ( $answers as $key => $value ) {
            if ( in_array( $key, $used, true ) ) {
                continue;
            }
            $parts[] = array( 'keys' => array( $key ), 'label' => null );
        }

        return array_slice( $parts, 0, max( 0, (int) $limit ) );
    }

    /**
     * Whether a stored value is worth showing.
     *
     * @param mixed $value Stored value.
     * @return bool
     */
    public static function has_value( $value ) {
        if ( is_array( $value ) ) {
            return array() !== array_filter( $value, array( __CLASS__, 'has_value' ) );
        }
        return '' !== trim( (string) $value );
    }

    /**
     * The name answer: a full-name field, else a first + last pair.
     *
     * @param array $answers   Answers with a value.
     * @param array $field_map Field configs by key.
     * @return array|null Part, or null when the form has no name field.
     */
    private static function find_name( array $answers, array $field_map ) {
        $given  = null;
        $family = null;

        foreach ( $answers as $key => $value ) {
            $auto = self::autocomplete( $key, $field_map );
            if ( 'name' === $auto || ( '' === $auto && in_array( $key, self::NAME_KEYS, true ) ) ) {
                return array( 'keys' => array( $key ), 'label' => null );
            }
            if ( null === $given && ( 'given-name' === $auto || ( '' === $auto && in_array( $key, self::GIVEN_NAME_KEYS, true ) ) ) ) {
                $given = $key;
            }
            if ( null === $family && ( 'family-name' === $auto || ( '' === $auto && in_array( $key, self::FAMILY_NAME_KEYS, true ) ) ) ) {
                $family = $key;
            }
        }

        $keys = array_values( array_filter( array( $given, $family ), 'is_string' ) );
        if ( ! $keys ) {
            return null;
        }

        return array(
            'keys'  => $keys,
            'label' => 2 === count( $keys ) ? __( 'Name', 'promptless-forms' ) : null,
        );
    }

    /**
     * The first email answer.
     *
     * @param array    $answers   Answers with a value.
     * @param array    $field_map Field configs by key.
     * @param string[] $used      Keys already chosen.
     * @return string|null Field key.
     */
    private static function find_email( array $answers, array $field_map, array $used ) {
        foreach ( $answers as $key => $value ) {
            if ( in_array( $key, $used, true ) ) {
                continue;
            }
            $type = isset( $field_map[ $key ]['type'] ) ? $field_map[ $key ]['type'] : '';
            if ( 'email' === $type || 'email' === self::autocomplete( $key, $field_map ) || ( '' === $type && 'email' === $key ) ) {
                return $key;
            }
        }
        return null;
    }

    /**
     * A field's autocomplete token, lower-cased ('' when unset).
     *
     * @param string $key       Field key.
     * @param array  $field_map Field configs by key.
     * @return string
     */
    private static function autocomplete( $key, array $field_map ) {
        if ( empty( $field_map[ $key ]['autocomplete'] ) || ! is_string( $field_map[ $key ]['autocomplete'] ) ) {
            return '';
        }
        // A token may carry a section or contact prefix ("shipping name").
        $tokens = preg_split( '/\s+/', strtolower( trim( $field_map[ $key ]['autocomplete'] ) ) );
        return (string) end( $tokens );
    }
}
