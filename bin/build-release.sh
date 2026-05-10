#!/bin/bash
#
# Build a release ZIP for Form Runtime Engine.
#
# Creates a properly-named zip file (form-runtime-engine.zip) with the
# correct directory structure so WordPress recognizes it as the same
# plugin when uploaded/updated. GitHub's auto-generated zipballs use
# a different folder name which causes duplicate plugin installs.
#
# Usage:
#   ./bin/build-release.sh
#
# Output:
#   ./build/form-runtime-engine.zip
#
# The zip contains a root folder named "form-runtime-engine/" with
# only the files needed for production (no tests, docs, or dev files).

set -e

PLUGIN_SLUG="form-runtime-engine"
BUILD_DIR="build"
TEMP_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

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
    for m in re.finditer(r'var\\s*\\(\\s*(--aisb-[A-Za-z0-9_-]+)\\s*([,)])', text):
        if m.group(2) == ')':
            ln = text[:m.start()].count(chr(10)) + 1
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

# Clean previous build.
rm -rf "${BUILD_DIR}"
mkdir -p "${TEMP_DIR}"

# Copy production files (exclude dev/test files).
rsync -av --exclude-from=- . "${TEMP_DIR}/" <<'EXCLUDE'
.git
.github
.claude
.gitignore
.phpcs.xml
.phpunit.xml
phpunit.xml
phpunit.xml.dist
composer.lock
node_modules
vendor
tests
bin/install-wp-tests.sh
build
*.log
.DS_Store
Thumbs.db
EXCLUDE

echo "Creating zip..."

# Create the zip from the build directory so the root folder is correct.
cd "${BUILD_DIR}"
zip -r "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}/" -x "*.DS_Store" "*/.git/*"
cd ..

# Report.
ZIP_SIZE=$(du -h "${BUILD_DIR}/${PLUGIN_SLUG}.zip" | cut -f1)
echo ""
echo "Done! Created ${BUILD_DIR}/${PLUGIN_SLUG}.zip (${ZIP_SIZE})"
echo "Version: ${VERSION}"
echo ""
echo "Next steps:"
echo "  1. Upload to GitHub release:"
echo "     gh release upload v${VERSION} ${BUILD_DIR}/${PLUGIN_SLUG}.zip --clobber"
echo "  2. Or upload manually via WordPress Admin → Plugins → Add New → Upload Plugin"
