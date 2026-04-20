<?php
/**
 * Forms repository for Form Runtime Engine.
 *
 * Pure data-access layer for database-stored form configurations. Every form
 * CRUD operation in the plugin goes through this class — the admin UI
 * (FRE_Forms_Manager), the public wrapper functions (fre_save_db_form() et al.),
 * and the Cowork REST connector (Phase 2+) all call the same methods.
 *
 * Extracted from FRE_Forms_Manager in the Phase 1 Cowork connector work so the
 * connector's REST handlers and the admin UI's AJAX handlers share one storage
 * path. FRE_Forms_Manager retains thin static delegators that call into this
 * class so external callers of the old API continue to work unchanged.
 *
 * Storage: single `wp_options` row keyed by `fre_client_forms`. Each form is an
 * array keyed by form ID. Schema per form:
 *
 *   [
 *     'id'                 => string,   // form_id (lowercase alphanumeric + dash/underscore)
 *     'title'              => string,   // human-readable title
 *     'config'             => string,   // JSON form config (validated by FRE_JSON_Schema_Validator)
 *     'custom_css'         => string,   // optional, sanitized by FRE_CSS_Validator
 *     'webhook_enabled'    => bool,
 *     'webhook_url'        => string,   // validated by FRE_Webhook_Validator
 *     'webhook_secret'     => string,   // 32-char HMAC key
 *     'webhook_preset'     => string,   // google_sheets|zapier|make|custom
 *     'managed_by'         => string,   // 'admin' | 'connector:cowork' | future connector IDs
 *     'connector_version'  => int,      // monotonic, bumped on every save; used for entry versioning
 *     'created'            => int,      // unix ts
 *     'modified'           => int,      // unix ts
 *   ]
 *
 * Backward compatibility: existing forms stored before Phase 1 lack the
 * `managed_by` and `connector_version` keys. `get()` and `get_all()` normalize
 * reads so callers always see a complete record, and `save()` writes the new
 * keys on the next update.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Forms repository.
 */
class FRE_Forms_Repository {

    /**
     * Option key that stores all forms.
     *
     * Kept identical to the former FRE_Forms_Manager::OPTION_KEY so existing
     * installs read their forms without migration.
     *
     * @var string
     */
    const OPTION_KEY = 'fre_client_forms';

    /**
     * Valid values for the `managed_by` field.
     *
     * When a form is created through the admin UI, `managed_by` is 'admin'.
     * When a form is created through the Cowork connector (Phase 2+), it is
     * 'connector:cowork'. Additional connectors in the future reserve the
     * 'connector:<id>' namespace.
     *
     * @var array
     */
    const MANAGED_BY_VALUES = array( 'admin', 'connector:cowork' );

    /**
     * Default `managed_by` value for forms with no explicit origin.
     *
     * Applied to existing forms that predate Phase 1 and to forms saved
     * without a managed_by argument.
     *
     * @var string
     */
    const DEFAULT_MANAGED_BY = 'admin';

    /**
     * Valid webhook preset values.
     *
     * @var array
     */
    const WEBHOOK_PRESETS = array( 'google_sheets', 'zapier', 'make', 'custom' );

