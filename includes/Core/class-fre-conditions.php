<?php
/**
 * Conditional visibility evaluator for Form Runtime Engine.
 *
 * Single source of truth for evaluating whether a field's `conditions` block
 * is met. Used during validation (skip required-field checks for hidden fields)
 * and during submission processing (strip orphan values from hidden fields
 * before storage so downstream consumers — email, webhook, sheet, CSV, admin —
 * never see leaked values from fields the prospect couldn't actually see).
 *
 * Designed to be shape-agnostic about the data array: the lookup tries the
 * clean field key first, then the prefixed `fre_field_<key>` form, so the
 * same evaluator works both pre-sanitize (validator path, where data is
 * raw $_POST with prefixed keys) and post-sanitize (submission handler path,
 * where data is the sanitizer's clean-keyed return map).
 *
 * @package FormRuntimeEngine
 * @since   1.5.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conditional visibility helper.
 */
class FRE_Conditions {

    /**
     * Determine whether a field is visible given the current submission data.
     *
     * A field with no `conditions` block is always visible. A field whose
     * conditions evaluate to false is hidden — its value should be ignored
     * for validation and stripped before storage.
     *
     * @param array $field       Field configuration.
     * @param array $form_config Full form configuration.
     * @param array $data        Submission data (clean or prefixed keys).
     * @return bool True if the field should be treated as visible.
     */
    public static function field_is_visible( array $field, array $form_config, array $data ) {
        if ( empty( $field['conditions'] ) ) {
            $visible = true;
        } else {
            $visible = self::evaluate_conditions( $field['conditions'], $form_config, $data );
        }

        /**
         * Filter a field's computed visibility.
         *
         * Lets sites layer additional gates (role-based, locale-based, A/B-test
         * cohorts, etc.) on top of the form's declared `conditions` block.
         * Returning false hides the field — its value will be stripped at
         * submission time and skipped by the validator.
         *
         * @param bool  $visible     Whether the field is currently visible.
         * @param array $field       Field configuration.
         * @param array $form_config Full form configuration.
         * @param array $data        Submission data.
         */
        return (bool) apply_filters( 'fre_field_is_visible', $visible, $field, $form_config, $data );
    }

    /**
     * Strip values from any field whose `conditions` evaluate to false.
     *
     * Called by the submission handler immediately after sanitization and
     * before entry creation, so storage, email notifications, webhooks,
     * Google Sheets, CSV exports, and the admin entry detail view all see
     * a clean payload without orphan values from conditionally-hidden fields.
     *
     * The returned array is a copy — the caller's input is not mutated.
     *
     * @param array $form_config Full form configuration.
     * @param array $data        Sanitized submission data (clean field keys).
     * @return array Data with hidden-field values removed.
     */
    public static function strip_hidden_field_values( array $form_config, array $data ) {
        if ( empty( $form_config['fields'] ) || ! is_array( $form_config['fields'] ) ) {
            return $data;
        }

        foreach ( $form_config['fields'] as $field ) {
            if ( empty( $field['key'] ) || empty( $field['conditions'] ) ) {
                continue;
            }

            if ( self::field_is_visible( $field, $form_config, $data ) ) {
                continue;
            }

            // Drop both clean-key and prefixed-key variants so the helper is
            // safe to call on either shape of data array.
            unset( $data[ $field['key'] ] );
            unset( $data[ 'fre_field_' . $field['key'] ] );
        }

        return $data;
    }

    /**
     * Evaluate a `conditions` block against submission data.
     *
     * Public so other surfaces (e.g., the renderer's server-rendered fallback,
     * future REST endpoints, custom integrations) can run the same evaluator
     * without forking the rule logic.
     *
     * @param array $conditions  Conditions block ({ rules: [...], logic: 'and'|'or' }).
     * @param array $form_config Full form configuration (used to resolve referenced fields).
     * @param array $data        Submission data (clean or prefixed keys).
     * @return bool True if the conditions are met.
     */
    public static function evaluate_conditions( array $conditions, array $form_config, array $data ) {
        if ( empty( $conditions['rules'] ) || ! is_array( $conditions['rules'] ) ) {
            return true;
        }

        $logic   = isset( $conditions['logic'] ) ? $conditions['logic'] : 'and';
        $results = array();

        foreach ( $conditions['rules'] as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }
            $results[] = self::evaluate_rule( $rule, $form_config, $data );
        }

