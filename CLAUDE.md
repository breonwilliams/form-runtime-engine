# Form Runtime Engine - AI Reference

A lightweight WordPress form engine that renders forms from configuration arrays.

## Quick Start

Register forms on the `fre_init` hook (not `init` or `plugins_loaded`):

```php
add_action( 'fre_init', function() {
    fre_register_form( 'contact', array(
        'title'  => 'Contact Us',
        'fields' => array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
            array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
            array( 'key' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true ),
        ),
    ));
});
```

Display with shortcode: `[fre_form id="contact"]` or `[client_form id="contact"]`

## Field Types

### text
Standard text input.
```php
array(
    'key'         => 'name',
    'type'        => 'text',
    'label'       => 'Your Name',
    'placeholder' => 'Enter your name',
    'required'    => true,
    'maxlength'   => 100,
    'minlength'   => 2,
    'default'     => '',
    'css_class'   => 'custom-class',
    'description' => 'Help text below field',
    'readonly'    => false,
    'disabled'    => false,
    'autocomplete'=> 'name',
)
```

### email
Email input with validation.
```php
array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true )
```

### tel
Phone number input.
```php
array( 'key' => 'phone', 'type' => 'tel', 'label' => 'Phone' )
```

### textarea
Multi-line text.
```php
array(
    'key'   => 'message',
    'type'  => 'textarea',
    'label' => 'Message',
    'rows'  => 5,      // Default: 5
    'cols'  => 50,     // Optional
)
```

### select
Dropdown menu.
```php
array(
    'key'         => 'country',
    'type'        => 'select',
    'label'       => 'Country',
    'placeholder' => 'Select a country',  // Empty first option
    'multiple'    => false,               // Allow multiple selections
    'options'     => array(
        array( 'value' => 'us', 'label' => 'United States' ),
        array( 'value' => 'ca', 'label' => 'Canada' ),
        // Or simple format:
        'uk',  // Value and label are the same
    ),
)
```

### radio
Radio button group.
```php
array(
    'key'     => 'contact_method',
    'type'    => 'radio',
    'label'   => 'Preferred Contact',
    'inline'  => true,  // Display inline instead of stacked
    'options' => array(
        array( 'value' => 'email', 'label' => 'Email' ),
        array( 'value' => 'phone', 'label' => 'Phone' ),
    ),
)
```

### checkbox
Single checkbox or checkbox group.

**Single checkbox:**
```php
array(
    'key'      => 'agree_terms',
    'type'     => 'checkbox',
    'label'    => 'I agree to the terms',
    'required' => true,
)
```

**Checkbox group (multiple selections):**
```php
array(
    'key'     => 'interests',
    'type'    => 'checkbox',
    'label'   => 'Interests',
    'inline'  => true,
    'options' => array(
        array( 'value' => 'tech', 'label' => 'Technology' ),
        array( 'value' => 'design', 'label' => 'Design' ),
    ),
)
```

### file
File upload.
```php
array(
    'key'           => 'resume',
    'type'          => 'file',
    'label'         => 'Upload Resume',
    'required'      => false,
    'multiple'      => false,           // Allow multiple files
    'allowed_types' => array( 'pdf', 'doc', 'docx' ),  // Default: pdf,jpg,jpeg,png,gif,doc,docx
    'max_size'      => 5242880,         // Bytes. Default: 5MB
)
```

### hidden
Hidden field.
```php
array(
    'key'     => 'source',
    'type'    => 'hidden',
    'default' => 'website',
)
```

### message
Display-only content (not submitted).
```php
array(
    'key'     => 'notice',
    'type'    => 'message',
    'label'   => 'Important',           // Optional heading
    'content' => '<p>HTML content here</p>',
    'style'   => 'info',                // info, warning, success, error
)
```

## Common Field Options

| Option | Type | Description |
|--------|------|-------------|
| `key` | string | **Required.** Unique field identifier |
| `type` | string | Field type. Default: `text` |
| `label` | string | Field label |
| `placeholder` | string | Placeholder text |
| `required` | bool | Is field required? Default: `false` |
| `css_class` | string | Additional CSS class |
| `default` | mixed | Default value |
| `description` | string | Help text below field |
| `maxlength` | int | Maximum character length |
| `minlength` | int | Minimum character length |
| `readonly` | bool | Read-only field |
| `disabled` | bool | Disabled field |

## Form Settings

```php
fre_register_form( 'contact', array(
    'title'    => 'Contact Form',
    'version'  => '1.0.0',
    'fields'   => array( /* ... */ ),
    'settings' => array(
        'submit_button_text' => 'Submit',           // Default: 'Submit'
        'success_message'    => 'Thank you!',       // Default: 'Thank you for your submission.'
        'redirect_url'       => null,               // URL to redirect after submission
        'css_class'          => '',                 // Form CSS class
        'store_entries'      => true,               // Save to database

        'notification' => array(
            'enabled'    => true,
            'to'         => '{admin_email}',        // Comma-separated or array
            'subject'    => 'New Form Submission',
            'from_name'  => '{site_name}',
            'from_email' => '{admin_email}',
            'reply_to'   => '{field:email}',        // Reply to submitter
        ),

        'spam_protection' => array(
            'honeypot'            => true,
            'timing_check'        => true,
            'min_submission_time' => 3,             // Seconds
            'rate_limit'          => array(
                'max'    => 5,                      // Max submissions
                'window' => 3600,                   // Per hour (seconds)
            ),
        ),
    ),
));
```

