#!/usr/bin/env node

/**
 * Form Runtime Engine — Cowork MCP Connector
 *
 * A stdio MCP server that bridges Claude Cowork (via Claude Desktop) to the
 * Form Runtime Engine's WordPress REST API. Runs on the user's local machine
 * so it can make HTTP(S) requests to any WordPress site with Application
 * Password authentication.
 *
 * The bridge exists because the Cowork sandbox cannot make outbound HTTP
 * requests to arbitrary hosts. By running Node.js locally and speaking the
 * MCP protocol over stdio to Claude Desktop, we give Claude Cowork full REST
 * access to the user's WordPress install without a server-side agent.
 *
 * Contract: this server maps one-to-one to the REST endpoints documented in
 * docs/CONNECTOR_SPEC.md v1. Any change to the tool set here must also be
 * reflected in the spec document — and vice versa.
 *
 * Environment variables:
 *   FORM_ENGINE_SITE_URL      - WordPress site URL (e.g., https://example.com)
 *   FORM_ENGINE_USERNAME      - WordPress user login
 *   FORM_ENGINE_APP_PASSWORD  - WordPress Application Password (spaces OK, stripped on use)
 *
 * This file is forked from the Promptless WP connector (ai-section-builder-modern/
 * includes/Connector/assets/wordpress-connector.js). The MCP stdio framing,
 * Basic Auth + ModSecurity workarounds, and protocol-version echo in the
 * initialize handler are preserved verbatim because the fixes there were
 * hard-won and apply equally to this connector.
 */

const http = require("http");
const https = require("https");
const { URL } = require("url");

// ---------------------------------------------------------------------------
// Tool definitions. Must mirror CONNECTOR_SPEC.md §9.
// ---------------------------------------------------------------------------

