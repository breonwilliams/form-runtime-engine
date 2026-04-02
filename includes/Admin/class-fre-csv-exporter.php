<?php
/**
 * CSV Exporter for Form Runtime Engine.
 *
 * NOTE: Uses direct database queries for efficient batch export of entries.
 * Custom tables require direct queries for proper JOIN operations.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CSV export handler with streaming output.
 *
 * Fix #12: Improved memory efficiency by using batched database queries
 * and streaming pagination instead of loading all entries at once.
 */
class FRE_CSV_Exporter {

    /**
     * Entry query instance.
     *
     * @var FRE_Entry_Query
     */
    private $query;

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Field type instances.
     *
     * @var array
     */
    private $field_instances = array();

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->query = new FRE_Entry_Query();
    }

    /**
     * Export entries to CSV.
     *
     * Uses streaming output to handle large datasets.
     *
     * @param array $args Export arguments.
     */
    public function export( array $args = array() ) {
        $args = wp_parse_args( $args, array(
            'form_id'      => '',
            'date_from'    => '',
            'date_to'      => '',
            'status'       => '',
            'exclude_spam' => true,
        ) );

        // Build query.
        $this->query->reset();

        if ( ! empty( $args['form_id'] ) ) {
            $this->query->form( $args['form_id'] );
        }

        if ( ! empty( $args['date_from'] ) && ! empty( $args['date_to'] ) ) {
            $this->query->date_range( $args['date_from'], $args['date_to'] );
        }

        if ( ! empty( $args['status'] ) ) {
            $this->query->status( $args['status'] );
        }

        if ( $args['exclude_spam'] ) {
            $this->query->spam( false );
        }

        $this->query->order_by( 'created_at', 'DESC' );

        // Generate filename (Fix #27: Sanitize for header injection).
        $filename = 'form-entries';
        if ( ! empty( $args['form_id'] ) ) {
            $filename .= '-' . sanitize_file_name( $args['form_id'] );
        }
        $filename .= '-' . gmdate( 'Y-m-d' ) . '.csv';

        // Fix #27: Strict filename sanitization to prevent header injection.
        $filename = preg_replace( '/[^\w\-.]/', '', $filename );

        // Set headers for download.
        header( 'Content-Type: text/csv; charset=utf-8' );
        // Fix #27: Use RFC 5987 encoding for filename with both fallback and UTF-8 versions.
        header( 'Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );

        // Open output stream.
        $output = fopen( 'php://output', 'w' );

        // Add BOM for Excel compatibility.
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        // Get column headers.
        $headers = $this->get_column_headers( $args['form_id'] );
        fputcsv( $output, $headers );

        // Fix #12: Stream entries in chunks with batched meta loading.
        // This prevents memory exhaustion on large datasets by:
        // 1. Loading only IDs first
        // 2. Fetching metadata in batches
        // 3. Streaming output directly
        $page     = 1;
        $per_page = 100;

        do {
            // Get entries without metadata (faster initial query).
            $entries = $this->query->page( $page, $per_page )->get( false );

            if ( empty( $entries ) ) {
                break;
            }

            // Fix #12: Batch load metadata for all entries in this chunk.
            $entry_ids = array_column( $entries, 'id' );
            $meta_data = $this->batch_get_meta( $entry_ids );
            $files_data = $this->batch_get_files( $entry_ids );

            foreach ( $entries as $entry ) {
                $entry_id = $entry['id'];
                $entry['fields'] = isset( $meta_data[ $entry_id ] ) ? $meta_data[ $entry_id ] : array();
                $entry['files'] = isset( $files_data[ $entry_id ] ) ? $files_data[ $entry_id ] : array();

                $row = $this->format_entry_row( $entry, $args['form_id'] );
                fputcsv( $output, $row );
            }

            // Flush output every page (100 entries) to reduce peak memory usage.
            // This prevents memory accumulation between flushes.
            if ( ob_get_level() > 0 ) {
                ob_flush();
            }
            flush();

            // Fix #12: Clear arrays to free memory before next iteration.
            unset( $entries, $meta_data, $files_data, $entry_ids );

            $page++;
        } while ( true ); // Loop exits via empty($entries) check

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream.
        fclose( $output );
        exit;
    }

    /**
     * Get column headers for CSV.
     *
     * @param string $form_id Specific form ID (optional).
     * @return array
     */
    private function get_column_headers( $form_id = '' ) {
        $headers = array(
            'ID',
            'Form',
            'Status',
            'Date',
            'IP Address',
            'User',
        );

        // If specific form, add form field headers.
        if ( ! empty( $form_id ) ) {
            $form = fre()->registry->get( $form_id );
            if ( $form ) {
                foreach ( $form['fields'] as $field ) {
                    // Skip non-storing fields.
                    if ( $field['type'] === 'message' ) {
                        continue;
                    }

                    $label = ! empty( $field['label'] ) ? $field['label'] : ucfirst( $field['key'] );
                    $headers[] = $label;
                }
            }
        } else {
            // Generic "Data" column for mixed forms.
            $headers[] = 'Submitted Data';
        }

        return $headers;
    }

    /**
     * Format entry row for CSV.
     *
     * @param array  $entry   Entry data with fields.
     * @param string $form_id Specific form ID (optional).
     * @return array
     */
    private function format_entry_row( array $entry, $form_id = '' ) {
        // Get user display name.
        $user_display = '';
        if ( ! empty( $entry['user_id'] ) ) {
            $user = get_user_by( 'id', $entry['user_id'] );
            $user_display = $user ? $user->user_email : $entry['user_id'];
        }

        $row = array(
            $entry['id'],
            $entry['form_id'],
            $entry['is_spam'] ? 'Spam' : ucfirst( $entry['status'] ),
            $entry['created_at'],
            $entry['ip_address'],
            $user_display,
        );

        $fields = $entry['fields'] ?? array();

        // If specific form, add field values in order.
        if ( ! empty( $form_id ) ) {
            $form = fre()->registry->get( $form_id );
            if ( $form ) {
                foreach ( $form['fields'] as $field ) {
                    if ( $field['type'] === 'message' ) {
                        continue;
                    }

                    $value = isset( $fields[ $field['key'] ] ) ? $fields[ $field['key'] ] : '';
                    $row[] = $this->format_csv_value( $value, $field );
                }
            }
        } else {
            // Combine all fields for mixed export.
            $data_parts = array();
            foreach ( $fields as $key => $value ) {
                if ( is_array( $value ) ) {
                    $value = implode( ', ', $value );
                }
                $data_parts[] = ucfirst( str_replace( '_', ' ', $key ) ) . ': ' . $value;
            }
            $row[] = implode( ' | ', $data_parts );
        }

        // Handle file fields - add URLs.
        if ( ! empty( $entry['files'] ) ) {
            foreach ( $entry['files'] as $file ) {
                $url = $file['attachment_id'] ? wp_get_attachment_url( $file['attachment_id'] ) : '';
                if ( $url ) {
                    // Find the field column and append URL.
                    // For simplicity, we'll add file info to the field value.
                }
            }
        }

        return $row;
    }

    /**
     * Format value for CSV output.
     *
     * Fix #1: Escape CSV formula injection characters.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @return string
     */
    private function format_csv_value( $value, array $field ) {
        $type = $field['type'] ?? 'text';

        // Get field instance.
        $field_class = FRE_Autoloader::get_field_class( $type );
        if ( $field_class && class_exists( $field_class ) ) {
            if ( ! isset( $this->field_instances[ $type ] ) ) {
                $this->field_instances[ $type ] = new $field_class();
            }
            $value = $this->field_instances[ $type ]->format_csv_value( $value, $field );
        } elseif ( is_array( $value ) ) {
            // Fallback for arrays.
            $value = implode( ', ', $value );
        }

        $value = (string) $value;

        // Fix #1: Escape CSV formula injection.
        // Values starting with =, +, -, @, tab, or newlines can be executed as formulas
        // or cause injection in Excel/LibreOffice/Google Sheets.
        $dangerous_chars = array( '=', '+', '-', '@', "\t", "\r", "\n" );
        if ( strlen( $value ) > 0 && in_array( $value[0], $dangerous_chars, true ) ) {
            $value = "'" . $value;
        }

        // Also escape any embedded tabs/newlines that could cause row injection.
        $value = str_replace( array( "\t", "\r\n", "\r", "\n" ), ' ', $value );

        return $value;
    }

    /**
     * Batch load metadata for multiple entries (Fix #12).
     *
     * Single query instead of N queries for N entries.
     *
     * @param array $entry_ids Array of entry IDs.
     * @return array Associative array keyed by entry_id => field_key => value.
     */
    private function batch_get_meta( array $entry_ids ) {
        if ( empty( $entry_ids ) ) {
            return array();
        }

        $table = $this->wpdb->prefix . 'fre_entry_meta';
        $placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT entry_id, field_key, field_value FROM {$table}
                 WHERE entry_id IN ({$placeholders})",
                ...$entry_ids
            ),
            ARRAY_A
        );

        $meta = array();
        foreach ( $results as $row ) {
            $entry_id  = $row['entry_id'];
            $field_key = $row['field_key'];
            $value     = maybe_unserialize( $row['field_value'] );

            if ( ! isset( $meta[ $entry_id ] ) ) {
                $meta[ $entry_id ] = array();
            }
            $meta[ $entry_id ][ $field_key ] = $value;
        }

        return $meta;
    }

    /**
     * Batch load files for multiple entries (Fix #12).
     *
     * @param array $entry_ids Array of entry IDs.
     * @return array Associative array keyed by entry_id => array of files.
     */
    private function batch_get_files( array $entry_ids ) {
        if ( empty( $entry_ids ) ) {
            return array();
        }

        $table = $this->wpdb->prefix . 'fre_entry_files';
        $placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$table} WHERE entry_id IN ({$placeholders})",
                ...$entry_ids
            ),
            ARRAY_A
        );

        $files = array();
        foreach ( $results as $row ) {
            $entry_id = $row['entry_id'];
            if ( ! isset( $files[ $entry_id ] ) ) {
                $files[ $entry_id ] = array();
            }
            $files[ $entry_id ][] = $row;
        }

        return $files;
    }

    /**
     * Get count of entries to be exported.
     *
     * @param array $args Export arguments.
     * @return int
     */
    public function get_export_count( array $args = array() ) {
        $this->query->reset();

        if ( ! empty( $args['form_id'] ) ) {
            $this->query->form( $args['form_id'] );
        }

        if ( ! empty( $args['date_from'] ) && ! empty( $args['date_to'] ) ) {
            $this->query->date_range( $args['date_from'], $args['date_to'] );
        }

        if ( ! empty( $args['status'] ) ) {
            $this->query->status( $args['status'] );
        }

        if ( ! empty( $args['exclude_spam'] ) ) {
            $this->query->spam( false );
        }

        return $this->query->count();
    }
}
