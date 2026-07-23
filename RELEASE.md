# Promptless Forms — Release Process

**This is the canonical release procedure.** Follow every step in order. If you're an AI assistant (Claude Code, Cowork, etc.) asked to "create a release" or "tag a new version" for this plugin, this document is your source of truth.

---

## Distribution model

Promptless Forms is distributed via the **WordPress.org plugin directory**. Updates are handled automatically by WordPress core — no bundled updater required.

- **SVN repository:** `https://plugins.svn.wordpress.org/promptless-forms/`
- **Plugin page:** https://wordpress.org/plugins/promptless-forms/
- **GitHub:** Source control and development only (not for distribution)

> The GitHub auto-updater (`includes/Updates/`) and the old dual GitHub/WP.org build flavors were **retired** in v1.8.3 — WordPress.org is the sole update channel, and Plugin Check flags bundled updaters as an ERROR (guideline #8). `bin/build-release.sh` now produces a single WP.org-compliant package and fails the build if a `PForms_GitHub_Updater` reference reappears. Do not reintroduce an updater.

The WordPress.org SVN repository has this structure:
```
promptless-forms/
├── assets/          # Plugin directory assets (icons, banners, screenshots)
├── tags/            # Immutable version snapshots (1.8.0/, 1.8.1/, etc.)
└── trunk/           # Current development version (users on "Stable tag" get this)
```

---

## Version-stamp locations

Every release must update the version number in **all** locations below. Mismatches break the update mechanism.

| File | Line / location | Format |
|------|----------------|--------|
| `form-runtime-engine.php` | Header `Version:` comment (~line 6) | `Version: 1.8.1` |
| `form-runtime-engine.php` | `PForms_VERSION` constant (~line 25) | `define( 'PForms_VERSION', '1.8.1' );` |
| `readme.txt` | `Stable tag:` header | `Stable tag: 1.8.1` |
| `CHANGELOG.md` | Move `[Unreleased]` content under new heading | `## [1.8.1] — 2026-06-16` |
| Git tag | After commit | `v1.8.1` (with `v` prefix) |

> **Critical:** The `Stable tag` in `readme.txt` tells WordPress.org which tag to serve to users. It must match an existing tag in `tags/`.

---

## Pre-release checklist

- [ ] All code changes are committed and pushed to `main`
- [ ] `CHANGELOG.md` has a populated `[Unreleased]` section (move it under the new version heading during release)
- [ ] All five version-stamp locations updated to the new version
- [ ] `readme.txt` `Requires at least:` is `5.6` or higher (Plugin Check requirement)
- [ ] Plugin Check returns **zero errors** (run on the staged build)
- [ ] No PHP errors in `debug.log` on a smoke-test install
- [ ] If schema-affecting changes: bump `PForms_DB_VERSION` in the main plugin file too

---

## Release commands (copy/paste-ready)

Replace `1.8.1` with the actual version. Run from the plugin root.

### Step 1: Version bump and Git tag

```bash
# 1. Verify version stamps are consistent.
grep -E "^ \* Version:|PForms_VERSION" form-runtime-engine.php
grep "Stable tag" readme.txt

# 2. Commit the version bump.
git add -A
git commit -m "Release v1.8.1 — WordPress multisite support"

# 3. Tag with v prefix.
git tag v1.8.1

# 4. Push branch and tag to GitHub.
git push origin main --tags
```

### Step 2: Build the release

```bash
# Build the WP.org-ready package (single flavor — the updater and the old
# dual GitHub/WP.org builds were retired; see Distribution model above).
./bin/build-release.sh

# This creates:
#   build/promptless-forms/     (staged plugin files)
#   build/promptless-forms.zip  (GitHub release asset / manual upload)
```

### Step 3: Run Plugin Check

```bash
# Requires Local Sites / wp-env running with Plugin Check installed.
wp plugin check build/promptless-forms --format=table

# Must return ZERO errors. Warnings are acceptable.
```

### Step 4: Deploy to WordPress.org SVN

```bash
# Checkout the SVN repo (first time only).
svn checkout https://plugins.svn.wordpress.org/promptless-forms/ ~/svn/promptless-forms

# Or update existing checkout.
cd ~/svn/promptless-forms && svn update

# Sync the build into trunk (preserves .svn directories).
rsync -a --delete --exclude='.svn' \
  /path/to/form-runtime-engine/build/promptless-forms/ \
  ~/svn/promptless-forms/trunk/

# Verify trunk has correct version.
grep "Stable tag" ~/svn/promptless-forms/trunk/readme.txt

# Add new files, remove deleted files.
cd ~/svn/promptless-forms
svn add --force trunk
svn status | grep '^!' | awk '{print $2}' | xargs -r svn delete

# Create the version tag.
svn copy trunk tags/1.8.1

# Review changes.
svn status

# Commit (you'll be prompted for your WP.org password).
svn commit -m "Release 1.8.1 — WordPress multisite support" --username promptlesswp
```

---

## Post-release verification

1. **Check SVN:** Run `svn log -l 1` to confirm the commit succeeded.
2. **Check WordPress.org:** Visit https://wordpress.org/plugins/promptless-forms/ — version should update within a few minutes.
3. **Test update:** On a WordPress site with the old version installed, go to **Dashboard → Updates**. The new version should appear (or trigger manually: `wp transient delete update_plugins`).
4. **Smoke test:** Apply the update and verify the plugin activates without PHP errors. Submit a test form.

---

## CHANGELOG format

Follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/):