        if ( 'or' === $logic ) {
            return in_array( true, $results, true );
        }

        // Default 'and' logic: every rule must pass.
        return ! in_array( false, $results, true );
    }

    /**
     * Evaluate a single rule.
     *
     * Mirrors the operator vocabulary previously implemented in
     * `FRE_Validator::evaluate_rule()`. Equivalences (`==`, `=` for `equals`;
     * `!=`, `<>` for `not_equals`; etc.) are preserved so existing form
     * configurations continue to evaluate identically.
     *
     * @param array $rule        Rule configuration.
     * @param array $form_config Full form configuration.
     * @param array $data        Submission data.
     * @return bool True if the rule passes.
     */
    private static function evaluate_rule( array $rule, array $form_config, array $data ) {
        if ( empty( $rule['field'] ) || ! isset( $rule['operator'] ) ) {
            return true;
        }

        $field_key = $rule['field'];
        $operator  = $rule['operator'];
        $value     = isset( $rule['value'] ) ? $rule['value'] : '';

        $field_value = self::get_field_value_from_data( $field_key, $form_config, $data );

        switch ( $operator ) {
            case 'equals':
            case '==':
            case '=':
                return $field_value === $value;

            case 'not_equals':
            case '!=':
            case '<>':
                return $field_value !== $value;

            case 'contains':
                return strpos( strtolower( (string) $field_value ), strtolower( (string) $value ) ) !== false;

            case 'not_contains':
                return strpos( strtolower( (string) $field_value ), strtolower( (string) $value ) ) === false;

            case 'is_empty':
            case 'empty':
                return $field_value === '' || $field_value === null
                    || ( is_array( $field_value ) && empty( $field_value ) );

            case 'is_not_empty':
            case 'not_empty':
                return $field_value !== '' && $field_value !== null
                    && ! ( is_array( $field_value ) && empty( $field_value ) );

            case 'is_checked':
            case 'checked':
                return $field_value === true || $field_value === '1' || $field_value === 'on';

            case 'is_not_checked':
            case 'not_checked':
                return $field_value === false || $field_value === '' || $field_value === '0' || $field_value === null;

            case 'greater_than':
            case '>':
                return floatval( $field_value ) > floatval( $value );

            case 'less_than':
            case '<':
                return floatval( $field_value ) < floatval( $value );

            case 'greater_than_or_equals':
            case '>=':
                return floatval( $field_value ) >= floatval( $value );

            case 'less_than_or_equals':
            case '<=':
                return floatval( $field_value ) <= floatval( $value );

            case 'in':
                return is_array( $value ) && in_array( $field_value, $value, true );

            case 'not_in':
                return ! is_array( $value ) || ! in_array( $field_value, $value, true );

            default:
                return true;
        }
    }

    /**
     * Resolve a field's value from the submission data.
     *
     * Tries the clean field key first (the post-sanitize shape), then falls
     * back to `fre_field_<key>` (the pre-sanitize / raw $_POST shape) so the
     * same evaluator works in both the validator path and the submission
     * handler's strip path without two parallel implementations.
     *
     * Note on $form_config: kept in the signature for forward compatibility
     * (e.g., resolving a single-checkbox's presence semantics, or de-aliasing
     * legacy field key renames in the future). Currently unused.
     *
     * @param string $field_key   Clean field key.
     * @param array  $form_config Full form configuration.
     * @param array  $data        Submission data.
     * @return mixed Field value, or '' if absent.
     */
    private static function get_field_value_from_data( $field_key, array $form_config, array $data ) {
        unset( $form_config ); // Reserved for future use.

        if ( array_key_exists( $field_key, $data ) ) {
            return $data[ $field_key ];
        }

        $prefixed = 'fre_field_' . $field_key;
        if ( array_key_exists( $prefixed, $data ) ) {
            return $data[ $prefixed ];
        }

        return '';
    }
}
