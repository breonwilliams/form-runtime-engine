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

### section
Container for grouping related fields.
```php
array(
    'key'         => 'contact_info',
    'type'        => 'section',
    'label'       => 'Contact Information',  // Section heading
    'description' => 'Please provide your contact details.',  // Optional
    'css_class'   => 'custom-section',
)
```
Fields belong to a section via the `section` attribute (see Advanced Layout Features).

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
| `column` | string | Column width: `1/2`, `1/3`, `2/3`, `1/4`, `3/4` |
| `section` | string | Key of section this field belongs to |
| `step` | string | Key of step this field belongs to (multi-step forms) |
| `conditions` | array | Conditional logic rules (see Conditional Logic) |

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

        'multistep' => array(                       // Multi-step form settings
            'show_progress'     => true,            // Show progress indicator
            'progress_style'    => 'steps',         // 'steps', 'bar', or 'dots'
            'validate_on_next'  => true,            // Validate before proceeding
            'show_step_titles'  => false,           // Show step title in content
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

## Advanced Layout Features

### Column Layouts
Place fields side-by-side using the `column` attribute:
```php
array(
    'fields' => array(
        array( 'key' => 'first_name', 'type' => 'text', 'label' => 'First Name', 'column' => '1/2' ),
        array( 'key' => 'last_name', 'type' => 'text', 'label' => 'Last Name', 'column' => '1/2' ),
        array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ),  // Full width
    ),
)
```
Column values: `1/2`, `1/3`, `2/3`, `1/4`, `3/4`. Consecutive column fields are automatically wrapped in a row. Columns stack on mobile.

### Sections/Groups
Group related fields under a heading:
```php
array(
    'fields' => array(
        array( 'key' => 'contact_info', 'type' => 'section', 'label' => 'Contact Information' ),
        array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'section' => 'contact_info' ),
        array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'section' => 'contact_info' ),

        array( 'key' => 'address_info', 'type' => 'section', 'label' => 'Address' ),
        array( 'key' => 'street', 'type' => 'text', 'label' => 'Street', 'section' => 'address_info' ),
        array( 'key' => 'city', 'type' => 'text', 'label' => 'City', 'section' => 'address_info', 'column' => '1/2' ),
        array( 'key' => 'zip', 'type' => 'text', 'label' => 'ZIP', 'section' => 'address_info', 'column' => '1/2' ),
    ),
)
```

### Conditional Logic
Show/hide fields based on other field values:
```php
array(
    'fields' => array(
        array(
            'key'     => 'contact_method',
            'type'    => 'select',
            'label'   => 'Preferred Contact',
            'options' => array(
                array( 'value' => 'email', 'label' => 'Email' ),
                array( 'value' => 'phone', 'label' => 'Phone' ),
                array( 'value' => 'other', 'label' => 'Other' ),
            ),
        ),
        array(
            'key'        => 'phone_number',
            'type'       => 'tel',
            'label'      => 'Phone Number',
            'conditions' => array(
                'rules' => array(
                    array( 'field' => 'contact_method', 'operator' => 'equals', 'value' => 'phone' ),
                ),
            ),
        ),
        array(
            'key'        => 'other_method',
            'type'       => 'text',
            'label'      => 'Please specify',
            'conditions' => array(
                'rules' => array(
                    array( 'field' => 'contact_method', 'operator' => 'equals', 'value' => 'other' ),
                ),
            ),
        ),
    ),
)
```

**Condition Operators:**
- `equals`, `not_equals` - Exact match
- `contains`, `not_contains` - Substring match
- `is_empty`, `is_not_empty` - Empty check
- `is_checked`, `is_not_checked` - Checkbox state
- `greater_than`, `less_than`, `>=`, `<=` - Numeric comparison
- `in`, `not_in` - Array membership

**Multiple Rules (AND/OR):**
```php
'conditions' => array(
    'logic' => 'or',  // Default: 'and'
    'rules' => array(
        array( 'field' => 'country', 'operator' => 'equals', 'value' => 'us' ),
        array( 'field' => 'country', 'operator' => 'equals', 'value' => 'ca' ),
    ),
)
```

### Multi-Step Forms
Split long forms into steps:
```php
fre_register_form( 'quote', array(
    'title' => 'Request a Quote',
    'steps' => array(
        array( 'key' => 'contact', 'title' => 'Your Info' ),
        array( 'key' => 'project', 'title' => 'Project Details' ),
        array( 'key' => 'budget', 'title' => 'Budget & Timeline' ),
    ),
    'fields' => array(
        // Step 1: Contact
        array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'step' => 'contact', 'required' => true ),
        array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'step' => 'contact', 'required' => true ),
        array( 'key' => 'company', 'type' => 'text', 'label' => 'Company', 'step' => 'contact' ),

        // Step 2: Project
        array( 'key' => 'service', 'type' => 'select', 'label' => 'Service', 'step' => 'project',
            'options' => array(
                array( 'value' => 'web', 'label' => 'Web Design' ),
                array( 'value' => 'app', 'label' => 'App Development' ),
            ),
        ),
        array( 'key' => 'description', 'type' => 'textarea', 'label' => 'Project Description', 'step' => 'project' ),

        // Step 3: Budget
        array( 'key' => 'budget', 'type' => 'radio', 'label' => 'Budget Range', 'step' => 'budget',
            'options' => array(
                array( 'value' => 'small', 'label' => 'Under $5k' ),
                array( 'value' => 'medium', 'label' => '$5k - $20k' ),
                array( 'value' => 'large', 'label' => 'Over $20k' ),
            ),
        ),
        array( 'key' => 'timeline', 'type' => 'select', 'label' => 'Timeline', 'step' => 'budget',
            'options' => array(
                array( 'value' => 'asap', 'label' => 'ASAP' ),
                array( 'value' => '1month', 'label' => '1 Month' ),
                array( 'value' => '3months', 'label' => '3 Months' ),
            ),
        ),
    ),
    'settings' => array(
        'multistep' => array(
            'show_progress'    => true,
            'progress_style'   => 'steps',  // 'steps', 'bar', or 'dots'
            'validate_on_next' => true,
        ),
    ),
));
```

**Progress Styles:**
- `steps` - Numbered steps with labels
- `bar` - Progress bar with step count
- `dots` - Simple dot indicators

**Combining Features:**
Columns, sections, and conditions work within multi-step forms:
```php
array(
    'key' => 'billing_section', 'type' => 'section', 'label' => 'Billing Address', 'step' => 'payment',
),
array(
    'key' => 'billing_city', 'type' => 'text', 'label' => 'City',
    'step' => 'payment', 'section' => 'billing_section', 'column' => '1/2',
),
array(
    'key' => 'billing_zip', 'type' => 'text', 'label' => 'ZIP',
    'step' => 'payment', 'section' => 'billing_section', 'column' => '1/2',
),
```

## Gotchas

1. **Use `fre_init` hook** - Not `init` or `plugins_loaded`. The plugin must be fully loaded first.

2. **Field keys must be unique** - Duplicate keys will cause registration to fail.

3. **Single vs Group Checkbox** - With `options`, it's a group. Without, it's a single yes/no checkbox.

4. **File uploads** - Files are stored in `wp-content/uploads/fre-uploads/` with PHP execution disabled.

5. **Email failures** - Failed emails retry automatically (5min, 30min, 2hr) up to 3 times.

---

## Companion Plugin Architecture

For production sites, store form configurations in a separate companion plugin rather than in theme files or code snippet plugins. This approach keeps client-specific forms separate from the core engine.

### Why Use a Companion Plugin

- **Separation of concerns** - Core engine stays clean; client forms live separately
- **Version control friendly** - Commit form configs without modifying the core plugin
- **Portable** - Move forms between environments by copying one plugin folder
- **Organized** - One file per form makes maintenance straightforward
- **Safe updates** - Core plugin updates won't overwrite your form definitions

### Directory Structure

```
/wp-content/plugins/fre-client-forms/
    fre-client-forms.php           # Main bootstrap with dependency check
    forms/
        contact.php                # One file per form
        quote-request.php
        newsletter-signup.php
```

### Main Plugin File Template

Create `fre-client-forms.php`:

```php
<?php
/**
 * Plugin Name: FRE Client Forms
 * Description: Form configurations for this site
 * Version: 1.0.0
 * Requires Plugins: form-runtime-engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FRE_CLIENT_VERSION', '1.0.0' );
define( 'FRE_CLIENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Check if Form Runtime Engine is available.
 */
function fre_client_check_dependencies() {
    if ( ! function_exists( 'fre_register_form' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>FRE Client Forms:</strong> Form Runtime Engine plugin must be activated.';
            echo '</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Register all forms from the forms/ directory.
 */
function fre_client_init_forms( $fre_instance ) {
    if ( ! fre_client_check_dependencies() ) {
        return;
    }

    // Auto-load all form files
    $forms_dir = FRE_CLIENT_PLUGIN_DIR . 'forms/';
    if ( is_dir( $forms_dir ) ) {
        foreach ( glob( $forms_dir . '*.php' ) as $form_file ) {
            require_once $form_file;
        }
    }
}

add_action( 'fre_init', 'fre_client_init_forms' );
add_action( 'admin_init', 'fre_client_check_dependencies' );
```

### Individual Form File Template

Create files in the `forms/` directory. Example `forms/contact.php`:

```php
<?php
/**
 * Contact Form
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

fre_register_form( 'contact', array(
    'title'   => 'Contact Us',
    'version' => '1.0.0',
    'fields'  => array(
        array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
        array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
        array( 'key' => 'phone', 'type' => 'tel', 'label' => 'Phone' ),
        array( 'key' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true, 'rows' => 6 ),
    ),
    'settings' => array(
        'submit_button_text' => 'Send Message',
        'success_message'    => 'Thank you! We\'ll respond within 24 hours.',
        'notification'       => array(
            'subject'  => 'New Contact Form Submission',
            'reply_to' => '{field:email}',
        ),
    ),
));
```

### Best Practices

1. **Always use the `fre_init` hook** - The main plugin handles this automatically when loading form files

2. **One form per file** - Easier to maintain, review, and debug

3. **Use unique form IDs** - Prefix with client/project name if deploying multiple sites: `acme_contact`, `acme_quote`

4. **Include version numbers** - Helps track which config version is deployed

5. **Test with WP_DEBUG enabled** - Registration errors are logged when debug mode is on

6. **Keep field keys unique within a form** - Duplicate keys cause registration to fail silently

### Debugging Registration Issues

If a form doesn't appear:

1. Enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php`
2. Check `wp-content/debug.log` for registration errors
3. Verify the core plugin is activated (check for admin notice)
4. Confirm you're using `fre_init` hook (not `init` or `plugins_loaded`)
5. Check for duplicate field keys in your config

```php
// Quick debug: verify form is registered
add_action( 'wp_footer', function() {
    if ( current_user_can( 'manage_options' ) ) {
        $forms = fre()->registry->get_all();
        echo '<!-- Registered forms: ' . implode( ', ', array_keys( $forms ) ) . ' -->';
    }
});
```

### Claude Code Workflow

When generating forms with Claude Code:

1. **Describe the form** - Explain fields, validation, and notification requirements
2. **Claude generates the config** - A complete form definition array
3. **Save to forms/ directory** - Create a new file like `forms/my-form.php`
4. **Add shortcode to page** - Use `[fre_form id="my-form"]`

No manual hook wiring or shortcode registration needed—the companion plugin handles it automatically.
