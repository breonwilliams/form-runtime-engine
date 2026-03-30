<?php
/**
 * Form Renderer for Form Runtime Engine.
 *
 * Generates HTML for forms based on configuration.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form renderer class.
 */
class FRE_Renderer {

    /**
     * Field type instances cache.
     *
     * @var array
     */
    private $field_instances = array();

    /**
     * Render a form.
     *
     * @param string $form_id Form identifier.
     * @param array  $args    Render arguments.
     * @return string Form HTML.
     */
    public function render( $form_id, array $args = array() ) {
        $form = fre()->registry->get( $form_id );

        if ( ! $form ) {
            return $this->render_error(
                sprintf(
                    /* translators: %s: form ID */
                    __( 'Form not found: %s', 'form-runtime-engine' ),
                    esc_html( $form_id )
                )
            );
        }

        // Add form ID to form config for field rendering.
        $form['id'] = $form_id;

        // Merge with defaults.
        $args = wp_parse_args( $args, array(
            'values'    => array(),
            'css_class' => '',
            'ajax'      => true,
        ) );

        // Enqueue assets.
        $this->enqueue_assets();

        // Enqueue field-type-specific scripts (e.g., Google Places API for address fields).
        $this->enqueue_field_type_scripts( $form );

        // Build form HTML.
        $html = $this->build_form( $form, $args );

        // Prepend custom CSS if form has it (database-stored forms only).
        $html = $this->prepend_custom_css( $form_id, $html );

        return $html;
    }

    /**
     * Prepend custom CSS for database-stored forms.
     *
     * @param string $form_id Form ID.
     * @param string $html    Form HTML.
     * @return string HTML with custom CSS prepended.
     */
    private function prepend_custom_css( $form_id, $html ) {
        // Check if FRE_Forms_Manager class exists (it should for DB forms).
        if ( ! class_exists( 'FRE_Forms_Manager' ) ) {
            return $html;
        }

        $db_form = FRE_Forms_Manager::get_form( $form_id );

        if ( $db_form && ! empty( $db_form['custom_css'] ) ) {
            $css_html = sprintf(
                '<style id="fre-custom-css-%s">%s</style>',
                esc_attr( $form_id ),
                wp_strip_all_tags( $db_form['custom_css'] )
            );
            $html = $css_html . $html;
        }

        return $html;
    }

