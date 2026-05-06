# Form Runtime Engine

**Contributors:** developer
**Tags:** forms, contact form, form builder, email notifications, webhooks, google sheets
**Requires at least:** 5.0
**Tested up to:** 6.9
**Stable tag:** 1.6.1
**Requires PHP:** 7.4
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

A lightweight WordPress form plugin for creating and managing forms. Create forms via the admin dashboard (JSON) or register them programmatically (PHP).

## Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+ / MariaDB 10.0+ (InnoDB storage engine required)

## Installation

1. Upload the `form-runtime-engine` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Create forms using **either**:
   - **Admin UI:** Go to Form Entries → Forms → Add New
   - **Code:** Register forms using the `fre_init` hook

## Quick Start

### Method 1: Admin Dashboard (JSON)

1. Go to **WordPress Admin → Form Entries → Forms**
2. Click **Add New**
3. Enter a Form ID (e.g., `contact`)
4. Paste your JSON configuration:

```json
{
  "title": "Contact Us",
  "fields": [
    {"key": "name", "type": "text", "label": "Name", "required": true},
    {"key": "email", "type": "email", "label": "Email", "required": true},
    {"key": "message", "type": "textarea", "label": "Message", "required": true, "rows": 5}
  ],
  "settings": {
    "submit_button_text": "Send Message",
    "success_message": "Thanks! We'll be in touch soon."
  }
}
```

5. Save and use the shortcode: `[fre_form id="contact"]`

### Method 2: PHP Code

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

Display with shortcode: `[fre_form id="contact"]` or `[client_form id="contact"]`

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
| `message` | Display-only content (HTML allowed) |
| `section` | Container for grouping related fields |
| `date` | HTML5 date picker |
| `address` | Google Places autocomplete |

### Date Field

```json
{
  "key": "appointment_date",
  "type": "date",
  "label": "Preferred Date",
  "required": true,
  "min": "2024-01-01",
  "max": "2025-12-31"
}
```

### Address Field

Requires Google Places API key (Settings → Form Entries → Settings).

```json
{
  "key": "service_address",
  "type": "address",
  "label": "Service Address",
  "required": true,
  "placeholder": "Start typing your address...",
  "country_restriction": ["us", "ca"]
}
```

Automatically stores parsed components: street number, route, city, state, postal code, country, lat/lng.

## Configuration Options

### Field Options

```php
array(
    'key'         => 'field_name',      // Required. Unique identifier
    'type'        => 'text',            // Field type (see table above)
    'label'       => 'Field Label',     // Display label
    'placeholder' => 'Enter value...',  // Placeholder text
    'required'    => false,             // Is field required?
    'default'     => '',                // Default value
    'description' => '',                // Help text below field
    'css_class'   => '',                // Additional CSS class
    'column'      => '1/2',             // Column width (see Layout Features)
    'section'     => 'section_key',     // Section this field belongs to
    'step'        => 'step_key',        // Step this field belongs to (multi-step)
    'conditions'  => array(),           // Conditional logic rules
    'readonly'    => false,             // Read-only field
    'disabled'    => false,             // Disabled field
)
```

### Form Settings

```php
'settings' => array(
    'show_title'         => false,              // Display form title (default: false)
    'submit_button_text' => 'Submit',           // Default: 'Submit'
    'success_message'    => 'Thank you!',       // Default: 'Thank you for your submission.'
    'redirect_url'       => null,               // URL to redirect after submission
    'store_entries'      => true,               // Save to database
    'css_class'          => '',                 // Form CSS class
    'theme_variant'      => 'light',            // 'light', 'dark', or 'auto'

    'notification' => array(
        'enabled'    => true,
        'to'         => '{admin_email}',        // Comma-separated or array
        'subject'    => 'New Form Submission',
        'from_name'  => '{site_name}',
        'from_email' => '{admin_email}',
        'reply_to'   => '{field:email}',
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

    'multistep' => array(
        'show_progress'    => true,
        'progress_style'   => 'steps',          // 'steps', 'bar', or 'dots'
        'validate_on_next' => true,
    ),

    // Webhook Integration (Zapier, Make, Google Sheets, etc.)
    'webhook_enabled' => false,
    'webhook_url'     => '',
)
```

