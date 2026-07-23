#!/bin/bash
#
# Build a release ZIP for Form Runtime Engine.
#
# Produces a single WordPress.org-compliant package:
#   - Output: ./build/promptless-forms.zip (root folder promptless-forms/)
#   - Distributed via the WordPress.org plugin directory SVN; the same zip
#     is attached to GitHub Releases as a convenience artifact.
#   - Excludes the dev-only markdown files (CLAUDE.md, *_AUDIT.md,
#     RELEASE.md, README.md) — WP.org reads metadata from readme.txt.
#
# The GitHub auto-updater and the old dual GitHub/WP.org build flavors were
# RETIRED when the plugin moved to WordPress.org-only distribution — WP.org
# guideline #8 prohibits plugins from overriding the core update mechanism,
# and Plugin Check flags bundled updaters as an ERROR. This script fails the
# build if a PForms_GitHub_Updater reference reappears (mirrors the guard in
# Promptless CPT Pages).
#
# Usage:
#   ./bin/build-release.sh

set -e

PLUGIN_SLUG="promptless-forms"
BUILD_DIR="build"
TEMP_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

ZIP_NAME="${PLUGIN_SLUG}.zip"

# Get the version from the main plugin file. (Constant was renamed from
# FRE_VERSION to PForms_VERSION in 1.8.0 as part of the WordPress.org
# prefix-compliance rename.)
VERSION=$(grep -m1 "define( 'PForms_VERSION'" form-runtime-engine.php | sed "s/.*'\\(.*\\)'.*/\\1/")

if [ -z "$VERSION" ]; then
    echo "Error: Could not detect plugin version."
    exit 1
fi

# Guard: the GitHub auto-updater was retired (WP.org-only distribution).
# Plugin Check flags bundled updaters as an ERROR (guideline #8); fail the
# build immediately if a reference ever reappears in shipping code.
if grep -rq "PForms_GitHub_Updater" form-runtime-engine.php includes/ 2>/dev/null; then
    echo "Error: PForms_GitHub_Updater reference detected — the GitHub auto-updater"
    echo "was retired (WP.org guideline #8). Remove the reference before building."
    exit 1
fi

# Pre-flight: AISB token contract check.
#
# Verifies every `var(--aisb-X)` reference in shipped CSS includes a
# fallback (`var(--aisb-X, fallback)`). Without fallbacks, the plugin
# silently degrades when the Promptless WP plugin is inactive — forms
# render with transparent / browser-default values instead of a
# documented sane default. This was flagged as Important #2 in
# FORM_RUNTIME_AUDIT.md and the plugin is currently clean. This guard
# prevents future CSS additions from regressing it silently.
echo "Checking AISB token fallback discipline..."
if python3 -c "
import re, sys, glob
gaps = []
for path in glob.glob('assets/css/*.css'):
    if path.endswith('.min.css'):
        continue
    text = open(path).read()
    # Strip CSS comments before checking (avoids false positives from
    # illustrative examples in comment blocks like '/* don't do var(--aisb-X) */')
    stripped = re.sub(r'/\\*.*?\\*/', '', text, flags=re.DOTALL)
    for m in re.finditer(r'var\\s*\\(\\s*(--aisb-[A-Za-z0-9_-]+)\\s*([,)])', stripped):
        if m.group(2) == ')':
            # Find line number in original text by searching for the match
            ln = text[:text.find(m.group(0))].count(chr(10)) + 1 if m.group(0) in text else 0
            gaps.append(f'  {path}:{ln}  var({m.group(1)})')
if gaps:
    print('AISB token contract violation — bare var() calls without fallback:')
    print(chr(10).join(gaps))
    print()
    print('Fix: change var(--aisb-X) to var(--aisb-X, sensible-default).')
    print('See docs/AISB_TOKEN_CONTRACT.md for documented fallbacks.')
    sys.exit(1)
"; then
    echo "  ✓ All var(--aisb-*) calls have fallbacks"
else
    echo ""
    echo "Build aborted: fix the violations above before releasing."
    exit 1
fi

echo "Building ${PLUGIN_SLUG} v${VERSION}..."

# Clean only the staging subdirectory, not the whole build/ folder.
# This lets both GitHub and WP.org ZIPs coexist in build/ when both
# targets are built in sequence.
rm -rf "${TEMP_DIR}"
mkdir -p "${TEMP_DIR}"

# Assemble the rsync exclude list in a temp file so we can conditionally
# append WP.org-specific exclusions without duplicating the base list.
EXCLUDE_FILE=$(mktemp)
trap "rm -f ${EXCLUDE_FILE}" EXIT

# Base exclusions — applied to BOTH GitHub and WP.org builds.
#
# The exclude list intentionally drops:
#   - VCS / IDE state (.git, .github, .claude, .gitignore)
#   - Lint / test config (.phpcs.xml, .phpunit.xml, composer.lock)
#   - Build & test artifacts (build/, release/, *.zip, .phpunit.result.cache, *.log)
#   - The build script itself (we don't ship the script that builds the ZIP)
#   - Source-only dirs (node_modules, vendor, tests)
#   - OS junk (.DS_Store, Thumbs.db)
#
# Plugin Check (the WordPress.org compliance tool) flags ZIPs, hidden files,
# and shell scripts that ship inside a plugin — the additions below keep the
# deployed plugin folder clean of build/dev pollution.
cat > "${EXCLUDE_FILE}" <<'BASE_EXCLUDES'
.git
.github
.claude
.gitignore
.phpcs.xml
.phpunit.xml
.phpunit.result.cache
phpunit.xml
phpunit.xml.dist
composer.lock
node_modules
vendor
tests
bin/install-wp-tests.sh
bin/build-release.sh
build
release
*.zip
*.log
_to_delete
*.bak
*.swp
*.swo
*~
.DS_Store
Thumbs.db
BASE_EXCLUDES