const TOOLS = [
  {
    name: "formengine_preflight",
    description:
      "Verify the Form Runtime Engine connector is reachable and report its state. MUST be called first in any session that will use this connector. " +
      "Returns a digest of critical rules inline (critical_rules, field_hints for all 13 field types, universal_field_properties) AND a `schema_reference_url` pointing at the comprehensive markdown rulebook. " +
      "ALWAYS WebFetch that URL before creating or updating forms — it covers column layouts (1/2, 1/3, 2/3, 1/4, 3/4 as strings), conditional visibility, multistep forms, settings (theme_variant, notifications, spam_protection, webhook), and the drift patterns that cause silent failures. " +
      "Also returns plugin_version, connector_api_version, connector_enabled, entry_read_enabled, authenticated_as, user_capabilities, and diagnostics (database health, recent calls).",
    inputSchema: {
      type: "object",
      properties: {},
      required: [],
    },
  },
  {
    name: "formengine_list_forms",
    description:
      "List all database-stored forms on the site. Paginated. Optionally filter by origin: 'admin' for hand-authored forms, 'connector:cowork' for forms you (Cowork) previously created via this API. Use the origin filter to avoid inadvertently modifying forms the site owner maintains by hand.",
    inputSchema: {
      type: "object",
      properties: {
        page: { type: "integer", minimum: 1, default: 1 },
        per_page: { type: "integer", minimum: 1, maximum: 100, default: 20 },
        managed_by: {
          type: "string",
          enum: ["admin", "connector:cowork"],
          description:
            "Filter by form origin. Omit to include forms of any origin.",
        },
      },
      required: [],
    },
  },
  {
    name: "formengine_get_form",
    description:
      "Fetch a single form by ID. The response includes the raw config JSON string (parse it client-side with JSON.parse), the shortcode for embedding the form on a page, the connector_version (bumps on every update — useful for A/B tracking), and the managed_by tag. Webhook secrets are intentionally omitted from all responses.",
    inputSchema: {
      type: "object",
      properties: {
        form_id: {
          type: "string",
          description:
            "Form identifier. Lowercase alphanumeric with dashes or underscores.",
        },
      },
      required: ["form_id"],
    },
  },
  {
    name: "formengine_create_form",
    description:
      "Create a new form. BEFORE your first create in a session, call `formengine_preflight` and WebFetch the returned `schema_reference_url` — that markdown document covers column layouts (1/2, 1/3, 2/3, 1/4, 3/4 as strings), the 13 field types and their required properties, conditional visibility, multistep forms, settings (theme_variant for dark backgrounds, notifications, webhooks), and common drift patterns that the raw JSON schema doesn't explain. " +
      "The 'config' argument MUST be a JSON STRING (not an object) conforming to the Form Runtime Engine form schema. JSON.stringify your config object before passing it in. " +
      "KEY VISUAL SETTINGS to decide up front: (a) `settings.theme_variant` — set to \"dark\" when the form will be embedded in a dark-background section (a Promptless hero with theme_variant:\"dark\", for example), \"light\" otherwise. Defaults to light; skipping this produces light-theme inputs on dark backgrounds, which fails accessibility contrast. (b) `settings.appearance.surface` — set to \"card\" to wrap the form in a token-aware card (background, border, radius, padding) that inherits design tokens from the parent AISB section. Default \"none\" leaves the form flat on the section background. " +
      "If you're building forms for a site that ALSO uses Promptless WP (AI Section Builder Modern), see docs/WORKFLOW_PROMPTLESS_INTEGRATION.md for the end-to-end deployment flow — the FRE shortcode goes into a Promptless hero's `shortcode_content` field with `media_type: \"shortcode\"` so the form renders as the hero's primary visual element. " +
      "Forms created through this tool are automatically tagged managed_by='connector:cowork' and start at connector_version=1. " +
      "Returns the created record, including the shortcode to embed the form (e.g. [fre_form id=\"contact\"]). " +
      "Conflicts on an existing ID return form_exists (409); use formengine_update_form instead.",
    inputSchema: {
      type: "object",
      properties: {
        id: {
          type: "string",
          description:
            "Unique form identifier. Must match ^[a-z0-9\\-_]+$. This becomes the shortcode attribute (e.g. [fre_form id=\"<id>\"]).",
        },
        title: {
          type: "string",
          description:
            "Human-readable title. Optional — if omitted, falls back to the title inside the config JSON.",
        },
        config: {
          type: "string",
          description:
            "JSON string describing fields, settings, steps, etc. Must validate against docs/form-schema.json. Required keys: fields (array of field objects, each with key + type). Tip: JSON.stringify your config object before passing it in.",
        },
        custom_css: {
          type: "string",
          description: "Optional form-scoped CSS. Sanitized by the server.",
        },
        webhook_enabled: { type: "boolean", default: false },
        webhook_url: {
          type: "string",
          description:
            "HTTPS webhook endpoint. Only meaningful when webhook_enabled is true.",
        },
        webhook_preset: {
          type: "string",
          enum: ["google_sheets", "zapier", "make", "custom"],
          default: "custom",
        },
      },
      required: ["id", "config"],
    },
  },
  {
    name: "formengine_update_form",
    description:
      "Update an existing form. BEFORE your first update in a session, call `formengine_preflight` and WebFetch the returned `schema_reference_url` — the markdown rulebook covers every rule you need to avoid silent regressions when editing forms. " +
      "All fields except form_id are optional — omitted fields retain their current values. If you supply a new `config`, it MUST be a JSON STRING (not an object) and it REPLACES the existing config; include every field/step/setting you want to preserve. " +
      "KEY VISUAL SETTINGS to preserve or change intentionally: `settings.theme_variant` (\"light\"|\"dark\"|\"auto\" — match the parent section) and `settings.appearance.surface` (\"none\"|\"card\" — toggle the form-level card wrapper). Changing these is cheap; forgetting to carry them forward when replacing config silently reverts to defaults. " +
      "If you're working on a site that ALSO uses Promptless WP, consult docs/WORKFLOW_PROMPTLESS_INTEGRATION.md for the cross-plugin workflow. " +
      "The connector_version bumps on every update. managed_by is immutable through this API; origin set at create time is preserved. " +
      "If you only need to regenerate the webhook secret, use the admin UI (secret rotation is intentionally not exposed via the API).",
    inputSchema: {
      type: "object",
      properties: {
        form_id: { type: "string", description: "Form identifier to update." },
        title: { type: "string" },
        config: {
          type: "string",
          description:
            "Replacement config JSON string. If omitted, the existing config is preserved.",
        },
        custom_css: { type: "string" },
        webhook_enabled: { type: "boolean" },
        webhook_url: { type: "string" },
        webhook_preset: {
          type: "string",
          enum: ["google_sheets", "zapier", "make", "custom"],
        },
      },
      required: ["form_id"],
    },
  },
  {
    name: "formengine_delete_form",
    description:
      "Delete a form by ID. Per the Form Runtime Engine's data-preservation policy, associated submission entries are NOT deleted — they remain queryable in the admin Entries view with their original form_id. The response reports how many entries were preserved. Irreversible through this API; use with care.",
    inputSchema: {
      type: "object",
      properties: {
        form_id: { type: "string" },
      },
      required: ["form_id"],
    },
  },
  {
    name: "formengine_list_entries",
    description:
      "List submission entries. Requires the site administrator to have enabled entry-read access on the Claude Connection admin page — otherwise returns 403 entry_access_disabled. Each entry includes its fields (form field key → submitted value), the form_version it was submitted against (for A/B analysis), and standard metadata (status, created_at, ip_address). Paginated.",
    inputSchema: {
      type: "object",
      properties: {
        form_id: { type: "string", description: "Filter to one form." },
        status: { type: "string", enum: ["unread", "read", "spam"] },
        is_spam: { type: "boolean", default: false },
        date_from: {
          type: "string",
          description: "ISO date (YYYY-MM-DD). Inclusive lower bound.",
        },
        date_to: {
          type: "string",
          description: "ISO date (YYYY-MM-DD). Inclusive upper bound.",
        },
        page: { type: "integer", minimum: 1, default: 1 },
        per_page: { type: "integer", minimum: 1, maximum: 100, default: 20 },
      },
      required: [],
    },
  },
  {
    name: "formengine_get_entry",
    description:
      "Fetch a single entry by ID. Requires entry-read access to be enabled. Returns fields as a key-value map of field key → submitted value, plus form_version (the version of the form at the time of submission) and standard metadata.",
    inputSchema: {
      type: "object",
      properties: {
        entry_id: { type: "integer" },
      },
      required: ["entry_id"],
    },
  },
  {
    name: "formengine_test_submit",
    description:
      "Submit a form programmatically for testing. Primary use: validate end-to-end that a form works — validation rules, webhook dispatch, notifications — before handing off to a client. The 'data' argument is a map of FIELD KEY (clean, no fre_field_ prefix) → value. Use options.dry_run=true to run validation only and return what would have been stored, without writing to the database or firing any side effects. Use options.skip_notifications=true to write a real entry (for downstream webhook testing) but suppress the email notification.",
    inputSchema: {
      type: "object",
      properties: {
        form_id: { type: "string" },
        data: {
          type: "object",
          description:
            "Field key → value map. Use clean field keys (e.g. 'email', not 'fre_field_email').",
        },
        options: {
          type: "object",
          properties: {
            dry_run: { type: "boolean", default: false },
            skip_notifications: { type: "boolean", default: false },
          },
        },
      },
      required: ["form_id", "data"],
    },
  },
];