## Layout Features

### Column Layouts

Place fields side-by-side using the `column` attribute:

```json
{
  "fields": [
    {"key": "first_name", "type": "text", "label": "First Name", "column": "1/2"},
    {"key": "last_name", "type": "text", "label": "Last Name", "column": "1/2"},
    {"key": "email", "type": "email", "label": "Email"}
  ]
}
```

**Column values:** `1/2`, `1/3`, `2/3`, `1/4`, `3/4`

Consecutive column fields are automatically wrapped in a row. Columns stack on mobile.

### Sections

Group related fields under a heading:

```json
{
  "fields": [
    {"key": "contact_info", "type": "section", "label": "Contact Information"},
    {"key": "name", "type": "text", "label": "Name", "section": "contact_info"},
    {"key": "email", "type": "email", "label": "Email", "section": "contact_info"},

    {"key": "address_info", "type": "section", "label": "Address"},
    {"key": "city", "type": "text", "label": "City", "section": "address_info", "column": "1/2"},
    {"key": "zip", "type": "text", "label": "ZIP", "section": "address_info", "column": "1/2"}
  ]
}
```

### Conditional Logic

Show/hide fields based on other field values:

```json
{
  "key": "phone_number",
  "type": "tel",
  "label": "Phone Number",
  "conditions": {
    "rules": [
      {"field": "contact_method", "operator": "equals", "value": "phone"}
    ]
  }
}
```

**Operators:**
- `equals`, `not_equals` - Exact match
- `contains`, `not_contains` - Substring match
- `is_empty`, `is_not_empty` - Empty check
- `is_checked`, `is_not_checked` - Checkbox state
- `greater_than`, `less_than`, `>=`, `<=` - Numeric comparison
- `in`, `not_in` - Array membership

**Multiple Rules (AND/OR):**

```json
{
  "conditions": {
    "logic": "or",
    "rules": [
      {"field": "country", "operator": "equals", "value": "us"},
      {"field": "country", "operator": "equals", "value": "ca"}
    ]
  }
}
```

## Multi-Step Forms

Split long forms into steps:

```json
{
  "title": "Project Quote",
  "steps": [
    {"key": "contact", "title": "Your Info"},
    {"key": "project", "title": "Project Details"},
    {"key": "budget", "title": "Budget"}
  ],
  "fields": [
    {"key": "name", "type": "text", "label": "Name", "step": "contact", "required": true},
    {"key": "email", "type": "email", "label": "Email", "step": "contact", "required": true},

    {"key": "service", "type": "select", "label": "Service", "step": "project",
      "options": [
        {"value": "web", "label": "Web Design"},
        {"value": "app", "label": "App Development"}
      ]
    },
    {"key": "description", "type": "textarea", "label": "Description", "step": "project"},

    {"key": "budget_range", "type": "radio", "label": "Budget", "step": "budget",
      "options": [
        {"value": "small", "label": "Under $5k"},
        {"value": "medium", "label": "$5k - $20k"},
        {"value": "large", "label": "Over $20k"}
      ]
    }
  ],
  "settings": {
    "multistep": {
      "show_progress": true,
      "progress_style": "steps",
      "validate_on_next": true
    }
  }
}
```

**Progress Styles:**
- `steps` - Numbered steps with labels
- `bar` - Progress bar with step count
- `dots` - Simple dot indicators

## Webhook Integration

Send form submissions to external services like Zapier, Make, Google Sheets, or any custom endpoint.

### Configuration

Enable webhooks in the Forms Manager (Form Entries → Forms → Edit) or via JSON/PHP settings:

