# Form Runtime Engine - AI Reference

A lightweight WordPress form engine that renders forms from configuration arrays.

## Design System Integration

When **AI Section Builder Modern** is active, forms automatically inherit brand styling:
- **Colors**: Primary, text, background, border colors from AISB global settings
- **Typography**: Heading and body fonts from AISB typography settings
- **Border Radius**: Button and card radius from AISB design tokens
- **Dark Mode**: Forms inside `.aisb-section--dark` automatically use dark colors
- **Neo-Brutalist Mode**: Bold borders and box shadows when enabled in AISB settings

Forms work perfectly standalone with sensible defaults when AISB is not active.

## System Requirements

| Requirement | Version | Notes |
|-------------|---------|-------|
| WordPress | 5.0+ | Uses REST API, block editor compatibility |
| PHP | 7.4+ | Type hints, arrow functions |
| MySQL | 5.6+ / MariaDB 10.0+ | **InnoDB storage engine required** |

### Database Requirements

This plugin requires **MySQL InnoDB storage engine** for transactional integrity:

- **Entry creation** uses transactions to ensure atomic storage of entries and metadata
- **Duplicate detection** uses atomic INSERT operations for race condition protection
- If your database uses MyISAM tables, form submissions may fail or produce inconsistent data

**Verification:** Most modern WordPress installations use InnoDB by default. You can verify by checking your `wp_options` table engine:
```sql
SHOW TABLE STATUS WHERE Name = 'wp_options';
```
The `Engine` column should show `InnoDB`.

### Theme Variants

Set `theme_variant` in form settings to control dark mode:
- `light` (default): Light mode styling
- `dark`: Dark mode styling
- `auto`: Inherits from parent AISB section

```json
{
  "settings": {
    "theme_variant": "dark"
  }
}
```

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

## Form Registration Methods

There are **two ways** to create forms with this plugin:

### Option 1: Admin Dashboard (JSON)
Use the built-in Forms Manager in the WordPress admin:
1. Go to WordPress Admin → Form Entries → Forms → Add New
2. Enter a Form ID and optional Title
3. Paste **JSON configuration** (not PHP code)
4. Save and use shortcode: `[fre_form id="your-form-id"]`

**JSON Format Example:**
```json
{
  "title": "Contact Us",
  "fields": [
    {"key": "name", "type": "text", "label": "Name", "required": true},
    {"key": "email", "type": "email", "label": "Email", "required": true},
    {"key": "message", "type": "textarea", "label": "Message", "required": true}
  ],
  "settings": {
    "submit_button_text": "Send",
    "success_message": "Thank you for your submission."
  }
}
```

### Option 2: PHP Code
If creating forms via code (theme functions.php, custom plugin, or `fre_init` hook):
1. Hook into `fre_init` action
2. Use `fre_register_form()` with a PHP array
3. The form registers automatically on page load

**PHP Format Example:**
```php
<?php
fre_register_form( 'contact', array(
    'title'  => 'Contact Us',
    'fields' => array(
        array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
        array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
        array( 'key' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true ),
    ),
    'settings' => array(
        'submit_button_text' => 'Send',
        'success_message'    => 'Thank you for your submission.',
    ),
));
```

### Which Method to Use?

| Scenario | Use |
|----------|-----|
| Adding form via WordPress admin | **JSON** |
| Creating form files for deployment | **PHP** |
| Need version control | **PHP** |
| Quick testing | **JSON** |
| AI-generated forms → admin UI | **JSON** |
| AI-generated forms → code files | **PHP** |

> **Note for AI:** When a user asks to generate a form, ask which method they prefer OR check context clues (e.g., if they mention "admin", "dashboard", or "paste into", use JSON; if they mention "file", "code", or "functions.php", use PHP).

## JSON Structure Reference

Every JSON form follows this structure:

```json
{
  "title": "Form Name",
  "version": "1.0.0",
  "steps": [
    {"key": "step1", "title": "Step 1 Title"},
    {"key": "step2", "title": "Step 2 Title"}
  ],
  "fields": [
    {"key": "field_key", "type": "text", "label": "Field Label"}
  ],
  "settings": {
    "submit_button_text": "Submit",
    "success_message": "Thank you!"
  }
}
```

**Top-Level Keys:**

