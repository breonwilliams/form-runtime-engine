<?php
/**
 * Accessibility contract for rendered field markup.
 *
 * These assertions exist because the fixes they cover were verified once, by
 * hand, in a browser — and nothing since would have caught them being undone.
 * The plugin has no browser-based accessibility gate (it has no Node toolchain
 * at all), so the contract is pinned here instead, at the level the markup is
 * actually produced.
 *
 * What went wrong before, and what each assertion protects:
 *
 *   - No field carried `aria-invalid`. A screen reader announced the errors
 *     once when they appeared; tab back to the field afterwards and it said
 *     "First name, edit text" with no error state at all.
 *   - No error message was tied to its field. The message rendered into a
 *     `role="alert"` element with no id and nothing pointing at it, so it was
 *     announced on arrival and then unreachable.
 *   - Field descriptions had no ids, so help text could not be associated
 *     either.
 *
 * The error id is deliberately present in `aria-describedby` from the start,
 * before any error exists — see the comment on get_described_by(). Changing the
 * attribute at the same moment its target gains content is not something
 * assistive technology handles reliably.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

/**
 * Rendered-markup accessibility contract.
 */
class AccessibilityTest extends UnitTestCase {

    /**
     * Stub the one escaping helper the shared UnitTestCase does not provide.
     *
     * Nothing else in the unit suite renders a <textarea>, so esc_textarea()
     * has never been needed before. Stubbed here rather than in UnitTestCase so
     * this file carries its own requirement.
     */
    protected function set_up() {
        parent::set_up();
        \Brain\Monkey\Functions\when( 'esc_textarea' )->alias(
            function ( $text ) {
                return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
            }
        );
    }

    /**
     * Field types whose rendered control must carry the describedby wiring.
     *
     * Radio and checkbox groups put the attribute on the <fieldset> rather
     * than on each input, which is why they are exercised here too — the four
     * render paths (abstract, select, textarea, group) were fixed separately
     * and can drift separately.
     *
     * @return array
     */
    public function field_type_provider() {
        return array(
            'text'     => array( 'PForms_Field_Text', 'text' ),
            'email'    => array( 'PForms_Field_Email', 'email' ),
            'tel'      => array( 'PForms_Field_Tel', 'tel' ),
            'date'     => array( 'PForms_Field_Date', 'date' ),
            'textarea' => array( 'PForms_Field_Textarea', 'textarea' ),
            'select'   => array( 'PForms_Field_Select', 'select' ),
            'radio'    => array( 'PForms_Field_Radio', 'radio' ),
            'checkbox' => array( 'PForms_Field_Checkbox', 'checkbox' ),
        );
    }

    /**
     * One field definition, with a description so both ids are in play.
     *
     * @param string $type Field type slug.
     * @return array
     */
    private function field( $type ) {
        return array(
            'key'         => 'first_name',
            'type'        => $type,
            'label'       => 'First name',
            'description' => 'As it appears on your ID.',
            'required'    => true,
            'options'     => array(
                array(
                    'label' => 'One',
                    'value' => 'one',
                ),
                array(
                    'label' => 'Two',
                    'value' => 'two',
                ),
            ),
        );
    }

    /**
     * Renders a field to HTML.
     *
     * @param string $class Field class name.
     * @param string $type  Field type slug.
     * @return string
     */
    private function render( $class, $type ) {
        if ( ! class_exists( $class ) ) {
            $this->markTestSkipped( "Field class {$class} is not loaded." );
        }
        $instance = new $class();
        return $instance->render( $this->field( $type ), '', array( 'id' => 'contact' ) );
    }

    /**
     * Every field points at its own error element.
     *
     * @dataProvider field_type_provider
     * @param string $class Field class name.
     * @param string $type  Field type slug.
     */
    public function test_field_is_described_by_its_error_element( $class, $type ) {
        $html = $this->render( $class, $type );

        $this->assertStringContainsString(
            'aria-describedby=',
            $html,
            "{$type}: no aria-describedby, so its error message is announced once and then unreachable"
        );
        $this->assertStringContainsString(
            'fre-contact-first_name-error',
            $html,
            "{$type}: aria-describedby does not reference the field's error element"
        );
    }

    /**
     * The element the describedby points at actually exists in the markup.
     *
     * A dangling aria-describedby is worse than none: the attribute reads as
     * "there is more information here" and there is not.
     *
     * @dataProvider field_type_provider
     * @param string $class Field class name.
     * @param string $type  Field type slug.
     */
    public function test_referenced_ids_exist_in_the_markup( $class, $type ) {
        $html = $this->render( $class, $type );

        $this->assertTrue(
            (bool) preg_match( '/aria-describedby="([^"]+)"/', $html, $matches ),
            "{$type}: no aria-describedby to resolve"
        );

        foreach ( preg_split( '/\s+/', trim( $matches[1] ) ) as $id ) {
            $this->assertStringContainsString(
                'id="' . $id . '"',
                $html,
                "{$type}: aria-describedby points at \"{$id}\", which is not in the rendered markup"
            );
        }
    }

    /**
     * A field with a description exposes it, and one without does not invent a
     * reference to an element that was never rendered.
     *
     * @dataProvider field_type_provider
     * @param string $class Field class name.
     * @param string $type  Field type slug.
     */
    public function test_description_is_only_referenced_when_present( $class, $type ) {
        $with = $this->render( $class, $type );
        $this->assertStringContainsString(
            'fre-contact-first_name-description',
            $with,
            "{$type}: a described field does not reference its description"
        );

        if ( ! class_exists( $class ) ) {
            return;
        }
        $instance = new $class();
        $field    = $this->field( $type );
        unset( $field['description'] );
        $without = $instance->render( $field, '', array( 'id' => 'contact' ) );

        $this->assertStringNotContainsString(
            'fre-contact-first_name-description',
            $without,
            "{$type}: references a description element that is not rendered"
        );
    }

    /**
     * A freshly rendered field is not announced as invalid.
     *
     * aria-invalid is set by the frontend script when validation fails and
     * cleared when the field is corrected; the server-rendered markup is the
     * "no error yet" state, and shipping aria-invalid="true" in it would tell
     * every user the form is broken before they touch it.
     *
     * @dataProvider field_type_provider
     * @param string $class Field class name.
     * @param string $type  Field type slug.
     */
    public function test_field_does_not_start_invalid( $class, $type ) {
        $html = $this->render( $class, $type );

        $this->assertStringNotContainsString(
            'aria-invalid="true"',
            $html,
            "{$type}: rendered as invalid before the user has entered anything"
        );
    }
}
