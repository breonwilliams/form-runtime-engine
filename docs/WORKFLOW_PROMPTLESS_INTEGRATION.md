# End-to-End Workflow: Promptless WP + Form Runtime Engine via Cowork

This document describes the full agency pipeline that emerges when both connectors are installed on the same WordPress site and Claude Cowork has access to both. It exists because neither plugin's documentation alone tells the whole story — the value comes from the composition.

**Audience.** Operators (you) thinking through how to use the two connectors together for client work. Also useful for Cowork itself: when you give Cowork access to both connectors and ask for a complete website, it should follow this workflow rather than improvise.

---

## 1. The pipeline at a glance

```
   ┌─────────────────────────────────────────────────────────────────┐
   │                      Claude Cowork session                       │
   └─────────────────────────────────────────────────────────────────┘
                  │            │              │             │
                  ▼            ▼              ▼             ▼
          ┌──────────┐  ┌─────────────┐  ┌────────┐  ┌──────────────┐
          │ Research │  │  Promptless │  │ Form   │  │  Analytics   │
          │ &        │  │  WP         │  │ Engine │  │  (whichever  │
          │ planning │  │  connector  │  │ conn'r │  │  Cowork has) │
          └──────────┘  └─────────────┘  └────────┘  └──────────────┘
                                  │            │
                                  ▼            ▼
                          ┌────────────────────────┐
                          │   Hosted WordPress     │
                          │   (the client site)    │
                          └────────────────────────┘
```

The two MCP connectors run as separate Node.js processes on the operator's local machine. Claude Desktop multiplexes them. Cowork sees them as two distinct tool families (`wordpress_*` for Promptless, `formengine_*` for Form Engine) and can orchestrate them in a single session against the same WordPress site.

---

## 2. The full pipeline

### Stage 1 — Research and discovery

Cowork uses whatever tools you've connected for research: web browsing, file access, screenshots of competitor sites, brand documents, etc. The output of this stage is a project brief — target audience, value propositions, page structure, key offers, brand voice, color palette, typography preferences.

Neither WordPress connector is involved at this stage.

### Stage 2 — Site scaffolding via Promptless

Cowork calls `wordpress_preflight` first to confirm the connector is reachable and the site is in a deployable state. Then `wordpress_scaffold` to create the page hierarchy — typically Home, About, Services, Contact, plus child pages under Services (e.g. each service offering as its own page). The tool returns page IDs and slugs that subsequent calls reference.

The slugs returned by scaffold are used everywhere downstream: in CTA button URLs, in nav menu items, in cross-links between sections. Cowork should treat them as the authoritative URL fragments for the site.

### Stage 3 — Page content deployment via Promptless