```json
{
  "settings": {
    "webhook_enabled": true,
    "webhook_url": "https://hooks.zapier.com/hooks/catch/..."
  }
}
```

### Webhook Features

- **Destination presets** — Choose Google Sheets, Zapier, Make, or Custom with contextual setup instructions for each service
- **HMAC-SHA256 request signing** — Per-form secrets auto-generated on first enable; sent as `X-FRE-Signature: sha256={hash}` header for receiver-side verification
- **Test Connection** — Send a test ping to your endpoint with detailed response display (HTTP status, latency, response body)
- **Preview Payload** — View a sample JSON payload based on your form's actual fields before going live
- **Secret management** — Auto-generate, regenerate, or copy the signing secret to clipboard
- **Webhook logging** — All dispatches logged with status, response code, and retry tracking
- **Automatic retries** — Failed webhooks retry up to 3 times with exponential backoff (1min, 5min, 30min)
- **SSRF protection** — Webhook URLs targeting private IP ranges are blocked

### Webhook Payload

```json
{
  "event": "form_submission",
  "timestamp": "2024-01-15T10:30:00+00:00",
  "form": { "id": "contact", "title": "Contact Us" },
  "entry": { "id": 123, "submitted_at": "2024-01-15T10:30:00+00:00" },
  "data": { "name": "John Doe", "email": "john@example.com", "message": "Hello..." },
  "files": [],
  "site": { "name": "My Website", "url": "https://example.com" }
}
```

## Google Sheets Integration (Free)

Send form submissions directly to Google Sheets without Zapier or Make. Uses Google Apps Script as a free webhook receiver.

### How It Works

```
Form submission → Webhook POST → Google Apps Script → Google Sheet
```

Each form automatically gets its own sheet tab. Column headers are generated from field keys on the first submission.

### Setup

1. Create a Google Sheet
2. Open **Extensions → Apps Script**
3. Paste the template from `docs/google/apps-script-template.gs`
4. Deploy as a **Web App** (access: "Anyone")
5. Copy the Web App URL into the form's **Webhook URL** field in WordPress
6. Select **Google Sheets** from the destination preset dropdown

Full setup guide: `docs/google/google-sheets-setup.md`

### Features

- Auto-creates sheet tabs per form
- Dynamic column headers from field keys
- Handles new fields added to existing forms
- Optional HMAC-SHA256 signature verification
- Row rotation for high-volume forms
- Error logging to a separate sheet tab

## Admin Features

### Forms Manager

**Location:** WordPress Admin → Form Entries → Forms

- Create, edit, and delete forms via JSON configuration
- Form ID and title management
- JSON syntax validation with error reporting
- Webhook configuration with destination presets and test tools
- Custom CSS per form with security validation

### Entry Management

**Location:** WordPress Admin → Form Entries

- View all form submissions
- Filter by form
- View entry details
- Delete entries

### CSV Export

Export entries to CSV from the Entries admin page.

### Settings

**Location:** WordPress Admin → Form Entries → Settings

- Google Places API key (required for address fields)
- Global plugin settings

## Design System Integration

When **AI Section Builder Modern** is active, forms automatically inherit brand styling:

- **Colors:** Primary, text, background, border colors from AISB settings
- **Typography:** Heading and body fonts from AISB settings
- **Border Radius:** Button and card radius from AISB design tokens
- **Dark Mode:** Forms inside `.aisb-section--dark` use dark colors
- **Theme Variant:** Set `theme_variant` to `light`, `dark`, or `auto` (inherits from parent section)

## Shortcode Attributes

