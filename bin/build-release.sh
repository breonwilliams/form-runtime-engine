#!/bin/bash
#
# Build a release ZIP for Form Runtime Engine.
#
# Produces one of two build flavors from the same source tree:
#
#   GitHub build (default):
#     - Includes the GitHub auto-updater (includes/Updates/).
#     - Output: ./build/form-runtime-engine.zip
#     - Distributed via GitHub Releases.
#
#   WP.org build (--wporg):
#     - Excludes the GitHub auto-updater (WP.org guideline #8 prohibits
#       plugins from overriding the WordPress update mechanism).
#     - Excludes additional dev-only markdown files (CLAUDE.md, *_AUDIT.md,
#       RELEASE.md, README.md) — WP.org reads metadata from readme.txt instead.
#     - Output: ./build/form-runtime-engine-wporg.zip
#     - Distributed via the WordPress.org plugin directory SVN.
#
# Usage:
#   ./bin/build-release.sh            # GitHub build (default)
#   ./bin/build-release.sh --github   # Explicit GitHub build (same as default)
#   ./bin/build-release.sh --wporg    # WordPress.org-compliant build
#   ./bin/build-release.sh --help     # Show this usage
#
# Both flavors contain a root folder named "form-runtime-engine/" so
# WordPress recognizes them as the same plugin when uploaded/updated.
# Only the file contents inside that folder differ between flavors.

set -e

# Parse command-line flags.
BUILD_TARGET="github"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --wporg)
            BUILD_TARGET="wporg"
            shift
            ;;
        --github)
            BUILD_TARGET="github"
            shift
            ;;
        --help|-h)
            cat <<'USAGE'
Usage:
  ./bin/build-release.sh            # GitHub build (default)
  ./bin/build-release.sh --github   # Explicit GitHub build (same as default)
  ./bin/build-release.sh --wporg    # WordPress.org-compliant build
  ./bin/build-release.sh --help     # Show this usage

GitHub build:
  - Includes the GitHub auto-updater (includes/Updates/).
  - Output: ./build/form-runtime-engine.zip
  - Distributed via GitHub Releases.

WP.org build:
  - Excludes the GitHub auto-updater (WP.org guideline #8).
  - Excludes dev-only markdown (CLAUDE.md, *_AUDIT.md, RELEASE.md, README.md).
  - Output: ./build/form-runtime-engine-wporg.zip
  - Distributed via the WordPress.org plugin directory SVN.
USAGE
            exit 0
            ;;
        *)
            echo "Error: Unknown option: $1"
            echo "Run with --help for usage."
            exit 1
            ;;
    esac
done

PLUGIN_SLUG="form-runtime-engine"
BUILD_DIR="build"
TEMP_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

# ZIP name differs by target so both flavors can coexist in ./build/
# without one overwriting the other.
if [ "$BUILD_TARGET" = "wporg" ]; then
    ZIP_NAME="${PLUGIN_SLUG}-wporg.zip"
else
    ZIP_NAME="${PLUGIN_SLUG}.zip"
fi

# Get the version from the main plugin file.
VERSION=$(grep -m1 "define( 'FRE_VERSION'" form-runtime-engine.php | sed "s/.*'\\(.*\\)'.*/\\1/")

if [ -z "$VERSION" ]; then
    echo "Error: Could not detect plugin version."
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

echo "Building ${PLUGIN_SLUG} v${VERSION} for ${BUILD_TARGET}..."

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
.DS_Store
Thumbs.db
BASE_EXCLUDES

# WP.org-only exclusions. These files exist for developer/repo use but are
# not part of the shipping plugin. Plugin Check rejects the GitHub auto-
# updater (guideline #8 — plugins must use WordPress's update mechanism)
# and warns about "unexpected markdown files" in the plugin root.
if [ "$BUILD_TARGET" = "wporg" ]; then
    cat >> "${EXCLUDE_FILE}" <<'WPORG_EXCLUDES'
includes/Updates
CLAUDE.md
FORM_RUNTIME_AUDIT.md
RELEASE.md
README.md
WPORG_EXCLUDES
fi

# Copy production files using the assembled exclude list.
rsync -av --exclude-from="${EXCLUDE_FILE}" . "${TEMP_DIR}/"

echo "Creating zip..."

# Create the zip from the build directory so the root folder is correct.
cd "${BUILD_DIR}"
zip -r "${ZIP_NAME}" "${PLUGIN_SLUG}/" -x "*.DS_Store" "*/.git/*"
cd ..

# Report.
ZIP_SIZE=$(du -h "${BUILD_DIR}/${ZIP_NAME}" | cut -f1)
echo ""
echo "Done! Created ${BUILD_DIR}/${ZIP_NAME} (${ZIP_SIZE})"
echo "Version: ${VERSION}"
echo "Target:  ${BUILD_TARGET}"
echo ""

if [ "$BUILD_TARGET" = "wporg" ]; then
    echo "Next steps (WordPress.org):"
    echo "  1. Verify the staged build with Plugin Check:"
    echo "     wp plugin check ${BUILD_DIR}/${PLUGIN_SLUG} --format=table"
    echo "  2. Commit to WordPress.org SVN trunk and tag for release."
    echo "     (see RELEASE.md for the SVN workflow once it's documented)"
else
    echo "Next steps (GitHub):"
    echo "  1. Upload to GitHub release:"
    echo "     gh release upload v${VERSION} ${BUILD_DIR}/${ZIP_NAME} --clobber"
    echo "  2. Or upload manually via WordPress Admin → Plugins → Add New → Upload Plugin"
fi
