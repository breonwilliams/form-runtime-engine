# Changelog

All notable changes to Form Runtime Engine will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