| Key | Required | Description |
|-----|----------|-------------|
| `title` | No | Form name for admin reference (not displayed by default) |
| `version` | No | Version string for tracking changes |
| `steps` | No | Array of step objects (only for multi-step forms) |
| `fields` | **Yes** | Array of field objects |
| `settings` | No | Form settings object (all have sensible defaults) |

**Key Rules:**
- `fields` is the only required key; everything else is optional
- Each field must have a unique `key`
- For multi-step forms: define `steps` array, then add `"step": "step_key"` to each field
- For sections: define section field first, then add `"section": "section_key"` to grouped fields
- Columns, sections, and conditions can all be combined within multi-step forms

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

### date
Native HTML5 date picker input.
```php
array(
    'key'         => 'appointment_date',
    'type'        => 'date',
    'label'       => 'Appointment Date',
    'required'    => true,
    'min'         => '2024-01-01',           // Minimum allowed date (YYYY-MM-DD)
    'max'         => '2025-12-31',           // Maximum allowed date (YYYY-MM-DD)
    'default'     => '',                      // Default value (YYYY-MM-DD)
    'description' => 'Select your preferred date',
)
```
**Notes:**
- Uses native HTML5 date input with browser-native date picker
- Mobile-friendly with native date picker on iOS/Android
- Dates are stored in YYYY-MM-DD format
- Min/max validation is performed both client-side and server-side

### address
Address input with Google Places autocomplete.
```php
array(
    'key'                 => 'property_address',
    'type'                => 'address',
    'label'               => 'Property Address',
    'required'            => true,
    'placeholder'         => 'Start typing an address...',
    'country_restriction' => array( 'us', 'ca' ),  // ISO country codes (optional)
    'description'         => 'Start typing to see suggestions',
)
```
**Notes:**
- Requires Google Places API key (Settings → Form Entries → Settings)
- Automatically stores parsed address components in hidden fields:
  - `{field_key}_street_number`, `{field_key}_route`, `{field_key}_locality`
  - `{field_key}_administrative_area_level_1`, `{field_key}_postal_code`, `{field_key}_country`
  - `{field_key}_formatted_address`, `{field_key}_lat`, `{field_key}_lng`
- Shows a warning message to admins if no API key is configured
- Country restriction accepts ISO 3166-1 alpha-2 codes (e.g., `us`, `ca`, `gb`)

**Setting up Google Places API:**
1. Go to WordPress Admin → Form Entries → Settings
2. Enter your Google Places API key
3. In Google Cloud Console, enable:
   - Places API
   - Maps JavaScript API

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

## Field Naming Conventions

For consistency across forms and reusable automations, use these standard field keys:

### Contact Information
| Data | Recommended Key | Avoid |
|------|-----------------|-------|
| Full name | `name` | `full_name`, `your_name`, `customer_name` |
| First name | `first_name` | `fname`, `firstName` |
| Last name | `last_name` | `lname`, `lastName` |
| Email | `email` | `email_address`, `your_email` |
| Phone | `phone` | `phone_number`, `tel`, `mobile` |

### Address Fields
| Data | Recommended Key |
|------|-----------------|
| Full address | `address` |
| Street | `street` or `street_address` |
| City | `city` |
| State/Province | `state` |
| ZIP/Postal | `zip` or `postal_code` |
| Country | `country` |

### Business/Service Fields
| Data | Recommended Key |
|------|-----------------|
| Service type | `service` or `service_type` |
| Budget range | `budget` |
| Timeline | `timeline` |
| Urgency | `urgency` |
| Company name | `company` |

### Common Patterns
| Data | Recommended Key |
|------|-----------------|
| Message/Description | `message` or `details` |
| How heard about us | `source` or `referral_source` |
| Appointment date | `date` or `appointment_date` |
| File upload | `file` or descriptive like `resume`, `documents` |

> **Tip:** Prefer standard keys from this list. Only create custom keys when the data doesn't fit any standard pattern.

**Why this matters:**
- Zapier/Make automations can be reused across forms
- Webhook payloads are predictable
- AI-generated forms are consistent

## Form Settings

### Settings Defaults Quick Reference

