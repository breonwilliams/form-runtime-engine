<?php
/**
 * CSV Exporter for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CSV export handler with streaming output.
 */
class FRE_CSV_Exporter {

    /**
     * Entry query instance.
     *
     * @var FRE_Entry_Query
     */
    private $query;

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

        // Generate filename.
        $filename = 'form-entries';
        if ( ! empty( $args['form_id'] ) ) {
            $filename .= '-' . sanitize_file_name( $args['form_id'] );
        }
        $filename .= '-' . date( 'Y-m-d' ) . '.csv';

        // Set headers for download.
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // Open output stream.
        $output = fopen( 'php://output', 'w' );

        // Add BOM for Excel compatibility.
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        // Get column headers.
        $headers = $this->get_column_headers( $args['form_id'] );
        fputcsv( $output, $headers );

        // Stream entries in chunks.
        $page     = 1;
        $per_page = 100;

        do {
            $entries = $this->query->page( $page, $per_page )->get( true );

            foreach ( $entries as $entry ) {
                $row = $this->format_entry_row( $entry, $args['form_id'] );
                fputcsv( $output, $row );
            }

            // Flush output periodically.
            if ( $page % 10 === 0 ) {
                if ( ob_get_level() > 0 ) {
                    ob_flush();
                }
                flush();
            }

            $page++;
        } while ( count( $entries ) === $per_page );

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
            return $this->field_instances[ $type ]->format_csv_value( $value, $field );
        }

        // Fallback.
        if ( is_array( $value ) ) {
            return implode( ', ', $value );
        }

        return (string) $value;
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