// ---------------------------------------------------------------------------
// HTTP client.
// ---------------------------------------------------------------------------

function getConfig() {
  const siteUrl = process.env.FORM_ENGINE_SITE_URL;
  const username = process.env.FORM_ENGINE_USERNAME;
  const appPassword = process.env.FORM_ENGINE_APP_PASSWORD;

  if (!siteUrl) {
    throw new Error(
      "FORM_ENGINE_SITE_URL is not set. Set it to your WordPress site URL (e.g. https://example.com)."
    );
  }
  if (!username || !appPassword) {
    throw new Error(
      "FORM_ENGINE_USERNAME and FORM_ENGINE_APP_PASSWORD must both be set. Generate an Application Password through the Form Entries → Claude Connection admin page."
    );
  }

  // WordPress Application Passwords display with spaces for readability, but
  // the actual credential is the space-stripped form. Strip before encoding.
  const cleanPassword = appPassword.replace(/\s+/g, "");
  const auth = Buffer.from(`${username}:${cleanPassword}`).toString("base64");

  return { siteUrl: siteUrl.replace(/\/+$/, ""), auth };
}

/**
 * Build a request to the connector's REST base.
 *
 * Notable headers — all inherited from the Promptless connector's hard-won
 * experience with shared hosts (see the relevant section of the parent
 * project's MCP_CONNECTOR_SETUP.md):
 *   - User-Agent starts with "WordPress/" so ModSecurity WAFs don't block
 *     the request as a suspicious Node.js client.
 *   - Connection: close prevents chunked transfer encoding on the request
 *     body, which some WAFs reject for POST requests.
 *   - Content-Length is set explicitly on requests with a body for the
 *     same reason — let Node.js compute it, but set the header rather than
 *     relying on chunked.
 */