| Setting | Default | Description |
|---------|---------|-------------|
| `show_title` | `false` | Display form title above form |
| `submit_button_text` | `"Submit"` | Text on submit button |
| `success_message` | `"Thank you for your submission."` | Message after successful submission |
| `redirect_url` | `null` | URL to redirect after submission (null = no redirect) |
| `store_entries` | `true` | Save submissions to database |
| `css_class` | `""` | Additional CSS class on form element |
| `notification.enabled` | `true` | Send email notifications |
| `notification.to` | `"{admin_email}"` | Recipient email(s) |
| `notification.subject` | `"New Form Submission"` | Email subject line |
| `spam_protection.honeypot` | `true` | Enable honeypot field |
| `spam_protection.timing_check` | `true` | Reject fast submissions |
| `spam_protection.min_submission_time` | `3` | Minimum seconds before submission |
| `multistep.show_progress` | `true` | Show progress indicator |
| `multistep.progress_style` | `"steps"` | Progress style: `steps`, `bar`, or `dots` |
| `multistep.validate_on_next` | `true` | Validate fields before next step |
| `webhook_enabled` | `false` | Enable webhook for form submissions |
| `webhook_url` | `null` | URL to send form data (Zapier, Make, etc.) |
| `theme_variant` | `"light"` | Theme mode: `light`, `dark`, or `auto` (inherits from AISB section) |

### Full Settings Structure

```php
fre_register_form( 'contact', array(
    'title'    => 'Contact Form',
    'version'  => '1.0.0',
    'fields'   => array( /* ... */ ),
    'settings' => array(
        'submit_button_text' => 'Submit',           // Default: 'Submit'
        'success_message'    => 'Thank you!',       // Default: 'Thank you for your submission.'
        'redirect_url'       => null,               // URL to redirect after submission
        'show_title'         => false,              // Display form title (default: false)
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

        // Webhook Integration (Zapier, Make, etc.)
        'webhook_enabled' => false,                 // Enable webhook dispatch
        'webhook_url'     => '',                    // Endpoint URL (validated)
    ),
));
```

### Webhook Configuration

Enable webhooks to send form submissions to external services like Zapier, Make, or custom endpoints.

**Admin UI:** Configure webhooks in the Forms Manager (Form Entries → Forms → Edit).

**JSON Configuration:**
```json
{
  "title": "Contact Form",
  "fields": [...],
  "settings": {
    "webhook_enabled": true,
    "webhook_url": "https://hooks.zapier.com/hooks/catch/..."
  }
}
```

**Webhook Payload Structure:**
```json
{
  "event": "form_submission",
  "timestamp": "2024-01-15T10:30:00+00:00",
  "form": {
    "id": "contact",
    "title": "Contact Us"
  },
  "entry": {
    "id": 123,
    "submitted_at": "2024-01-15T10:30:00+00:00"
  },
  "data": {
    "name": "John Doe",
    "email": "john@example.com",
    "message": "Hello..."
  },
  "files": [
    {
      "field_key": "resume",
      "file_name": "resume.pdf",
      "file_size": 12345,
      "mime_type": "application/pdf"
    }
  ],
  "site": {
    "name": "My Website",
    "url": "https://example.com"
  }
}
```

**Security Features:**
- SSRF protection (blocks private IP ranges)
- URL validation at save and dispatch time
- Honeypot/timing fields filtered from payload
- Non-blocking async dispatch

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

// After form entry created (webhook dispatcher listens here)
do_action( 'fre_entry_created', $entry_id, $form_id, $data );

// After notification sent
do_action( 'fre_notification_sent', $sent, $entry_id, $form_config, $entry_data );

// Email permanently failed after retries
do_action( 'fre_email_permanently_failed', $entry_id, $form_config );

// After webhook sent successfully
do_action( 'fre_webhook_sent', $url, $payload, $entry_id, $form_id );

// When webhook request fails
do_action( 'fre_webhook_failed', $wp_error, $url, $payload, $entry_id, $form_id );
```

### Filters

```php
// Modify webhook payload before sending
$payload = apply_filters( 'fre_webhook_payload', $payload, $entry_id, $form_id, $data );

// Modify webhook request arguments
$args = apply_filters( 'fre_webhook_request_args', $args, $url, $payload, $entry_id, $form_id );
```

```php
// Modify valid field types
$types = apply_filters( 'fre_field_types', array( 'text', 'email', ... ) );

