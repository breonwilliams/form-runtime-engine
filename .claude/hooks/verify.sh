#!/usr/bin/env bash
#
# Turn-end verification gate (Stop and SubagentStop hooks).
#
# Exit 2 refuses to let the turn or subagent finish and shows STDERR to the
# agent; exit 0 lets it finish. Fails closed: anything that cannot be checked
# (Local site stopped, test library missing, baseline ref missing) blocks too,
# because an unverified pass is the failure this exists to prevent.
#
# Steps, in order:
#   1. tamper  — protected paths vs refs/verify/baseline (any write method)
#   2. lint    — php -l over every shipped file, as CI does
#   3. unit    — PHPUnit Unit suite
#   4. integ   — PHPUnit Integration suite against Local's MySQL
#
# Can also be run by hand: .claude/hooks/verify.sh < /dev/null

set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT" || exit 2
cat > /dev/null 2>&1 # Drain the hook's JSON input; it is not needed.

WP_TESTS_DIR="$HOME/.wp-tests/wordpress-tests-lib"
LOCK="$HOME/.wp-tests/.gate.lock"
BASELINE=refs/verify/baseline
LOG="$(mktemp -t pforms-gate)"
PHPUNIT_STRICT=(--fail-on-risky --fail-on-incomplete)

# Every path whose content decides what "verified" means.
PROTECTED=(tests phpunit.xml phpunit.xml.dist composer.json composer.lock .gitignore bin/install-wp-tests.sh .github .claude/settings.json .claude/hooks)

cleanup() { rm -f "$LOG"; [ -n "${HAVE_LOCK:-}" ] && rm -rf "$LOCK"; }
trap cleanup EXIT

fail() {
	local step="$1"
	shift
	{
		echo "VERIFICATION GATE FAILED at: $step"
		echo
		printf '%s\n' "$@"
		echo
		echo "This turn cannot finish until the gate is green. Fix the code, not the check."
		echo "If the check itself is wrong, say which and why and leave it for the user."
	} >&2
	exit 2
}

# --- 1. tamper ------------------------------------------------------------

git rev-parse -q --verify "$BASELINE" > /dev/null ||
	fail tamper "$BASELINE is missing, so the verification layer cannot be checked." \
		"The user restores it with .claude/hooks/rebaseline.sh."

bad=()

# Baseline tree vs working tree: catches edits, deletions and commits alike.
# A file added since the baseline is allowed only under tests/ (new tests).
while IFS=$'\t' read -r status path; do
	[ -z "$status" ] && continue
	if [ "$status" = A ] && [[ "$path" == tests/* ]]; then
		continue
	fi
	bad+=("  $status  $path")
done < <(git diff --name-status --no-renames "$BASELINE" -- "${PROTECTED[@]}")

# A new test may be edited until it is committed; after that it is locked.
while IFS=$'\t' read -r status path; do
	[ -z "$status" ] && continue
	[ "$status" = A ] && continue
	bad+=("  $status  $path (committed test changed since HEAD)")
done < <(git diff --name-status --no-renames HEAD -- tests)

# Untracked files that would change config: a new workflow, hook, phpunit.xml.dist.
while IFS= read -r path; do
	[ -z "$path" ] && continue
	[[ "$path" == tests/* ]] && continue
	bad+=("  ?  $path (untracked)")
done < <(git ls-files --others --exclude-standard -- "${PROTECTED[@]}")

if [ ${#bad[@]} -gt 0 ]; then
	fail tamper "The verification layer differs from $BASELINE ($(git rev-parse --short "$BASELINE")):" \
		"${bad[@]}" \
		"" \
		"Agents: put these back (git diff $BASELINE -- <path> shows the change); weakening a check is not a fix." \
		"User: if you made this change deliberately, commit it and run .claude/hooks/rebaseline.sh."
fi

# --- 2. lint --------------------------------------------------------------

[ -f vendor/autoload.php ] || fail lint "vendor/ is missing: run composer install in $ROOT."

if ! find . -name '*.php' -not -path './vendor/*' -not -path './build/*' -not -path './release/*' -print0 |
	xargs -0 -n1 -P8 php -l > "$LOG" 2>&1; then
	fail lint "$(grep -E 'Parse error|Fatal error|Errors parsing' "$LOG" | head -20)"
fi

# --- 3. unit --------------------------------------------------------------

if ! TEST_SUITE=unit ./vendor/bin/phpunit -c phpunit.xml --testsuite Unit "${PHPUNIT_STRICT[@]}" > "$LOG" 2>&1; then
	fail "unit (composer run test:unit)" "$(tail -60 "$LOG")"
fi
unit_summary="$(grep -E '^(OK|Tests:)' "$LOG" | tail -1)"

# --- 4. integration -------------------------------------------------------

config="$WP_TESTS_DIR/wp-tests-config.php"
[ -f "$WP_TESTS_DIR/includes/functions.php" ] && [ -f "$config" ] ||
	fail integration "WordPress test library not found at $WP_TESTS_DIR." \
		"Install it (as the user): see 'Verification gates' in CLAUDE.md."

db_host="$(php -r 'foreach (file($argv[1]) as $l) if (preg_match("/define\\(\\s*.DB_HOST.\\s*,\\s*.(.*).\\s*\\)/", $l, $m)) echo $m[1];' "$config")"
socket="${db_host#*:}"
if [ "$socket" != "$db_host" ] && [ ! -S "$socket" ]; then
	fail integration "The Local site's MySQL socket is not there: $socket" \
		"Start the 'AI Section Builder' site in Local, then finish the turn again." \
		"(This is the environment, not the code. The gate stays closed rather than pass unverified.)"
fi

# One Integration run at a time: every run rebuilds the wptests_ tables in the
# same database, so parallel subagents would corrupt each other's run.
for _ in $(seq 1 600); do
	if mkdir "$LOCK" 2> /dev/null; then
		HAVE_LOCK=1
		echo $$ > "$LOCK/pid"
		break
	fi
	holder="$(cat "$LOCK/pid" 2> /dev/null)"
	if [ -n "$holder" ] && ! kill -0 "$holder" 2> /dev/null; then
		rm -rf "$LOCK"
		continue
	fi
	sleep 1
done
[ -n "${HAVE_LOCK:-}" ] || fail integration "Timed out after 10 minutes waiting for another gate run ($LOCK)."

if ! TEST_SUITE=integration WP_TESTS_DIR="$WP_TESTS_DIR" ./vendor/bin/phpunit -c phpunit.xml --testsuite Integration "${PHPUNIT_STRICT[@]}" > "$LOG" 2>&1; then
	fail integration "$(tail -60 "$LOG")"
fi
integ_summary="$(grep -E '^(OK|Tests:)' "$LOG" | tail -1)"

echo "Verification gate green — baseline $(git rev-parse --short "$BASELINE"), lint ok, Unit: $unit_summary, Integration: $integ_summary" >&2
exit 0
