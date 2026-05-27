<?php
/**
 * GDPR / Privacy Compliance for Promptless Forms.
 *
 * Hooks into WordPress's personal-data export and erasure tools so site
 * administrators can fulfill data-subject access requests (DSAR) and right-
 * to-erasure requests for users whose data is stored in FRE form entries.
 *
 * WordPress's privacy tools (Tools → Export Personal Data, Tools → Erase
 * Personal Data) work by registering exporter/eraser callbacks keyed by
 * email address. Each plugin that stores user-identifiable data should
 * register its own callbacks. This class implements both for FRE.
 *
 * Email matching is schema-aware: for each registered form, fields declared
 * as `type: email` are queried for value matches against the requested
 * email. This avoids false positives against arbitrary text fields that
 * happen to contain email-like strings, and avoids missing entries with
 * custom email field keys like `your_email` or `contact_email`.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Privacy compliance handler.
 */
class FRE_Privacy {

    /**
     * Group identifier used in WordPress's personal-data export tool to
     * label the section containing FRE entries. Must be unique across
     * all exporters registered on the site.
     */
    const EXPORTER_GROUP = 'promptless-forms';

    /**
     * Entries per page when iterating during export/erasure. WordPress
     * calls the callbacks with successive page numbers until the callback
     * signals done=true. 100 strikes a balance between memory pressure
     * (per-request entry load) and round-trip count on large datasets.
     */
    const PAGE_SIZE = 100;

    /**
     * Register WordPress hooks.
     *
     * Called once from the main plugin init() flow.
     */
    public function init() {
        add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
        add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
    }

    /**
     * Register the FRE personal-data exporter.
     *
     * @param array $exporters Existing exporters registered by WordPress.
     * @return array Augmented exporters list.
     */
    public function register_exporter( $exporters ) {
        $exporters[ self::EXPORTER_GROUP ] = array(
            'exporter_friendly_name' => __( 'Promptless Forms — Form Submissions', 'promptless-forms' ),
            'callback'               => array( $this, 'export_user_data' ),
        );
        return $exporters;
    }

    /**
     * Register the FRE personal-data eraser.
     *
     * @param array $erasers Existing erasers registered by WordPress.
     * @return array Augmented erasers list.
     */
    public function register_eraser( $erasers ) {
        $erasers[ self::EXPORTER_GROUP ] = array(
            'eraser_friendly_name' => __( 'Promptless Forms — Form Submissions', 'promptless-forms' ),
            'callback'             => array( $this, 'erase_user_data' ),
        );
        return $erasers;
    }

    /**
     * Add suggested text to the WordPress Privacy Policy editor.
     *
     * Site administrators can include or customize this text in their
     * site's published privacy policy via Settings → Privacy → Policy Guide.
     * The text is informational and stays accurate as long as FRE's data-
     * collection surface (fields + IP + user agent + notification routes)
     * does not change materially.
     */
    public function add_privacy_policy_content() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $content = sprintf(
            '<p>%s</p><p>%s</p><p>%s</p>',
            esc_html__( 'When you submit a form on this site powered by Promptless Forms, the form data is stored in our database for record-keeping and follow-up purposes. Collected data typically includes the values you entered into the form (such as your name, email address, phone number, and message), the date and time of submission, your IP address (used for spam protection), and your browser identification (user agent).', 'promptless-forms' ),
            esc_html__( 'Form submissions may also trigger email notifications to site administrators, and may be sent to external services that the site administrator has configured. Common destinations include webhook endpoints used by automation tools such as Zapier, Make, or Google Sheets, and SMS providers such as Twilio. The specific external recipients depend on each form\'s configuration.', 'promptless-forms' ),
            esc_html__( 'You can request a copy of the form-submission data we have collected about you, or request that it be deleted, by contacting the site administrator. WordPress\'s built-in data-export and data-erasure tools are wired up to remove your data from this plugin automatically when such a request is processed.', 'promptless-forms' )
        );

