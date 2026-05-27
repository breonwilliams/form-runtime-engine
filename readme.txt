=== Promptless Forms ===
Contributors: promptlesswp
Tags: forms, contact-form, form-builder, webhook, lightweight
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.7.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight forms with webhooks, multi-step support, and conditional logic. Inherits brand styling when Promptless WP is active.

== Description ==

Promptless Forms is a lightweight WordPress form plugin built for developers and design-system-aware sites. Configure forms via JSON in the admin UI or via PHP code, render them with a shortcode, and send submissions to webhooks, email, or both.

**Core features**

* JSON or PHP form configuration
* 13 field types: text, email, tel, textarea, select, radio, checkbox, file, hidden, message, section, date, and address (with Google Places autocomplete)
* Multi-step forms with progress indicators
* Conditional field visibility based on other field values
* Column layouts (1/2, 1/3, 2/3, 1/4, 3/4) and section grouping
* Webhook delivery with HMAC-SHA256 signing and SSRF protection
* Email notifications with template variables
* Spam protection: honeypot, timing check, and rate limiting
* CSV export of form entries
* File upload handling with magic-byte verification

**Integration**

When the Promptless WP plugin is active, Promptless Forms automatically inherits design tokens (colors, typography, border radius) from the global brand settings. Forms also support dark mode through Promptless's `theme_variant` setting. The plugin works fully standalone with sensible defaults when Promptless WP is not active.

**For developers**

* Stable hook surface for extending behavior (`fre_submission_complete`, `fre_webhook_payload`, `fre_field_display_value`, etc.)
* Connector REST API for external integration (default-disabled, opt-in via admin toggle)
* WordPress coding standards compliant
* Transactional InnoDB storage for entries

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/promptless-forms/` or install through the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Form Entries** in your WordPress admin menu to create your first form.

For developer documentation including JSON configuration examples, hook reference, and integration patterns, see the plugin's GitHub repository.

== Frequently Asked Questions ==

= Does this plugin require Promptless WP? =

No. Promptless Forms works fully standalone with sensible default styling. When Promptless WP is active, forms automatically inherit your brand colors, typography, and design tokens.

= How do I display a form on a page? =

Use the shortcode `[fre_form id="your-form-id"]` on any post, page, or widget. The `id` matches the form ID you set when creating the form in the admin.

= Can I send form submissions to Zapier, Make, or Google Sheets? =

Yes. Each form can be configured with a webhook URL in the Forms Manager. Submissions are signed with HMAC-SHA256 for verification on the receiving end. The plugin supports preset configurations for common destinations including Zapier, Make, and Google Sheets (via Apps Script).

= Does the plugin support multi-step forms? =

Yes. Define a `steps` array in your form configuration and assign each field to a step via the `step` attribute. The plugin renders a progress indicator and handles step navigation automatically.

= Where are uploaded files stored? =

Uploaded files are stored in `/wp-content/uploads/fre-uploads/` with PHP execution disabled. Filenames are randomized (UUIDs) to prevent guessing. File types are validated by extension AND magic byte signature.

== External Services ==

This plugin can optionally connect to external services. Each service is opt-in and requires explicit configuration by a site administrator.

**Webhooks** (when configured per-form):
Submission data is POSTed to a URL of your choosing. The URL, request payload, and signing secret are all configured by the site administrator on a per-form basis. No data is sent until a webhook URL is configured.

**Google Places API** (when the Address field is used):
The Address field uses Google's Places API to provide address autocomplete. Requires a Google Places API key configured in the plugin settings. Address queries are sent to Google when users type in the field. See [Google's Places API Terms](https://developers.google.com/maps/terms) and [Privacy Policy](https://policies.google.com/privacy).

**Twilio API** (when configured):
The optional Twilio module sends SMS messages and handles missed-call text-back workflows. Requires Twilio account credentials configured in the plugin settings. Phone numbers and message content are sent to Twilio for delivery. See [Twilio's Terms of Service](https://www.twilio.com/legal/tos) and [Privacy Policy](https://www.twilio.com/legal/privacy).

**Claude Cowork Connector** (default-disabled, opt-in):
The Connector exposes a REST API allowing AI agents (such as Anthropic's Claude Cowork) to manage forms via WordPress Application Passwords. Default state is disabled. To enable, an administrator must explicitly toggle the connector on in **Form Entries → Claude Connection** and generate a per-user Application Password. No external requests are made by the connector — it only responds to authenticated incoming requests.

== Screenshots ==

1. Forms Manager admin UI — paste JSON to define a form, or generate one with an AI assistant
2. A rendered contact form on the frontend, automatically inheriting Promptless WP brand styling when present
3. Entries dashboard with per-submission detail view and CSV export
4. Webhook configuration with destination presets (Zapier, Make, Google Sheets) and Test Connection diagnostics
5. Claude Cowork connector setup screen — default-disabled, opt-in App Password generation

== Changelog ==

See CHANGELOG.md in the plugin folder or visit the GitHub repository for full release notes.

== Upgrade Notice ==

= 1.7.0 =
WP.org compliance release. The Custom CSS form-setting is removed — use theme CSS or a CSS plugin instead. `[client_form]` is replaced by `[fre_form]` and `[promptless_form]`; update old tags. Form data, entries, webhooks, and css_class are unaffected.

= 1.6.5 =
Routine maintenance release.