Cowork calls `wordpress_deploy_content` (or `wordpress_batch_deploy` for multi-page) with the section JSON for each page. The Hero, Features, FAQ, Stats, Team, Steps, Checklist, Testimonials, and Pricing section types each have their own field shape (fully documented in the Promptless connector's spec).

CTAs and buttons in these sections reference the slugs from scaffold to create internal links. External links can be used too. Cowork should typically NOT reference contact-form pages by their final URL yet — that comes in Stage 5.

### Stage 4 — Form design via Form Engine

Cowork calls `formengine_preflight` to confirm the form connector is reachable and the entry-read toggle is set as expected. Then `formengine_create_form` for each form the site needs. Typical client sites need:

- **Contact form** — name, email, message; possibly phone, subject, source
- **Lead capture / quote request** — multi-step with conditional fields based on service interest
- **Newsletter signup** — email plus optional name
- **Booking / consultation request** — date, time, contact info, brief
- **Specialty forms** — depends on the client (event registration, support intake, application, etc.)

The form's `config` JSON conforms to `docs/form-schema.json`. Each form returns a `shortcode` — that's the embed string for Stage 5.

If the form needs webhook integration (Zapier, Make, Google Sheets), set `webhook_enabled: true` and the URL during `formengine_create_form`. The webhook secret is auto-generated and persisted; it never appears in API responses.

### Stage 5 — Embedding forms into Promptless pages

Cowork uses `wordpress_deploy_content` (Promptless again) to update the relevant page sections — typically the contact page, the bottom of the home page, and any service page that needs a quote request — with rich text content that includes the shortcode returned in Stage 4.

The shortcode is just text (e.g. `[fre_form id="contact"]`). It can be placed inside a Promptless rich text section. WordPress automatically resolves the shortcode at render time, calling into the Form Runtime Engine's renderer.

Crucially, neither plugin needs to know about the other for this to work. The integration is `do_shortcode()`, a WordPress core mechanism. The Form Engine's frontend CSS reads from the AISB design tokens (per `docs/AISB_TOKEN_CONTRACT.md`), so the form visually inherits the brand styling from Promptless's Global Settings automatically.

### Stage 6 — Verification

Cowork calls `formengine_test_submit` with `dry_run: true` for each form to verify validation works with realistic data. This catches required-field misses, type mismatches, and conditional-logic errors before any real user encounters them.

Optionally, Cowork can call `formengine_test_submit` with `skip_notifications: true` to send a real submission through to the database (and any configured webhooks) without spamming the client's notification inbox. This is the right way to verify a webhook integration works end-to-end before handing off.

`wordpress_status` (Promptless) confirms all pages are deployed, published or draft as intended, and showing correct section counts.

### Stage 7 — Iterative optimization (the recurring loop)

This is where the Form Engine's `connector_version` and `_fre_form_version` entry stamping pay off.

After a few weeks of real submissions, Cowork can:

1. Call `formengine_list_entries` for a form (requires the entry-read toggle to be on) to see how submissions are flowing.
2. Cross-reference with whatever analytics connector you have (GA4, Plausible, Posthog, etc.) to see conversion rates and drop-off points.
3. Identify a hypothesis: "The lead capture form is converting at 8% — let's try a shorter version with no phone field."
4. Call `formengine_update_form` to change the form. The `connector_version` bumps automatically; new entries are stamped with the new version.
5. After a comparable time window, call `formengine_list_entries` filtered to the new version (Cowork can compare entries with `form_version: 2` against entries with `form_version: 1`) and see whether the change moved the metric.

The version stamping is the foundation that makes this loop possible. Without it, you'd see "entries before and after the change" but couldn't reliably correlate which entries belonged to which version of the form.

---

## 3. What this looks like in a Cowork prompt

A site-builder operator might prompt Cowork once with the full brief:

> "I'm building a website for an HVAC company called CoolBreeze HVAC. They serve residential and light-commercial customers in the Pacific Northwest. Tone: trustworthy, prompt, neighborly. Their main offers are emergency repair, system installation, and seasonal maintenance contracts. Please use both the Promptless and Form Engine connectors to build out their site at https://coolbreeze.example. Include Home, About, Services (with child pages for each offer), Pricing, Contact. Add a contact form on the Contact page (name, email, phone, brief), a quote request form on each service page (service interest, urgency, address, contact), and a newsletter signup at the bottom of every page. Webhook the contact and quote forms to https://hooks.zapier.com/.../coolbreeze-leads. After deployment, dry-run all the forms to verify validation works."

Cowork would then walk through stages 2-6 calling the appropriate tools across both connectors, returning a brief summary of what it built and links to the resulting pages.

---

## 4. Why two connectors instead of one

The Promptless and Form Engine connectors deliberately stay separate. Reasons (carried over from the Cowork connector assessment §6):

- The Form Engine is useful on sites that don't have Promptless installed at all — e.g. a site running a different page builder where the operator just wants to add forms via Cowork. A merged connector would force users to install both plugins to get either capability.
- Each connector can evolve its API independently without coupling the version policies. Promptless's connector is at v1; Form Engine's is at v1; both can ship v2s on different timelines.
- The MCP server processes are isolated. A bug in one can't take down the other in the same Cowork session.
- The setup commands are short and purpose-built. A unified setup command would have to handle the Cartesian product of "Promptless yes/no × Form Engine yes/no" cases.

The cost is two entries in `claude_desktop_config.json` instead of one, and two setup commands instead of one. We accepted this trade-off explicitly.

---

## 5. Operational notes

**Order of operations matters when using both connectors on the same page.** Always create forms (Form Engine) before deploying the Promptless section that references them. The shortcode `[fre_form id="contact"]` resolves to nothing visible if the form doesn't exist when the page renders.

**Connector visibility.** The two MCP servers each have their own `connector_enabled` toggle (one in Promptless's "Claude Connection" admin page, one in the Form Engine's). Disabling one does not affect the other. If a client says "we don't want forms managed by AI but the page-building part is fine," disable the Form Engine connector and leave Promptless enabled.

**Credential rotation.** Each connector manages its own App Password, scoped to the user who generated it. Rotating one does not rotate the other.

**Entry data ownership.** Form submissions go to the WordPress database via the Form Engine, not to Promptless. The two connectors are read-isolated from each other — the Promptless connector cannot read form entries, and the Form Engine connector cannot read page content.

**Back to the operator.** Both connectors require the operator's WordPress account to have admin-level access (administrator role with the relevant capability). Sub-administrator delegation is possible (see the capability customization in each plugin's docs) but not exercised in v1 testing.

---

## 6. Related documents

- `docs/CONNECTOR_SPEC.md` — Form Engine REST API contract
- `docs/MCP_CONNECTOR_SETUP.md` — Form Engine MCP setup and troubleshooting
- `docs/CONNECTOR_TESTING_REPORT.md` — Form Engine pressure-test results
- `docs/COWORK_CONNECTOR_ASSESSMENT.md` — Form Engine architectural rationale
- `docs/AISB_TOKEN_CONTRACT.md` — design-token contract between Form Engine and Promptless
- `docs/form-schema.json` — Form configuration schema
- `ai-section-builder-modern/docs/development/CONNECTOR_SPEC.md` — Promptless connector REST contract
- `ai-section-builder-modern/docs/development/MCP_CONNECTOR_SETUP.md` — Promptless MCP setup
