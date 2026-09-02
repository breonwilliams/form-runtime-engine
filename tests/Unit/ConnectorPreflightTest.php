<?php
/**
 * Unit tests for the FRE connector preflight rulebook + schema endpoint.
 *
 * These tests guard the Phase 2A hardening pattern: fresh consumer sessions
 * must receive a complete rules digest inline from /preflight, plus a
 * schema_reference_url that serves the markdown knowledge map. Regressions
 * in this surface cause the session-context-asymmetry bugs this pattern
 * exists to prevent.
 *
 * Focus areas:
 *   - get_connector_rulebook() shape — read_first, critical_rules,
 *     field_hints (all 13 field types), universal_field_properties,
 *     settings_hints
 *   - Individual critical rules known to cause silent failures
 *   - Existence of FRE_KNOWLEDGE_MAP.md as the schema endpoint source
 *   - MCP tool descriptions contain mandatory-read framing
 *
 * Does NOT test live REST behavior — that would require a WordPress test
 * framework. Instead we test the pure PHP payload builder and assert that
 * the source files other subsystems rely on are in the expected shape.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for PForms_Connector_API::get_connector_rulebook() and related surface.
 */
class ConnectorPreflightTest extends UnitTestCase {

    /**
     * All field types FRE currently supports. Must match the field_hints keys.
     *
     * Source of truth: includes/Fields/ class-fre-field-*.php files, and the
     * FRE_KNOWLEDGE_MAP.md §Field types table.
     *
     * @var string[]
     */
    private static $supported_field_types = array(
        'text',
        'email',
        'tel',
        'textarea',
        'select',
        'radio',
        'checkbox',
        'file',
        'hidden',
        'message',
        'section',
        'date',
        'address',
    );

    protected function set_up() {
        parent::set_up();

        // The rulebook is pure data — no WP dependencies. But the class file
        // itself imports WP_REST_Server constants etc., so we only require the
        // class file once and ensure WP_REST_Server is stubbed for class load.
        if ( ! class_exists( 'WP_REST_Server' ) ) {
            // Minimal stub — real WP defines READABLE/CREATABLE/EDITABLE/DELETABLE
            // as HTTP verb strings. We only need the class to exist for require_once
            // to succeed without fatal.
            eval( 'class WP_REST_Server { const READABLE = "GET"; const CREATABLE = "POST"; const EDITABLE = "POST, PUT, PATCH"; const DELETABLE = "DELETE"; }' );
        }

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-capabilities.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Connector/class-fre-connector-settings.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Connector/class-fre-connector-auth.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Connector/class-fre-connector-log.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Connector/class-fre-connector-api.php';
    }

