<?php
/**
 * GitHub Updater for Form Runtime Engine.
 *
 * Checks GitHub releases for plugin updates and integrates with
 * WordPress's built-in update system.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub Updater class.
 *
 * Handles checking for updates from GitHub releases and providing
 * update information to WordPress.
 */
class FRE_GitHub_Updater {

    /**
     * Plugin slug.
     *
     * @var string
     */
    private $slug = 'form-runtime-engine';

    /**
     * Plugin file path relative to plugins directory.
     *
     * @var string
     */
    private $plugin_file = 'form-runtime-engine/form-runtime-engine.php';

    /**
     * GitHub repository (username/repo).
     *
     * @var string
     */
    private $github_repo = 'breonwilliams/form-runtime-engine';

    /**
     * Current installed version.
     *
     * @var string
     */
    private $version;

    /**
     * Transient cache key.
     *
     * @var string
     */
    private $cache_key = 'fre_github_update_check';

    /**
     * Cache expiry time in seconds (12 hours).
     *
     * @var int
     */
    private $cache_expiry = 43200;

    /**
     * GitHub API response data.
     *
     * @var object|null
     */
    private $github_response = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->version = FRE_VERSION;
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks.
     */
    private function init_hooks() {
        // Check for updates when WordPress checks for plugin updates.
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

        // Provide plugin info for the "View Details" popup.
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

        // Clear cache after plugin update.
        add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );

