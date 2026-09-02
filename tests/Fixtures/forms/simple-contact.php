<?php
/**
 * Simple Contact Form Fixture.
 *
 * @package FormRuntimeEngine\Tests\Fixtures
 */

return array(
    'title'  => 'Contact Us',
    'fields' => array(
        array(
            'key'      => 'name',
            'type'     => 'text',
            'label'    => 'Name',
            'required' => true,
        ),
        array(
            'key'      => 'email',
            'type'     => 'email',
            'label'    => 'Email',
            'required' => true,
        ),
        array(
            'key'   => 'phone',
            'type'  => 'tel',
            'label' => 'Phone',
        ),
        array(
            'key'      => 'message',
            'type'     => 'textarea',
            'label'    => 'Message',
            'required' => true,
            'rows'     => 5,
        ),
    ),
    'settings' => array(
        'submit_button_text' => 'Send Message',
        'success_message'    => 'Thanks! We\'ll be in touch soon.',
        'notification'       => array(
            'enabled'  => true,
            'reply_to' => '{field:email}',
        ),
    ),
);
