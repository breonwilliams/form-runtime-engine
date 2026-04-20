# MCP Connector Setup

**Audience:** Site owners setting up the Form Runtime Engine Claude Cowork connector.
**Prerequisite reading:** `docs/CONNECTOR_SPEC.md` (what the REST API does) and `docs/COWORK_CONNECTOR_ASSESSMENT.md` (why the design is the way it is).

---

## 1. What this is

The connector is a **Node.js MCP server** that runs on your local machine and bridges Claude Desktop to your WordPress site's REST API. Claude Cowork (the sandboxed agent) cannot make outbound HTTP requests to arbitrary WordPress sites on its own; the MCP server closes that gap by running locally and translating Cowork's tool calls into authenticated HTTPS requests to your site.

The architecture is identical to the Promptless WP connector's MCP bridge — this project deliberately forks that code. The hard-won fixes baked in there (message framing auto-detection, protocol-version echo, ModSecurity workarounds) are preserved here so you inherit the same reliability.

```
 ┌────────────────┐    stdio     ┌────────────────────┐   HTTPS +      ┌───────────────┐
 │ Claude Desktop │ ◀─────────▶ │ form-engine-       │   Basic Auth   │ WordPress     │
 │  (Cowork)      │   JSON-RPC   │ connector.js       │ ◀────────────▶ │ REST API      │
 └────────────────┘              │ (on your Mac)      │                │ /wp-json/fre/ │
                                 └────────────────────┘                └───────────────┘
```

---

## 2. Requirements

- **macOS** (the shipped setup command is macOS-specific; Linux and Windows support is a future phase).
- **Node.js 14+** installed. Works with Homebrew-installed Node, the official installer, or nvm-managed versions.
- **Claude Desktop** installed and launched at least once (so its config directory exists).
- **WordPress site reachable over HTTPS** with Application Passwords enabled. WordPress enforces HTTPS for Application Passwords by default; the `WP_ENVIRONMENT_TYPE=local` constant waives this for Local by Flywheel and similar local-dev environments.
- **An admin user** on the site with the `fre_manage_forms` capability (administrators receive this automatically on plugin activation — see Phase 1 of the Cowork connector work).

---

## 3. Setup (happy path)

Three steps, each done once per site:

1. On your WordPress site, open **Form Entries → Claude Connection** in the admin.
2. Enable the **Claude Cowork Connection** toggle (Step 1 on the page). The outer gate is off by default; nothing works until you flip it on.
3. Click **Generate Connection** (Step 2). WordPress creates an Application Password for your user, revoking any prior connector credential for you. The password displays once — do not close the page until you have copied the bash command in Step 4.

   A bash command appears in Step 4 that looks roughly like:

   ```bash
   mkdir -p ~/form-engine-mcp && \
   curl -fsSL -A 'WordPress/FormRuntimeEngine' '{your-site}/wp-admin/admin-ajax.php?action=fre_download_connector' -o ~/form-engine-mcp/form-engine-connector.js && \
   NODE_PATH=$(ls -d ~/.nvm/versions/node/v*/bin/node 2>/dev/null | sort -V | tail -1) ; [ -z "$NODE_PATH" ] && NODE_PATH=$(which node) ; \
   …writes claude_desktop_config.json via Node…
   ```

   Copy it and paste into Terminal.

4. Quit Claude Desktop (⌘Q) and reopen it. A new Cowork session now has access to the Form Runtime Engine tools. Try: *"Run a form engine preflight check on my site"* — Claude should return the plugin version and connector state.

Optional: enable the **Allow Claude Cowork to read form submissions** toggle (Step 3 on the admin page) if you want Cowork to query submission data for A/B analysis or lead review. Default off. Enabling this specifically widens what Cowork can see — most workflows don't need it.

---

## 4. Claude Desktop configuration

