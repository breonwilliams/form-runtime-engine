# Twilio Missed-Call Text-Back — Setup & Reference

Automatically forward incoming calls to a business owner's phone. If the call is missed (unanswered, busy, or failed), send an SMS auto-reply to the caller and log the lead.

**Added in:** v1.2.0
**Current production version:** 1.2.5 (as of April 2026)

---

## How It Works

```
Caller dials Twilio number
  → Twilio POSTs to /wp-json/fre-twilio/v1/incoming-call
  → Plugin responds with TwiML: <Dial> to owner's phone
  → If owner answers: call connects, both parties talk
  → If owner doesn't answer (no-answer / busy / failed):
      → Twilio POSTs to /wp-json/fre-twilio/v1/call-status
      → Plugin sends SMS auto-reply to caller via Twilio API
      → Lead is logged in FRE entries + optional Google Sheet webhook
```

---

## Architecture

### Classes

| File | Purpose |
|------|---------|
| `class-fre-twilio-handler.php` | REST API webhook endpoints. Handles incoming calls, call status, incoming SMS, and SMS status callbacks. Returns TwiML XML responses. |
| `class-fre-twilio-admin.php` | WordPress admin UI. Settings tab (credentials), Clients tab (phone number management), AJAX handlers for CRUD operations. |
| `class-fre-twilio-client.php` | Client data model. Loads client config from the `fre_twilio_clients` database table by Twilio number lookup. |
| `class-fre-twilio-validator.php` | Twilio webhook signature validation (HMAC-SHA1). Handles HTTPS detection behind reverse proxies (X-Forwarded-Proto, X-Forwarded-SSL). |
| `class-fre-sms-sender.php` | Outbound SMS via Twilio REST API. Handles rate limiting (hourly/daily caps per number). |
| `class-fre-twilio-migrator.php` | Database migration. Creates `fre_twilio_clients` and `fre_twilio_messages` tables on activation. |

### Database Tables

**`fre_twilio_clients`** — One row per client/business:
- `id`, `client_name`, `twilio_number`, `owner_phone`, `owner_email`
- `auto_reply_template` — SMS template with `{business_name}` placeholder
- `form_id` — Virtual FRE form ID (auto-generated as `twilio-{slug}`)
- `webhook_url` — Google Apps Script endpoint for logging leads
- `webhook_secret` — HMAC secret for webhook signing
- `is_active`, `created_at`, `updated_at`

**`fre_twilio_messages`** — SMS conversation log:
- `id`, `client_id`, `twilio_number`, `from_number`, `to_number`
- `direction` (inbound/outbound), `body`, `twilio_sid`
- `status` (queued/sent/delivered/failed), `created_at`

### REST API Endpoints

All endpoints are under the `fre-twilio/v1` namespace. All validate Twilio webhook signatures (HMAC-SHA1) before processing.

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/incoming-call` | POST | Twilio sends this when someone calls the number. Returns TwiML with `<Dial>` to forward to owner. |
| `/call-status` | POST | Twilio sends this after the `<Dial>` completes. If `DialCallStatus` is not `completed`, triggers SMS auto-reply. |
| `/incoming-sms` | POST | Twilio sends this when an SMS is received. Logs the message and forwards to owner via email. |
| `/sms-status` | POST | Twilio sends this with SMS delivery status updates. Updates the message record. |

### TwiML Response Handling

WordPress REST API JSON-encodes all response data by default. Since Twilio expects raw XML (TwiML), the plugin uses a `rest_pre_serve_request` filter to intercept responses with `Content-Type: text/xml` from the `fre-twilio/v1` namespace and output raw XML directly. This was a critical bug fix in v1.2.2.

### Credential Storage

Twilio Account SID and Auth Token are stored encrypted in `wp_options` using AES-256-CBC with a key derived from `AUTH_KEY` and `SECURE_AUTH_SALT` WordPress constants.

---

## Twilio Account Setup

### Prerequisites

1. A Twilio account (https://www.twilio.com)
2. A purchased Twilio phone number with Voice and SMS capabilities
3. A2P 10DLC registration (required for US SMS — see below)

### Twilio Console Configuration

For each Twilio phone number, configure these webhook URLs in the Twilio console:

**Voice Configuration:**
- When a call comes in: Webhook → `https://yoursite.com/wp-json/fre-twilio/v1/incoming-call` (HTTP POST)

**Messaging Configuration:**
- When a message comes in: Webhook → `https://yoursite.com/wp-json/fre-twilio/v1/incoming-sms` (HTTP POST)

### WordPress Plugin Configuration

1. Go to **Form Entries → Twilio Text-Back** in WordPress admin
2. **Settings tab:** Enter your Twilio Account SID and Auth Token, click Save
3. Use **Test Connection** to verify credentials work
4. **Clients tab:** Click **Add Client** and fill in:
   - **Business Name** — Client's business name (used in SMS template)
   - **Twilio Number** — The Twilio number in E.164 format (+15551112222)
   - **Owner Phone** — Business owner's personal cell for call forwarding
   - **Owner Email** — For inbound SMS notification forwarding
   - **Auto-Reply Template** — SMS sent to missed callers. Use `{business_name}` placeholder
   - **Webhook URL** — (Optional) Google Apps Script endpoint for lead logging

