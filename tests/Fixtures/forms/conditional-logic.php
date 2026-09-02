<?php
/**
 * Conditional Logic Form Fixture.
 *
 * @package FormRuntimeEngine\Tests\Fixtures
 */

return array(
    'title'  => 'Support Request',
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
            'key'         => 'issue_type',
            'type'        => 'select',
            'label'       => 'Issue Type',
            'required'    => true,
            'placeholder' => 'Select an issue type',
            'options'     => array(
                array(
                    'value' => 'billing',
                    'label' => 'Billing Question',
                ),
                array(
                    'value' => 'technical',
                    'label' => 'Technical Issue',
                ),
                array(
                    'value' => 'other',
                    'label' => 'Other',
                ),
            ),
        ),
        array(
            'key'        => 'order_number',
            'type'       => 'text',
            'label'      => 'Order Number',
            'conditions' => array(
                'rules' => array(
                    array(
                        'field'    => 'issue_type',
                        'operator' => 'equals',
                        'value'    => 'billing',
                    ),
                ),
            ),
        ),
        array(
            'key'        => 'browser',
            'type'       => 'select',
            'label'      => 'Browser',
            'options'    => array(
                array(
                    'value' => 'chrome',
                    'label' => 'Chrome',
                ),
                array(
                    'value' => 'firefox',
                    'label' => 'Firefox',
                ),
                array(
                    'value' => 'safari',
                    'label' => 'Safari',
                ),
                array(
                    'value' => 'other',
                    'label' => 'Other',
                ),
            ),
            'conditions' => array(
                'rules' => array(
                    array(
                        'field'    => 'issue_type',
                        'operator' => 'equals',
                        'value'    => 'technical',
                    ),
                ),
            ),
        ),
        array(
            'key'        => 'other_details',
            'type'       => 'text',
            'label'      => 'Please specify',
            'conditions' => array(
                'rules' => array(
                    array(
                        'field'    => 'issue_type',
                        'operator' => 'equals',
                        'value'    => 'other',
                    ),
                ),
            ),
        ),
        array(
            'key'      => 'message',
            'type'     => 'textarea',
            'label'    => 'Describe your issue',
            'required' => true,
            'rows'     => 5,
        ),
        // Test multiple rules with OR logic.
        array(
            'key'        => 'urgency',
            'type'       => 'radio',
            'label'      => 'Urgency Level',
            'inline'     => true,
            'options'    => array(
                array(
                    'value' => 'low',
                    'label' => 'Low',
                ),
                array(
                    'value' => 'medium',
                    'label' => 'Medium',
                ),
                array(
                    'value' => 'high',
                    'label' => 'High',
                ),
            ),
            'conditions' => array(
                'logic' => 'or',
                'rules' => array(
                    array(
                        'field'    => 'issue_type',
                        'operator' => 'equals',
                        'value'    => 'billing',
                    ),
                    array(
                        'field'    => 'issue_type',
                        'operator' => 'equals',
                        'value'    => 'technical',
                    ),
                ),
            ),
        ),
        // Test in operator.
        array(
            'key'        => 'priority_contact',
            'type'       => 'checkbox',
            'label'      => 'I need priority support',
            'conditions' => array(
                'rules' => array(
                    array(
                        'field'    => 'issue_type',
                        'operator' => 'in',
                        'value'    => array( 'billing', 'technical' ),
                    ),
                ),
            ),
        ),
        // Test is_not_empty operator.
        array(
            'key'        => 'contact_preference',
            'type'       => 'select',
            'label'      => 'Preferred Contact Method',
            'options'    => array(
                array(
                    'value' => 'email',
                    'label' => 'Email',
                ),
                array(
                    'value' => 'phone',
                    'label' => 'Phone',
                ),
            ),
            'conditions' => array(
                'rules' => array(
                    array(
                        'field'    => 'name',
                        'operator' => 'is_not_empty',
                    ),
                ),
            ),
        ),
    ),
    'settings' => array(
        'submit_button_text' => 'Submit Request',
        'success_message'    => 'Your support request has been submitted.',
    ),
);
