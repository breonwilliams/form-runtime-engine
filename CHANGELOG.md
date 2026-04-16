# Changelog

All notable changes to Form Runtime Engine will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.5] - 2026-04-16

### Fixed
- "Update available" notice persists after successful plugin update

## [1.2.4] - 2026-04-16

### Fixed
- Edit button on Client Phone Numbers table was non-functional
- Modal not scrollable on smaller viewports

## [1.2.3] - 2026-04-16

### Fixed
- Call-status callback fails signature validation due to query params in action URL, causing caller to hear "an error occurred" when the answering party hangs up first
- Removed query params from Dial action URL — caller and call SID are already in Twilio's POST body (From, CallSid fields), eliminating URL encoding and signature mismatch issues

## [1.2.2] - 2026-04-16

### Fixed
- Twilio webhook returns JSON-encoded TwiML instead of raw XML, causing Twilio "Document parse failure" (error 12100) on all incoming calls
- Added `rest_pre_serve_request` filter to bypass WordPress REST API JSON encoding for TwiML responses
- Error responses to Twilio now return valid TwiML (`<Say>` + `<Hangup/>`) instead of JSON, preventing secondary parse failures
- SSL detection behind reverse proxies (Bluehost, Cloudflare) now checks `X-Forwarded-Proto` and `X-Forwarded-SSL` headers for accurate signature validation URL construction

## [1.2.1] - 2026-04-16

### Fixed
- Database migration fails on fresh installs: migration_1_0_0 incorrectly validated all tables including ones created by later migrations
- Twilio clients table uses ON UPDATE CURRENT_TIMESTAMP which is incompatible with dbDelta on some MySQL versions

## [1.2.0] - 2026-04-16

### Added
- Twilio missed-call text-back integration module (6 new classes)
- Automatic SMS reply when a business owner misses a call
- Owner notification via SMS and email for every missed call
- Multi-client routing: map multiple Twilio numbers to different businesses
- REST API endpoints for Twilio webhooks (incoming call, call status, incoming SMS, SMS status)
- Twilio signature validation (HMAC-SHA1) on all incoming webhooks
- Encrypted credential storage (AES-256-CBC) for Twilio Account SID and Auth Token
- Rate limiting for outbound SMS (hourly per-client and daily global caps)
- Admin UI: Twilio Text-Back settings page under Form Entries menu
- Admin UI: Client management with add, edit, toggle, and delete operations
- Admin UI: Test Connection button for Twilio credential validation
- Virtual FRE form registration per Twilio client for unified lead pipeline
- SMS conversation logging in dedicated fre_twilio_messages table
- Inbound SMS forwarding from leads to business owners
- SMS delivery status tracking via Twilio status callbacks
- Missed-call leads appear in the same entries list and Google Sheets as form submissions
- Source type metadata (_source_type: missed_call / sms_inbound) on Twilio-originated entries

### Changed
- Autoloader updated with Twilio class mappings
- Main plugin initialization sequence now includes Twilio module bootstrap
- Plugin activation now runs Twilio database migrations alongside core migrations

## [1.1.0] - 2026-04-11

### Added
- HMAC-SHA256 webhook request signing with auto-generated per-form secrets
- Webhook destination presets (Google Sheets, Zapier, Make, Custom) with contextual setup help
- Test Connection button with rich response display (HTTP status, latency, response body)
- Preview Payload button showing sample JSON based on form field definitions
- Webhook secret management: auto-generate on first enable, regenerate, copy to clipboard
- Webhook delivery logging with database table, retry tracking, and status monitoring
- Google Sheets integration via Google Apps Script (free Zapier alternative)
- Google Apps Script template (`docs/google/apps-script-template.gs`) with signature verification support
- Google Sheets setup guide (`docs/google/google-sheets-setup.md`)
- Webhook secret field auto-populates in admin UI after server-side generation

### Changed
- Webhook dispatcher refactored to support HMAC signing and rich test responses
- Forms Manager admin UI expanded with webhook configuration panel
- Admin JS updated with handlers for preset switching, test, preview, regenerate, and copy actions
- Split CLAUDE.md into root (core reference) + `docs/CLAUDE.md` (examples) + `includes/CLAUDE.md` (security) for performance

## [1.0.1] - 2026-04-05

### Changed
- Comprehensive README.md rewrite with complete feature documentation
- Updated plugin description to reflect admin UI capabilities
- Added documentation for all 13 field types (including section, date, address)
- Added documentation for layout features (columns, sections, conditional logic)
- Added documentation for multi-step forms with progress styles
- Added documentation for admin features (Forms Manager, entries, CSV export)
- Expanded API functions documentation (4 → 8 functions)
- Added design system integration documentation

## [1.0.0] - 2026-04-05

### Added
- Initial stable release
- Form registration via PHP arrays and JSON configuration
- Admin UI for creating and managing forms (Forms Manager)
- Field types: text, email, tel, textarea, select, radio, checkbox, file, hidden, message, section, date, address
- Multi-step forms with progress indicators (steps, bar, dots styles)
- Conditional field logic (show/hide based on field values)
- Column layouts (1/2, 1/3, 2/3, 1/4, 3/4)
- Field sections/groups with headings
- File uploads with security validation and MIME type checking
- Email notifications with template variables
- Webhook integration for Zapier, Make, and custom endpoints
- Spam protection: honeypot fields, timing check, rate limiting
- Entry storage and admin management
- CSV export for form entries
- Google Places API integration for address fields
- Design system integration with AI Section Builder Modern
- Theme variants: light, dark, auto (inherits from AISB section)
- Neo-Brutalist mode support
- GitHub-based automatic updates

### Security
- SSRF protection for webhook URLs
- CSS validation for custom styles
- JSON schema validation for form configurations
- PHP execution disabled in upload directory
- Secure file uploads with extension and MIME validation
