# Form Runtime Engine — Release Process

**This is the canonical release procedure.** Follow every step in order. If you're an AI assistant (Claude Code, Cowork, etc.) asked to "create a release" or "tag a new version" for this plugin, this document is your source of truth.

The detailed long-form release notes that previously lived in `docs/CLAUDE.md` (lines 714–820) are preserved there for historical context, but this top-level document is the operative checklist.

---

## Distribution model

FRE ships via **GitHub Releases** (not the WordPress.org plugin directory). Customer sites pull updates through the bundled `FRE_GitHub_Updater` class (`includes/Updates/class-fre-github-updater.php`), which hooks `site_transient_update_plugins` to check GitHub for newer release tags.

The repository is **private**, so customer sites need a GitHub Personal Access Token in `wp-config.php` to fetch updates:

```php
define( 'FRE_GITHUB_TOKEN', 'github_pat_…' );
```

Token scope: **Contents: Read** on the `breonwilliams/form-runtime-engine` repo. See the Private Repository Setup section in `docs/CLAUDE.md` for the GitHub token creation steps if you need to issue a new one.

---

## Version-stamp locations

Every release must update the version number in **every** location below. Mismatches break the auto-updater (GitHub tag vs `FRE_VERSION` vs plugin header — they all must agree).

| File | Line / location | Format |
|------|----------------|--------|
| `form-runtime-engine.php` | Header `Version:` comment (~line 6) | `Version: 1.7.0` |
| `form-runtime-engine.php` | `FRE_VERSION` constant (~line 25) | `define( 'FRE_VERSION', '1.7.0' );` |
| `CHANGELOG.md` | Move `[Unreleased]` content under a new heading | `## [1.7.0] — 2026-05-11` |
| Git tag | After commit | `v1.7.0` (with `v` prefix) |

> FRE does not ship a `readme.txt` — Plugin Check was not configured to require one. If you ever add one, also bump its `Stable tag` here.

---

## Pre-release checklist

- [ ] All code changes are committed and pushed to `main`
- [ ] `CHANGELOG.md` has a populated `[Unreleased]` section (move it under the new version heading during release)
- [ ] All four version-stamp locations updated to the new version
- [ ] Plugin Check run locally returns clean (only the GitHub-updater warning is acceptable — see plugin-check notes below)
- [ ] No PHP errors in `debug.log` on a smoke-test install
- [ ] If schema-affecting changes: bump `FRE_DB_VERSION` in the main plugin file too (separate from plugin version)

---

## Release commands (copy/paste-ready)

Replace `1.7.0` with the actual version. Run from the plugin root.

```bash
# 1. Verify the version stamp is consistent (catch typos before tagging).
grep -E "^ \* Version:|FRE_VERSION" form-runtime-engine.php

# 2. Commit the version bump.
git add -A
git commit -m "Release v1.7.0"

# 3. Tag with v prefix (the updater requires it).
git tag v1.7.0

# 4. Push branch and tag to GitHub.
git push origin main --tags

# 5. Build the release ZIP. This script handles vendor/ and strips dev files.
./bin/build-release.sh

# 6. Create the GitHub Release and attach the ZIP.
gh release create v1.7.0 build/form-runtime-engine.zip \
    --title "v1.7.0" \
    --notes-file CHANGELOG.md
```

For the release notes, you can also write a focused summary instead of dumping the whole CHANGELOG:

```bash
gh release create v1.7.0 build/form-runtime-engine.zip \
    --title "v1.7.0" \
    --notes "Bug fixes and Plugin Check compliance. See CHANGELOG.md for details."
```

**Critical:** Always attach the ZIP from `build/form-runtime-engine.zip`. GitHub's auto-generated source zipball uses a folder name like `breonwilliams-form-runtime-engine-abc1234/`, which would cause WordPress to install the update as a NEW plugin alongside the existing one instead of replacing it.

---

## Post-release verification

1. Open the GitHub release page. Confirm the ZIP asset is attached (not just the source tarball).
2. On a test WordPress site that already has the old version of FRE installed, visit **Plugins → Updates**. The new version should appear within an hour (or trigger manually via WP-CLI: `wp transient delete update_plugins`).
3. Apply the update and verify the plugin still activates without PHP errors.
4. Spot-check a form submission to confirm the runtime is healthy.

---

## CHANGELOG format

Follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/):

```markdown
## [Unreleased]

## [1.7.0] — 2026-05-11

### Added
- New feature

### Changed
- Modified behavior

### Fixed
- Bug fix
```

Allowed sections: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.

---

## Version numbering

[Semantic Versioning](https://semver.org/) — `MAJOR.MINOR.PATCH`:

- **MAJOR** — breaking changes (config shape changes, removed hooks, etc.)
- **MINOR** — new features, backward compatible
- **PATCH** — bug fixes only, no API changes

---

## Plugin Check expectations

The current Plugin Check report should return clean except for **one** known finding:

- `includes/Updates/class-fre-github-updater.php` — *"Plugin Updater detected"* (severity 9)

This is intentional. WordPress.org forbids plugin-bundled updaters because they provide the update mechanism for WP.org-hosted plugins. FRE is self-hosted via GitHub Releases, so the bundled updater is required. The file carries a header comment explaining this, but the Plugin Check tool uses a custom PHP-based check that doesn't honor `phpcs:ignoreFile` directives — so the warning will persist even though it's understood.

If any **other** Plugin Check error appears, fix it before tagging.

---

## Emergency rollback

If a bad release ships:

1. **Immediately tag a fix**: bump the patch version, fix the issue, and follow the full release flow above with the new version.
2. **Don't delete the bad tag** — users who already updated are pinned to it. Forcing them backwards is harder than rolling forward.
3. Note the regression in `CHANGELOG.md` under the new version's `Fixed` section so the audit trail is clear.

---

**Last updated:** 2026-05-11