    /**
     * Get all stored forms.
     *
     * Returns an array keyed by form_id. Each record is normalized so callers
     * can rely on all expected keys being present, even for forms created
     * before Phase 1.
     *
     * @return array
     */
    public static function get_all() {
        $forms = get_option( self::OPTION_KEY, array() );

        if ( ! is_array( $forms ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $forms as $form_id => $form ) {
            if ( is_array( $form ) ) {
                $normalized[ $form_id ] = self::normalize_record( $form, $form_id );
            }
        }

        return $normalized;
    }

    /**
     * Get a single form by ID.
     *
     * @param string $form_id Form identifier.
     * @return array|null Normalized form record, or null if not found.
     */
    public static function get( $form_id ) {
        $forms = self::get_all();

        return isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : null;
    }

    /**
     * Check whether a form exists.
     *
     * @param string $form_id Form identifier.
     * @return bool
     */
    public static function exists( $form_id ) {
        $forms = get_option( self::OPTION_KEY, array() );

        return is_array( $forms ) && isset( $forms[ $form_id ] );
    }

    /**
     * Save (create or update) a form.
     *
     * Validates, sanitizes, persists. Idempotent across repeat calls with
     * identical input.
     *
     * On update, preserves immutable fields (created, webhook_secret when not
     * changed) and bumps `connector_version` and `modified`.
     *
     * @param string $form_id Form identifier. Lowercase alphanumeric + dash/underscore.
     * @param array  $input   {
     *     Save input. All keys optional except where noted.
     *
     *     @type string $title           Form title. Defaults to the title in config, or empty.
     *     @type string $config          JSON form config. Required.
     *     @type string $custom_css      Custom CSS. Default empty.
     *     @type bool   $webhook_enabled Whether webhook is enabled. Default false.
     *     @type string $webhook_url     Webhook endpoint. Default empty.
     *     @type string $webhook_secret  HMAC secret. Auto-generated on first enable.
     *     @type string $webhook_preset  One of WEBHOOK_PRESETS. Default 'custom'.
     *     @type string $managed_by      One of MANAGED_BY_VALUES. Default 'admin' for new forms;
     *                                   preserved from existing record on update if not provided.
     * }
     * @return array|WP_Error Normalized form record on success, WP_Error on failure.
     */
    public static function save( $form_id, array $input ) {
        // Validate form ID.
        $form_id_error = self::validate_form_id( $form_id );
        if ( is_wp_error( $form_id_error ) ) {
            return $form_id_error;
        }

        // Extract and validate config.
        $json_config = isset( $input['config'] ) ? (string) $input['config'] : '';
        if ( '' === $json_config ) {
            return new WP_Error( 'empty_config', __( 'Form configuration is required.', 'form-runtime-engine' ) );
        }

        $config = json_decode( $json_config, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error(
                'invalid_json',
                __( 'Invalid JSON syntax: ', 'form-runtime-engine' ) . json_last_error_msg()
            );
        }

        // Schema validation.
        $schema_result = FRE_JSON_Schema_Validator::validate( $config );
        if ( ! $schema_result['valid'] ) {
            return new WP_Error( 'schema_error', implode( ' ', $schema_result['errors'] ) );
        }

        // Log non-fatal warnings.
        if ( ! empty( $schema_result['warnings'] ) ) {
            foreach ( $schema_result['warnings'] as $warning ) {
                FRE_Logger::warning( 'Form Schema Warning [' . $form_id . ']: ' . $warning );
            }
        }

        // Custom CSS: validate + sanitize if provided.
        $custom_css = isset( $input['custom_css'] ) ? (string) $input['custom_css'] : '';
        if ( '' !== $custom_css ) {
            $css_result = FRE_CSS_Validator::validate( $custom_css );
            if ( is_wp_error( $css_result ) ) {
                return $css_result;
            }
            $custom_css = FRE_CSS_Validator::sanitize( $custom_css );
        }

        // Webhook: validate URL if enabled.
        $webhook_enabled = ! empty( $input['webhook_enabled'] );
        $webhook_url     = isset( $input['webhook_url'] ) ? (string) $input['webhook_url'] : '';
        if ( $webhook_enabled && '' !== $webhook_url ) {
            $webhook_result = FRE_Webhook_Validator::validate_and_sanitize( $webhook_url );
            if ( is_wp_error( $webhook_result ) ) {
                return $webhook_result;
            }
            $webhook_url = $webhook_result;
        } elseif ( ! $webhook_enabled ) {
            $webhook_url = '';
        }

        // Load existing record (if any) to preserve immutable fields.
        $existing = self::get( $form_id );
        $is_new   = ( null === $existing );

        // Title: prefer explicit input, fall back to config.title, then empty.
        $title = isset( $input['title'] ) ? (string) $input['title'] : '';
        if ( '' === $title && ! empty( $config['title'] ) ) {
            $title = (string) $config['title'];
        }

        // Webhook secret resolution.
        $webhook_secret = self::resolve_webhook_secret( $input, $existing, $is_new, $webhook_enabled );

        // Webhook preset validation.
        $webhook_preset = isset( $input['webhook_preset'] ) ? (string) $input['webhook_preset'] : 'custom';
        if ( ! in_array( $webhook_preset, self::WEBHOOK_PRESETS, true ) ) {
            $webhook_preset = 'custom';
        }

        // managed_by: explicit input, else preserve existing, else default.
        $managed_by = isset( $input['managed_by'] ) ? (string) $input['managed_by'] : null;
        if ( null === $managed_by ) {
            $managed_by = $is_new
                ? self::DEFAULT_MANAGED_BY
                : ( $existing['managed_by'] ?? self::DEFAULT_MANAGED_BY );
        }
        if ( ! in_array( $managed_by, self::MANAGED_BY_VALUES, true ) ) {
            $managed_by = self::DEFAULT_MANAGED_BY;
        }

        // connector_version: monotonic bump. New forms start at 1; updates increment.
        $connector_version = $is_new
            ? 1
            : ( (int) ( $existing['connector_version'] ?? 0 ) + 1 );

        // Build record.
        $record = array(
            'id'                => $form_id,
            'title'             => sanitize_text_field( $title ),
            'config'            => $json_config,
            'custom_css'        => $custom_css,
            'webhook_enabled'   => $webhook_enabled,
            'webhook_url'       => $webhook_url,
            'webhook_secret'    => $webhook_secret,
            'webhook_preset'    => $webhook_preset,
            'managed_by'        => $managed_by,
            'connector_version' => $connector_version,
            'created'           => $is_new ? time() : (int) ( $existing['created'] ?? time() ),
            'modified'          => time(),
        );

        // Persist.
        $forms             = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $forms ) ) {
            $forms = array();
        }
        $forms[ $form_id ] = $record;