// Modify notification body before sending
$body = apply_filters( 'fre_notification_body', $body, $form_config, $entry_data, $entry_id );
```

## API Functions

```php
// Register a form (runtime)
fre_register_form( $form_id, $config );

// Get form configuration (from registry)
$config = fre_get_form( $form_id );

// Render form HTML
$html = fre_render_form( $form_id, $args );

// Get plugin instance
$plugin = fre();
$plugin->registry->get_all();          // All registered forms
$plugin->registry->exists( $form_id ); // Check if form exists

// Database-stored forms (admin UI)
fre_get_db_forms();                              // Get all stored forms
fre_get_db_form( $form_id );                     // Get single stored form
fre_save_db_form( $form_id, $title, $json );     // Save form to database
fre_delete_db_form( $form_id );                  // Delete form from database
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
  form-runtime-engine.php         # Main plugin file
  includes/
    Core/
      class-fre-registry.php      # Form registration
      class-fre-renderer.php      # HTML rendering
      class-fre-shortcode.php     # Shortcode handler
    Fields/                       # Field type classes
    Notifications/                # Email handling
    Security/                     # Spam protection
    Database/                     # Entry storage
    Admin/
      class-fre-admin.php         # Admin interface
      class-fre-forms-manager.php # Forms CRUD (JSON admin UI)
