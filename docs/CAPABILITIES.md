# Form Runtime Engine Capabilities

Form Runtime Engine registers one custom WordPress capability for form and
entry management and uses it consistently across the admin UI, REST
endpoints, and the Cowork connector.

## The capability

**`fre_manage_forms`** — Controls access to the Form Entries admin pages
(Form Entries, Forms, Settings, Twilio Text-Back, Claude Connection),
the Forms Manager CRUD UI, the entry detail / export AJAX handlers, the
connector REST endpoints, and the Cowork MCP tools.

Constant in code: `FRE_Capabilities::MANAGE_FORMS`.

## Default role grants

On plugin activation and on every plugin-version upgrade, the capability
is granted to:

- `administrator`

The grant is idempotent — WordPress's `add_cap()` is a no-op when the
role already has the capability, so multiple calls are safe.

## Granting to other roles

### Option A — Hook the filter (preferred)

Add this snippet to a site-specific plugin or your theme's `functions.php`.
Fires once at activation / version-upgrade time:

```php
add_filter( 'fre_default_manage_forms_roles', function ( $roles ) {
    $roles[] = 'editor';
    $roles[] = 'shop_manager';
    return $roles;
} );
```

After adding the filter, deactivate and reactivate FRE (or trigger a
version bump) for the grant to apply to the new roles.

### Option B — Grant manually via WP-CLI

```bash
wp cap add editor fre_manage_forms
wp cap add shop_manager fre_manage_forms
```

### Option C — Grant programmatically

```php
$role = get_role( 'editor' );
if ( $role ) {
    $role->add_cap( 'fre_manage_forms' );
}
```

## Cleanup on uninstall

When the plugin is deleted (not just deactivated), FRE's `uninstall.php`
calls `FRE_Capabilities::revoke_all_capabilities()`, which iterates every
WordPress role and removes the capability. This catches custom roles that
admins may have granted the capability to via `add_cap` directly.

## Pattern parity across the Promptless plugin family

This pattern aligns with:

- **FlowMint Workflows:** `flowmint_manage_workflows` via `FMW_Capabilities::MANAGE_WORKFLOWS` (see [FlowMint CAPABILITIES.md](../../flowmint-workflows/docs/CAPABILITIES.md))
- **PRE (Post Runtime Engine):** `pre_manage_cpts` via `PRE_Capabilities::MANAGE_CAP` (see [PRE CAPABILITIES.md](../../post-runtime-engine/docs/CAPABILITIES.md))
- **Promptless WP:** `promptless_manage_settings` via `\AISB\Modern\Core\Capabilities::MANAGE_SETTINGS` (see [Promptless CAPABILITIES.md](../../ai-section-builder-modern/docs/development/CAPABILITIES.md))

Each plugin owns its own scoped capability. Multi-user sites (agencies
with client editors, e-commerce teams with marketing roles, nonprofit
volunteer setups) can grant per-plugin access without giving up site-wide
super-admin.

### Capability summary across the family

| Plugin | Capability | Constant | Granted to (default) |
|---|---|---|---|
| Form Runtime Engine | `fre_manage_forms` | `FRE_Capabilities::MANAGE_FORMS` | `administrator` |
| FlowMint Workflows | `flowmint_manage_workflows` | `FMW_Capabilities::MANAGE_WORKFLOWS` | `administrator` |
| Post Runtime Engine | `pre_manage_cpts` | `PRE_Capabilities::MANAGE_CAP` | `administrator` |
| Promptless WP | `promptless_manage_settings` | `\AISB\Modern\Core\Capabilities::MANAGE_SETTINGS` | `administrator` |

Each plugin's `default_*_roles()` (or equivalent) is filterable so the
same site-wide grant pattern works on any role model. Each plugin's
`revoke_all_capabilities()` runs on uninstall so role tables stay clean.