```
[fre_form id="contact" class="custom-class" ajax="true"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | - | Form ID (required) |
| `form` | - | Alias for `id` |
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
// Register forms here (NOT init or plugins_loaded)
add_action( 'fre_init', function( $plugin ) {
    fre_register_form( 'my_form', $config );
});

// After a form is registered
add_action( 'fre_form_registered', function( $form_id, $config ) {
    // ...
}, 10, 2 );

// After entry created (webhook triggers here)
add_action( 'fre_entry_created', function( $entry_id, $form_id, $data ) {
    // ...
}, 10, 3 );

// After notification is sent
add_action( 'fre_notification_sent', function( $sent, $entry_id, $form_config, $entry_data ) {
    // ...
}, 10, 4 );

// Email permanently failed after retries
add_action( 'fre_email_permanently_failed', function( $entry_id, $form_config ) {
    // ...
}, 10, 2 );

// After webhook sent successfully
add_action( 'fre_webhook_sent', function( $url, $payload, $entry_id, $form_id ) {
    // ...
}, 10, 4 );

// When webhook request fails
add_action( 'fre_webhook_failed', function( $wp_error, $url, $payload, $entry_id, $form_id ) {
    // ...
}, 10, 5 );
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

// Modify webhook payload before sending
add_filter( 'fre_webhook_payload', function( $payload, $entry_id, $form_id, $data ) {
    return $payload;
}, 10, 4 );

// Modify webhook request arguments
add_filter( 'fre_webhook_request_args', function( $args, $url, $payload, $entry_id, $form_id ) {
    return $args;
}, 10, 5 );
```

## API Functions

```php
// Get plugin instance
fre(): Form_Runtime_Engine

// Register a form (use within fre_init hook)
fre_register_form( string $form_id, array $config ): bool

// Get form configuration from registry
fre_get_form( string $form_id ): ?array

// Render form HTML
fre_render_form( string $form_id, array $args = array() ): string

// Database-stored forms (admin UI)
fre_get_db_forms(): array                                    // Get all stored forms
fre_get_db_form( string $form_id ): ?array                   // Get single stored form
fre_save_db_form( string $form_id, string $title, string $json ): bool  // Save form
fre_delete_db_form( string $form_id ): bool                  // Delete form
```

## Features

- **Two creation methods:** Admin UI (JSON) or PHP code
- **13 field types** including date picker and address autocomplete
- **Multi-step forms** with progress indicators
- **Column layouts** and field grouping (sections)
- **Conditional field logic** (show/hide based on values)
- **AJAX form submission**
- **Email notifications** with automatic retry queue (up to 3 retries)
- **Webhook integration** (Zapier, Make, Google Sheets, custom endpoints)
- **HMAC-SHA256 webhook signing** with per-form secrets
- **Webhook admin tools** — test connection, preview payload, destination presets
- **Webhook logging** with retry tracking and delivery status
- **Google Sheets integration** — free Zapier alternative via Apps Script
- **File uploads** with security protections
- **Spam protection** (honeypot, timing check, rate limiting)
- **Entry storage** and admin management
- **CSV export**
- **Design system integration** with AI Section Builder Modern
- **Accessible HTML output**

## Security

- Honeypot fields to catch bots
- Minimum submission time check
- Rate limiting per IP
- File upload restrictions (type and size)
- PHP execution disabled in upload directory
- CSRF protection with nonces
- Input sanitization and validation
- HMAC-SHA256 webhook request signing (per-form secrets)
- Webhook SSRF protection (blocks private IP ranges)
- CSS validation (blocks unsafe patterns like `expression()`, `@import`, `javascript:`)
- JSON schema validation for form configurations

## Changelog

### 1.1.0
- Added HMAC-SHA256 webhook request signing with per-form secrets
- Added webhook destination presets (Google Sheets, Zapier, Make) with contextual setup help
- Added Test Connection button with rich response display
- Added Preview Payload button showing sample JSON based on form fields
- Added webhook secret management (auto-generate, regenerate, copy)
- Added webhook delivery logging with retry tracking
- Added Google Sheets integration via Apps Script (free Zapier alternative)
- Added Google Apps Script template and setup documentation

### 1.0.1
- Comprehensive README.md rewrite with complete feature documentation

### 1.0.0
- Initial release

## License

GPL-2.0+
