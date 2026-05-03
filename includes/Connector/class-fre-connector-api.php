<?php
/**
 * Cowork connector REST API.
 *
 * Registers all routes under /wp-json/fre/v1/connector/*. Delegates to the
 * existing plugin subsystems:
 *
 *   - FRE_Forms_Repository for form CRUD
 *   - FRE_Entry, FRE_Entry_Query for entry reads
 *   - FRE_Submission_Handler::process_submission() for test submissions
 *
 * This class owns:
 *   - Route registration (see self::register_routes)
 *   - Request parsing + response shaping (see self::build_form_response et al.)
 *   - Argument validation via register_rest_route's $args sanitize_callback
 *
 * Does NOT own:
 *   - Permission decisions — those live in FRE_Connector_Auth
 *   - Storage — that lives in FRE_Forms_Repository
 *   - Validation of form config JSON — that lives in FRE_JSON_Schema_Validator
 *
 * Contract and error codes documented in docs/CONNECTOR_SPEC.md.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Connector REST controller.
 */
class FRE_Connector_API {

    /**
     * REST namespace. The URL becomes /wp-json/fre/v1/...
     *
     * @var string
     */
    const NAMESPACE_PREFIX = 'fre/v1';

    /**
     * Connector base, appended to the namespace. All routes share this.
     *
     * @var string
     */
    const ROUTE_BASE = 'connector';

    /**
     * Per-request start time, in milliseconds, for the call-log duration.
     *
     * Captured by the rest_pre_dispatch filter and consumed by
     * rest_post_dispatch. Microtime float, not unix seconds.
     *
     * @var float|null
     */
    private $request_started_at_ms = null;

