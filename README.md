# Form Runtime Engine

A lightweight WordPress form plugin that renders and processes forms from PHP configuration arrays. No GUI builder - just clean, declarative code.

## Requirements

- WordPress 5.0+
- PHP 7.4+

## Installation

1. Upload the `form-runtime-engine` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Register your forms using the `fre_init` hook

## Quick Start

### 1. Register a Form

Add this to your theme's `functions.php` or a custom plugin:

```php
add_action( 'fre_init', function() {
    fre_register_form( 'contact', array(
        'title'  => 'Contact Us',
        'fields' => array(
            array(
                'key'      => 'name',
                'type'     => 'text',
                'label'    => 'Your Name',
                'required' => true,
            ),
            array(
                'key'      => 'email',
                'type'     => 'email',
                'label'    => 'Email Address',
                'required' => true,
            ),
            array(
                'key'      => 'message',
                'type'     => 'textarea',
                'label'    => 'Message',
                'required' => true,
                'rows'     => 6,
            ),
        ),
    ));
});
```

### 2. Display the Form

Use the shortcode in any post, page, or template:

```
[fre_form id="contact"]
```

Or use the alias:

```
[client_form id="contact"]
```

## Field Types

| Type | Description |
|------|-------------|
| `text` | Single-line text input |
| `email` | Email input with validation |
| `tel` | Phone number input |
| `textarea` | Multi-line text area |
| `select` | Dropdown menu |
| `radio` | Radio button group |
| `checkbox` | Single checkbox or checkbox group |
| `file` | File upload |
| `hidden` | Hidden field |
| `message` | Display-only content |

## Configuration Options

### Field Options

```php
array(
    'key'         => 'field_name',      // Required. Unique identifier
    'type'        => 'text',            // Field type
    'label'       => 'Field Label',     // Display label
    'placeholder' => 'Enter value...',  // Placeholder text
    'required'    => false,             // Is field required?
    'default'     => '',                // Default value
    'description' => '',                // Help text below field
    'css_class'   => '',                // Additional CSS class
)
```

### Form Settings

```php
'settings' => array(
    'submit_button_text' => 'Submit',
    'success_message'    => 'Thank you for your submission.',
    'redirect_url'       => null,
    'store_entries'      => true,

    'notification' => array(
        'enabled'    => true,
        'to'         => '{admin_email}',
        'subject'    => 'New Form Submission',
        'from_name'  => '{site_name}',
        'from_email' => '{admin_email}',
        'reply_to'   => '{field:email}',
    ),

    'spam_protection' => array(
        'honeypot'            => true,
        'timing_check'        => true,
        'min_submission_time' => 3,
        'rate_limit'          => array(
            'max'    => 5,
            'window' => 3600,
        ),
    ),
)
```

## Shortcode Attributes

```
[fre_form id="contact" class="custom-class" ajax="true"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | - | Form ID (required) |
| `class` | - | Additional CSS class |
| `ajax` | `true` | Use AJAX submission |

## Template Variables

Use these in notification settings:

| Variable | Description |
|----------|-------------|
| `{admin_email}` | WordPress admin email |
| `{site_name}` | Site name |
| `{site_url}` | Home URL |
| `{form_title}` | Form title |
| `{field:key}` | Value of a submitted field |

## Developer Hooks

### Actions

```php
// Register forms here
add_action( 'fre_init', function( $plugin ) {
    fre_register_form( 'my_form', $config );
});

// After a form is registered
add_action( 'fre_form_registered', function( $form_id, $config ) {
    // ...
}, 10, 2 );

// After notification is sent
add_action( 'fre_notification_sent', function( $sent, $entry_id, $form_config, $entry_data ) {
    // ...
}, 10, 4 );
```

### Filters

```php
// Add custom field types
add_filter( 'fre_field_types', function( $types ) {
    $types[] = 'custom_type';
    return $types;
});

// Modify email body
add_filter( 'fre_notification_body', function( $body, $form_config, $entry_data, $entry_id ) {
    return $body;
}, 10, 4 );
```

## API Functions

```php
// Register a form
fre_register_form( string $form_id, array $config ): bool

// Get form configuration
fre_get_form( string $form_id ): ?array

// Render form HTML
fre_render_form( string $form_id, array $args = array() ): string

// Get plugin instance
fre(): Form_Runtime_Engine
```

## Features

- AJAX form submission
- Email notifications with retry queue
- File uploads with security protections
- Spam protection (honeypot, timing check, rate limiting)
- Entry storage and admin management
- CSV export
- Accessible HTML output

## Security

- Honeypot fields to catch bots
- Minimum submission time check
- Rate limiting per IP
- File upload restrictions (type and size)
- PHP execution disabled in upload directory
- CSRF protection with nonces
- Input sanitization and validation

## Changelog

### 1.0.0
- Initial release

## License

GPL-2.0+