    /**
     * Build form HTML.
     *
     * @param array $form Form configuration.
     * @param array $args Render arguments.
     * @return string
     */
    private function build_form( array $form, array $args ) {
        $form_id  = $form['id'];
        $settings = $form['settings'];
        $is_multistep = ! empty( $form['steps'] );

        // Build form classes.
        $classes = array( 'fre-form' );
        if ( ! empty( $settings['css_class'] ) ) {
            $classes[] = esc_attr( $settings['css_class'] );
        }
        if ( ! empty( $args['css_class'] ) ) {
            $classes[] = esc_attr( $args['css_class'] );
        }
        if ( $is_multistep ) {
            $classes[] = 'fre-form--multistep';
        }

        // Add theme variant class for dark mode support.
        $theme_variant = isset( $settings['theme_variant'] ) ? $settings['theme_variant'] : 'light';
        if ( in_array( $theme_variant, array( 'light', 'dark', 'auto' ), true ) ) {
            $classes[] = 'fre-form--' . $theme_variant;
        }

        // Check for file fields to set enctype.
        $has_file_field = $this->has_file_field( $form );

        // Build data attributes.
        $data_attrs = sprintf( 'data-form-id="%s"', esc_attr( $form_id ) );
        if ( $args['ajax'] ) {
            $data_attrs .= ' data-ajax="true"';
        }
        if ( $is_multistep ) {
            $data_attrs .= sprintf( ' data-steps="%d"', count( $form['steps'] ) );
            $multistep_settings = isset( $settings['multistep'] ) ? $settings['multistep'] : array();
            if ( ! empty( $multistep_settings['validate_on_next'] ) ) {
                $data_attrs .= ' data-validate-steps="true"';
            }
        }

        // Start form.
        $html = sprintf(
            '<form id="fre-form-%s" class="%s" method="post" %s%s>',
            esc_attr( $form_id ),
            implode( ' ', $classes ),
            $data_attrs,
            $has_file_field ? ' enctype="multipart/form-data"' : ''
        );

        // Nonce field.
        $html .= wp_nonce_field( 'fre_submit_' . $form_id, '_wpnonce', true, false );

        // Form ID hidden field.
        $html .= sprintf(
            '<input type="hidden" name="fre_form_id" value="%s" />',
            esc_attr( $form_id )
        );

        // Signed timing token for timing check (Fix #16: Prevents bypass).
        if ( ! empty( $settings['spam_protection']['timing_check'] ) ) {
            $timing_token = FRE_Timing_Check::generate_timing_token( $form_id );
            $html .= sprintf(
                '<input type="hidden" name="_fre_timing_token" value="%s" />',
                esc_attr( $timing_token )
            );
        }

        // Honeypot field.
        if ( ! empty( $settings['spam_protection']['honeypot'] ) ) {
            $html .= $this->render_honeypot( $form_id );
        }

        // Form title (only if show_title setting is true).
        $show_title = isset( $settings['show_title'] ) ? $settings['show_title'] : false;
        if ( $show_title && ! empty( $form['title'] ) ) {
            $html .= sprintf(
                '<h3 class="fre-form__title">%s</h3>',
                esc_html( $form['title'] )
            );
        }

        // Messages container.
        $html .= '<div class="fre-form__messages" role="alert" aria-live="polite"></div>';

        // Multi-step progress indicator.
        if ( $is_multistep ) {
            $html .= $this->render_progress_indicator( $form['steps'], $settings );
        }

        // Fields container.
        $html .= '<div class="fre-form__fields">';

        if ( $is_multistep ) {
            $html .= $this->render_multistep_fields( $form, $args );
        } else {
            $html .= $this->render_fields_with_layout( $form, $args );
        }

        $html .= '</div>';

        // Submit button (for non-multistep forms).
        if ( ! $is_multistep ) {
            $html .= $this->render_submit_button( $settings );
        }

        // Close form.
        $html .= '</form>';

        /**
         * Filter the rendered form HTML.
         *
         * @param string $html    Form HTML.
         * @param string $form_id Form ID.
         * @param array  $form    Form configuration.
         * @param array  $args    Render arguments.
         */
        return apply_filters( 'fre_rendered_form', $html, $form_id, $form, $args );
    }

    /**
     * Render fields with column and section layout support.
     *
     * @param array $form Form configuration.
     * @param array $args Render arguments.
     * @return string
     */
    private function render_fields_with_layout( array $form, array $args ) {
        $html = '';
        $fields = $form['fields'];
        $total = count( $fields );
        $i = 0;

        while ( $i < $total ) {
            $field = $fields[ $i ];

            // Handle section fields.
            if ( isset( $field['type'] ) && $field['type'] === 'section' ) {
                $html .= $this->render_section_with_fields( $form, $args, $fields, $i );
                continue;
            }

            // Check if this field starts a column row.
            if ( ! empty( $field['column'] ) ) {
                $html .= $this->render_column_row( $form, $args, $fields, $i );
                continue;
            }

            // Regular field.
            $value = $this->get_field_value( $field, $args );
            $html .= $this->render_field( $field, $value, $form );
            $i++;
        }

        return $html;
    }