        update_option( self::OPTION_KEY, $forms );

        /**
         * Fires after a form is saved (created or updated) via the repository.
         *
         * @param string $form_id   Form identifier.
         * @param array  $record    The saved record.
         * @param bool   $is_new    True if this was a create, false if update.
         */
        do_action( 'fre_form_saved', $form_id, $record, $is_new );

        return $record;
    }

    /**
     * Delete a form.
     *
     * Per the Cowork connector design (see docs/COWORK_CONNECTOR_ASSESSMENT.md §11.2),
     * entries for the deleted form are NOT cascade-deleted. They remain in the
     * entries tables with their original form_id and become effectively
     * "orphaned" from the form definition but are still queryable via the
     * entries admin UI.
     *
     * @param string $form_id Form identifier.
     * @return bool True on success, false if form not found.
     */
    public static function delete( $form_id ) {
        $forms = get_option( self::OPTION_KEY, array() );

        if ( ! is_array( $forms ) || ! isset( $forms[ $form_id ] ) ) {
            return false;
        }

        $deleted_record = $forms[ $form_id ];
        unset( $forms[ $form_id ] );
        update_option( self::OPTION_KEY, $forms );

        /**
         * Fires after a form is deleted via the repository.
         *
         * @param string $form_id        Form identifier.
         * @param array  $deleted_record The record that was removed.
         */
        do_action( 'fre_form_deleted', $form_id, $deleted_record );

        return true;
    }

    /**
     * Regenerate a form's webhook secret.
     *
     * Bumps `modified` and `connector_version` so downstream consumers see the
     * record has changed.
     *
     * @param string $form_id Form identifier.
     * @return string|WP_Error New secret on success, WP_Error on failure.
     */
    public static function regenerate_webhook_secret( $form_id ) {
        $forms = get_option( self::OPTION_KEY, array() );

        if ( ! is_array( $forms ) || ! isset( $forms[ $form_id ] ) ) {
            return new WP_Error( 'form_not_found', __( 'Form not found.', 'form-runtime-engine' ) );
        }

        $new_secret = wp_generate_password( 32, false );

        $forms[ $form_id ]['webhook_secret']    = $new_secret;
        $forms[ $form_id ]['connector_version'] = (int) ( $forms[ $form_id ]['connector_version'] ?? 0 ) + 1;
        $forms[ $form_id ]['modified']          = time();

        update_option( self::OPTION_KEY, $forms );

        return $new_secret;
    }

    /**
     * Count entries for a given form.
     *
     * Used by the delete response (entries_preserved count per §11.2) and by
     * the admin UI. Lightweight — single indexed COUNT query.
     *
     * @param string $form_id Form identifier.
     * @return int Entry count for this form_id (including orphaned entries
     *             from previously-deleted forms with the same ID).
     */
    public static function count_entries( $form_id ) {
        $entry = new FRE_Entry();
        return $entry->count( $form_id );
    }

    /**
     * Register every DB-stored form with the runtime registry.
     *
     * Called on `fre_init` to make DB-stored forms available to the renderer
     * and submission handler alongside PHP-registered forms.
     */
    public static function register_all_with_runtime_registry() {
        $forms = self::get_all();

        foreach ( $forms as $form_id => $form_data ) {
            $config = json_decode( $form_data['config'], true );

            if ( ! is_array( $config ) ) {
                continue;
            }

            // If config lacks a title, use the stored one.
            if ( ! empty( $form_data['title'] ) && empty( $config['title'] ) ) {
                $config['title'] = $form_data['title'];
            }

            fre_register_form( $form_id, $config );
        }
    }

    /**
     * Validate a form ID.
     *
     * @param string $form_id Candidate form ID.
     * @return true|WP_Error True if valid, WP_Error otherwise.
     */
    private static function validate_form_id( $form_id ) {
        if ( empty( $form_id ) ) {
            return new WP_Error( 'empty_id', __( 'Form ID is required.', 'form-runtime-engine' ) );
        }

        if ( ! preg_match( '/^[a-z0-9\-_]+$/', $form_id ) ) {
            return new WP_Error(
                'invalid_id',
                __( 'Form ID must be lowercase alphanumeric with dashes or underscores only.', 'form-runtime-engine' )
            );
        }

        return true;
    }

    /**
     * Determine the webhook secret to use for this save.
     *
     * Rules:
     *   1. If input provides a non-empty secret, sanitize and use it.
     *   2. Else if webhook is enabled and an existing secret is on file, preserve it.
     *   3. Else if webhook is enabled but no existing secret, generate a new one.
     *   4. Else (webhook disabled, nothing to do), return empty string.
     *
     * @param array      $input           Raw save input.
     * @param array|null $existing        Existing form record or null.
     * @param bool       $is_new          True if creating; false if updating.
     * @param bool       $webhook_enabled Whether the webhook is enabled in this save.
     * @return string Secret to persist.
     */
    private static function resolve_webhook_secret( array $input, $existing, $is_new, $webhook_enabled ) {
        $provided = isset( $input['webhook_secret'] ) ? (string) $input['webhook_secret'] : '';

        if ( '' !== $provided ) {
            return sanitize_text_field( $provided );
        }

        if ( ! $webhook_enabled ) {
            return '';
        }

        if ( ! $is_new && ! empty( $existing['webhook_secret'] ) ) {
            return (string) $existing['webhook_secret'];
        }

        // Newly enabled webhook with no secret: generate one.
        return wp_generate_password( 32, false );
    }

    /**
     * Normalize a stored record so callers always see the complete schema.
     *
     * Backfills defaults for keys introduced after the initial plugin release
     * (managed_by, connector_version) without triggering a write.
     *
     * @param array  $record  Raw stored record.
     * @param string $form_id Fallback form_id if the record lacks one.
     * @return array
     */
    private static function normalize_record( array $record, $form_id ) {
        $defaults = array(
            'id'                => $form_id,
            'title'             => '',
            'config'            => '',
            'custom_css'        => '',
            'webhook_enabled'   => false,
            'webhook_url'       => '',
            'webhook_secret'    => '',
            'webhook_preset'    => 'custom',
            'managed_by'        => self::DEFAULT_MANAGED_BY,
            'connector_version' => 0,
            'created'           => 0,
            'modified'          => 0,
        );

        $record = array_merge( $defaults, $record );

        // Coerce types for fields that might have been stored with looser types in earlier versions.
        $record['webhook_enabled']   = (bool) $record['webhook_enabled'];
        $record['connector_version'] = (int) $record['connector_version'];
        $record['created']           = (int) $record['created'];
        $record['modified']          = (int) $record['modified'];

        return $record;
    }
}