```

## Common Patterns (JSON)

These JSON examples are ready to paste into the admin UI.

### Simple Contact Form
```json
{
  "title": "Contact Us",
  "fields": [
    {"key": "name", "type": "text", "label": "Name", "required": true},
    {"key": "email", "type": "email", "label": "Email", "required": true},
    {"key": "phone", "type": "tel", "label": "Phone"},
    {"key": "message", "type": "textarea", "label": "Message", "required": true, "rows": 5}
  ],
  "settings": {
    "submit_button_text": "Send Message",
    "success_message": "Thanks! We'll be in touch soon.",
    "notification": {
      "reply_to": "{field:email}"
    }
  }
}
```

### Form with Columns and Sections
```json
{
  "title": "Registration Form",
  "fields": [
    {"key": "personal_info", "type": "section", "label": "Personal Information"},
    {"key": "first_name", "type": "text", "label": "First Name", "required": true, "section": "personal_info", "column": "1/2"},
    {"key": "last_name", "type": "text", "label": "Last Name", "required": true, "section": "personal_info", "column": "1/2"},
    {"key": "email", "type": "email", "label": "Email", "required": true, "section": "personal_info"},

    {"key": "address_info", "type": "section", "label": "Address"},
    {"key": "street", "type": "text", "label": "Street Address", "section": "address_info"},
    {"key": "city", "type": "text", "label": "City", "section": "address_info", "column": "1/2"},
    {"key": "zip", "type": "text", "label": "ZIP Code", "section": "address_info", "column": "1/2"}
  ]
}
```

### Appointment Booking Form (with Date and Address)
```json
{
  "title": "Book an Appointment",
  "fields": [
    {"key": "name", "type": "text", "label": "Full Name", "required": true},
    {"key": "email", "type": "email", "label": "Email", "required": true},
    {"key": "phone", "type": "tel", "label": "Phone Number", "required": true},
    {
      "key": "appointment_date",
      "type": "date",
      "label": "Preferred Date",
      "required": true,
      "min": "2024-01-01",
      "max": "2025-12-31",
      "description": "Select a date for your appointment"
    },
    {
      "key": "service_address",
      "type": "address",
      "label": "Service Address",
      "required": true,
      "placeholder": "Start typing your address...",
      "country_restriction": ["us"],
      "description": "Where should we come?"
    },
    {"key": "notes", "type": "textarea", "label": "Additional Notes", "rows": 4}
  ],
  "settings": {
    "submit_button_text": "Book Appointment",
    "success_message": "Your appointment request has been submitted. We'll confirm within 24 hours.",
    "notification": {
      "subject": "New Appointment Request for {field:appointment_date}",
      "reply_to": "{field:email}"
    }
  }
}
```

### Home Services Quote with Webhook
This example shows webhook integration for routing leads to Zapier/Make:
```json
{
  "title": "Request Service Quote",
  "fields": [
    {"key": "name", "type": "text", "label": "Name", "required": true},
    {"key": "email", "type": "email", "label": "Email", "required": true},
    {"key": "phone", "type": "tel", "label": "Phone", "required": true},
    {
      "key": "service",
      "type": "select",
      "label": "Service Needed",
      "required": true,
      "placeholder": "Select a service",
      "options": [
        {"value": "plumbing", "label": "Plumbing"},
        {"value": "electrical", "label": "Electrical"},
        {"value": "hvac", "label": "HVAC"},
        {"value": "general", "label": "General Handyman"}
      ]
    },
    {
      "key": "urgency",
      "type": "radio",
      "label": "Urgency",
      "inline": true,
      "options": [
        {"value": "emergency", "label": "Emergency (Today)"},
        {"value": "soon", "label": "This Week"},
        {"value": "flexible", "label": "Flexible"}
      ]
    },
    {
      "key": "address",
      "type": "address",
      "label": "Service Address",
      "required": true,
      "country_restriction": ["us"]
    },
    {"key": "details", "type": "textarea", "label": "Describe the Issue", "rows": 4}
  ],
  "settings": {
    "submit_button_text": "Get Quote",
    "success_message": "We'll contact you within 2 hours.",
    "webhook_enabled": true,
    "webhook_url": "https://hooks.zapier.com/hooks/catch/...",
    "notification": {
      "subject": "New {field:service} Request - {field:urgency}",
      "reply_to": "{field:email}"
    }
  }
}
```

### Form with Conditional Logic
```json
{
  "title": "Support Request",
  "fields": [
    {"key": "name", "type": "text", "label": "Name", "required": true},
    {"key": "email", "type": "email", "label": "Email", "required": true},
    {
      "key": "issue_type",
      "type": "select",
      "label": "Issue Type",
      "required": true,
      "placeholder": "Select an issue type",
      "options": [
        {"value": "billing", "label": "Billing Question"},
        {"value": "technical", "label": "Technical Issue"},
        {"value": "other", "label": "Other"}
      ]
    },
    {
      "key": "order_number",
      "type": "text",
      "label": "Order Number",
      "conditions": {
        "rules": [
          {"field": "issue_type", "operator": "equals", "value": "billing"}
        ]
      }
    },
    {
      "key": "browser",
      "type": "select",
      "label": "Browser",
      "options": [
        {"value": "chrome", "label": "Chrome"},
        {"value": "firefox", "label": "Firefox"},
        {"value": "safari", "label": "Safari"},
        {"value": "other", "label": "Other"}
      ],
      "conditions": {
        "rules": [
          {"field": "issue_type", "operator": "equals", "value": "technical"}
        ]
      }
    },
    {
      "key": "other_details",
      "type": "text",
      "label": "Please specify",
      "conditions": {
        "rules": [
          {"field": "issue_type", "operator": "equals", "value": "other"}
        ]
      }
    },
    {"key": "message", "type": "textarea", "label": "Describe your issue", "required": true, "rows": 5}
  ],
  "settings": {
    "submit_button_text": "Submit Request"
  }
}
```

### Complete Multi-Step Form (All Features)

This example demonstrates steps, sections, columns, and conditional logic working together:

```json
{
  "title": "Project Quote Request",
  "version": "1.0.0",
  "steps": [
    {"key": "contact", "title": "Your Information"},
    {"key": "project", "title": "Project Details"},
    {"key": "budget", "title": "Budget & Timeline"}
  ],
  "fields": [
    {"key": "contact_section", "type": "section", "label": "Contact Details", "step": "contact"},
    {"key": "first_name", "type": "text", "label": "First Name", "required": true, "step": "contact", "section": "contact_section", "column": "1/2"},
    {"key": "last_name", "type": "text", "label": "Last Name", "required": true, "step": "contact", "section": "contact_section", "column": "1/2"},
    {"key": "email", "type": "email", "label": "Email", "required": true, "step": "contact", "section": "contact_section"},
    {"key": "phone", "type": "tel", "label": "Phone", "step": "contact", "section": "contact_section"},
    {"key": "company", "type": "text", "label": "Company Name", "step": "contact"},

    {"key": "project_section", "type": "section", "label": "Tell Us About Your Project", "step": "project"},
    {
      "key": "service_type",
      "type": "select",
      "label": "Service Needed",
      "required": true,
      "step": "project",
      "section": "project_section",
      "placeholder": "Select a service",
      "options": [
        {"value": "website", "label": "Website Design"},
        {"value": "app", "label": "Mobile App"},
        {"value": "branding", "label": "Branding"},
        {"value": "other", "label": "Other"}
      ]
    },
    {
      "key": "other_service",
      "type": "text",
      "label": "Please describe the service",
      "step": "project",
      "section": "project_section",
      "conditions": {
        "rules": [
          {"field": "service_type", "operator": "equals", "value": "other"}
        ]
      }
    },
    {
      "key": "has_existing_site",
      "type": "radio",
      "label": "Do you have an existing website?",
      "step": "project",
      "section": "project_section",
      "inline": true,
      "options": [
        {"value": "yes", "label": "Yes"},
        {"value": "no", "label": "No"}
      ],
      "conditions": {
        "rules": [
          {"field": "service_type", "operator": "in", "value": ["website", "branding"]}
        ]
      }
    },
    {
      "key": "existing_url",
      "type": "text",
      "label": "Current Website URL",
      "step": "project",
      "section": "project_section",
      "placeholder": "https://",
      "conditions": {
        "rules": [
          {"field": "has_existing_site", "operator": "equals", "value": "yes"}
        ]
      }
    },
    {"key": "description", "type": "textarea", "label": "Project Description", "step": "project", "rows": 5},
    {"key": "files", "type": "file", "label": "Upload Reference Files", "step": "project", "multiple": true, "allowed_types": ["pdf", "doc", "docx", "jpg", "png"]},

    {"key": "budget_section", "type": "section", "label": "Budget & Timeline", "step": "budget"},
    {
      "key": "budget_range",
      "type": "radio",
      "label": "Budget Range",
      "required": true,
      "step": "budget",
      "section": "budget_section",
      "options": [
        {"value": "under5k", "label": "Under $5,000"},
        {"value": "5k-10k", "label": "$5,000 - $10,000"},
        {"value": "10k-25k", "label": "$10,000 - $25,000"},
        {"value": "over25k", "label": "Over $25,000"}
      ]
    },
    {
      "key": "timeline",
      "type": "select",
      "label": "Desired Timeline",
      "step": "budget",
      "section": "budget_section",
      "placeholder": "Select timeline",
      "options": [
        {"value": "asap", "label": "ASAP"},
        {"value": "1month", "label": "Within 1 month"},
        {"value": "3months", "label": "Within 3 months"},
        {"value": "flexible", "label": "Flexible"}
      ]
    },
    {
      "key": "how_heard",
      "type": "select",
      "label": "How did you hear about us?",
      "step": "budget",
      "placeholder": "Select an option",
      "options": [
        {"value": "google", "label": "Google Search"},
        {"value": "referral", "label": "Referral"},
        {"value": "social", "label": "Social Media"},
        {"value": "other", "label": "Other"}
      ]
    },
    {
      "key": "referral_name",
      "type": "text",
      "label": "Who referred you?",
      "step": "budget",
      "conditions": {
        "rules": [
          {"field": "how_heard", "operator": "equals", "value": "referral"}
        ]
      }
    },
    {"key": "additional_notes", "type": "textarea", "label": "Additional Notes", "step": "budget", "rows": 4}
  ],
  "settings": {
    "submit_button_text": "Submit Quote Request",
    "success_message": "Thank you! We'll review your project and get back to you within 24 hours.",
    "multistep": {
      "show_progress": true,
      "progress_style": "steps",
      "validate_on_next": true
    },
    "notification": {
      "subject": "New Quote Request: {field:service_type}",
      "reply_to": "{field:email}"
    }
  }
}
```

## Common Patterns (PHP)

For forms registered via code.

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

## Security Features

### CSS Validation

Custom CSS is validated and sanitized to prevent malicious code injection. The following patterns are **blocked**:

| Pattern | Reason |
|---------|--------|
| `expression()` | IE JavaScript execution via CSS expressions |
| `behavior:` | IE HTC files (HTML Components) |
| `-moz-binding:` | Firefox XBL bindings |
| `javascript:` | JavaScript URLs in CSS |
| `@import` | External stylesheet loading (data exfiltration risk) |
| `data:` | Data URIs (can contain embedded scripts) |
| `vbscript:` | VBScript URLs |
| `base64` | Base64 encoded content (often hides malicious code) |

**Syntax Validation:**
- Balanced braces `{ }` are required
- Balanced parentheses `( )` are required
- URLs with dangerous protocols are blocked

**Example Errors:**
```
"CSS contains potentially unsafe pattern: expression()"
"CSS contains potentially unsafe pattern: @import"
"Invalid CSS syntax: unbalanced braces. Check that all { have matching }."
```

### JSON Schema Validation

Form configurations are validated for structure and field types. This prevents invalid configurations and catches common errors early.

**Validated:**
- `fields` array is present and non-empty
- Each field has required `key` and `type` properties
- Field types are valid (text, email, tel, textarea, select, radio, checkbox, file, hidden, message, section, date, address)
- No duplicate field keys
- Select/radio fields have `options` array
- Steps configuration (if multi-step form)

**Warnings (non-fatal):**
- Unknown field properties (logged but allowed for flexibility)
- Unknown settings properties
- Invalid condition rule structure
- Fields referencing non-existent steps

**Example Errors:**
```
"Field at index 2 is missing required 'key' property."
"Invalid field type 'dropdown' for field 'country'. Valid types: text, email, tel, ..."
"Duplicate field keys found: email, name"
"Field 'service' requires an 'options' array."
```

### Allowed HTML in Message Fields

The `message` field type supports HTML content for display. The HTML is sanitized using WordPress's `wp_kses_post()` which allows safe tags:

**Allowed Tags:**
- Headings: `<h1>` - `<h6>`
- Paragraphs: `<p>`
- Lists: `<ul>`, `<ol>`, `<li>`
- Inline: `<strong>`, `<em>`, `<a>`, `<span>`, `<br>`
- Structural: `<div>`, `<blockquote>`

**Stripped:**
- `<script>`, `<style>`, `<iframe>` tags
- Event attributes (`onclick`, `onerror`, etc.)
- JavaScript URLs in `href`

### Spam Protection

Built-in spam protection (enabled by default):
- **Honeypot field** - Hidden field that bots fill out
- **Timing check** - Rejects submissions faster than 3 seconds
- **Rate limiting** - Max 5 submissions per hour per IP

Configure in form settings:
```php
'settings' => array(
    'spam_protection' => array(
        'honeypot'            => true,
        'timing_check'        => true,
        'min_submission_time' => 3,
        'rate_limit'          => array(
            'max'    => 5,
            'window' => 3600,
        ),
    ),
),
```

---

## Claude Code Workflow

When generating forms with Claude Code:

1. **Clarify the target:** Ask if the form will be:
   - Pasted into the **admin UI** → Provide JSON
   - Added via **code/hook** → Provide PHP code

2. **Generate appropriate format:**
   - **Admin UI:** JSON object starting with `{`
   - **PHP code:** Full PHP with `fre_register_form()` call wrapped in `fre_init` hook

3. **Provide shortcode:** `[fre_form id="form-id"]`

**Context clues for format selection:**
- User mentions "admin", "dashboard", "paste" → JSON
- User mentions "file", "code", "functions.php", "plugin" → PHP
- Ambiguous → Ask the user which method they prefer

### AI Generation Checklist

Before outputting a form, verify:

- [ ] Every field has a unique `key` (no duplicates)
- [ ] Field keys use `snake_case` format (e.g., `first_name`, not `firstName` or `first-name`)
- [ ] Every field has a `type` (valid: text, email, tel, textarea, select, radio, checkbox, file, hidden, message, section, date, address)
- [ ] Select, radio, and checkbox-group fields have an `options` array
- [ ] Date fields use YYYY-MM-DD format for `min`, `max`, and `default` values
- [ ] Address fields require Google Places API key to be configured in plugin settings
- [ ] Multi-step forms have a `steps` array at the top level
- [ ] Each field in a multi-step form has `"step": "step_key"` matching a defined step
- [ ] Sections are defined (type: section) before fields reference them with `"section": "section_key"`
- [ ] Conditional logic references existing field keys in the `"field"` property
- [ ] Column values are valid fractions: `1/2`, `1/3`, `2/3`, `1/4`, `3/4`
- [ ] `show_title` is only set to `true` if the title should display above the form
- [ ] Webhook URLs use HTTPS and are valid endpoints (Zapier, Make, custom)
- [ ] JSON syntax is valid (no trailing commas, proper quotes)

### Common Mistakes to Avoid

1. **Wrong hook (PHP):** Use `fre_init`, not `init` or `plugins_loaded`
2. **Duplicate field keys:** Every `key` must be unique across the entire form
3. **Missing options:** Select/radio/checkbox-group require `options` array
4. **Orphan step references:** Don't set `"step": "foo"` without defining `{"key": "foo", "title": "..."}` in `steps`
5. **Orphan section references:** Don't set `"section": "bar"` without a field `{"key": "bar", "type": "section"}`
6. **Condition referencing non-existent field:** The `"field"` in a condition rule must match an existing field key
7. **JSON trailing commas:** Unlike PHP arrays, JSON doesn't allow trailing commas

---

## Debugging Tips

If a form doesn't appear:

1. Enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php`
2. Check `wp-content/debug.log` for registration errors
3. Confirm you're using `fre_init` hook (not `init` or `plugins_loaded`)
4. Check for duplicate field keys in your config

```php
// Quick debug: verify form is registered
add_action( 'wp_footer', function() {
    if ( current_user_can( 'manage_options' ) ) {
        $forms = fre()->registry->get_all();
        echo '<!-- Registered forms: ' . implode( ', ', array_keys( $forms ) ) . ' -->';
    }
});
```

---

## Releasing New Versions

This plugin uses GitHub-based automatic updates. WordPress sites with this plugin installed will receive update notifications when new releases are published on GitHub.

### How Updates Work

1. The plugin checks GitHub for new releases periodically
2. When a newer version tag is found, WordPress shows an update notification
3. Users can update directly from the WordPress admin (Plugins → Updates)

### Version Locations

When releasing a new version, update the version number in **all** of these locations:

| File | Location | Example |
|------|----------|---------|
| `form-runtime-engine.php` | Plugin header (line ~6) | `Version: 1.1.0` |
| `form-runtime-engine.php` | `FRE_VERSION` constant (line ~25) | `define( 'FRE_VERSION', '1.1.0' );` |
| `CHANGELOG.md` | New entry at top | `## [1.1.0] - 2024-01-15` |

### Release Checklist

Follow these steps to create a new release:

1. **Update version numbers** in all locations listed above
2. **Update CHANGELOG.md** with new features, fixes, and changes
3. **Commit changes:**
   ```bash
   git add -A
   git commit -m "Release v1.1.0"
   ```
4. **Create a git tag** (must include `v` prefix):
   ```bash
   git tag v1.1.0
   ```
5. **Push to GitHub with tags:**
   ```bash
   git push origin main --tags
   ```
6. **Create GitHub Release** (choose one method):

   **Option A: GitHub CLI**
   ```bash
   gh release create v1.1.0 --title "v1.1.0" --notes "See CHANGELOG.md for details"
   ```

   **Option B: GitHub Web UI**
   - Go to repository → Releases → "Create a new release"
   - Select the tag you just pushed
   - Add release title and description
   - Publish

### CHANGELOG Format

Follow the [Keep a Changelog](https://keepachangelog.com/) format:

```markdown
## [1.1.0] - 2024-01-15

### Added
- New feature description

### Changed
- Modified behavior description

### Fixed
- Bug fix description
```

**Change Types:**
- `Added` - New features
- `Changed` - Changes in existing functionality
- `Deprecated` - Features to be removed in future
- `Removed` - Features removed in this release
- `Fixed` - Bug fixes
- `Security` - Security vulnerability fixes

### Version Numbering

Use [Semantic Versioning](https://semver.org/) (MAJOR.MINOR.PATCH):

| Type | When to Increment | Example |
|------|-------------------|---------|
| **MAJOR** | Breaking changes / incompatible API changes | 1.0.0 → 2.0.0 |
| **MINOR** | New features (backward compatible) | 1.0.0 → 1.1.0 |
| **PATCH** | Bug fixes (backward compatible) | 1.0.0 → 1.0.1 |

### Important Notes

- **Tag prefix:** Always use `v` prefix for tags (e.g., `v1.1.0`, not `1.1.0`)
- **GitHub Release required:** The update checker looks for GitHub Releases, not just tags
- **Version format:** Use three-part version numbers (MAJOR.MINOR.PATCH)
- **Testing:** Test the plugin thoroughly before releasing
- **Commit message:** Use clear commit messages like "Release v1.1.0"