    /**
     * Hook registration.
     *
     * Called from the main plugin's init(). Connects route registration to
     * the WordPress rest_api_init action and wires the call-log filters that
     * record every request to a connector route.
     */
    public function init() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        // Connector call log — wraps every connector REST request via the WP
        // dispatch filters so durations and outcomes are captured uniformly
        // without each handler having to log itself.
        add_filter( 'rest_pre_dispatch', array( $this, 'mark_request_start' ), 10, 3 );
        add_filter( 'rest_post_dispatch', array( $this, 'log_request_outcome' ), 10, 3 );
    }

    /**
     * Capture request start time so we can compute duration in the post-dispatch
     * filter. Only records for connector routes — ignores all other REST traffic.
     *
     * @param mixed           $result  Pre-dispatch short-circuit (we don't touch it).
     * @param WP_REST_Server  $server  REST server.
     * @param WP_REST_Request $request Incoming request.
     * @return mixed Pass through unchanged.
     */
    public function mark_request_start( $result, $server, $request ) {
        $route = $request instanceof WP_REST_Request ? $request->get_route() : '';
        if ( 0 === strpos( $route, '/' . self::NAMESPACE_PREFIX . '/' . self::ROUTE_BASE ) ) {
            $this->request_started_at_ms = microtime( true ) * 1000;
        }
        return $result;
    }

    /**
     * Log the outcome of a connector request.
     *
     * Called by WP for every REST response — we filter to our namespace before
     * recording. Captures method, route, user_id, response status, and duration.
     *
     * @param WP_REST_Response $response Response object (or WP_Error wrapped).
     * @param WP_REST_Server   $server   REST server.
     * @param WP_REST_Request  $request  Original request.
     * @return WP_REST_Response Pass through unchanged.
     */
    public function log_request_outcome( $response, $server, $request ) {
        if ( ! ( $request instanceof WP_REST_Request ) ) {
            return $response;
        }

        $route = $request->get_route();
        if ( 0 !== strpos( $route, '/' . self::NAMESPACE_PREFIX . '/' . self::ROUTE_BASE ) ) {
            return $response;
        }

        $duration_ms = 0;
        if ( null !== $this->request_started_at_ms ) {
            $duration_ms = (int) round( ( microtime( true ) * 1000 ) - $this->request_started_at_ms );
            $this->request_started_at_ms = null;
        }

        $status = 0;
        if ( $response instanceof WP_REST_Response ) {
            $status = (int) $response->get_status();
        } elseif ( is_wp_error( $response ) ) {
            $data = $response->get_error_data();
            $status = isset( $data['status'] ) ? (int) $data['status'] : 500;
        }

        FRE_Connector_Log::record( array(
            'ts'       => time(),
            'method'   => $request->get_method(),
            'route'    => $route,
            'user_id'  => get_current_user_id(),
            'status'   => $status,
            'duration' => $duration_ms,
        ) );

        return $response;
    }

    /**
     * Register all connector routes.
     *
     * Every route passes through FRE_Connector_Auth::build_callback() for the
     * three-check stack + rate limit. The `args` key leverages WordPress's
     * built-in argument validation where possible.
     */
    public function register_routes() {
        $ns   = self::NAMESPACE_PREFIX;
        $base = self::ROUTE_BASE;

        // 9.1 Preflight.
        register_rest_route(
            $ns,
            "/{$base}/preflight",
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle_preflight' ),
                'permission_callback' => FRE_Connector_Auth::build_callback( 'preflight' ),
            )
        );

        // Schema reference — the canonical markdown rulebook for connector
        // consumers. Returned as raw text/markdown so an MCP client can
        // WebFetch + read it directly. Public route: rules are not sensitive,
        // and the content is identical to what ships in the plugin repository.
        // Gating it behind auth would block the legitimate "read rules before
        // first deploy" flow.
        register_rest_route(
            $ns,
            "/{$base}/schema",
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle_schema_document' ),
                'permission_callback' => '__return_true',
            )
        );

        // 9.2 List forms.
        register_rest_route(
            $ns,
            "/{$base}/forms",
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'handle_list_forms' ),
                    'permission_callback' => FRE_Connector_Auth::build_callback( 'list_forms' ),
                    'args'                => array(
                        'page'       => array(
                            'type'              => 'integer',
                            'default'           => 1,
                            'minimum'           => 1,
                            'sanitize_callback' => 'absint',
                        ),
                        'per_page'   => array(
                            'type'              => 'integer',
                            'default'           => 20,
                            'minimum'           => 1,
                            'maximum'           => 100,
                            'sanitize_callback' => 'absint',
                        ),
                        'managed_by' => array(
                            'type'              => 'string',
                            'enum'              => array( 'admin', 'connector:cowork' ),
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'handle_create_form' ),
                    'permission_callback' => FRE_Connector_Auth::build_callback( 'create_form' ),
                    'args'                => array(
                        'id'              => array( 'type' => 'string', 'required' => true ),
                        'title'           => array( 'type' => 'string' ),
                        'config'          => array( 'type' => 'string', 'required' => true ),
                        'custom_css'      => array( 'type' => 'string' ),
                        'webhook_enabled' => array( 'type' => 'boolean' ),
                        'webhook_url'     => array( 'type' => 'string' ),
                        'webhook_preset'  => array(
                            'type' => 'string',
                            'enum' => array( 'google_sheets', 'zapier', 'make', 'custom' ),
                        ),
                    ),
                ),
            )
        );

        // 9.3, 9.5, 9.6 — single form operations.
        register_rest_route(
            $ns,
            "/{$base}/forms/(?P<form_id>[a-z0-9\-_]+)",
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'handle_get_form' ),
                    'permission_callback' => FRE_Connector_Auth::build_callback( 'get_form' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE, // PATCH, PUT, POST
                    'callback'            => array( $this, 'handle_update_form' ),
                    'permission_callback' => FRE_Connector_Auth::build_callback( 'update_form' ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'handle_delete_form' ),
                    'permission_callback' => FRE_Connector_Auth::build_callback( 'delete_form' ),
                ),
            )
        );

        // 9.9 Test submit.
        register_rest_route(
            $ns,
            "/{$base}/forms/(?P<form_id>[a-z0-9\-_]+)/submit",
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_submit' ),
                // Rate-limit bucket is chosen dynamically by self::handle_submit
                // based on dry_run flag — but we still need a permission callback
                // up front, and we use the stricter of the two buckets to gate
                // initial access. Actual bucket-specific limiting happens inside
                // the handler before any side-effecting work.
                'permission_callback' => FRE_Connector_Auth::build_callback( 'submit_dry_run' ),
            )
        );

        // 9.7 List entries.
        register_rest_route(
            $ns,
            "/{$base}/entries",
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle_list_entries' ),
                'permission_callback' => FRE_Connector_Auth::build_callback( 'list_entries', true ),
                'args'                => array(
                    'form_id'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
                    'status'    => array(
                        'type' => 'string',
                        'enum' => array( 'unread', 'read', 'spam' ),
                    ),
                    'is_spam'   => array( 'type' => 'boolean', 'default' => false ),
                    'date_from' => array( 'type' => 'string' ),
                    'date_to'   => array( 'type' => 'string' ),
                    'page'      => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
                    'per_page'  => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
                ),
            )
        );

        // 9.8 Single entry.
        register_rest_route(
            $ns,
            "/{$base}/entries/(?P<entry_id>\d+)",
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle_get_entry' ),
                'permission_callback' => FRE_Connector_Auth::build_callback( 'get_entry', true ),
            )
        );

        // 9.9 Delete entry.
        register_rest_route(
            $ns,
            "/{$base}/entries/(?P<entry_id>\d+)",
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'handle_delete_entry' ),
                'permission_callback' => FRE_Connector_Auth::build_callback( 'delete_entry', true ),
            )
        );
    }

    // ---------------------------------------------------------------------
    // Handlers
    // ---------------------------------------------------------------------

    /**
     * GET /preflight — report connector state.
     *
     * @param WP_REST_Request $request REST request (unused).
     * @return WP_REST_Response
     */
    public function handle_preflight( $request ) {
        $user = wp_get_current_user();

        // Diagnostic block — designed to make remote troubleshooting easy.
        // Every value here can be inspected without touching the database
        // or admin UI; if a connector is misbehaving the operator can ask
        // Cowork to "run a preflight check" and see most state at once.
        $diagnostics = array(
            'stored_plugin_version' => class_exists( 'FRE_Upgrader' )
                ? FRE_Upgrader::get_stored_version()
                : null,
            'database_health'       => $this->collect_database_health(),
            'recent_calls'          => class_exists( 'FRE_Connector_Log' )
                ? FRE_Connector_Log::get_recent( 5 )
                : array(),
        );

        $schema_reference_url = rest_url( self::NAMESPACE_PREFIX . '/' . self::ROUTE_BASE . '/schema' );
        $rulebook             = self::get_connector_rulebook( $schema_reference_url );

        return $this->success( array(
            'plugin_version'            => defined( 'FRE_VERSION' ) ? FRE_VERSION : null,
            'connector_api_version'     => 'v1',
            'connector_enabled'         => FRE_Connector_Settings::is_enabled(),
            'entry_read_enabled'        => FRE_Connector_Settings::is_entry_read_enabled(),
            'authenticated_as'          => $user ? $user->user_login : null,
            'user_capabilities'         => array(
                FRE_Capabilities::MANAGE_FORMS => current_user_can( FRE_Capabilities::MANAGE_FORMS ),
            ),

            // JSON Schema — the authoritative machine contract used by the
            // server-side validator. Consumers that want strict shape rules
            // can fetch this directly. Kept at the original key for backwards
            // compatibility with existing connector clients.
            'schema_document_url'       => plugins_url( 'docs/form-schema.json', dirname( __DIR__, 2 ) . '/form-runtime-engine.php' ),

            // Human-friendly rulebook — the comprehensive markdown document
            // that explains field types, column layouts, conditional
            // visibility, multi-step forms, settings, and drift patterns.
            // ALWAYS read this before creating or updating forms.
            'schema_reference_url'      => $schema_reference_url,
            'read_first'                 => $rulebook['read_first'],
            'critical_rules'             => $rulebook['critical_rules'],
            'field_hints'                => $rulebook['field_hints'],
            'universal_field_properties' => $rulebook['universal_field_properties'],
            'settings_hints'             => $rulebook['settings_hints'],

            'diagnostics'                => $diagnostics,
        ) );
    }

    /**
     * Build the inline connector rulebook digest returned from /preflight.
     *
     * This is the same pattern Promptless WP's connector uses: consumers that
     * only read the preflight response still get the handful of rules that
     * cause 90% of silent-failure bugs, while the exhaustive rulebook lives
     * at the markdown endpoint referenced by schema_reference_url.
     *
     * KEEP THIS IN SYNC with docs/FRE_KNOWLEDGE_MAP.md. The markdown file is
     * the canonical expandable source — this inline digest is a summary.
     *
     * @param string $schema_reference_url URL of the markdown rulebook endpoint.
     * @return array{read_first:string,critical_rules:array,field_hints:array,universal_field_properties:array,settings_hints:array}
     */
    public static function get_connector_rulebook( $schema_reference_url ) {
        return array(
            'read_first' => sprintf(
                /* translators: %s: URL of the FRE markdown rulebook */
                'This list is a summary. The AUTHORITATIVE and COMPREHENSIVE rulebook is the markdown document at %s. Fetch it (WebFetch the URL) and read it before creating or updating any form. It covers every field type, universal field properties, the column layout system, conditional visibility, multi-step forms, settings (notifications, webhooks, theme_variant), and the drift patterns that cause silent failures.',
                $schema_reference_url
            ),

            'critical_rules' => array(
                'config_is_string' => 'The `config` parameter on formengine_create_form and formengine_update_form must be a JSON STRING, not an object. JSON.stringify your config before passing it in. Passing an object will fail schema validation with "invalid_json".',
                'column_values'    => 'The `column` property on a field accepts only these exact string values: "1/2", "1/3", "2/3", "1/4", "3/4". Not "half", not 0.5, not 50. Any other value will silently render the field at full width.',
                'options_required' => 'Fields of type "select", "radio", and checkbox groups MUST include a non-empty `options` array. Each option may be a string (value and label are the same) or an object {"value": "...", "label": "..."}. A select or radio without options will fail schema validation.',
                'form_id_regex'    => 'Form IDs must match ^[a-z0-9\\-_]+$. Lowercase letters, digits, hyphens, and underscores only. The ID becomes the shortcode attribute and URL path segment.',
                'section_before_reference' => 'To group fields into a section, first define a field of type "section" with a unique key. Then set `"section": "<that-key>"` on each field that belongs to the group. Referencing a section key that was never defined results in orphan fields rendering outside any section.',
                'step_before_reference' => 'For multi-step forms, first define a `steps` array at the top level where each element is {"key":"...","title":"..."}. Then set `"step": "<that-key>"` on every field. Fields without a `step` when `steps` is defined appear on the first step.',
                'theme_variant_for_dark_backgrounds' => 'When embedding a form inside an AISB section with a dark background, set settings.theme_variant = "dark" (or "auto" to inherit from the parent AISB section). Default is "light" and will render poorly on dark backgrounds.',
                'webhook_secret_rotation' => 'Webhook secrets are admin-only. The connector API never exposes or accepts them. Use the Forms Manager admin UI to rotate. The connector can toggle webhook_enabled and set webhook_url, but not the signing secret.',
                'managed_by_immutable' => 'Forms created via this connector are tagged managed_by = "connector:cowork" at create time. This origin tag is IMMUTABLE — PATCH requests cannot change it. Use the managed_by filter on formengine_list_forms to avoid modifying admin-authored forms.',
                'entry_read_gate' => 'formengine_list_entries and formengine_get_entry require the site administrator to have explicitly enabled entry-read access on the Form Entries → Claude Connection admin page. Without it, these endpoints return 403 entry_access_disabled. Preflight reports the current state in `entry_read_enabled`.',
                'test_submit_dry_run' => 'formengine_test_submit writes a real entry and may dispatch webhooks / send notifications by default. Pass options.dry_run = true to validate-only without any side effects. Pass options.skip_notifications = true to write a real entry (e.g. for webhook testing) but suppress the email.',
                'form_surface_options' => 'A form can render as a flat element on its parent section background (default) OR as a card with its own surface, border, and padding. Two routes: (1) FORM-LEVEL — set settings.appearance.surface = "card" to wrap the whole form in a token-aware card; preferred for simple single-surface forms. (2) FIELD-LEVEL — use one or more fields of type "section" to wrap grouped fields in cards; use when a form needs multiple grouped regions (e.g. a "Contact info" card above a "Message" card in the same form). When settings.appearance.surface = "card" is set, inner section cards are flattened automatically to avoid nested-card artifacts. Both routes inherit colors from the parent AISB section\'s theme variant. Vocabulary: "card", "surface", "wrapper", "container around the form" all refer to this feature.',
                'honeypot_field_name_dynamic' => 'The spam-protection honeypot field name is NOT static. When a form is rendered, FRE generates a per-form honeypot name of the form `_fre_website_url_<hmac-suffix>`. Never hard-code that field into a test submission — the server rejects any submission that fills it. formengine_test_submit handles this correctly when called via the connector; the warning is for any direct REST consumers that might imitate form posts.',
                'min_submission_time_enforcement' => 'settings.spam_protection.min_submission_time defaults to 3 seconds. Submissions posted within that window are silently rejected as likely-bot. Relevant when using formengine_test_submit without options.dry_run immediately after creating a form — add a small delay in automated test flows or pass dry_run=true.',
                'aisb_token_inheritance' => 'When AI Section Builder Modern (Promptless WP) is active, FRE forms automatically inherit brand design tokens — primary / text / background / border colors, heading and body fonts, button and card border-radius, neo-brutalist mode if enabled — via CSS custom properties (--aisb-*). Forms inside an .aisb-section--dark ancestor automatically flip to dark mode without needing settings.theme_variant = "dark" (though setting it is still recommended for clarity). See schema_reference_url and docs/AISB_TOKEN_CONTRACT.md for the full token list.',
            ),

            'field_hints' => array(
                'text'     => array(
                    'required_properties' => array( 'key', 'type', 'label' ),
                    'optional_properties' => array( 'placeholder', 'required', 'maxlength', 'minlength', 'pattern', 'default', 'description', 'readonly', 'disabled', 'autocomplete', 'css_class' ),
                    'notes'               => 'Standard text input. Labels are required for accessibility. `pattern` accepts a regex string for client+server validation.',
                ),
                'email'    => array(
                    'required_properties' => array( 'key', 'type', 'label' ),
                    'optional_properties' => array( 'placeholder', 'required', 'default', 'description' ),
                    'notes'               => 'Validated client- and server-side. Prefer key "email" for cross-form automation reuse.',
                ),
                'tel'      => array(
                    'required_properties' => array( 'key', 'type', 'label' ),
                    'optional_properties' => array( 'placeholder', 'required', 'pattern', 'description' ),
                    'notes'               => 'Phone number input. Prefer key "phone". If `pattern` is not provided, FRE auto-applies "[0-9+\\-\\s\\(\\)]+" so typical US / international formats validate out of the box. Pass an explicit `pattern` to tighten or relax this.',
                ),
                'textarea' => array(
                    'required_properties' => array( 'key', 'type', 'label' ),
                    'optional_properties' => array( 'placeholder', 'required', 'rows', 'cols', 'maxlength', 'minlength', 'description' ),
                    'notes'               => 'Multi-line text. Default rows = 5.',
                ),
                'select'   => array(
                    'required_properties' => array( 'key', 'type', 'label', 'options' ),
                    'optional_properties' => array( 'placeholder', 'required', 'multiple', 'default', 'description' ),
                    'notes'               => 'Dropdown. `options` must be non-empty. Options may be strings or {value,label} objects. Placeholder renders as the empty first option.',
                ),
                'radio'    => array(
                    'required_properties' => array( 'key', 'type', 'label', 'options' ),
                    'optional_properties' => array( 'required', 'inline', 'default', 'description' ),
                    'notes'               => 'Radio button group. `options` must be non-empty. Use inline=true for horizontal layout.',
                ),
                'checkbox' => array(
                    'required_properties' => array( 'key', 'type', 'label' ),
                    'optional_properties' => array( 'options', 'required', 'inline', 'description' ),
                    'notes'               => 'Single checkbox WITHOUT options is a yes/no toggle (stores "1" when checked). WITH options it becomes a multi-select checkbox group.',
                ),
                'file'     => array(
                    'required_properties' => array( 'key', 'type', 'label' ),
                    'optional_properties' => array( 'required', 'multiple', 'allowed_types', 'max_size', 'description' ),
                    'notes'               => 'File upload. Default allowed_types: pdf,jpg,jpeg,png,gif,doc,docx. Default max_size: 5242880 (5MB in bytes). allowed_types is an array of lowercase extensions without the dot.',
                ),
                'hidden'   => array(
                    'required_properties' => array( 'key', 'type' ),
                    'optional_properties' => array( 'default' ),
                    'notes'               => 'Not rendered to the user. Value taken from `default`.',
                ),
                'message'  => array(
                    'required_properties' => array( 'key', 'type' ),
                    'optional_properties' => array( 'label', 'content', 'style' ),
                    'notes'               => 'Display-only (not submitted). At minimum supply either `label` or `content` (or both) — an empty message field renders nothing. style: "info" (default), "warning", "success", "error". `content` is sanitized via wp_kses_post().',
                ),
                'section'  => array(
                    'required_properties' => array( 'key', 'type' ),
                    'optional_properties' => array( 'label', 'description', 'css_class' ),
                    'notes'               => 'VISUAL container AND structural group. Renders as a card with surface background, border, and padding (inherits AISB design tokens). Reference it from other fields via their `section` property (see critical_rules.section_before_reference). Use the section field when a form needs multiple grouped regions; use settings.appearance.surface = "card" instead when the whole form should be one card (see critical_rules.form_surface_options).',
                ),
                'date'     => array(
                    'required_properties' => array( 'key', 'type', 'label' ),
                    'optional_properties' => array( 'required', 'min', 'max', 'default', 'description' ),
                    'notes'               => 'Native HTML5 date picker. min/max/default are YYYY-MM-DD strings. Validation enforced client- and server-side.',
                ),
                'address'  => array(
                    'required_properties' => array( 'key', 'type', 'label' ),
                    'optional_properties' => array( 'required', 'placeholder', 'country_restriction', 'description' ),
                    'notes'               => 'Google Places autocomplete. REQUIRES the admin to configure a Google Places API key in Form Entries → Settings. Auto-stores parsed components in hidden fields suffixed _street_number, _route, _locality, _administrative_area_level_1, _postal_code, _country, _formatted_address, _lat, _lng. country_restriction is an array of ISO 3166-1 alpha-2 codes.',
                ),
            ),

            'universal_field_properties' => array(
                'identity'    => array( 'key', 'type', 'label', 'placeholder', 'required' ),
                'layout'      => array( 'column', 'section', 'step', 'css_class' ),
                'behavior'    => array( 'default', 'description', 'conditions', 'readonly', 'disabled' ),
                'constraints' => array( 'maxlength', 'minlength', 'min', 'max' ),
                'notes'       => 'column accepts strings "1/2"|"1/3"|"2/3"|"1/4"|"3/4". conditions is an object {rules:[{field,operator,value}], logic:"and"|"or"}. See schema_reference_url for operator list.',
            ),

            'settings_hints' => array(
                'theme_variant'     => 'Form styling mode: "light" (default) | "dark" | "auto" (inherits from parent AISB section). Set "dark" when embedding in a dark section. Forms inside an .aisb-section--dark ancestor auto-inherit dark mode even without this flag, but setting it explicitly is recommended.',
                'appearance'        => 'settings.appearance.surface: "none" (default — fields sit on the parent section background) | "card" (form renders as a token-aware card with surface background, border, radius, and padding). Works for every form type. Synonyms users reach for: "card", "surface", "wrapper", "container". See critical_rules.form_surface_options for the full explanation of this versus using the section field type.',
                'webhook'           => 'settings.webhook_enabled + settings.webhook_url enable external dispatch. Use HTTPS endpoints. The signing secret is admin-only and NOT exposed via this API. settings.webhook_preset = "google_sheets" | "zapier" | "make" | "custom" — drives the smart default for option-label resolution: google_sheets resolves select/radio/checkbox option values to their human-readable labels in the payload, while zapier/make/custom emit raw values (typically machine-readable integrations prefer stable identifiers). settings.webhook_resolve_option_labels (boolean, optional) explicitly overrides the preset default — true forces labels regardless, false forces raw values regardless. Webhook payload includes a files array; each file row has field_key, file_name, file_size, mime_type, and file_url for downstream automations to fetch (Zapier → Drive, Make → S3, etc.). Webhook fires on the fre_submission_complete action — AFTER files are attached — so file_url is always populated.',
                'notifications'     => 'settings.notification is an object {enabled, to, subject, from_name, from_email, reply_to}. Defaults: enabled=true, to={admin_email}, subject="New Form Submission", from_name={site_name}, from_email={admin_email}. Template variables available anywhere in these strings: {admin_email}, {site_name}, {site_url}, {form_title}, {field:key} (substitutes the submitted value of the field whose key is "key" — option values are resolved to their labels). reply_to defaults to {field:email} when an email field exists. settings.hide_empty_fields (boolean, default true) controls whether empty optional fields are skipped in the email body — set false to render every field with an em-dash placeholder for empty values, useful when emails feed downstream tooling that expects a fixed table shape. Conditionally-hidden fields (those with a conditions block that evaluates false) are ALWAYS skipped regardless of this flag.',
                'spam_protection'   => 'settings.spam_protection.honeypot and .timing_check default to true. settings.spam_protection.min_submission_time defaults to 3 seconds — submissions faster than that are silently rejected. Rate limit default: 5 submissions per 3600 seconds (1 hour) per IP. The honeypot field name is dynamically generated per form (see critical_rules.honeypot_field_name_dynamic).',
                'multistep'         => 'settings.multistep.progress_style: "steps" (default) | "bar" | "dots". settings.multistep.show_progress (default true) and .show_step_titles (default false) and .validate_on_next (default true) are also available. Only meaningful when a `steps` array is defined.',
                'success_behavior'  => 'Set settings.redirect_url for post-submit redirect, or settings.success_message for inline confirmation (default: "Thank you for your submission.").',
                'presentation_flags' => 'settings.show_title (default false) — show form title above the form. settings.css_class — custom CSS class on the <form> element, applied alongside the built-in fre-form--* modifiers.',
            ),
        );
    }

    /**
     * GET /schema — serve the markdown rulebook as raw text/markdown.
     *
     * Bypasses WordPress's REST JSON serialization so clients that WebFetch
     * this endpoint receive raw markdown, not a JSON-quoted string. Returning
     * a WP_REST_Response here would cause WordPress to json_encode() the body,
     * which breaks the advertised text/markdown Content-Type.
     *
     * Exits after writing the body — nothing else should run on this request.
     *
     * @param WP_REST_Request $request REST request (unused).
     * @return void|WP_REST_Response Returns a 404 response only on failure.
     */
    public function handle_schema_document( $request ) {
        // Candidate paths, in priority order. The comprehensive FRE knowledge
        // map is the canonical rulebook; the JSON Schema is a fallback for
        // strict-shape use cases but doesn't cover the drift patterns or
        // design-intent rules that consumers need to avoid silent failures.
        $candidates = array();
        if ( defined( 'FRE_PLUGIN_DIR' ) ) {
            $candidates[] = FRE_PLUGIN_DIR . 'docs/FRE_KNOWLEDGE_MAP.md';
        }
        // Relative fallback for unusual installs where FRE_PLUGIN_DIR isn't set.
        $candidates[] = dirname( __DIR__, 2 ) . '/docs/FRE_KNOWLEDGE_MAP.md';

        foreach ( $candidates as $path ) {
            if ( file_exists( $path ) && is_readable( $path ) ) {
                $content = file_get_contents( $path );
                if ( false !== $content ) {
                    nocache_headers();
                    status_header( 200 );
                    header( 'Content-Type: text/markdown; charset=utf-8' );
                    header( 'X-Content-Type-Options: nosniff' );
                    echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown file content served raw by design.
                    exit;
                }
            }
        }

        return new WP_Error(
            'schema_document_not_found',
            __( 'The schema document could not be located on the server.', 'form-runtime-engine' ),
            array( 'status' => 404 )
        );
    }

    /**
     * Collect database health into a small structured payload.
     *
     * Wraps FRE_Migrator's check_database_health into a shape suitable for
     * REST output. Returns either ['ok' => true] when all required tables
     * exist, or ['ok' => false, 'missing_tables' => [...]] when one or more
     * are absent — which usually indicates an incomplete activation.
     *
     * @return array
     */
    private function collect_database_health() {
        if ( ! class_exists( 'FRE_Migrator' ) ) {
            return array( 'ok' => null, 'reason' => 'migrator_class_missing' );
        }

        $migrator = new FRE_Migrator();
        $health   = $migrator->check_database_health();

        if ( true === $health ) {
            return array( 'ok' => true );
        }

        return array(
            'ok'             => false,
            'missing_tables' => is_array( $health ) ? $health : array(),
        );
    }

    /**
     * GET /forms — list forms with pagination and optional managed_by filter.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function handle_list_forms( $request ) {
        $page       = (int) $request->get_param( 'page' );
        $per_page   = (int) $request->get_param( 'per_page' );
        $managed_by = $request->get_param( 'managed_by' );

        $all = FRE_Forms_Repository::get_all();

        // Optional managed_by filter.
        if ( $managed_by ) {
            $all = array_filter(
                $all,
                function ( $form ) use ( $managed_by ) {
                    return isset( $form['managed_by'] ) && $form['managed_by'] === $managed_by;
                }
            );
        }

        $total   = count( $all );
        $offset  = ( $page - 1 ) * $per_page;
        $slice   = array_slice( array_values( $all ), $offset, $per_page );
        $records = array_map( array( $this, 'build_form_response' ), $slice );

        return $this->collection( $records, array(
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'has_more' => ( $offset + $per_page ) < $total,
        ) );
    }

    /**
     * GET /forms/{form_id} — single form.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_get_form( $request ) {
        $form_id = $request->get_param( 'form_id' );
        $record  = FRE_Forms_Repository::get( $form_id );

        if ( null === $record ) {
            return $this->not_found_form( $form_id );
        }

        return $this->success( $this->build_form_response( $record ) );
    }

    /**
     * POST /forms — create a new form. Origin tagged as connector:cowork.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_create_form( $request ) {
        $form_id = sanitize_key( (string) $request->get_param( 'id' ) );

        if ( FRE_Forms_Repository::exists( $form_id ) ) {
            return new WP_Error(
                'form_exists',
                sprintf(
                    /* translators: %s: form ID */
                    __( 'A form with ID "%s" already exists. Use PATCH to update it.', 'form-runtime-engine' ),
                    $form_id
                ),
                array( 'status' => 409 )
            );
        }

        $input = $this->extract_save_input( $request );
        // The API layer stamps managed_by; callers can't override it. Forms
        // created through this endpoint are always owned by the connector.
        $input['managed_by'] = 'connector:cowork';

        $result = FRE_Forms_Repository::save( $form_id, $input );
        if ( is_wp_error( $result ) ) {
            return $this->enrich_save_error( $result );
        }

        $response = rest_ensure_response( array(
            'success' => true,
            'data'    => $this->build_form_response( $result ),
        ) );
        $response->set_status( 201 );
        return $response;
    }

    /**
     * PATCH /forms/{form_id} — update an existing form.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_update_form( $request ) {
        $form_id  = (string) $request->get_param( 'form_id' );
        $existing = FRE_Forms_Repository::get( $form_id );

        if ( null === $existing ) {
            return $this->not_found_form( $form_id );
        }

        // Build a save input that merges caller-supplied fields on top of the
        // existing record. The repository's save() already preserves fields it
        // doesn't see, but being explicit here protects against partial-body
        // requests that would otherwise strip custom_css etc.
        $input = $this->extract_save_input( $request );

        // If caller didn't supply config, keep existing config.
        if ( empty( $input['config'] ) ) {
            $input['config'] = $existing['config'];
        }

        // Caller cannot change managed_by via PATCH — origin is immutable.
        // The repository preserves the existing value when we omit it.

        $result = FRE_Forms_Repository::save( $form_id, $input );
        if ( is_wp_error( $result ) ) {
            return $this->enrich_save_error( $result );
        }

        return $this->success( $this->build_form_response( $result ) );
    }

    /**
     * DELETE /forms/{form_id} — remove a form, preserve entries.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_delete_form( $request ) {
        $form_id = (string) $request->get_param( 'form_id' );

        if ( ! FRE_Forms_Repository::exists( $form_id ) ) {
            return $this->not_found_form( $form_id );
        }

        // Count before delete so we can report preserved entries accurately.
        $preserved_count = FRE_Forms_Repository::count_entries( $form_id );

        $deleted = FRE_Forms_Repository::delete( $form_id );
        if ( ! $deleted ) {
            return new WP_Error(
                'delete_failed',
                __( 'Form deletion failed.', 'form-runtime-engine' ),
                array( 'status' => 500 )
            );
        }

        return $this->success( array(
            'form_id'           => $form_id,
            'entries_preserved' => $preserved_count,
            'message'           => sprintf(
                /* translators: %d: entry count */
                _n(
                    'Form deleted. %d associated entry has been preserved and remains accessible in the admin Entries view.',
                    'Form deleted. %d associated entries have been preserved and remain accessible in the admin Entries view.',
                    $preserved_count,
                    'form-runtime-engine'
                ),
                $preserved_count
            ),
        ) );
    }

    /**
     * POST /forms/{form_id}/submit — programmatic submit for testing.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_submit( $request ) {
        $form_id     = (string) $request->get_param( 'form_id' );
        $body        = $request->get_json_params();
        $body        = is_array( $body ) ? $body : array();
        $data        = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
        $options_raw = isset( $body['options'] ) && is_array( $body['options'] ) ? $body['options'] : array();

        $options = array(
            'dry_run'            => ! empty( $options_raw['dry_run'] ),
            'skip_notifications' => ! empty( $options_raw['skip_notifications'] ),
            'source'             => 'connector',
        );

        // The initial permission_callback used the stricter bucket; when this
        // is a live (non-dry-run) submit, re-check the stricter live limit
        // explicitly. Dry-runs were already gated by the submit_dry_run
        // bucket, which is less strict.
        if ( ! $options['dry_run'] ) {
            $rate_check = FRE_Connector_Auth::enforce_rate_limit( 'submit_live', get_current_user_id() );
            if ( is_wp_error( $rate_check ) ) {
                return $rate_check;
            }
        }

        $handler = new FRE_Submission_Handler();
        $result  = $handler->process_submission( $form_id, $data, $options );

        if ( is_wp_error( $result ) ) {
            // Field-validation errors should surface as 400, not 500.
            $code = $result->get_error_code();
            $data = $result->get_error_data();
            if ( 'form_not_found' === $code ) {
                $result->add_data( array( 'status' => 404 ) );
            } elseif ( 'invalid_form_id' === $code ) {
                $result->add_data( array( 'status' => 400 ) );
            } elseif ( ! isset( $data['status'] ) ) {
                // Validator returns a generic code; assume 400 unless otherwise set.
                $result->add_data( array( 'status' => 400 ) );
            }
            return $result;
        }

        return $this->success( $result );
    }

    /**
     * GET /entries — list with filtering and pagination.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function handle_list_entries( $request ) {
        $query = new FRE_Entry_Query();

        if ( $form_id = $request->get_param( 'form_id' ) ) {
            $query->form( $form_id );
        }
        if ( $status = $request->get_param( 'status' ) ) {
            $query->status( $status );
        }
        if ( null !== $request->get_param( 'is_spam' ) ) {
            $query->spam( (bool) $request->get_param( 'is_spam' ) );
        }
        if ( ( $from = $request->get_param( 'date_from' ) ) && ( $to = $request->get_param( 'date_to' ) ) ) {
            $query->date_range( $from, $to );
        }

        $page     = (int) $request->get_param( 'page' );
        $per_page = (int) $request->get_param( 'per_page' );

        // Count before applying pagination so meta.total is accurate.
        $total = $query->count();

        $entries = $query
            ->order_by( 'created_at', 'DESC' )
            ->page( $page, $per_page )
            ->get( true );

        $records = array_map( array( $this, 'build_entry_response' ), $entries );

        return $this->collection( $records, array(
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per_page,
            'has_more' => ( ( $page * $per_page ) < $total ),
        ) );
    }

    /**
     * GET /entries/{entry_id} — single entry.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_get_entry( $request ) {
        $entry_id = (int) $request->get_param( 'entry_id' );
        $repo     = new FRE_Entry();
        $record   = $repo->get( $entry_id );

        if ( null === $record ) {
            return new WP_Error(
                'entry_not_found',
                sprintf(
                    /* translators: %d: entry ID */
                    __( 'Entry %d not found.', 'form-runtime-engine' ),
                    $entry_id
                ),
                array( 'status' => 404 )
            );
        }

        return $this->success( $this->build_entry_response( $record ) );
    }

    /**
     * DELETE /entries/{entry_id} — delete an entry and its associated files.
     *
     * Performs a full cascade delete: uploaded files on disk, file records,
     * entry metadata, SMS messages (if Twilio module active), and the entry
     * row itself. This is the same cleanup path used by the admin "Delete
     * Entry" button.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_delete_entry( $request ) {
        $entry_id = (int) $request->get_param( 'entry_id' );
        $repo     = new FRE_Entry();
        $record   = $repo->get( $entry_id );

        if ( null === $record ) {
            return new WP_Error(
                'entry_not_found',
                sprintf(
                    /* translators: %d: entry ID */
                    __( 'Entry %d not found.', 'form-runtime-engine' ),
                    $entry_id
                ),
                array( 'status' => 404 )
            );
        }

        $deleted = $repo->delete( $entry_id );

        if ( ! $deleted ) {
            return new WP_Error(
                'delete_failed',
                sprintf(
                    /* translators: %d: entry ID */
                    __( 'Failed to delete entry %d.', 'form-runtime-engine' ),
                    $entry_id
                ),
                array( 'status' => 500 )
            );
        }

        // Clean up SMS messages if the Twilio messages table exists.
        global $wpdb;
        $messages_table = $wpdb->prefix . 'fre_twilio_messages';
        $table_exists   = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $messages_table )
        );
        if ( $table_exists ) {
            $wpdb->delete( $messages_table, array( 'entry_id' => $entry_id ), array( '%d' ) );
        }

        return $this->success( array(
            'entry_id' => $entry_id,
            'form_id'  => $record['form_id'] ?? '',
            'message'  => sprintf(
                /* translators: %d: entry ID */
                __( 'Entry %d and all associated files have been deleted.', 'form-runtime-engine' ),
                $entry_id
            ),
        ) );
    }

    // ---------------------------------------------------------------------
    // Response shaping
    // ---------------------------------------------------------------------

    /**
     * Shape a form record for API output.
     *
     * Notably: omits webhook_secret (write-only through the API), adds the
     * convenience `shortcode` field.
     *
     * @param array $record Form record from the repository.
     * @return array
     */
    private function build_form_response( array $record ) {
        return array(
            'id'                => $record['id'] ?? '',
            'title'             => $record['title'] ?? '',
            'config'            => $record['config'] ?? '',
            'custom_css'        => $record['custom_css'] ?? '',
            'webhook_enabled'   => (bool) ( $record['webhook_enabled'] ?? false ),
            'webhook_url'       => $record['webhook_url'] ?? '',
            'webhook_preset'    => $record['webhook_preset'] ?? 'custom',
            'managed_by'        => $record['managed_by'] ?? 'admin',
            'connector_version' => (int) ( $record['connector_version'] ?? 0 ),
            'created'           => (int) ( $record['created'] ?? 0 ),
            'modified'          => (int) ( $record['modified'] ?? 0 ),
            'shortcode'         => sprintf( '[fre_form id="%s"]', $record['id'] ?? '' ),
        );
    }

    /**
     * Shape an entry record for API output.
     *
     * Hoists the internal `_fre_form_version` meta into a top-level
     * `form_version` field so consumers can correlate entries with form
     * versions for A/B analysis.
     *
     * @param array $record Entry record from FRE_Entry::get or FRE_Entry_Query.
     * @return array
     */
    private function build_entry_response( array $record ) {
        $fields       = isset( $record['fields'] ) && is_array( $record['fields'] ) ? $record['fields'] : array();
        $form_version = isset( $fields['_fre_form_version'] ) ? (int) $fields['_fre_form_version'] : 0;

        // Strip the internal meta key from the public fields payload.
        unset( $fields['_fre_form_version'] );

        $response = array(
            'id'           => isset( $record['id'] ) ? (int) $record['id'] : 0,
            'form_id'      => $record['form_id'] ?? '',
            'status'       => $record['status'] ?? 'unread',
            'is_spam'      => (bool) ( $record['is_spam'] ?? false ),
            'created_at'   => $record['created_at'] ?? null,
            'updated_at'   => $record['updated_at'] ?? null,
            'user_id'      => isset( $record['user_id'] ) ? (int) $record['user_id'] : null,
            'ip_address'   => $record['ip_address'] ?? '',
            'user_agent'   => $record['user_agent'] ?? '',
            'fields'       => $fields,
            'form_version' => $form_version,
            'files'        => isset( $record['files'] ) && is_array( $record['files'] ) ? $record['files'] : array(),
        );

        // Attach SMS conversation thread when the Twilio messages table exists.
        $entry_id = $response['id'];
        if ( $entry_id > 0 ) {
            $messages = $this->get_sms_messages( $entry_id );
            if ( ! empty( $messages ) ) {
                $response['messages'] = $messages;
            }
        }

        return $response;
    }

    /**
     * Fetch SMS conversation messages for an entry.
     *
     * Returns an empty array when the Twilio module is inactive or the
     * messages table does not exist — callers never need to guard.
     *
     * @param int $entry_id Entry ID.
     * @return array Array of message objects (direction, body, status, created_at).
     */
    private function get_sms_messages( $entry_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'fre_twilio_messages';

        // Bail silently if the Twilio module's table doesn't exist.
        $table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );

        if ( ! $table_exists ) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT direction, body, status, created_at FROM {$table} WHERE entry_id = %d ORDER BY created_at ASC",
                $entry_id
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Extract the repository-save input from a REST request.
     *
     * Translates request params into the array shape FRE_Forms_Repository::save
     * expects. Only copies keys the caller actually supplied; everything else
     * is left out so the repository's own defaulting kicks in.
     *
     * @param WP_REST_Request $request REST request.
     * @return array
     */
    private function extract_save_input( $request ) {
        $input = array();
        foreach ( array( 'title', 'config', 'custom_css', 'webhook_enabled', 'webhook_url', 'webhook_preset' ) as $key ) {
            $value = $request->get_param( $key );
            if ( null !== $value ) {
                $input[ $key ] = $value;
            }
        }
        return $input;
    }

    /**
     * Ensure a save-path WP_Error has an HTTP status AND an actionable hint.
     *
     * The repository returns codes like `empty_id`, `invalid_id`, `empty_config`,
     * `invalid_json`, `schema_error` — all of which should surface as 400s. For
     * each known code, attach a `hint` field to the error data describing how
     * the consumer can correct the shape. A Cowork session reading the 400
     * response can then act on the hint without having to fetch external
     * documentation mid-flow.
     *
     * See Promptless WP's `invalid_pricing_features_shape` error pattern for
     * the equivalent convention on that connector.
     *
     * @param WP_Error $error Repository error.
     * @return WP_Error
     */
    private function enrich_save_error( WP_Error $error ) {
        $data = $error->get_error_data();
        $code = $error->get_error_code();

        // Every save-path error should surface as a 400 unless the repository
        // explicitly set a different status.
        if ( ! isset( $data['status'] ) ) {
            $error->add_data( array( 'status' => 400 ) );
        }

        // Attach a corrective hint per known code. Cowork sessions read these
        // to reshape the payload without a round-trip to the markdown rulebook.
        $hints = array(
            'empty_id'      => 'Form ID is required. Provide a non-empty `id` parameter matching ^[a-z0-9\\-_]+$ (lowercase alphanumerics, hyphens, underscores).',
            'invalid_id'    => 'Form ID must match ^[a-z0-9\\-_]+$. Lowercase letters, digits, hyphens, and underscores only. Examples: "contact", "book-a-demo", "newsletter_signup".',
            'empty_config'  => 'The `config` parameter is required and must be a non-empty JSON string describing the form. At minimum: {"fields": [{"key": "email", "type": "email", "label": "Email"}]}.',
            'invalid_json'  => 'The `config` parameter must be a valid JSON STRING, not a JavaScript/Python object. Call JSON.stringify(yourConfigObject) before passing it in. The top-level shape is {"title": ..., "fields": [...], "settings": {...}}.',
            'schema_error'  => 'The form config failed JSON Schema validation. Check that every field has a `key` and `type`; select/radio fields have a non-empty `options` array; column values are one of "1/2"/"1/3"/"2/3"/"1/4"/"3/4"; and the `settings` object only uses known keys. Call formengine_preflight and WebFetch schema_reference_url for the complete rulebook.',
            'delete_failed' => 'Form deletion failed server-side. The form may be protected by a filter or another plugin. Try again or remove via the WordPress admin Forms Manager.',
        );

        if ( isset( $hints[ $code ] ) ) {
            // Preserve any existing data — in particular the `status` we just
            // set above and any field-path data the validator attached — and
            // add the hint alongside it.
            $current = $error->get_error_data();
            $current = is_array( $current ) ? $current : array();
            $current['hint'] = $hints[ $code ];
            $error->add_data( $current );
        }

        return $error;
    }

    /**
     * Standard "form not found" response.
     *
     * @param string $form_id Requested form ID.
     * @return WP_Error
     */
    private function not_found_form( $form_id ) {
        return new WP_Error(
            'form_not_found',
            sprintf(
                /* translators: %s: form ID */
                __( 'Form "%s" not found.', 'form-runtime-engine' ),
                $form_id
            ),
            array( 'status' => 404 )
        );
    }

    /**
     * Wrap a resource in the standard success envelope.
     *
     * @param mixed $data Response body.
     * @return WP_REST_Response
     */
    private function success( $data ) {
        return rest_ensure_response( array(
            'success' => true,
            'data'    => $data,
        ) );
    }

    /**
     * Wrap a collection in the standard success envelope with pagination meta.
     *
     * @param array $items Collection items.
     * @param array $meta  Pagination metadata.
     * @return WP_REST_Response
     */
    private function collection( array $items, array $meta ) {
        return rest_ensure_response( array(
            'success' => true,
            'data'    => $items,
            'meta'    => $meta,
        ) );
    }
}
