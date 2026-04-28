# Form Runtime Engine - Examples & Reference

This file contains form patterns, advanced layout examples, integration guides, and release procedures.
For core API reference, field types, and settings, see the root `CLAUDE.md`.
For Twilio missed-call text-back setup, architecture, and troubleshooting, see `docs/twilio/twilio-setup.md`.

---

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

### Pattern: CRM-bound form with structurally-consistent emails

Use case: a B2B intake form whose submissions feed a CRM via Zapier. The internal sales team also wants the notification email to look identical for every prospect — every field rendered, even unfilled ones — so they can scan the email at a glance without missing anything. Two settings drive this:

- `hide_empty_fields: false` — render every field with an em-dash (`—`) placeholder for empty values instead of skipping (matches the visual structure across submissions; required when emails feed downstream tooling that expects a fixed table shape).
- `webhook_resolve_option_labels: true` — force option-label resolution in the webhook payload so the CRM stores `"Home services (HVAC, plumbing, roofing, etc.)"` instead of `"home_services"`. Useful when the CRM's view of the data is inspected by humans (sales, support) rather than filtered by other software.

```json
{
  "title": "Intake — High-Touch Onboarding",
  "fields": [
    {"key": "name", "type": "text", "label": "Full name", "required": true, "column": "1/2"},
    {"key": "email", "type": "email", "label": "Work email", "required": true, "column": "1/2"},
    {"key": "company", "type": "text", "label": "Company", "required": true},
    {
      "key": "business_type",
      "type": "select",
      "label": "Business type",
      "required": true,
      "options": [
        {"value": "saas", "label": "SaaS / Software"},
        {"value": "agency", "label": "Agency / Consulting"},
        {"value": "ecom", "label": "E-commerce / DTC"},
        {"value": "services", "label": "Professional services"}
      ]
    },
    {"key": "team_size", "type": "select", "label": "Team size",
     "options": [
       {"value": "solo", "label": "Just me"},
       {"value": "2_10", "label": "2–10"},
       {"value": "11_50", "label": "11–50"},
       {"value": "50_plus", "label": "50+"}
     ]},
    {"key": "notes", "type": "textarea", "label": "Anything specific?", "rows": 4}
  ],
  "settings": {
    "hide_empty_fields": false,
    "webhook_enabled": true,
    "webhook_url": "https://hooks.zapier.com/...",
    "webhook_preset": "zapier",
    "webhook_resolve_option_labels": true,
    "notification": {
      "enabled": true,
      "to": "sales@example.com",
      "subject": "New intake from {field:name} ({field:business_type})"
    }
  }
}
```

A SaaS prospect who skips `team_size` and `notes` produces this email body:

| Field | Value |
|---|---|
| Full name | Maya Chen |
| Work email | maya@company.com |
| Company | ExampleCo |
| Business type | SaaS / Software |
| Team size | — |
| Anything specific? | — |

…and this Zapier payload `data` block (raw text fields, resolved option labels because the form opted in):

```json
"data": {
  "name": "Maya Chen",
  "email": "maya@company.com",
  "company": "ExampleCo",
  "business_type": "SaaS / Software",
  "team_size": "",
  "notes": ""
}
```

Default behavior (omit both settings) skips empty rows from the email and emits raw `business_type: "saas"` to Zapier — fine for most lead capture, but this pattern is the right shape when downstream consumers expect human-readable values.

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

---

## Google Sheets Integration (Free Zapier Alternative)

Form Runtime Engine supports sending submissions directly to Google Sheets via Google Apps Script. This is a free alternative to Zapier for projects that just need form data in a spreadsheet.

### How It Works

The plugin's existing webhook feature sends a JSON payload to a Google Apps Script web app endpoint, which writes the data to a Google Sheet. No OAuth, no API keys, no recurring costs.

```
Form submission → Webhook POST → Google Apps Script → Google Sheet
```

### Setup

1. Create a Google Sheet
2. Open Extensions → Apps Script
3. Paste the template from `docs/google/apps-script-template.gs`
4. Deploy as a Web App (access: "Anyone")
5. Copy the URL into the form's Webhook URL field in WordPress

Full guide: `docs/google/google-sheets-setup.md`

### Key Details for AI-Generated Forms

When generating forms that will use Google Sheets:
- The webhook payload is already compatible — no special configuration needed
- Each form automatically gets its own sheet tab
- Column headers are generated from field keys, so **consistent field naming matters**
- Use the standard field naming conventions from this document (e.g., `name`, `email`, `phone`)
- Checkbox groups are joined with commas in the spreadsheet

### Webhook Configuration for Google

```json
{
  "settings": {
    "webhook_enabled": true,
    "webhook_url": "https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec"
  }
}
```

Or in PHP:
```php
'settings' => array(
    'webhook_enabled' => true,
    'webhook_url'     => 'https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec',
),
```

> **Note for AI:** When a user mentions "Google Sheets", "spreadsheet", or "free alternative to Zapier", recommend the Google Apps Script integration and reference the setup guide at `docs/google/google-sheets-setup.md`.

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

### Private Repository Setup

This plugin uses a private GitHub repository. For automatic updates to work on
any WordPress site, a GitHub Personal Access Token must be configured:

1. Go to GitHub → Settings → Developer Settings → Personal Access Tokens → Fine-grained tokens
2. Create a token with **Contents: Read** permission for the `breonwilliams/form-runtime-engine` repo
3. Add the token to `wp-config.php` on the WordPress site:
   ```php
   define( 'FRE_GITHUB_TOKEN', 'github_pat_your_token_here' );
   ```

### Release Checklist

Follow these steps to create a new release:

1. **Update version numbers** in all locations listed above (plus `README.md` Stable tag)
2. **Update CHANGELOG.md** with new features, fixes, and changes
3. **Commit changes:**
   ```bash
   git add -A
   git commit -m "Release v1.2.0"
   ```
4. **Create a git tag** (must include `v` prefix):
   ```bash
   git tag v1.2.0
   ```
5. **Push to GitHub with tags:**
   ```bash
   git push origin main --tags
   ```
6. **Build the release ZIP** (ensures correct folder name for WordPress):
   ```bash
   ./bin/build-release.sh
   ```
7. **Create GitHub Release and attach the ZIP:**
   ```bash
   gh release create v1.2.0 build/form-runtime-engine.zip --title "v1.2.0" --notes "See CHANGELOG.md for details"
   ```

> **Important:** Always use the build script to create the zip and attach it to
> the release. GitHub's auto-generated zipball uses a folder name like
> `breonwilliams-form-runtime-engine-abc1234/` which causes WordPress to create
> a duplicate plugin instead of replacing the existing one.

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