    /**
     * Render a row of column fields.
     *
     * @param array $form   Form configuration.
     * @param array $args   Render arguments.
     * @param array $fields All fields.
     * @param int   &$index Current index (modified by reference).
     * @return string
     */
    private function render_column_row( array $form, array $args, array $fields, &$index ) {
        $html = '<div class="fre-row">';
        $total = count( $fields );

        // Collect consecutive column fields.
        while ( $index < $total && ! empty( $fields[ $index ]['column'] ) ) {
            $field = $fields[ $index ];

            // Skip section fields in column detection.
            if ( isset( $field['type'] ) && $field['type'] === 'section' ) {
                break;
            }

            $value = $this->get_field_value( $field, $args );
            $html .= $this->render_field( $field, $value, $form );
            $index++;
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a section with its grouped fields.
     *
     * @param array $form   Form configuration.
     * @param array $args   Render arguments.
     * @param array $fields All fields.
     * @param int   &$index Current index (modified by reference).
     * @return string
     */
    private function render_section_with_fields( array $form, array $args, array $fields, &$index ) {
        $section = $fields[ $index ];
        $section_key = $section['key'];
        $index++;

        $classes = array( 'fre-section' );
        if ( ! empty( $section['css_class'] ) ) {
            $classes[] = esc_attr( $section['css_class'] );
        }

        // Check for conditions on the section itself.
        $data_attrs = sprintf( 'data-section-key="%s"', esc_attr( $section_key ) );
        if ( ! empty( $section['conditions'] ) ) {
            $data_attrs .= sprintf(
                ' data-conditions="%s"',
                esc_attr( wp_json_encode( $section['conditions'] ) )
            );
        }

        $html = sprintf( '<div class="%s" %s>', implode( ' ', $classes ), $data_attrs );

        // Section title.
        if ( ! empty( $section['label'] ) ) {
            $html .= sprintf(
                '<h4 class="fre-section__title">%s</h4>',
                esc_html( $section['label'] )
            );
        }

        // Section description.
        if ( ! empty( $section['description'] ) ) {
            $html .= sprintf(
                '<p class="fre-section__description">%s</p>',
                esc_html( $section['description'] )
            );
        }

        $html .= '<div class="fre-section__fields">';

        // Render fields that belong to this section.
        $total = count( $fields );
        while ( $index < $total ) {
            $field = $fields[ $index ];

            // Stop if we hit another section or a field not in this section.
            if ( isset( $field['type'] ) && $field['type'] === 'section' ) {
                break;
            }

            // Only include fields that explicitly belong to this section.
            if ( ! isset( $field['section'] ) || $field['section'] !== $section_key ) {
                // If field has no section, it doesn't belong here.
                // If field has a different section, stop.
                if ( isset( $field['section'] ) ) {
                    break;
                }
                // No section specified - this field is outside sections.
                break;
            }

            // Check if this field starts a column row within the section.
            if ( ! empty( $field['column'] ) ) {
                $html .= $this->render_column_row_in_section( $form, $args, $fields, $index, $section_key );
                continue;
            }

            $value = $this->get_field_value( $field, $args );
            $html .= $this->render_field( $field, $value, $form );
            $index++;
        }

        $html .= '</div></div>';

        return $html;
    }

    /**
     * Render a row of column fields within a section.
     *
     * @param array  $form        Form configuration.
     * @param array  $args        Render arguments.
     * @param array  $fields      All fields.
     * @param int    &$index      Current index (modified by reference).
     * @param string $section_key Section key to check.
     * @return string
     */
    private function render_column_row_in_section( array $form, array $args, array $fields, &$index, $section_key ) {
        $html = '<div class="fre-row">';
        $total = count( $fields );

        while ( $index < $total && ! empty( $fields[ $index ]['column'] ) ) {
            $field = $fields[ $index ];

            // Stop if we leave the section.
            if ( ! isset( $field['section'] ) || $field['section'] !== $section_key ) {
                break;
            }

            // Skip section fields.
            if ( isset( $field['type'] ) && $field['type'] === 'section' ) {
                break;
            }

            $value = $this->get_field_value( $field, $args );
            $html .= $this->render_field( $field, $value, $form );
            $index++;
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render multi-step form fields.
     *
     * @param array $form Form configuration.
     * @param array $args Render arguments.
     * @return string
     */
    private function render_multistep_fields( array $form, array $args ) {
        $html = '';
        $steps = $form['steps'];
        $fields = $form['fields'];
        $settings = $form['settings'];
        $total_steps = count( $steps );

        foreach ( $steps as $step_index => $step ) {
            $step_key = $step['key'];
            $is_first = $step_index === 0;
            $is_last = $step_index === $total_steps - 1;

            $step_classes = array( 'fre-step' );
            if ( $is_first ) {
                $step_classes[] = 'fre-step--active';
            }

            $html .= sprintf(
                '<div class="%s" data-step="%d" data-step-key="%s">',
                implode( ' ', $step_classes ),
                $step_index + 1,
                esc_attr( $step_key )
            );

            // Step title (optional).
            if ( ! empty( $step['title'] ) && ! empty( $settings['multistep']['show_step_titles'] ) ) {
                $html .= sprintf(
                    '<h4 class="fre-step__title">%s</h4>',
                    esc_html( $step['title'] )
                );
            }

            // Collect fields for this step.
            $step_fields = array_filter( $fields, function( $field ) use ( $step_key ) {
                return isset( $field['step'] ) && $field['step'] === $step_key;
            });

            // Re-index for processing.
            $step_fields = array_values( $step_fields );

            // Render step fields with layout support.
            $html .= '<div class="fre-step__fields">';
            $html .= $this->render_step_fields_with_layout( $form, $args, $step_fields );
            $html .= '</div>';

            // Step navigation.
            $html .= '<div class="fre-step__nav">';

            if ( ! $is_first ) {
                $html .= sprintf(
                    '<button type="button" class="fre-step__prev fre-btn fre-btn--secondary">%s</button>',
                    esc_html__( 'Previous', 'form-runtime-engine' )
                );
            }

            if ( $is_last ) {
                $html .= $this->render_submit_button( $settings, 'fre-step__submit' );
            } else {
                $html .= sprintf(
                    '<button type="button" class="fre-step__next fre-btn fre-btn--primary">%s</button>',
                    esc_html__( 'Next', 'form-runtime-engine' )
                );
            }

            $html .= '</div>';

            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Render step fields with layout support.
     *
     * @param array $form        Form configuration.
     * @param array $args        Render arguments.
     * @param array $step_fields Fields for this step.
     * @return string
     */
    private function render_step_fields_with_layout( array $form, array $args, array $step_fields ) {
        $html = '';
        $total = count( $step_fields );
        $i = 0;

        while ( $i < $total ) {
            $field = $step_fields[ $i ];

            // Handle section fields within a step.
            if ( isset( $field['type'] ) && $field['type'] === 'section' ) {
                $html .= $this->render_section_in_step( $form, $args, $step_fields, $i );
                continue;
            }

            // Check if this field starts a column row.
            if ( ! empty( $field['column'] ) ) {
                $html .= $this->render_column_row_simple( $form, $args, $step_fields, $i );
                continue;
            }

            // Regular field.
            $value = $this->get_field_value( $field, $args );
            $html .= $this->render_field( $field, $value, $form );
            $i++;
        }

        return $html;
    }

    /**
     * Render a simple column row (no section context).
     *
     * @param array $form   Form configuration.
     * @param array $args   Render arguments.
     * @param array $fields Fields array.
     * @param int   &$index Current index (modified by reference).
     * @return string
     */
    private function render_column_row_simple( array $form, array $args, array $fields, &$index ) {
        $html = '<div class="fre-row">';
        $total = count( $fields );

        while ( $index < $total && ! empty( $fields[ $index ]['column'] ) ) {
            $field = $fields[ $index ];

            if ( isset( $field['type'] ) && $field['type'] === 'section' ) {
                break;
            }

            $value = $this->get_field_value( $field, $args );
            $html .= $this->render_field( $field, $value, $form );
            $index++;
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a section within a step.
     *
     * @param array $form        Form configuration.
     * @param array $args        Render arguments.
     * @param array $step_fields Step fields.
     * @param int   &$index      Current index.
     * @return string
     */
    private function render_section_in_step( array $form, array $args, array $step_fields, &$index ) {
        $section = $step_fields[ $index ];
        $section_key = $section['key'];
        $index++;

        $classes = array( 'fre-section' );
        if ( ! empty( $section['css_class'] ) ) {
            $classes[] = esc_attr( $section['css_class'] );
        }

        $data_attrs = sprintf( 'data-section-key="%s"', esc_attr( $section_key ) );
        if ( ! empty( $section['conditions'] ) ) {
            $data_attrs .= sprintf(
                ' data-conditions="%s"',
                esc_attr( wp_json_encode( $section['conditions'] ) )
            );
        }

        $html = sprintf( '<div class="%s" %s>', implode( ' ', $classes ), $data_attrs );

        if ( ! empty( $section['label'] ) ) {
            $html .= sprintf(
                '<h4 class="fre-section__title">%s</h4>',
                esc_html( $section['label'] )
            );
        }

        if ( ! empty( $section['description'] ) ) {
            $html .= sprintf(
                '<p class="fre-section__description">%s</p>',
                esc_html( $section['description'] )
            );
        }

        $html .= '<div class="fre-section__fields">';

        $total = count( $step_fields );
        while ( $index < $total ) {
            $field = $step_fields[ $index ];

            if ( isset( $field['type'] ) && $field['type'] === 'section' ) {
                break;
            }

            if ( ! isset( $field['section'] ) || $field['section'] !== $section_key ) {
                break;
            }

            if ( ! empty( $field['column'] ) ) {
                $html .= $this->render_column_row_in_section_step( $form, $args, $step_fields, $index, $section_key );
                continue;
            }

            $value = $this->get_field_value( $field, $args );
            $html .= $this->render_field( $field, $value, $form );
            $index++;
        }

        $html .= '</div></div>';

        return $html;
    }

    /**
     * Render column row within a section in a step.
     *
     * @param array  $form        Form configuration.
     * @param array  $args        Render arguments.
     * @param array  $step_fields Step fields.
     * @param int    &$index      Current index.
     * @param string $section_key Section key.
     * @return string
     */
    private function render_column_row_in_section_step( array $form, array $args, array $step_fields, &$index, $section_key ) {
        $html = '<div class="fre-row">';
        $total = count( $step_fields );

        while ( $index < $total && ! empty( $step_fields[ $index ]['column'] ) ) {
            $field = $step_fields[ $index ];

            if ( ! isset( $field['section'] ) || $field['section'] !== $section_key ) {
                break;
            }

            if ( isset( $field['type'] ) && $field['type'] === 'section' ) {
                break;
            }

            $value = $this->get_field_value( $field, $args );
            $html .= $this->render_field( $field, $value, $form );
            $index++;
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render progress indicator for multi-step forms.
     *
     * @param array $steps    Steps configuration.
     * @param array $settings Form settings.
     * @return string
     */
    private function render_progress_indicator( array $steps, array $settings ) {
        $multistep_settings = isset( $settings['multistep'] ) ? $settings['multistep'] : array();
        $show_progress = isset( $multistep_settings['show_progress'] ) ? $multistep_settings['show_progress'] : true;

        if ( ! $show_progress ) {
            return '';
        }

        $style = isset( $multistep_settings['progress_style'] ) ? $multistep_settings['progress_style'] : 'steps';
        $total_steps = count( $steps );

        // Determine if we need a bar fallback for adaptive switching.
        // Always include bar fallback for steps style (dynamic threshold may trigger for any step count).
        $needs_bar_fallback = ( 'steps' === $style && $total_steps >= 2 );

        $html = sprintf(
            '<div class="fre-progress fre-progress--%s" data-total-steps="%d" data-original-style="%s">',
            esc_attr( $style ),
            $total_steps,
            esc_attr( $style )
        );

        if ( $style === 'bar' ) {
            $html .= '<div class="fre-progress__bar">';
            $html .= '<div class="fre-progress__fill" style="width: ' . ( 100 / $total_steps ) . '%;"></div>';
            $html .= '</div>';
            $html .= sprintf(
                '<div class="fre-progress__text">Step <span class="fre-progress__current">1</span> of %d</div>',
                $total_steps
            );
        } else {
            // Steps or dots style.
            $html .= '<div class="fre-progress__steps">';
            foreach ( $steps as $index => $step ) {
                $step_classes = array( 'fre-progress__step' );
                if ( $index === 0 ) {
                    $step_classes[] = 'fre-progress__step--active';
                }

                $html .= sprintf(
                    '<div class="%s" data-step="%d">',
                    implode( ' ', $step_classes ),
                    $index + 1
                );

                if ( $style === 'steps' ) {
                    $html .= sprintf( '<span class="fre-progress__number">%d</span>', $index + 1 );
                    if ( ! empty( $step['title'] ) ) {
                        $html .= sprintf(
                            '<span class="fre-progress__label">%s</span>',
                            esc_html( $step['title'] )
                        );
                    }
                } else {
                    // Dots style.
                    $html .= '<span class="fre-progress__dot"></span>';
                }

                $html .= '</div>';

                // Connector between steps.
                if ( $index < $total_steps - 1 ) {
                    $html .= '<div class="fre-progress__connector"></div>';
                }
            }
            $html .= '</div>';

            // Add hidden bar fallback for adaptive switching (5+ steps with "steps" style).
            if ( $needs_bar_fallback ) {
                $html .= '<div class="fre-progress__bar-container" style="display: none;">';
                $html .= '<div class="fre-progress__bar">';
                $html .= '<div class="fre-progress__fill" style="width: ' . ( 100 / $total_steps ) . '%;"></div>';
                $html .= '</div>';
                $html .= sprintf(
                    '<div class="fre-progress__text">Step <span class="fre-progress__current">1</span> of %d</div>',
                    $total_steps
                );
                $html .= '</div>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Get field value from args.
     *
     * @param array $field Field configuration.
     * @param array $args  Render arguments.
     * @return mixed
     */
    private function get_field_value( array $field, array $args ) {
        if ( isset( $args['values'][ $field['key'] ] ) ) {
            return $args['values'][ $field['key'] ];
        }
        return isset( $field['default'] ) ? $field['default'] : '';
    }

    /**
     * Render a single field.
     *
     * @param array  $field Field configuration.
     * @param mixed  $value Current value.
     * @param array  $form  Form configuration.
     * @return string
     */
    public function render_field( array $field, $value, array $form ) {
        $type     = isset( $field['type'] ) ? $field['type'] : 'text';
        $instance = $this->get_field_instance( $type );

        if ( ! $instance ) {
            return $this->render_error(
                sprintf(
                    /* translators: %s: field type */
                    __( 'Unknown field type: %s', 'form-runtime-engine' ),
                    esc_html( $type )
                )
            );
        }

        /**
         * Filter the field value before rendering.
         *
         * @param mixed  $value   Field value.
         * @param array  $field   Field configuration.
         * @param array  $form    Form configuration.
         * @param string $form_id Form ID.
         */
        $value = apply_filters( 'fre_render_field_value', $value, $field, $form, $form['id'] );

        return $instance->render( $field, $value, $form );
    }

    /**
     * Get field type instance.
     *
     * @param string $type Field type slug.
     * @return FRE_Field_Type|null
     */
    private function get_field_instance( $type ) {
        if ( isset( $this->field_instances[ $type ] ) ) {
            return $this->field_instances[ $type ];
        }

        $class_name = FRE_Autoloader::get_field_class( $type );

        if ( ! $class_name || ! class_exists( $class_name ) ) {
            return null;
        }

        $this->field_instances[ $type ] = new $class_name();

        return $this->field_instances[ $type ];
    }

    /**
     * Render honeypot field (Fix #26: Uses unpredictable field names).
     *
     * @param string $form_id Form ID.
     * @return string
     */
    private function render_honeypot( $form_id ) {
        // Fix #26: Use the honeypot class to generate unpredictable field names.
        $honeypot   = new FRE_Honeypot();
        $field_name = $honeypot->get_field_name( $form_id );

        // Hidden via CSS, not hidden input type (bots might skip hidden inputs).
        return sprintf(
            '<div class="fre-form__hp" aria-hidden="true" style="position:absolute;left:-9999px;height:0;overflow:hidden;">
                <label for="%1$s">Website (leave blank)</label>
                <input type="text" name="%1$s" id="%1$s" value="" tabindex="-1" autocomplete="off" />
            </div>',
            esc_attr( $field_name )
        );
    }

    /**
     * Render submit button.
     *
     * @param array  $settings       Form settings.
     * @param string $extra_class    Additional CSS class for the wrapper.
     * @return string
     */
    private function render_submit_button( array $settings, $extra_class = '' ) {
        $text = ! empty( $settings['submit_button_text'] )
            ? $settings['submit_button_text']
            : __( 'Submit', 'form-runtime-engine' );

        $wrapper_classes = array( 'fre-form__submit' );
        if ( ! empty( $extra_class ) ) {
            $wrapper_classes[] = esc_attr( $extra_class );
        }

        $html = sprintf( '<div class="%s">', implode( ' ', $wrapper_classes ) );
        $html .= sprintf(
            '<button type="submit" class="fre-form__submit-button">
                <span class="fre-form__submit-text">%s</span>
                <span class="fre-form__submit-loading" aria-hidden="true" style="display:none;">
                    <span class="fre-spinner"></span>
                    %s
                </span>
            </button>',
            esc_html( $text ),
            esc_html__( 'Submitting...', 'form-runtime-engine' )
        );
        $html .= '</div>';

        return $html;
    }

    /**
     * Check if form has file fields.
     *
     * @param array $form Form configuration.
     * @return bool
     */
    private function has_file_field( array $form ) {
        foreach ( $form['fields'] as $field ) {
            if ( isset( $field['type'] ) && $field['type'] === 'file' ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render an error message.
     *
     * @param string $message Error message.
     * @return string
     */
    private function render_error( $message ) {
        if ( current_user_can( 'manage_options' ) ) {
            return sprintf(
                '<div class="fre-form-error">%s</div>',
                esc_html( $message )
            );
        }

        return '';
    }

    /**
     * Enqueue form assets.
     */
    private function enqueue_assets() {
        wp_enqueue_style( 'fre-frontend' );
        wp_enqueue_script( 'fre-frontend' );
    }

    /**
     * Enqueue scripts for specific field types.
     *
     * @param array $form Form configuration.
     */
    private function enqueue_field_type_scripts( array $form ) {
        // Check for address fields and enqueue Google Places API.
        if ( $this->has_address_field( $form ) ) {
            FRE_Field_Address::enqueue_scripts();
        }
    }

    /**
     * Check if form has address fields.
     *
     * @param array $form Form configuration.
     * @return bool
     */
    private function has_address_field( array $form ) {
        foreach ( $form['fields'] as $field ) {
            if ( isset( $field['type'] ) && $field['type'] === 'address' ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all registered field type instances.
     *
     * @return array
     */
    public function get_all_field_instances() {
        $types = FRE_Autoloader::get_field_types();

        foreach ( $types as $type ) {
            $this->get_field_instance( $type );
        }

        return $this->field_instances;
    }
}