    // -------------------------------------------------------------------------
    // Rulebook structure
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function rulebook_returns_all_expected_top_level_keys() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );

        $this->assertIsArray( $rulebook );
        $this->assertArrayHasKey( 'read_first', $rulebook, 'Rulebook must include read_first instruction.' );
        $this->assertArrayHasKey( 'critical_rules', $rulebook, 'Rulebook must include critical_rules digest.' );
        $this->assertArrayHasKey( 'field_hints', $rulebook, 'Rulebook must include field_hints.' );
        $this->assertArrayHasKey( 'universal_field_properties', $rulebook, 'Rulebook must include universal_field_properties.' );
        $this->assertArrayHasKey( 'settings_hints', $rulebook, 'Rulebook must include settings_hints.' );
    }

    /**
     * @test
     */
    public function settings_hints_document_appearance_surface() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $hints    = $rulebook['settings_hints'];

        $this->assertArrayHasKey(
            'appearance',
            $hints,
            'settings_hints must include an appearance entry so consumers discover settings.appearance.surface.'
        );
        $this->assertStringContainsString( 'card', $hints['appearance'] );
        $this->assertStringContainsString( 'surface', $hints['appearance'] );
    }

    /**
     * @test
     */
    public function text_field_hint_includes_pattern_property() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $this->assertContains(
            'pattern',
            $rulebook['field_hints']['text']['optional_properties'],
            'text fields accept `pattern` for regex validation — field_hints must list it.'
        );
    }

    /**
     * @test
     */
    public function section_field_hint_explains_visual_card_treatment() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $notes    = $rulebook['field_hints']['section']['notes'];
        // Ensure the section field's notes mention both that it's a visual
        // container and the alternative form-level surface flag, so consumer
        // sessions can pick the right route without reading the full
        // markdown rulebook.
        $this->assertStringContainsString( 'card', $notes );
        $this->assertStringContainsString( 'appearance.surface', $notes );
    }

    /**
     * @test
     */
    public function read_first_references_provided_schema_url() {
        $url      = 'https://example.test/wp-json/fre/v1/connector/schema';
        $rulebook = \PForms_Connector_API::get_connector_rulebook( $url );

        $this->assertIsString( $rulebook['read_first'] );
        $this->assertStringContainsString(
            $url,
            $rulebook['read_first'],
            'read_first must include the schema_reference_url so consumers know where to fetch the rulebook.'
        );
        $this->assertStringContainsString(
            'WebFetch',
            $rulebook['read_first'],
            'read_first must instruct consumers to WebFetch the rulebook.'
        );
    }

    // -------------------------------------------------------------------------
    // Critical rules
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function critical_rules_include_config_is_string() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $rules    = $rulebook['critical_rules'];

        $this->assertArrayHasKey( 'config_is_string', $rules );
        $this->assertStringContainsString( 'JSON STRING', $rules['config_is_string'] );
        $this->assertStringContainsString( 'JSON.stringify', $rules['config_is_string'] );
    }

    /**
     * @test
     */
    public function critical_rules_include_column_values() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $rules    = $rulebook['critical_rules'];

        $this->assertArrayHasKey( 'column_values', $rules );
        foreach ( array( '1/2', '1/3', '2/3', '1/4', '3/4' ) as $fraction ) {
            $this->assertStringContainsString(
                $fraction,
                $rules['column_values'],
                "column_values rule must enumerate '{$fraction}' as a valid value."
            );
        }
    }

    /**
     * @test
     */
    public function critical_rules_include_options_required_for_select_and_radio() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $rules    = $rulebook['critical_rules'];

        $this->assertArrayHasKey( 'options_required', $rules );
        $this->assertStringContainsString( 'select', $rules['options_required'] );
        $this->assertStringContainsString( 'radio', $rules['options_required'] );
        $this->assertStringContainsString( 'options', $rules['options_required'] );
    }

    /**
     * @test
     */
    public function critical_rules_cover_known_drift_patterns() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $rules    = $rulebook['critical_rules'];

        // Every rule listed in FRE_CONNECTOR_HARDENING_PLAN.md P0.1 + the
        // later audit additions must exist.
        $required_rules = array(
            // Phase 2A hardening:
            'config_is_string',
            'column_values',
            'options_required',
            'form_id_regex',
            'theme_variant_for_dark_backgrounds',
            'webhook_secret_rotation',
            'managed_by_immutable',
            'entry_read_gate',
            'test_submit_dry_run',
            // Audit-driven additions (surface/card vocabulary, spam-protection
            // surprises, AISB inheritance):
            'form_surface_options',
            'honeypot_field_name_dynamic',
            'min_submission_time_enforcement',
            'aisb_token_inheritance',
        );

        foreach ( $required_rules as $key ) {
            $this->assertArrayHasKey(
                $key,
                $rules,
                "critical_rules must include '{$key}' — it was enumerated in the FRE hardening or audit plan."
            );
            $this->assertNotEmpty(
                $rules[ $key ],
                "critical_rules['{$key}'] must not be empty."
            );
        }
    }

    /**
     * @test
     */
    public function form_surface_rule_names_both_routes() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $rule     = $rulebook['critical_rules']['form_surface_options'];

        // Must mention the form-level route (settings.appearance.surface) AND
        // the field-level route (section field type) so consumer sessions can
        // pick the right one based on the user's ask.
        $this->assertStringContainsString( 'appearance.surface', $rule );
        $this->assertStringContainsString( 'section', $rule );
        // Vocabulary — users say "card" / "surface" / "wrapper" / "container".
        $this->assertStringContainsString( 'card', $rule );
        $this->assertStringContainsString( 'surface', $rule );
        $this->assertStringContainsString( 'wrapper', $rule );
    }

    // -------------------------------------------------------------------------
    // Field hints
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function field_hints_cover_every_supported_field_type() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $hints    = $rulebook['field_hints'];

        foreach ( self::$supported_field_types as $type ) {
            $this->assertArrayHasKey(
                $type,
                $hints,
                "field_hints must include '{$type}' — it's a supported field type."
            );
        }

        // Mirror check: hints shouldn't document field types we don't actually
        // support (would create consumer confusion + maintenance drift).
        foreach ( array_keys( $hints ) as $type ) {
            $this->assertContains(
                $type,
                self::$supported_field_types,
                "field_hints documents '{$type}' which is not in the supported field type list. Either add it to Fields/ or remove from hints."
            );
        }
    }

    /**
     * @test
     */
    public function every_field_hint_declares_required_properties() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $hints    = $rulebook['field_hints'];

        foreach ( $hints as $type => $hint ) {
            $this->assertArrayHasKey(
                'required_properties',
                $hint,
                "field_hints['{$type}'] must declare required_properties."
            );
            $this->assertIsArray( $hint['required_properties'], "field_hints['{$type}'].required_properties must be an array." );
            $this->assertContains(
                'key',
                $hint['required_properties'],
                "Every field type requires 'key' — field_hints['{$type}'] is missing it."
            );
            $this->assertContains(
                'type',
                $hint['required_properties'],
                "Every field type requires 'type' — field_hints['{$type}'] is missing it."
            );
        }
    }

    /**
     * @test
     */
    public function select_and_radio_hints_require_options() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $hints    = $rulebook['field_hints'];

        $this->assertContains( 'options', $hints['select']['required_properties'] );
        $this->assertContains( 'options', $hints['radio']['required_properties'] );
    }

    // -------------------------------------------------------------------------
    // Universal field properties
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function universal_field_properties_group_the_expected_categories() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $universal = $rulebook['universal_field_properties'];

        foreach ( array( 'identity', 'layout', 'behavior', 'constraints' ) as $group ) {
            $this->assertArrayHasKey(
                $group,
                $universal,
                "universal_field_properties must group properties under '{$group}'."
            );
        }

        // Layout group must mention column, section, step — the three layout
        // hooks consumers need to know about for multi-column / sectioned /
        // multi-step forms.
        $this->assertContains( 'column', $universal['layout'] );
        $this->assertContains( 'section', $universal['layout'] );
        $this->assertContains( 'step', $universal['layout'] );

        // Behavior group must mention conditions so consumers can discover
        // conditional visibility.
        $this->assertContains( 'conditions', $universal['behavior'] );
    }

    // -------------------------------------------------------------------------
    // Schema document (markdown rulebook)
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function schema_document_exists_at_expected_path() {
        $path = FRE_TEST_PLUGIN_DIR . 'docs/FRE_KNOWLEDGE_MAP.md';

        $this->assertFileExists(
            $path,
            'docs/FRE_KNOWLEDGE_MAP.md must exist — it is served by the /schema endpoint.'
        );
        $this->assertIsReadable( $path );
    }

    /**
     * @test
     */
    public function schema_document_starts_with_canonical_title() {
        $path    = FRE_TEST_PLUGIN_DIR . 'docs/FRE_KNOWLEDGE_MAP.md';
        $content = file_get_contents( $path );

        $this->assertIsString( $content );
        $this->assertNotEmpty( $content );
        $this->assertStringStartsWith(
            '# Form Runtime Engine — Connector Knowledge Map',
            $content,
            'Knowledge map must begin with the canonical H1 title so consumers see an identifiable heading.'
        );
    }

    // -------------------------------------------------------------------------
    // MCP tool descriptions (form-engine-connector.js)
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function mcp_preflight_description_mandates_being_called_first() {
        $js = $this->load_mcp_connector_js();

        // Preflight description must tell the consumer session that preflight
        // is mandatory BEFORE any other tool call, and that WebFetching the
        // schema reference URL is required.
        $this->assertStringContainsString(
            'MUST be called first',
            $js,
            'formengine_preflight description must mandate being called first.'
        );
        $this->assertStringContainsString(
            'WebFetch',
            $js,
            'formengine_preflight description must mention WebFetch of the schema_reference_url.'
        );
        $this->assertStringContainsString(
            'schema_reference_url',
            $js,
            'MCP descriptions must reference schema_reference_url by name.'
        );
    }

    /**
     * @test
     */
    public function mcp_create_and_update_descriptions_mandate_schema_read() {
        $js = $this->load_mcp_connector_js();

        // Create description must include the BEFORE-first-create framing.
        $create_slice = $this->slice_tool_description( $js, 'formengine_create_form' );
        $this->assertNotEmpty( $create_slice, 'Could not locate formengine_create_form description.' );
        $this->assertStringContainsString(
            'BEFORE your first create',
            $create_slice,
            'formengine_create_form description must tell consumers to call preflight + WebFetch schema before first create.'
        );
        $this->assertStringContainsString(
            'schema_reference_url',
            $create_slice,
            'formengine_create_form description must reference schema_reference_url by name.'
        );

        // Update description must mandate the same read.
        $update_slice = $this->slice_tool_description( $js, 'formengine_update_form' );
        $this->assertNotEmpty( $update_slice, 'Could not locate formengine_update_form description.' );
        $this->assertStringContainsString(
            'schema_reference_url',
            $update_slice,
            'formengine_update_form description must reference schema_reference_url by name.'
        );
    }

    /**
     * @test
     */
    public function mcp_create_description_mandates_config_as_json_string() {
        $js = $this->load_mcp_connector_js();

        $create_slice = $this->slice_tool_description( $js, 'formengine_create_form' );
        $this->assertStringContainsString(
            'JSON STRING',
            $create_slice,
            'formengine_create_form description must mandate that config is a JSON STRING, not an object.'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Load the MCP connector JS source as a raw string for grep-style assertions.
     *
     * @return string
     */
    private function load_mcp_connector_js() {
        $path = FRE_TEST_PLUGIN_DIR . 'includes/Connector/assets/form-engine-connector.js';
        $this->assertFileExists( $path, 'MCP connector JS source is missing.' );
        $content = file_get_contents( $path );
        $this->assertIsString( $content );
        return $content;
    }

    /**
     * Extract the description block for a single tool from the MCP JS source.
     *
     * We locate the tool's name: "..." declaration and then capture the
     * following `description:` string concatenation until the closing backtick
     * or the `inputSchema:` line (whichever comes first). This is a best-effort
     * slice — we only use it for substring assertions, not exact matching.
     *
     * @param string $js        Full JS source.
     * @param string $tool_name Tool name to locate.
     * @return string Slice containing the tool's description, or empty string.
     */
    private function slice_tool_description( $js, $tool_name ) {
        $needle = 'name: "' . $tool_name . '"';
        $start  = strpos( $js, $needle );
        if ( false === $start ) {
            return '';
        }
        // Find the next `inputSchema:` which marks the end of the description.
        $end = strpos( $js, 'inputSchema:', $start );
        if ( false === $end ) {
            return substr( $js, $start );
        }
        return substr( $js, $start, $end - $start );
    }
}