        // Add custom message on plugins page.
        add_action( 'in_plugin_update_message-' . $this->plugin_file, array( $this, 'update_message' ), 10, 2 );
    }

    /**
     * Check GitHub for the latest release.
     *
     * @param object $transient WordPress update transient.
     * @return object Modified transient with update info.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        // Get release info from GitHub.
        $release = $this->get_github_release();

        if ( ! $release ) {
            return $transient;
        }

        // Compare versions.
        $latest_version = ltrim( $release->tag_name, 'v' );

        if ( version_compare( $this->version, $latest_version, '<' ) ) {
            $update = (object) array(
                'slug'        => $this->slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $latest_version,
                'url'         => $release->html_url,
                'package'     => $this->get_download_url( $release ),
                'icons'       => array(),
                'banners'     => array(),
                'tested'      => '',
                'requires'    => '5.0',
                'requires_php'=> '7.4',
            );

            $transient->response[ $this->plugin_file ] = $update;
        } else {
            // No update available - add to no_update array.
            $transient->no_update[ $this->plugin_file ] = (object) array(
                'slug'        => $this->slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $this->version,
                'url'         => 'https://github.com/' . $this->github_repo,
                'package'     => '',
            );
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View Details" popup.
     *
     * @param false|object|array $result The result object or array.
     * @param string             $action The type of information being requested.
     * @param object             $args   Plugin API arguments.
     * @return false|object Plugin info or false.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( $this->slug !== $args->slug ) {
            return $result;
        }

        $release = $this->get_github_release();

        if ( ! $release ) {
            return $result;
        }

        $latest_version = ltrim( $release->tag_name, 'v' );

        // Build plugin info object.
        $plugin_info = (object) array(
            'name'              => 'Form Runtime Engine',
            'slug'              => $this->slug,
            'version'           => $latest_version,
            'author'            => '<a href="https://github.com/breonwilliams">Breon Williams</a>',
            'author_profile'    => 'https://github.com/breonwilliams',
            'homepage'          => 'https://github.com/' . $this->github_repo,
            'requires'          => '5.0',
            'tested'            => get_bloginfo( 'version' ),
            'requires_php'      => '7.4',
            'downloaded'        => 0,
            'last_updated'      => $release->published_at,
            'sections'          => array(
                'description'  => $this->get_plugin_description(),
                'changelog'    => $this->format_changelog( $release->body ),
                'installation' => $this->get_installation_instructions(),
            ),
            'download_link'     => $this->get_download_url( $release ),
            'banners'           => array(),
            'icons'             => array(),
        );

        return $plugin_info;
    }

    /**
     * Get the latest release from GitHub API.
     *
     * @return object|null Release data or null on failure.
     */
    private function get_github_release() {
        // Check cache first.
        $cached = get_transient( $this->cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        // Fetch from GitHub API.
        $url = sprintf(
            'https://api.github.com/repos/%s/releases/latest',
            $this->github_repo
        );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            // Log the error but don't cache it - allow retry on next check.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'FRE GitHub Updater: API request failed - ' . $response->get_error_message() );
            }
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            // Handle 404 (no releases) or other errors.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'FRE GitHub Updater: API returned status ' . $code );
            }
            // Cache a failure for a shorter time (1 hour) to avoid hammering API.
            set_transient( $this->cache_key, null, HOUR_IN_SECONDS );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body );

        if ( ! $data || ! isset( $data->tag_name ) ) {
            return null;
        }

        // Cache the successful response.
        set_transient( $this->cache_key, $data, $this->cache_expiry );

        return $data;
    }

    /**
     * Get the download URL from a release.
     *
     * Prefers a ZIP asset attached to the release, falls back to
     * the auto-generated zipball.
     *
     * @param object $release GitHub release data.
     * @return string Download URL.
     */
    private function get_download_url( $release ) {
        // Look for a specifically named ZIP file in release assets.
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( preg_match( '/\.zip$/i', $asset->name ) ) {
                    return $asset->browser_download_url;
                }
            }
        }

        // Fall back to GitHub's auto-generated zipball.
        return $release->zipball_url;
    }

    /**
     * Format the changelog from release body (markdown).
     *
     * @param string $body Release body in markdown.
     * @return string HTML formatted changelog.
     */
    private function format_changelog( $body ) {
        if ( empty( $body ) ) {
            return '<p>No changelog available for this release.</p>';
        }

        // Convert markdown to HTML (basic conversion).
        $html = esc_html( $body );

        // Convert headers.
        $html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
        $html = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );
        $html = preg_replace( '/^# (.+)$/m', '<h2>$1</h2>', $html );

        // Convert bold and italic.
        $html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
        $html = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $html );

        // Convert bullet points.
        $html = preg_replace( '/^[\-\*] (.+)$/m', '<li>$1</li>', $html );
        $html = preg_replace( '/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html );

        // Convert line breaks.
        $html = nl2br( $html );

        return $html;
    }

    /**
     * Get plugin description.
     *
     * @return string HTML description.
     */
    private function get_plugin_description() {
        return '<p>Form Runtime Engine is a lightweight WordPress form runtime engine that processes form submissions via configuration arrays.</p>'
            . '<h4>Features</h4>'
            . '<ul>'
            . '<li>Register forms via PHP arrays or JSON configuration</li>'
            . '<li>Multi-step forms with progress indicators</li>'
            . '<li>Conditional field logic</li>'
            . '<li>Column layouts and field sections</li>'
            . '<li>File uploads with security validation</li>'
            . '<li>Email notifications with templating</li>'
            . '<li>Webhook integration (Zapier, Make, etc.)</li>'
            . '<li>Spam protection (honeypot, timing check, rate limiting)</li>'
            . '<li>Design system integration with AI Section Builder</li>'
            . '</ul>';
    }

    /**
     * Get installation instructions.
     *
     * @return string HTML installation instructions.
     */
    private function get_installation_instructions() {
        return '<ol>'
            . '<li>Download the plugin ZIP file</li>'
            . '<li>Go to WordPress Admin → Plugins → Add New</li>'
            . '<li>Click "Upload Plugin" and select the ZIP file</li>'
            . '<li>Activate the plugin</li>'
            . '<li>Forms can be created via the admin UI or by using the <code>fre_register_form()</code> function</li>'
            . '</ol>';
    }

    /**
     * Display a message on the plugins page for updates.
     *
     * @param array  $plugin_data Plugin data.
     * @param object $response    Update response data.
     */
    public function update_message( $plugin_data, $response ) {
        // Additional message could be added here if needed.
        echo ' <em>' . esc_html__( 'Update available from GitHub.', 'form-runtime-engine' ) . '</em>';
    }

    /**
     * Clear the update cache.
     *
     * @param WP_Upgrader $upgrader WP_Upgrader instance.
     * @param array       $options  Array of update options.
     */
    public function clear_cache( $upgrader, $options ) {
        if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
            delete_transient( $this->cache_key );
        }
    }

    /**
     * Manually clear the update cache.
     *
     * Useful for debugging or forcing an update check.
     */
    public static function force_check() {
        delete_transient( 'fre_github_update_check' );
        delete_site_transient( 'update_plugins' );
    }
}
