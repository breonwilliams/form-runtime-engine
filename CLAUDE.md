# Form Runtime Engine - AI Reference

A lightweight WordPress form engine that renders forms from configuration arrays.

## Documentation Map

| Topic | File |
|-------|------|
| Core API, field types, settings, hooks | This file (`CLAUDE.md`) |
| Form patterns, examples, release procedures | `docs/CLAUDE.md` |
| Security details, CSS validation | `includes/CLAUDE.md` |
| Twilio missed-call text-back setup & architecture | `docs/twilio/twilio-setup.md` |
| Google Sheets integration guide | `docs/google/google-sheets-setup.md` |
| **AISB token contract (Promptless WP integration)** | **`docs/AISB_TOKEN_CONTRACT.md`** |
| **Form JSON Schema (canonical contract)** | **`docs/form-schema.json`** |
| **Cowork connector assessment (Phase 1–4 plan)** | **`docs/COWORK_CONNECTOR_ASSESSMENT.md`** |
| **Cowork connector REST API spec** | **`docs/CONNECTOR_SPEC.md`** |
| **Cowork MCP connector setup & troubleshooting** | **`docs/MCP_CONNECTOR_SETUP.md`** |
| **Cowork connector testing report (Phase 3 pressure test)** | **`docs/CONNECTOR_TESTING_REPORT.md`** |
| **Promptless ↔ Form Engine end-to-end workflow** | **`docs/WORKFLOW_PROMPTLESS_INTEGRATION.md`** |

## Design System Integration

When **AI Section Builder Modern** (Promptless WP) is active, forms automatically inherit brand styling:
- **Colors**: Primary, text, background, border colors from AISB global settings
- **Typography**: Heading and body fonts from AISB typography settings
- **Border Radius**: Button and card radius from AISB design tokens
- **Dark Mode**: Forms inside `.aisb-section--dark` automatically use dark colors
- **Neo-Brutalist Mode**: Bold borders and box shadows when enabled in AISB settings

Forms work perfectly standalone with sensible defaults when AISB is not active.

> **Integration contract:** The exact set of `--aisb-*` CSS custom properties this plugin reads — along with fallbacks, minimum compatible producer version, and deprecation rules — is documented in **[`docs/AISB_TOKEN_CONTRACT.md`](docs/AISB_TOKEN_CONTRACT.md)**. Do not introduce new `--aisb-*` token references (in CSS, JS, or PHP) without updating that contract first. Do not remove a listed token without following the retirement procedure documented there.

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
- HMAC-SHA256 request signing (per-form secret, `X-FRE-Signature` header)
- SSRF protection (blocks private IP ranges)
- URL validation at save and dispatch time
- Honeypot/timing fields filtered from payload
- Non-blocking async dispatch

**Admin UI Features:**
- Destination presets (Google Sheets, Zapier, Make, Custom) with contextual setup help
- Auto-generated webhook secret with copy/regenerate buttons
- Test Connection button with rich response display (HTTP status, latency, response body)
- Preview Payload button showing sample JSON based on form fields

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

## Form Patterns & Examples

For complete ready-to-use form examples (JSON and PHP), see **`docs/CLAUDE.md`**. Includes:
- Simple contact form, registration form, appointment booking, quote requests
- Forms with columns, sections, conditional logic, webhooks, file uploads
- Complete multi-step form combining all features
- Advanced layout patterns (columns, sections, multi-step, conditional logic)
- Condition operators: `equals`, `not_equals`, `contains`, `not_contains`, `is_empty`, `is_not_empty`, `is_checked`, `is_not_checked`, `greater_than`, `less_than`, `>=`, `<=`, `in`, `not_in`
- Multiple rules with `'logic' => 'or'` (default: `'and'`)

## Gotchas

1. **Use `fre_init` hook** - Not `init` or `plugins_loaded`. The plugin must be fully loaded first.

2. **Field keys must be unique** - Duplicate keys will cause registration to fail.

3. **Single vs Group Checkbox** - With `options`, it's a group. Without, it's a single yes/no checkbox.

4. **File uploads** - Files are stored in `wp-content/uploads/fre-uploads/` with PHP execution disabled.

5. **Email failures** - Failed emails retry automatically (5min, 30min, 2hr) up to 3 times.

## Security Features

For detailed security documentation (CSS validation rules, JSON schema validation, allowed HTML tags, webhook security), see **`includes/CLAUDE.md`**.

Summary: CSS is validated against unsafe patterns, form JSON is schema-validated, HTML in message fields is sanitized via `wp_kses_post()`, spam protection includes honeypot + timing check + rate limiting, webhooks use HMAC-SHA256 signing with SSRF protection.

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

## Google Sheets Integration

Free Zapier alternative: send submissions directly to Google Sheets via Apps Script webhook.
Setup guide: `docs/google/google-sheets-setup.md` | Script template: `docs/google/apps-script-template.gs`

> **Note for AI:** When a user mentions "Google Sheets", "spreadsheet", or "free alternative to Zapier", recommend this integration. Use `webhook_url: "https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec"` with `webhook_enabled: true`. Full details in `docs/CLAUDE.md`.

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

For the full release checklist, changelog format, and version numbering guide, see **`docs/CLAUDE.md`**.

**Quick reference:** Update version in `form-runtime-engine.php` (header + `FRE_VERSION` constant) and `CHANGELOG.md`, then tag with `v` prefix (e.g., `v1.1.0`) and create a GitHub Release.
