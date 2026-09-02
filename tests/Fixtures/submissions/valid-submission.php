<?php
/**
 * Valid Submission Data Fixture.
 *
 * @package FormRuntimeEngine\Tests\Fixtures
 */

return array(
    'simple_contact' => array(
        'name'    => 'John Doe',
        'email'   => 'john@example.com',
        'phone'   => '555-123-4567',
        'message' => 'This is a test message.',
    ),

    'multi_step' => array(
        'first_name'   => 'Jane',
        'last_name'    => 'Smith',
        'email'        => 'jane@example.com',
        'phone'        => '555-987-6543',
        'company'      => 'Acme Corp',
        'service_type' => 'website',
        'description'  => 'Need a new website design.',
        'budget_range' => '10k-25k',
        'timeline'     => '3months',
    ),

    'conditional_logic' => array(
        'name'       => 'Bob Wilson',
        'email'      => 'bob@example.com',
        'issue_type' => 'billing',
        'order_number' => 'ORD-12345',
        'message'    => 'I have a billing question.',
        'urgency'    => 'medium',
    ),

    'missing_required' => array(
        // Missing name and message.
        'email'   => 'test@example.com',
        'phone'   => '555-000-0000',
    ),

    'invalid_email' => array(
        'name'    => 'Test User',
        'email'   => 'not-a-valid-email',
        'message' => 'This has an invalid email.',
    ),
);
