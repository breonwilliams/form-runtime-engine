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

        return $this->success( array(
            'plugin_version'        => defined( 'FRE_VERSION' ) ? FRE_VERSION : null,
            'connector_api_version' => 'v1',
            'connector_enabled'     => FRE_Connector_Settings::is_enabled(),
            'entry_read_enabled'    => FRE_Connector_Settings::is_entry_read_enabled(),
            'authenticated_as'      => $user ? $user->user_login : null,
            'user_capabilities'     => array(
                FRE_Capabilities::MANAGE_FORMS => current_user_can( FRE_Capabilities::MANAGE_FORMS ),
            ),
            'schema_document_url'   => plugins_url( 'docs/form-schema.json', dirname( __DIR__, 2 ) . '/form-runtime-engine.php' ),
            'diagnostics'           => $diagnostics,
        ) );
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

        return array(
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
     * Ensure a save-path WP_Error has an HTTP status attached.
     *
     * The repository returns codes like `empty_id`, `invalid_id`, `empty_config`,
     * `invalid_json`, `schema_error` — all of which should surface as 400s.
     *
     * @param WP_Error $error Repository error.
     * @return WP_Error
     */
    private function enrich_save_error( WP_Error $error ) {
        $data = $error->get_error_data();
        if ( ! isset( $data['status'] ) ) {
            $error->add_data( array( 'status' => 400 ) );
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