The setup command writes a `mcpServers` entry to `~/Library/Application Support/Claude/claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "form-engine-wordpress": {
      "command": "/Users/you/.nvm/versions/node/v22.18.0/bin/node",
      "args": ["/Users/you/form-engine-mcp/form-engine-connector.js"],
      "env": {
        "FORM_ENGINE_SITE_URL": "https://example.com",
        "FORM_ENGINE_USERNAME": "admin",
        "FORM_ENGINE_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Key naming note: we use `form-engine-wordpress` as the server key so this connector can coexist with the Promptless connector, which uses `promptless-wordpress`. If you have both plugins installed you run both setup commands in sequence and end up with two entries in `mcpServers` — Claude Desktop multiplexes them cleanly.

---

## 5. Environment variables

| Variable | Purpose | Notes |
|---|---|---|
| `FORM_ENGINE_SITE_URL` | Root URL of the WordPress site | Trailing slash optional. HTTPS strongly recommended; see §6 on HTTP. |
| `FORM_ENGINE_USERNAME` | WordPress user login (not display name) | Whatever `wp_get_current_user()->user_login` returns. |
| `FORM_ENGINE_APP_PASSWORD` | Application Password | Spaces in the displayed form are stripped on use; either form works. |

Never put these into a shell profile or `.env` file that might end up in version control. The setup command puts them into the Claude Desktop config file, which stays local.

---

## 6. Troubleshooting

### `Authentication required` on every REST call

The symptom is 401 responses on `/wp-json/fre/v1/connector/*` with the body `{"code": "rest_not_logged_in", "message": "Authentication required..."}` even though you generated an Application Password through the admin UI and pasted the setup command into Terminal.

**Diagnostic shortcut**: WordPress returns the same `rest_not_logged_in` error code in two distinct cases — when no `Authorization` header reaches PHP at all, AND when the header is present but the credentials are invalid. So the response alone cannot tell you which is happening. The fastest way to distinguish:

1. From a Cowork session, ask Claude to run a preflight check. If it succeeds, your credentials and header forwarding are both working — the rest of this section does not apply to you.
2. If preflight fails with `rest_not_logged_in`, regenerate the connection from the admin page (Step 2 → Regenerate Connection), re-run the bash setup command, fully quit Claude Desktop (⌘Q), and reopen it. A surprising number of "auth not working" reports trace to a stale credential after toggling the connector off and back on.
3. If the regenerated connection still fails, then either the header is being stripped before it reaches PHP, or the credential is genuinely wrong (corruption between Terminal and `claude_desktop_config.json` is rare but possible — check the file with `cat ~/Library/Application\ Support/Claude/claude_desktop_config.json` and confirm `FORM_ENGINE_APP_PASSWORD` is present and matches what you copied).

**Most likely cause**: the web server is stripping the `Authorization` header before it reaches PHP. On nginx this happens when the FastCGI config for the WordPress rewrite block omits `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`. On Apache it happens when the plugin's `.htaccess` doesn't preserve the header through rewrites with a directive like:

```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

Most managed hosts (Kinsta, WP Engine, SiteGround, Bluehost, GoDaddy's managed WP) handle this correctly out of the box. If you're on a budget shared host or self-managed VPS and headers are being stripped, contact support — this is a one-line config change they can apply.

**Local by Flywheel specific**: the default nginx config in current Local versions strips the Authorization header for `/wp-json/` routes specifically. This was confirmed during testing of this plugin. Local development is fine for everything except verifying the MCP-server-to-REST-API auth flow itself; for that path you need a hosted site with HTTPS. HTTP (non-HTTPS) Local sites may also require `define('WP_ENVIRONMENT_TYPE', 'local');` in `wp-config.php` to allow Application Passwords at all — check via WordPress's Site Health tool.

### Claude Desktop picks the wrong Node version

The setup command detects Node via `ls -d ~/.nvm/versions/node/v*/bin/node | sort -V | tail -1` (newest nvm version) and falls back to `which node` if nvm is not installed. If you installed Node via Homebrew, the fallback finds `/opt/homebrew/bin/node` or `/usr/local/bin/node`, which works fine.

If Claude Desktop reports a Node version error, edit `claude_desktop_config.json` directly and change the `command` value to the full path of the Node binary you want to use. Restart Claude Desktop afterwards.

### "Method not found" or silent connector failure

Open Claude Desktop's developer console (Help → Developer Tools). Look for MCP handshake logs. The most common cause of handshake failure is a protocol-version mismatch — the MCP server echoes the client's reported protocol version verbatim to avoid this. If you see explicit protocol-version rejection messages, your Claude Desktop build may be ahead of what the shipped connector supports. File an issue referencing the version.

### ModSecurity WAF on shared hosting (Bluehost, GoDaddy, some managed WordPress hosts)

Symptoms: POST requests to `/wp-json/fre/v1/connector/forms` return generic 403 with no WordPress error body, or connection closes mid-request.

**Fixes baked into the connector**:
- `User-Agent` starts with `WordPress/` so the request looks like a WordPress-originated call rather than a generic Node.js client.
- `Connection: close` and explicit `Content-Length` on POST bodies prevent chunked Transfer-Encoding, which many ModSecurity rules block for write requests.

If you still see 403s from the WAF: ask the host to whitelist your WordPress site's outbound traffic to the connector endpoint. Most will; this is a common request.

### The connector works, but `formengine_list_entries` returns `403 entry_access_disabled`

This is the inner gate (§5 of the connector spec) doing its job. Turn on **Allow Claude Cowork to read form submissions** on the Claude Connection admin page.

### `Form configuration not found` after creating a form in the same Cowork session

The runtime form registry is populated once per HTTP request, at `fre_init` time. When the connector creates a new form via `POST /forms`, the registry for *that* request does not see the new form, but every subsequent request does. In practice this is a non-issue because each Cowork tool call is a separate HTTP request — the registry is always fresh.

If you see this error it means a single request is trying to create and submit in the same call, which should not happen through normal Cowork workflows.

---

## 7. Security posture

The connector opens two distinct surfaces to the outside world, each off by default:

- The connector REST namespace (`/wp-json/fre/v1/connector/*`) is gated by a site-wide toggle. Default off.
- Entry-read endpoints are gated by a second, independent toggle. Default off.

Credentials are WordPress Application Passwords. They can be revoked at any time from the Claude Connection admin page or from WordPress's native Users → Profile → Application Passwords view. Revocation is immediate; no cache.

The MCP server script (`form-engine-connector.js`) contains no secrets. It reads credentials from environment variables at launch, which are stored in the Claude Desktop config file on your local machine. The script is served publicly by the plugin (at `?action=fre_download_connector`) so the setup command can curl it without juggling authenticated downloads.

---

## 8. Uninstall

From the admin: **Form Entries → Claude Connection → Revoke Connection**. This deletes the Application Password immediately.

To remove the local components (after deactivating the plugin):

```bash
rm -rf ~/form-engine-mcp
# Edit ~/Library/Application Support/Claude/claude_desktop_config.json
# and remove the "form-engine-wordpress" entry under mcpServers.
```

Plugin uninstall through WordPress (Plugins → Delete) cleans up the capability grant, the toggles, and all connector-related options automatically.

---

## 9. Related documentation

- **`docs/CONNECTOR_SPEC.md`** — REST API contract. Read this if you want to understand what each tool actually does.
- **`docs/form-schema.json`** — Form configuration schema. Claude Cowork references this when generating form JSON.
- **`docs/COWORK_CONNECTOR_ASSESSMENT.md`** — Architectural rationale for the whole connector design.
- **`ai-section-builder-modern/docs/development/MCP_CONNECTOR_SETUP.md`** — The parent project's setup document. Many of the cross-host compatibility fixes there apply to this connector too.