        wp_add_privacy_policy_content(
            'Promptless Forms',
            wp_kses_post( wpautop( $content ) )
        );
    }

    /**
     * Personal data exporter callback.
     *
     * Returns FRE form entries that contain the supplied email address in
     * any field declared as `type: email`. Paginated — WordPress calls
     * this with successive page numbers until done=true is returned.
     *
     * @param string $email_address Email address to search for.
     * @param int    $page          1-based page number.
     * @return array {
     *     @type array $data Exporter items array.
     *     @type bool  $done Whether the export is complete.
     * }
     */
    public function export_user_data( $email_address, $page = 1 ) {
        $page = max( 1, (int) $page );

        $entry_ids = $this->find_entries_by_email( $email_address, $page, self::PAGE_SIZE );

        $items = array();

        if ( ! empty( $entry_ids ) ) {
            $entry_repo = new FRE_Entry();

            foreach ( $entry_ids as $entry_id ) {
                $entry = $entry_repo->get( $entry_id );
                if ( ! $entry ) {
                    continue;
                }

                $meta  = $entry_repo->get_all_meta( $entry_id );
                $files = $entry_repo->get_files( $entry_id );
                $form  = fre()->registry->get( $entry['form_id'] );

                $items[] = $this->build_export_item( $entry, $meta, $files, $form );
            }
        }

        // If fewer than a full page came back, there's nothing more to fetch.
        // Otherwise WordPress will call again with $page + 1.
        $done = count( $entry_ids ) < self::PAGE_SIZE;

        return array(
            'data' => $items,
            'done' => $done,
        );
    }

    /**
     * Personal data eraser callback.
     *
     * Deletes FRE form entries containing the supplied email address in
     * any field declared as `type: email`. Paginated like the exporter.
     *
     * Returns counts of items removed/retained per WordPress's expected
     * eraser response shape. FRE_Entry::delete() handles cascade cleanup
     * of meta rows and uploaded files.
     *
     * @param string $email_address Email address to search for.
     * @param int    $page          1-based page number.
     * @return array Eraser response (items_removed, items_retained, messages, done).
     */
    public function erase_user_data( $email_address, $page = 1 ) {
        $page = max( 1, (int) $page );

        $entry_ids = $this->find_entries_by_email( $email_address, $page, self::PAGE_SIZE );

        $removed  = 0;
        $retained = 0;
        $messages = array();

        if ( ! empty( $entry_ids ) ) {
            $entry_repo = new FRE_Entry();

            foreach ( $entry_ids as $entry_id ) {
                $result = $entry_repo->delete( $entry_id );
                // FRE_Entry::delete() returns true on success. Be defensive
                // about historical signatures that may have returned an int.
                if ( $result === true || ( is_int( $result ) && $result > 0 ) ) {
                    $removed++;
                } else {
                    $retained++;
                    $messages[] = sprintf(
                        /* translators: %d: entry ID */
                        __( 'Entry %d could not be erased.', 'promptless-forms' ),
                        $entry_id
                    );
                }
            }
        }

        $done = count( $entry_ids ) < self::PAGE_SIZE;

        return array(
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => $done,
        );
    }

    /**
     * Collect the set of email-typed field keys across all registered forms.
     *
     * Returns a deduplicated list of field keys to use in the entry_meta
     * query. Forms registered via PHP (fre_register_form) and forms stored
     * in the database (admin UI) are both surfaced through fre()->registry,
     * so a single pass over the registry covers both sources.
     *
     * Without this, the eraser would either (a) match only a hardcoded
     * 'email' key (missing custom forms) or (b) scan all field values for
     * email-like strings (slow and false-positive-prone).
     *
     * @return string[] Distinct field keys associated with email-type fields.
     */
    private function get_email_field_keys() {
        $field_keys = array();

        $forms = fre()->registry->get_all();

        foreach ( $forms as $form ) {
            if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
                continue;
            }
            foreach ( $form['fields'] as $field ) {
                if ( isset( $field['type'], $field['key'] ) && $field['type'] === 'email' ) {
                    $field_keys[] = (string) $field['key'];
                }
            }
        }

        return array_values( array_unique( array_filter( $field_keys ) ) );
    }

    /**
     * Find entry IDs containing the supplied email in any email-typed field.
     *
     * Paginated query against fre_entry_meta. Case-insensitive match per
     * RFC 5321 (email addresses are case-insensitive in their local part).
     *
     * @param string $email    Email address to match.
     * @param int    $page     1-based page number.
     * @param int    $per_page Page size.
     * @return int[] List of matching entry IDs.
     */
    private function find_entries_by_email( $email, $page, $per_page ) {
        $field_keys = $this->get_email_field_keys();

        if ( empty( $field_keys ) || empty( $email ) ) {
            return array();
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'fre_entry_meta';
        $offset = max( 0, ( $page - 1 ) * $per_page );

        // Build a placeholder list for the field_key IN(...) clause.
        $placeholders = implode( ', ', array_fill( 0, count( $field_keys ), '%s' ) );

        // Direct query is required — FRE entries live in a plugin-specific
        // table outside the WP query API. $table is built from $wpdb->prefix
        // + hardcoded suffix; field_keys come from the registry (admin- or
        // PHP-defined, not user form input); email value goes through %s.
        // The ReplacementsWrongNumber rule fires here because Plugin Check's
        // static analyzer cannot expand the dynamic {$placeholders} variable
        // to count the actual %s tokens; at runtime, $placeholders contains
        // one %s per element of $field_keys plus the three trailing tokens
        // (email/limit/offset), so the count matches array_merge() exactly.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT entry_id FROM {$table}
                 WHERE field_key IN ( {$placeholders} )
                 AND LOWER(field_value) = LOWER(%s)
                 ORDER BY entry_id ASC
                 LIMIT %d OFFSET %d",
                array_merge( $field_keys, array( $email, $per_page, $offset ) )
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

        return is_array( $rows ) ? array_map( 'intval', $rows ) : array();
    }

    /**
     * Build a single export item for WordPress's exporter response.
     *
     * Format follows the WordPress Privacy Tools spec — each item has a
     * group_id, group_label, item_id, and an array of {name, value} pairs.
     * The site admin sees this in the downloaded export ZIP, one item per
     * form entry with field labels (or raw keys as fallback) and values.
     *
     * @param array      $entry Entry row from FRE_Entry::get().
     * @param array      $meta  Entry meta map (field_key => field_value).
     * @param array      $files Uploaded files for the entry.
     * @param array|null $form  Form configuration (null if form was deleted after entry was created).
     * @return array Exporter item.
     */
    private function build_export_item( $entry, $meta, $files, $form ) {
        $data = array();

        // Form identifier + submission timestamp at the top of each item
        // so the admin can match each export entry back to its source.
        $form_label = $form && ! empty( $form['title'] ) ? $form['title'] : $entry['form_id'];
        $data[]     = array(
            'name'  => __( 'Form', 'promptless-forms' ),
            'value' => $form_label,
        );
        $data[]     = array(
            'name'  => __( 'Submitted', 'promptless-forms' ),
            'value' => $entry['created_at'],
        );

        // Iterate fields. If the form config is available, use field labels;
        // otherwise fall back to the raw field key.
        $field_map = array();
        if ( $form && ! empty( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                if ( isset( $field['key'] ) ) {
                    $field_map[ $field['key'] ] = $field;
                }
            }
        }

        foreach ( $meta as $field_key => $field_value ) {
            $label = isset( $field_map[ $field_key ]['label'] )
                ? $field_map[ $field_key ]['label']
                : $field_key;

            $data[] = array(
                'name'  => $label,
                'value' => is_scalar( $field_value ) ? (string) $field_value : wp_json_encode( $field_value ),
            );
        }

        // Uploaded files — listed by filename so the admin can pull them
        // separately from the uploads folder if the user requested them.
        if ( ! empty( $files ) ) {
            foreach ( $files as $file ) {
                if ( ! is_array( $file ) ) {
                    continue;
                }
                $data[] = array(
                    'name'  => sprintf(
                        /* translators: %s: form field key the file was uploaded to */
                        __( 'Uploaded file (%s)', 'promptless-forms' ),
                        $file['field_key'] ?? __( 'unknown', 'promptless-forms' )
                    ),
                    'value' => $file['file_name'] ?? '',
                );
            }
        }

        // IP and user agent — collected for spam protection and stored
        // alongside the entry, so they're part of the user's data export.
        if ( ! empty( $entry['ip_address'] ) ) {
            $data[] = array(
                'name'  => __( 'IP address (at submission)', 'promptless-forms' ),
                'value' => $entry['ip_address'],
            );
        }
        if ( ! empty( $entry['user_agent'] ) ) {
            $data[] = array(
                'name'  => __( 'User agent (at submission)', 'promptless-forms' ),
                'value' => $entry['user_agent'],
            );
        }

        return array(
            'group_id'    => self::EXPORTER_GROUP,
            'group_label' => __( 'Form Submissions (Promptless Forms)', 'promptless-forms' ),
            'item_id'     => 'fre-entry-' . (int) $entry['id'],
            'data'        => $data,
        );
    }
}