```markdown
## [Unreleased]

## [1.8.1] — 2026-06-16

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

## Plugin Check requirements

Before every release, run Plugin Check on the staged build. The check **must return zero errors**.

Common issues to watch for:
- `Requires at least` must be WordPress 5.6 or higher
- No `eval()` or similar unsafe functions
- Proper text domain usage
- Correct file permissions

If Plugin Check reports errors, fix them before deploying to WordPress.org.

---

## Emergency rollback

If a bad release ships:

1. **Immediately tag a fix**: bump the patch version, fix the issue, and follow the full release flow above.
2. **Don't delete the bad SVN tag** — WordPress.org may cache it, and users who already updated are pinned to it.
3. Note the regression in `CHANGELOG.md` under the new version's `Fixed` section.
4. The new `Stable tag` in trunk will automatically serve the fixed version to all users.

---

## WordPress.org SVN credentials

- **Username:** `promptlesswp`
- **Password:** Stored in Breon's password manager (SVN prompts interactively)
- **SVN URL:** `https://plugins.svn.wordpress.org/promptless-forms/`

---

## Asset updates (icons, banners, screenshots)

Plugin directory assets live in `~/svn/promptless-forms/assets/` (not `trunk/assets/`):

| File | Dimensions | Purpose |
|------|------------|---------|
| `icon-128x128.png` | 128×128 | Plugin icon (standard) |
| `icon-256x256.png` | 256×256 | Plugin icon (retina) |
| `banner-772x250.png` | 772×250 | Header banner (standard) |
| `banner-1544x500.png` | 1544×500 | Header banner (retina) |
| `screenshot-1.png` | Variable | Screenshots for plugin page |

To update assets:
```bash
cd ~/svn/promptless-forms
cp /path/to/new-icon.png assets/icon-256x256.png
svn add assets/icon-256x256.png  # If new file
svn commit -m "Update plugin icon" --username promptlesswp
```

---

**Last updated:** 2026-06-16


## Test-install policy (duplicate-plugin prevention)

Only install on test sites from `build/promptless-forms.zip` (or the GitHub
release asset built from it) — never from GitHub's auto-generated "Source
code (zip)" or by copying the dev repo folder, both of which land in a
differently-named plugin folder and make every future release ZIP install
as a DUPLICATE instead of replacing. If a duplicate exists: deactivate old,
activate new, then delete the stale FOLDER from disk — do NOT click Delete
in the Plugins screen (that runs `uninstall.php`, which drops the shared
entry tables; a duplicate-install guard was added 2026-07-11, but don't
rely on it for copies older than that). Full playbook: Promptless CPT
Pages' RELEASE.md, "Test-install policy".