function makeRequest(method, path, body = null) {
  return new Promise((resolve, reject) => {
    const config = getConfig();
    const url = new URL(
      `${config.siteUrl}/wp-json/fre/v1/connector${path}`
    );

    const isHttps = url.protocol === "https:";
    const transport = isHttps ? https : http;

    const bodyStr = body ? JSON.stringify(body) : null;

    const headers = {
      Authorization: `Basic ${config.auth}`,
      "Content-Type": "application/json",
      Accept: "application/json",
      "User-Agent":
        "WordPress/FormRuntimeEngine-Connector/1.0 (compatible; Cowork MCP)",
      Connection: "close",
    };

    if (bodyStr) {
      headers["Content-Length"] = Buffer.byteLength(bodyStr).toString();
    }

    const options = {
      hostname: url.hostname,
      port: url.port || (isHttps ? 443 : 80),
      path: url.pathname + url.search,
      method: method,
      headers: headers,
    };

    const req = transport.request(options, (res) => {
      let data = "";
      res.on("data", (chunk) => (data += chunk));
      res.on("end", () => {
        try {
          const json = JSON.parse(data);
          if (res.statusCode >= 400) {
            resolve({
              error: true,
              status: res.statusCode,
              ...(typeof json === "object" ? json : { message: data }),
            });
          } else {
            resolve(json);
          }
        } catch {
          resolve({
            error: true,
            status: res.statusCode,
            message: data.substring(0, 500),
          });
        }
      });
    });

    req.on("error", (e) => {
      reject(
        new Error(
          `Connection failed: ${e.message}. Is the site URL correct? (${config.siteUrl})`
        )
      );
    });

    req.setTimeout(30000, () => {
      req.destroy();
      reject(new Error("Request timed out after 30 seconds"));
    });

    if (bodyStr) {
      req.write(bodyStr);
    }
    req.end();
  });
}

// ---------------------------------------------------------------------------
// Tool → REST route mapping.
// ---------------------------------------------------------------------------

async function handleTool(name, args) {
  switch (name) {
    case "formengine_preflight":
      return await makeRequest("GET", "/preflight");

    case "formengine_list_forms": {
      const qs = new URLSearchParams();
      if (args.page) qs.set("page", String(args.page));
      if (args.per_page) qs.set("per_page", String(args.per_page));
      if (args.managed_by) qs.set("managed_by", args.managed_by);
      const suffix = qs.toString() ? `?${qs.toString()}` : "";
      return await makeRequest("GET", `/forms${suffix}`);
    }

    case "formengine_get_form":
      return await makeRequest(
        "GET",
        `/forms/${encodeURIComponent(args.form_id)}`
      );

    case "formengine_create_form": {
      const payload = {
        id: args.id,
        config: args.config,
      };
      if (args.title !== undefined) payload.title = args.title;
      if (args.custom_css !== undefined) payload.custom_css = args.custom_css;
      if (args.webhook_enabled !== undefined)
        payload.webhook_enabled = args.webhook_enabled;
      if (args.webhook_url !== undefined) payload.webhook_url = args.webhook_url;
      if (args.webhook_preset !== undefined)
        payload.webhook_preset = args.webhook_preset;
      return await makeRequest("POST", "/forms", payload);
    }

    case "formengine_update_form": {
      const payload = {};
      if (args.title !== undefined) payload.title = args.title;
      if (args.config !== undefined) payload.config = args.config;
      if (args.custom_css !== undefined) payload.custom_css = args.custom_css;
      if (args.webhook_enabled !== undefined)
        payload.webhook_enabled = args.webhook_enabled;
      if (args.webhook_url !== undefined) payload.webhook_url = args.webhook_url;
      if (args.webhook_preset !== undefined)
        payload.webhook_preset = args.webhook_preset;
      return await makeRequest(
        "PATCH",
        `/forms/${encodeURIComponent(args.form_id)}`,
        payload
      );
    }

    case "formengine_delete_form":
      return await makeRequest(
        "DELETE",
        `/forms/${encodeURIComponent(args.form_id)}`
      );

    case "formengine_list_entries": {
      const qs = new URLSearchParams();
      ["form_id", "status", "date_from", "date_to", "page", "per_page"].forEach(
        (k) => {
          if (args[k] !== undefined && args[k] !== null && args[k] !== "") {
            qs.set(k, String(args[k]));
          }
        }
      );
      if (args.is_spam !== undefined) {
        qs.set("is_spam", args.is_spam ? "true" : "false");
      }
      const suffix = qs.toString() ? `?${qs.toString()}` : "";
      return await makeRequest("GET", `/entries${suffix}`);
    }

    case "formengine_get_entry":
      return await makeRequest(
        "GET",
        `/entries/${encodeURIComponent(args.entry_id)}`
      );

    case "formengine_test_submit": {
      const payload = {
        data: args.data || {},
        options: args.options || {},
      };
      return await makeRequest(
        "POST",
        `/forms/${encodeURIComponent(args.form_id)}/submit`,
        payload
      );
    }

    default:
      throw new Error(`Unknown tool: ${name}`);
  }
}

