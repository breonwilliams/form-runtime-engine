<?php
/**
 * The documents AI assistants and developers are pointed at use the names
 * that exist today.
 *
 * The 1.8.0 rename to `pforms_*` left NO aliases, so a retired name in a
 * reference document is not a style problem — it is an instruction that
 * silently does nothing. The served knowledge map told assistants to embed
 * forms as `[fre_form id="…"]`, a shortcode nothing registers (it renders as
 * literal text), and named hooks and a capability that no longer exist
 * (found by the 2026-09-19 pressure test, eighteen months after the rename).
 *
 * Checked: the knowledge map (served live through /connector/schema), the
 * connector spec, the README, the capability and setup guides, the
 * Promptless integration guide, and the rulebook the preflight returns.
 * Dated assessment and audit reports are history and are not checked.
 *
 * @package FRE\Tests\Unit
 */

namespace FRE\Tests\Unit;

class RetiredNamesInDocsTest extends UnitTestCase {

    const REFERENCE_DOCS = array(
        'docs/FRE_KNOWLEDGE_MAP.md',
        'docs/CONNECTOR_SPEC.md',
        'README.md',
        'docs/CAPABILITIES.md',
        'docs/MCP_CONNECTOR_SETUP.md',
        'docs/WORKFLOW_PROMPTLESS_INTEGRATION.md',
    );

    /** Retired identifier → what replaced it. */
    const RETIRED = array(
        '/\[fre_form\b/'                   => '[pforms_form',
        '/\[client_form\b/'                => '[promptless_form',
        '/\bfre_register_form\(/'          => 'pforms_register_form(',
        '/\bfre_manage_forms\b/'           => 'pforms_manage_forms',
        '/\bfre_submission_complete\b/'    => 'pforms_submission_complete',
        '/\bfre_webhook_file_url\b/'       => 'pforms_webhook_file_url',
        '/\bFRE_Capabilities::/'           => 'PForms_Capabilities::',
    );

    private function read( $rel ) {
        $path = \FRE_TEST_PLUGIN_DIR . $rel;
        $this->assertFileExists( $path );
        return (string) file_get_contents( $path );
    }

    public function test_reference_docs_name_no_retired_identifier() {
        foreach ( self::REFERENCE_DOCS as $rel ) {
            $text = $this->read( $rel );
            foreach ( self::RETIRED as $pattern => $replacement ) {
                // A doc may say a name is retired, in a sentence that says so.
                $hits = array_filter(
                    preg_split( '/\R/', $text ),
                    static function ( $line ) use ( $pattern ) {
                        return preg_match( $pattern, $line ) && ! preg_match( '/retired|removed|renamed|no longer/i', $line );
                    }
                );
                $this->assertSame( array(), array_values( $hits ), "{$rel} uses a retired name — use {$replacement}" );
            }
        }
    }

    public function test_the_preflight_rulebook_names_no_retired_identifier() {
        $src = $this->read( 'includes/Connector/class-fre-connector-api.php' );
        $start = strpos( $src, 'function get_connector_rulebook' );
        $this->assertNotFalse( $start );
        $body = substr( $src, $start, 20000 );
        foreach ( self::RETIRED as $pattern => $replacement ) {
            $this->assertSame( 0, preg_match( $pattern, $body ), "the preflight rulebook uses a retired name — use {$replacement}" );
        }
    }

    public function test_every_shortcode_the_knowledge_map_shows_is_registered() {
        preg_match_all( "/add_shortcode\(\s*(?:\\\$this->tag|'([a-z_]+)')/", $this->read( 'includes/Core/class-fre-shortcode.php' ), $m );
        $registered = array_filter( $m[1] );
        preg_match( "/private \\\$tag = '([a-z_]+)'/", $this->read( 'includes/Core/class-fre-shortcode.php' ), $tag );
        $registered[] = $tag[1];
        $this->assertContains( 'pforms_form', $registered );

        preg_match_all( '/\[([a-z_]+_form)\s+id=/', $this->read( 'docs/FRE_KNOWLEDGE_MAP.md' ), $shown );
        $this->assertNotEmpty( $shown[1], 'the knowledge map shows how to embed a form' );
        foreach ( array_unique( $shown[1] ) as $name ) {
            $this->assertContains( $name, $registered, "the knowledge map shows [{$name}], which is not a registered shortcode" );
        }
    }
}
