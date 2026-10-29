#!/usr/bin/env bash
#
# Move refs/verify/baseline to HEAD — the user's deliberate way to change the
# verification layer (tests, phpunit.xml, composer.json, CI, these hooks).
#
#   1. Edit the files yourself (your editor; agents are blocked from them).
#   2. Commit them.
#   3. Run this in your own terminal: .claude/hooks/rebaseline.sh
#
# It needs a terminal to confirm on, so an agent's non-interactive shell cannot
# run it, and the guard refuses commands that name it. The ref lives outside
# refs/tags so `git push --tags` in RELEASE.md never publishes or trips on it.

set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

REF=refs/verify/baseline
PROTECTED=(tests phpunit.xml phpunit.xml.dist composer.json composer.lock .gitignore bin/install-wp-tests.sh .github .claude/settings.json .claude/hooks)

if [ ! -t 0 ] || ! { : < /dev/tty; } 2> /dev/null; then
	echo "rebaseline.sh must be run by you, in an interactive terminal." >&2
	exit 1
fi

dirty="$(git status --porcelain -- "${PROTECTED[@]}")"
if [ -n "$dirty" ]; then
	echo "Commit the verification-layer changes first; these are uncommitted:" >&2
	echo "$dirty" >&2
	exit 1
fi

if old="$(git rev-parse -q --verify "$REF")"; then
	echo "Baseline: $(git log -1 --format='%h %s' "$old")  ->  HEAD: $(git log -1 --format='%h %s' HEAD)"
	echo
	echo "Verification-layer changes this accepts:"
	git diff --stat "$old" HEAD -- "${PROTECTED[@]}" || true
else
	echo "No baseline yet; creating one at HEAD: $(git log -1 --format='%h %s' HEAD)"
fi

echo
read -r -p "Accept HEAD as the new verification baseline? [y/N] " answer < /dev/tty
case "$answer" in
	y | Y) ;;
	*) echo "Unchanged."; exit 1 ;;
esac

git update-ref -m "rebaseline by $(git config user.name) $(date -u +%FT%TZ)" "$REF" HEAD
echo "Baseline is now $(git rev-parse --short "$REF")."