---

## A2P 10DLC Registration

**Status as of April 2026:** Campaign registration submitted, pending vetting approval (1-7 business days). SMS auto-replies will not be delivered until approved.

A2P (Application-to-Person) 10DLC registration is **required** by US carriers for any business sending SMS from a standard 10-digit phone number. Without it, messages will be blocked or silently dropped.

### Registration Steps (done via Twilio Console)

1. **Customer Profile** — Business identity verification in Twilio Console → Trust Hub
2. **Brand Registration** — Register business with The Campaign Registry (TCR). Requires EIN, business address, etc.
3. **Campaign Registration** — Describe the SMS use case (missed-call text-back). Select message samples, opt-in method, etc.
4. **Phone Number Registration** — Associate Twilio numbers with the approved campaign

### Important Notes

- Brand registration is done once per business entity (FlowMint)
- Campaign registration is done once per use case (missed-call text-back)
- New phone numbers for new clients just need to be added to the existing campaign
- Vetting can take 1-7 business days; there's a paid option for faster review
- Until approved, outbound SMS will fail silently or with carrier rejection errors

---

## Client Onboarding Flow (Adding a New Client)

When you have a new client who wants missed-call text-back:

1. **Purchase a Twilio number** for them in the Twilio Console
2. **Configure webhooks** on that number in the Twilio Console (voice → incoming-call endpoint, messaging → incoming-sms endpoint)
3. **Add the number to the A2P campaign** in Twilio Console → Trust Hub (since A2P registration is already done, you just add numbers to the existing campaign)
4. **Add the client in WordPress** via Form Entries → Twilio Text-Back → Clients tab → Add Client
5. **Test** by calling the Twilio number from a phone that isn't the owner's number

---

## Current Test Configuration

- **Test client:** FlowMint Test
- **Twilio number:** +14344425467
- **Owner phone:** +14345489790
- **Production site:** getflowmint.com

---

## Known Issues & Bug History

### Fixed in v1.2.2
- **TwiML JSON encoding** — WordPress REST API was JSON-encoding TwiML XML responses, causing Twilio Error 12100 "Document parse failure." Fixed by adding `rest_pre_serve_request` filter to bypass JSON serialization for XML responses.
- **Error responses were JSON** — `error_response()` returned JSON instead of TwiML, so Twilio couldn't parse error responses either. Changed to return valid TwiML with `<Say>` and `<Hangup/>`.
- **HTTPS detection behind proxy** — Twilio signature validation failed on hosts using reverse proxies (like Bluehost) because `is_ssl()` returned false. Added `X-Forwarded-Proto` and `X-Forwarded-SSL` header checks.

### Fixed in v1.2.3
- **Call-status signature validation failure** — The `<Dial>` action URL included query parameters (`caller`, `call_sid`) appended via `add_query_arg(rawurlencode(...))`. This caused double URL encoding, and WordPress `esc_url()` converted `&` to `&#038;`, making the reconstructed URL not match what Twilio signed. Fixed by removing query params entirely — Twilio already sends `From` and `CallSid` in the POST body of status callbacks.

### Fixed in v1.2.4
- **Edit button non-functional** — The Edit button on the Client Phone Numbers table had no JavaScript click handler. Added data attributes and jQuery handler to populate the modal with existing client data.
- **Modal not scrollable** — The add/edit client modal couldn't scroll on smaller viewports, making lower fields inaccessible. Added `overflow-y: auto` and `max-height` constraints.

### Fixed in v1.2.5
- **Stale update notice after plugin update** — The GitHub updater's `clear_cache()` method wasn't clearing WordPress's `update_plugins` site transient, so the "update available" notice persisted even after updating. Added `delete_site_transient('update_plugins')`.

### GitHub Auto-Updater & Private Repos
The GitHub repo (`breonwilliams/form-runtime-engine`) is **private**. The auto-updater needs a GitHub Personal Access Token to check for releases. Without it, the API returns 404 and no updates are shown. Options:
- **Make the repo public** (simplest — works without tokens, like the Video Teaser plugin)
- **Add `FRE_GITHUB_TOKEN`** to `wp-config.php` on each site (see docs/CLAUDE.md release section)

---

## Troubleshooting

### Twilio Error 12100 "Document parse failure"
The plugin's TwiML response is being JSON-encoded. Ensure the `rest_pre_serve_request` filter in `class-fre-twilio-handler.php` is active. This filter intercepts XML responses and outputs them raw.

### "An error occurred. Please try again later" on call
This is the plugin's TwiML error response. Check the WordPress error log for `Twilio webhook error:` entries. Common causes: signature validation failure, missing client config for the dialed number, database errors.

### SMS not being delivered
1. Check A2P 10DLC campaign status in Twilio Console → Trust Hub
2. Check rate limits — default is 10/hour, 50/day per number
3. Check `fre_twilio_messages` table for status = 'failed'
4. Check Twilio Console → Monitor → Messaging Logs for carrier rejection codes

### Signature validation failures
- Ensure the site URL uses HTTPS (Twilio signs against the URL it POSTs to)
- For reverse proxy setups, verify `X-Forwarded-Proto: https` header is set
- Don't add query parameters to webhook callback URLs — they complicate signature computation