## Template Variables

Use in notification settings:

| Variable | Description |
|----------|-------------|
| `{admin_email}` | WordPress admin email |
| `{site_name}` | Site name |
| `{site_url}` | Home URL |
| `{form_title}` | Form title |
| `{field:key}` | Value of field with given key |

Example: `'reply_to' => '{field:email}'`

## Hooks Reference

### Actions

```php
// After plugin fully initialized - REGISTER FORMS HERE
do_action( 'fre_init', $plugin_instance );

// After form registered
do_action( 'fre_form_registered', $form_id, $config );

// After notification sent
do_action( 'fre_notification_sent', $sent, $entry_id, $form_config, $entry_data );

// Email permanently failed after retries
do_action( 'fre_email_permanently_failed', $entry_id, $form_config );
```

### Filters

```php
// Modify valid field types
$types = apply_filters( 'fre_field_types', array( 'text', 'email', ... ) );

// Modify notification body before sending
$body = apply_filters( 'fre_notification_body', $body, $form_config, $entry_data, $entry_id );
```

## API Functions

```php
// Register a form
fre_register_form( $form_id, $config );

// Get form configuration
$config = fre_get_form( $form_id );

// Render form HTML
$html = fre_render_form( $form_id, $args );

// Get plugin instance
$plugin = fre();
$plugin->registry->get_all();          // All registered forms
$plugin->registry->exists( $form_id ); // Check if form exists
```

## Shortcode Attributes

```
[fre_form id="contact" class="my-form" ajax="true"]
[client_form id="contact"]  <!-- Alias -->
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | - | Form ID (required) |
| `form` | - | Alias for `id` |
| `class` | - | Additional CSS class |
| `ajax` | `true` | Use AJAX submission |

## File Structure

```
form-runtime-engine/
  form-runtime-engine.php     # Main plugin file
  includes/
    Core/
      class-fre-registry.php  # Form registration
      class-fre-renderer.php  # HTML rendering
      class-fre-shortcode.php # Shortcode handler
    Fields/                   # Field type classes
    Notifications/            # Email handling
    Security/                 # Spam protection
    Database/                 # Entry storage
    Admin/                    # Admin interface
```

## Common Patterns

### Contact Form
```php
add_action( 'fre_init', function() {
    fre_register_form( 'contact', array(
        'title'  => 'Contact Us',
        'fields' => array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
            array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
            array( 'key' => 'phone', 'type' => 'tel', 'label' => 'Phone' ),
            array( 'key' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true, 'rows' => 6 ),
        ),
        'settings' => array(
            'notification' => array(
                'reply_to' => '{field:email}',
            ),
        ),
    ));
});
```

### Quote Request with File Upload
```php
add_action( 'fre_init', function() {
    fre_register_form( 'quote', array(
        'title'  => 'Request a Quote',
        'fields' => array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
            array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
            array( 'key' => 'service', 'type' => 'select', 'label' => 'Service', 'required' => true,
                'placeholder' => 'Select a service',
                'options' => array(
                    array( 'value' => 'web', 'label' => 'Web Design' ),
                    array( 'value' => 'seo', 'label' => 'SEO' ),
                ),
            ),
            array( 'key' => 'budget', 'type' => 'radio', 'label' => 'Budget', 'inline' => true,
                'options' => array(
                    array( 'value' => 'small', 'label' => '$1k-5k' ),
                    array( 'value' => 'medium', 'label' => '$5k-10k' ),
                    array( 'value' => 'large', 'label' => '$10k+' ),
                ),
            ),
            array( 'key' => 'brief', 'type' => 'file', 'label' => 'Project Brief',
                'allowed_types' => array( 'pdf', 'doc', 'docx' ),
            ),
            array( 'key' => 'details', 'type' => 'textarea', 'label' => 'Project Details', 'rows' => 5 ),
        ),
        'settings' => array(
            'submit_button_text' => 'Get Quote',
            'success_message' => 'Thanks! We\'ll be in touch within 24 hours.',
        ),
    ));
});
```

## Gotchas

1. **Use `fre_init` hook** - Not `init` or `plugins_loaded`. The plugin must be fully loaded first.

2. **Field keys must be unique** - Duplicate keys will cause registration to fail.

3. **Single vs Group Checkbox** - With `options`, it's a group. Without, it's a single yes/no checkbox.

4. **File uploads** - Files are stored in `wp-content/uploads/fre-uploads/` with PHP execution disabled.

5. **Email failures** - Failed emails retry automatically (5min, 30min, 2hr) up to 3 times.