// ---------------------------------------------------------------------------
// MCP stdio transport — auto-detects Content-Length or newline-delimited framing.
//
// This block is ported verbatim from the Promptless connector. Claude Desktop
// historically shipped versions that used different framing modes; auto-
// detection is necessary for cross-version compatibility. Do not simplify
// without also updating the parent project.
// ---------------------------------------------------------------------------

let buffer = Buffer.alloc(0);
let detectedMode = null; // "content-length" or "newline"

process.stdin.on("data", (chunk) => {
  buffer = Buffer.concat([buffer, chunk]);
  processBuffer();
});

function processBuffer() {
  if (detectedMode === null && buffer.length > 0) {
    const peek = buffer.toString("utf8", 0, Math.min(buffer.length, 20));
    if (peek.startsWith("Content-Length:")) {
      detectedMode = "content-length";
    } else {
      detectedMode = "newline";
    }
  }

  if (detectedMode === "content-length") {
    processContentLength();
  } else if (detectedMode === "newline") {
    processNewline();
  }
}

let contentLength = -1;

function processContentLength() {
  while (true) {
    if (contentLength === -1) {
      const headerEnd = buffer.indexOf("\r\n\r\n");
      if (headerEnd === -1) return;

      const header = buffer.slice(0, headerEnd).toString("utf8");
      const match = header.match(/Content-Length:\s*(\d+)/i);
      if (!match) {
        buffer = buffer.slice(headerEnd + 4);
        continue;
      }

      contentLength = parseInt(match[1], 10);
      buffer = buffer.slice(headerEnd + 4);
    }

    if (buffer.length < contentLength) return;

    const messageBytes = buffer.slice(0, contentLength);
    buffer = buffer.slice(contentLength);
    contentLength = -1;

    parseAndHandle(messageBytes.toString("utf8"));
  }
}

function processNewline() {
  const str = buffer.toString("utf8");
  let newlineIndex;
  while ((newlineIndex = str.indexOf("\n")) !== -1) {
    const line = str.slice(0, newlineIndex).trim();
    buffer = Buffer.from(str.slice(newlineIndex + 1), "utf8");

    if (line.length === 0) {
      return processNewline();
    }
    parseAndHandle(line);
    return processNewline();
  }
}

function parseAndHandle(text) {
  try {
    const message = JSON.parse(text);
    handleMessage(message);
  } catch (e) {
    sendError(null, -32700, "Parse error: " + e.message);
  }
}

function send(obj) {
  const body = JSON.stringify(obj);
  if (detectedMode === "content-length") {
    const header = `Content-Length: ${Buffer.byteLength(body)}\r\n\r\n`;
    process.stdout.write(header + body);
  } else {
    process.stdout.write(body + "\n");
  }
}

function sendResult(id, result) {
  send({ jsonrpc: "2.0", id, result });
}

function sendError(id, code, message) {
  send({ jsonrpc: "2.0", id, error: { code, message } });
}

async function handleMessage(msg) {
  const { id, method, params } = msg;

  switch (method) {
    case "initialize": {
      // Echo the client's protocol version back verbatim. Claude Desktop
      // expects this; hardcoding a different version causes connection
      // negotiation to fail silently. See Promptless connector setup docs.
      const clientVersion =
        (params && params.protocolVersion) || "2024-11-05";
      sendResult(id, {
        protocolVersion: clientVersion,
        capabilities: { tools: {} },
        serverInfo: {
          name: "form-engine-wordpress-connector",
          version: "1.0.0",
        },
      });
      break;
    }

    case "notifications/initialized":
      // No response required.
      break;

    case "tools/list":
      sendResult(id, { tools: TOOLS });
      break;

    case "tools/call": {
      const toolName = params?.name;
      const toolArgs = params?.arguments || {};

      try {
        const result = await handleTool(toolName, toolArgs);
        sendResult(id, {
          content: [
            {
              type: "text",
              text: JSON.stringify(result, null, 2),
            },
          ],
        });
      } catch (e) {
        sendResult(id, {
          content: [
            {
              type: "text",
              text: JSON.stringify({ error: true, message: e.message }),
            },
          ],
          isError: true,
        });
      }
      break;
    }

    default:
      if (id !== undefined) {
        sendError(id, -32601, `Method not found: ${method}`);
      }
  }
}

process.on("SIGINT", () => process.exit(0));
process.on("SIGTERM", () => process.exit(0));
process.stdin.on("end", () => process.exit(0));
