<?php
/**
 * Multi-Step Form Fixture.
 *
 * @package FormRuntimeEngine\Tests\Fixtures
 */

return array(
    'title'   => 'Project Quote Request',
    'version' => '1.0.0',
    'steps'   => array(
        array(
            'key'   => 'contact',
            'title' => 'Your Information',
        ),
        array(
            'key'   => 'project',
            'title' => 'Project Details',
        ),
        array(
            'key'   => 'budget',
            'title' => 'Budget & Timeline',
        ),
    ),
    'fields' => array(
        // Step 1: Contact.
        array(
            'key'      => 'first_name',
            'type'     => 'text',
            'label'    => 'First Name',
            'required' => true,
            'step'     => 'contact',
            'column'   => '1/2',
        ),
        array(
            'key'      => 'last_name',
            'type'     => 'text',
            'label'    => 'Last Name',
            'required' => true,
            'step'     => 'contact',
            'column'   => '1/2',
        ),
        array(
            'key'      => 'email',
            'type'     => 'email',
            'label'    => 'Email',
            'required' => true,
            'step'     => 'contact',
        ),
        array(
            'key'   => 'phone',
            'type'  => 'tel',
            'label' => 'Phone',
            'step'  => 'contact',
        ),
        array(
            'key'   => 'company',
            'type'  => 'text',
            'label' => 'Company Name',
            'step'  => 'contact',
        ),

        // Step 2: Project.
        array(
            'key'         => 'service_type',
            'type'        => 'select',
            'label'       => 'Service Needed',
            'required'    => true,
            'step'        => 'project',
            'placeholder' => 'Select a service',
            'options'     => array(
                array(
                    'value' => 'website',
                    'label' => 'Website Design',
                ),
                array(
                    'value' => 'app',
                    'label' => 'Mobile App',
                ),
                array(
                    'value' => 'branding',
                    'label' => 'Branding',
                ),
                array(
                    'value' => 'other',
                    'label' => 'Other',
                ),
            ),
        ),
        array(
            'key'        => 'other_service',
            'type'       => 'text',
            'label'      => 'Please describe the service',
            'step'       => 'project',
            'conditions' => array(
                'rules' => array(
                    array(
                        'field'    => 'service_type',
                        'operator' => 'equals',
                        'value'    => 'other',
                    ),
                ),
            ),
        ),
        array(
            'key'   => 'description',
            'type'  => 'textarea',
            'label' => 'Project Description',
            'step'  => 'project',
            'rows'  => 5,
        ),
        array(
            'key'           => 'files',
            'type'          => 'file',
            'label'         => 'Upload Reference Files',
            'step'          => 'project',
            'multiple'      => true,
            'allowed_types' => array( 'pdf', 'doc', 'docx', 'jpg', 'png' ),
        ),

        // Step 3: Budget.
        array(
            'key'      => 'budget_range',
            'type'     => 'radio',
            'label'    => 'Budget Range',
            'required' => true,
            'step'     => 'budget',
            'options'  => array(
                array(
                    'value' => 'under5k',
                    'label' => 'Under $5,000',
                ),
                array(
                    'value' => '5k-10k',
                    'label' => '$5,000 - $10,000',
                ),
                array(
                    'value' => '10k-25k',
                    'label' => '$10,000 - $25,000',
                ),
                array(
                    'value' => 'over25k',
                    'label' => 'Over $25,000',
                ),
            ),
        ),
        array(
            'key'         => 'timeline',
            'type'        => 'select',
            'label'       => 'Desired Timeline',
            'step'        => 'budget',
            'placeholder' => 'Select timeline',
            'options'     => array(
                array(
                    'value' => 'asap',
                    'label' => 'ASAP',
                ),
                array(
                    'value' => '1month',
                    'label' => 'Within 1 month',
                ),
                array(
                    'value' => '3months',
                    'label' => 'Within 3 months',
                ),
                array(
                    'value' => 'flexible',
                    'label' => 'Flexible',
                ),
            ),
        ),
        array(
            'key'   => 'additional_notes',
            'type'  => 'textarea',
            'label' => 'Additional Notes',
            'step'  => 'budget',
            'rows'  => 4,
        ),
    ),
    'settings' => array(
        'submit_button_text' => 'Submit Quote Request',
        'success_message'    => 'Thank you! We\'ll review your project and get back to you within 24 hours.',
        'multistep'          => array(
            'show_progress'    => true,
            'progress_style'   => 'steps',
            'validate_on_next' => true,
        ),
        'notification'       => array(
            'enabled'  => true,
            'subject'  => 'New Quote Request: {field:service_type}',
            'reply_to' => '{field:email}',
        ),
    ),
);