# WP.org compliance exclusions. These files exist for developer/repo use
# but are not part of the shipping plugin. Plugin Check warns about
# "unexpected markdown files" in the plugin root, and the WP.org review
# team has explicitly flagged developer-facing planning documents as
# "AI-generated output" that should not ship — the *_HARDENING_PLAN.md /
# *_AUDIT.md / *_KNOWLEDGE_MAP.md files are internal engineering notes.
# includes/Updates stays excluded as belt-and-suspenders: the retired
# updater directory must never ship even if a file reappears there.
cat >> "${EXCLUDE_FILE}" <<'WPORG_EXCLUDES'
includes/Updates
CLAUDE.md
FORM_RUNTIME_AUDIT.md
RELEASE.md
README.md
docs/FRE_CONNECTOR_HARDENING_PLAN.md
docs/FRE_KNOWLEDGE_MAP.md
docs/COWORK_CONNECTOR_ASSESSMENT.md
docs/CONNECTOR_TESTING_REPORT.md
docs/twilio/CREDENTIAL_ENCRYPTION_AUDIT.md
docs/WORKFLOW_PROMPTLESS_INTEGRATION.md
docs/MULTISITE_READINESS_ASSESSMENT.md
WPORG_EXCLUDES

# Copy production files using the assembled exclude list.
rsync -av --exclude-from="${EXCLUDE_FILE}" . "${TEMP_DIR}/"

echo "Creating zip..."

# Create the zip from the build directory so the root folder is correct.
# Delete any previous archive first — `zip -r` against an existing zip runs
# in UPDATE mode and never removes entries whose source files were deleted,
# so a retired file would silently survive inside the stale archive.
cd "${BUILD_DIR}"
rm -f "${ZIP_NAME}"
zip -r "${ZIP_NAME}" "${PLUGIN_SLUG}/" -x "*.DS_Store" "*/.git/*"
cd ..

# ============================================
# VERIFY ZIP INTERNAL STRUCTURE
# ============================================
# Guards against a flattened/hand-assembled archive (Promptless Theme
# v1.2.5 incident: a manually built zip lost a directory level and fataled
# on every site). Checks the SHIPPED ARTIFACT's manifest, not the staging
# folder. This script is the only sanctioned packaging path.
echo ""
echo "Verifying ZIP internal structure..."

ZIP_MANIFEST=$(unzip -l "${BUILD_DIR}/${ZIP_NAME}")

REQUIRED_ZIP_PATHS=(
    "${PLUGIN_SLUG}/form-runtime-engine.php"
    "${PLUGIN_SLUG}/includes/class-fre-autoloader.php"
    "${PLUGIN_SLUG}/includes/Core/class-fre-submission-handler.php"
    "${PLUGIN_SLUG}/includes/Uploads/class-fre-upload-handler.php"
    "${PLUGIN_SLUG}/includes/Connector/assets/form-engine-connector.js"
    "${PLUGIN_SLUG}/assets/css/frontend.css"
)

ZIP_STRUCTURE_OK=1
for path in "${REQUIRED_ZIP_PATHS[@]}"; do
    if echo "$ZIP_MANIFEST" | grep -q " ${path}$"; then
        echo "  OK  $path"
    else
        echo "  MISSING FROM ZIP: $path"
        ZIP_STRUCTURE_OK=0
    fi
done

# A flattened build puts nested files at the plugin root — detect that too.
if echo "$ZIP_MANIFEST" | grep -q " ${PLUGIN_SLUG}/class-fre-submission-handler.php$"; then
    echo "  FLATTENED STRUCTURE DETECTED: includes/ files found at plugin root"
    ZIP_STRUCTURE_OK=0
fi

# Forbidden paths: the retired updater dir and local scratch clutter must
# never ship. Catches both a reappearing source file and a stale entry
# carried over from a previous archive.
FORBIDDEN_ZIP_PATHS=(
    "${PLUGIN_SLUG}/includes/Updates/"
    "${PLUGIN_SLUG}/_to_delete/"
)
for path in "${FORBIDDEN_ZIP_PATHS[@]}"; do
    if echo "$ZIP_MANIFEST" | grep -q " ${path}"; then
        echo "  FORBIDDEN PATH IN ZIP: $path"
        ZIP_STRUCTURE_OK=0
    fi
done

if [ $ZIP_STRUCTURE_OK -eq 0 ]; then
    rm -f "${BUILD_DIR}/${ZIP_NAME}"
    echo ""
    echo "ERROR: ZIP structure verification FAILED — archive deleted."
    echo "Do NOT hand-assemble release zips; this script is the only sanctioned packaging path."
    exit 1
fi

echo "ZIP structure verified."

# Report.
ZIP_SIZE=$(du -h "${BUILD_DIR}/${ZIP_NAME}" | cut -f1)
echo ""
echo "Done! Created ${BUILD_DIR}/${ZIP_NAME} (${ZIP_SIZE})"
echo "Version: ${VERSION}"
echo ""

echo "Next steps:"
echo "  1. Verify the staged build with Plugin Check:"
echo "     wp plugin check ${BUILD_DIR}/${PLUGIN_SLUG} --format=table"
echo "  2. Deploy to WordPress.org SVN trunk and tag (see RELEASE.md)."
echo "  3. Attach the zip to the GitHub release:"
echo "     gh release create v${VERSION} ${BUILD_DIR}/${ZIP_NAME} --title \"Promptless Forms v${VERSION}\" --notes-file release-notes.md"
echo "     (or: gh release upload v${VERSION} ${BUILD_DIR}/${ZIP_NAME} --clobber)"
