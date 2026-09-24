=== Promptless Forms ===
Contributors: promptlesswp
Tags: forms, contact-form, form-builder, webhook, lightweight
Requires at least: 5.6
Tested up to: 7.1
Stable tag: 1.12.0
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

* Stable hook surface for extending behavior (`pforms_submission_complete`, `pforms_webhook_payload`, `pforms_field_display_value`, etc.)
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

Use the shortcode `[promptless_form id="your-form-id"]` on any post, page, or widget. The `id` matches the form ID you set when creating the form in the admin.

= Can I send form submissions to Zapier, Make, or Google Sheets? =

Yes. Each form can be configured with a webhook URL in the Forms Manager. Submissions are signed with HMAC-SHA256 for verification on the receiving end. The plugin supports preset configurations for common destinations including Zapier, Make, and Google Sheets (via Apps Script).

= Does the plugin support multi-step forms? =

Yes. Define a `steps` array in your form configuration and assign each field to a step via the `step` attribute. The plugin renders a progress indicator and handles step navigation automatically.

= Where are uploaded files stored? =

Uploaded files are added to the Media Library in the normal uploads folder (`/wp-content/uploads/YYYY/MM/`) under a random name, so they cannot be guessed. Before a file is saved, it waits in a protected holding folder while it is checked: the extension must be allowed, the real file type must match, the file header must be right, images must open, and the file may not contain PHP code. SVG files are also checked for scripts.

== External Services ==

This plugin can optionally connect to external services. Each service is opt-in and requires explicit configuration by a site administrator.

**Webhooks** (when configured per-form):
Submission data is POSTed to a URL of your choosing. The URL, request payload, and signing secret are all configured by the site administrator on a per-form basis. No data is sent until a webhook URL is configured.

**Google Places API** (when the Address field is used):
The Address field uses Google's Places API to provide address autocomplete. Requires a Google Places API key configured in the plugin settings. Address queries are sent to Google when users type in the field. See [Google's Places API Terms](https://developers.google.com/maps/terms) and [Privacy Policy](https://policies.google.com/privacy).

**Twilio API** (when configured):
The optional Twilio module sends SMS messages and handles missed-call text-back workflows. Requires Twilio account credentials configured in the plugin settings. Phone numbers and message content are sent to Twilio for delivery. See [Twilio's Terms of Service](https://www.twilio.com/legal/tos) and [Privacy Policy](https://www.twilio.com/legal/privacy).

**Claude Cowork Connector** (default-disabled, opt-in):
The Connector exposes a REST API allowing AI agents (such as Anthropic's Claude Cowork) to manage forms via WordPress Application Passwords. Default state is disabled. To enable, an administrator must explicitly toggle the connector on in **Form Entries → Connector** and generate a per-user Application Password. No external requests are made by the connector — it only responds to authenticated incoming requests.

== Screenshots ==

1. Forms Manager admin UI — paste JSON to define a form, or generate one with an AI assistant
2. A rendered contact form on the frontend, automatically inheriting Promptless WP brand styling when present
3. Entries dashboard with per-submission detail view and CSV export
4. Webhook configuration with destination presets (Zapier, Make, Google Sheets) and Test Connection diagnostics
5. Claude Cowork connector setup screen — default-disabled, opt-in App Password generation

== Changelog ==

= 1.12.0 =
* Fixed: on the Form Entries list, the Email column showed a dash that looked like a delivery failure when a form's own notification is turned off — the normal setup when a FlowMint workflow sends your team's email instead. It now says "Off", and where a workflow sends the email the column shows what the workflow did, with a link to it.

= 1.11.0 =
* Fixed: real photos, PDFs and Adobe Illustrator files were refused with "File contains potentially dangerous content." Uploads are now checked for what each file type can actually hide; program code is still refused, and every message says what to do. SVG (on fields that list it) and EPS files with a preview are accepted.
* Fixed: a double tap, or a retry on a host with a persistent object cache, could show "Thanks" while nothing was saved. The button locks on the first tap, and a repeat is told "thanks" only once the first copy is saved.
* Fixed: apostrophes gained a backslash (O\'Brien) in entries, emails, webhooks and workflows.
* Changed: a submission caught by the honeypot is kept as a spam entry (no email, webhook or workflow) and can be restored with Mark as Not Spam. The honeypot field is renamed so password managers leave it alone.
* Changed: notification emails attach up to 10 MB of files and link the rest; a file over the size or type limit is caught as soon as it is chosen.

= 1.10.1 =
* Changed: deleting the plugin keeps entries, uploaded files and forms unless "Remove all data" is turned on in Settings.
* Changed: multi-step forms check each step's fields on Next.
* Fixed: the admin screens had lost their script and styles since 1.8.0; entry search, filter and bulk actions work again.
* Fixed: conditions on radio buttons, checkboxes and mixed-case keys; a required field in a hidden section blocked the form; required files were not enforced on the server; ajax="false" lost submissions.
* Fixed: Twilio missed-call leads now reach the form's webhook.

= 1.10.0 =
* Fixed: a form could send its notification From the visitor's address (a {field:...} token in the sender), which mail providers such as Resend reject, so the email never arrived. The sender now never comes from submitted data; the submitter goes in Reply-To. Existing forms configured that way change sender on update.
* Fixed: the connector described a Reply-To default that does not exist.

= 1.9.1 =
* Fixed: the form stylesheets loaded on every page of the site whenever Promptless WP's neo-brutalist style was on, not just on pages with a form. They now load only where a form renders, so pages without a form are lighter.

= 1.9.0 =
* Added: right-to-left languages load right-to-left stylesheets, so a form on an Arabic or Hebrew site mirrors its labels, steps, buttons and admin screens.


== Upgrade Notice ==

= 1.12.0 =
Clears up the Form Entries "Email" column, which showed a dash that looked like a failed notification when the form's own email is turned off. No settings or form data change.

= 1.11.0 =
Customers can upload real artwork again: most photos, PDFs and Illustrator files were refused as "dangerous content". Also stops a double tap from showing "Thanks" when nothing was saved. Recommended for all users.

= 1.10.1 =
Restores the admin screens' script and styles, fixes several conditional-logic and required-field bugs, and sends Twilio leads to the form's webhook. Deleting the plugin now keeps entries and forms unless you opt in under Settings.

= 1.10.0 =
Behaviour change: a {field:...} token in a form's notification sender is now ignored, so those notifications use your site's configured sender (the submitter moves to Reply-To). Set the sender to an address on your verified mail domain.

= 1.9.1 =
Form stylesheets now load only on pages that show a form, instead of on every page when the neo-brutalist style is on. No settings or form data change.

= 1.9.0 =
Adds right-to-left stylesheets: forms on Arabic or Hebrew sites now mirror correctly. No settings or form data change.



