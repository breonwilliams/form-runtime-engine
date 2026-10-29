#!/usr/bin/env bash
#
# The verification gate, as Claude Code hooks and as a command. Same shape
# as ai-section-builder-modern's scripts/claude-hooks/full-gate.sh:
#
#   verify.sh stop          Stop / SubagentStop hook (JSON on stdin)
#   verify.sh prompt        UserPromptSubmit hook: records the tree the turn
#                           started from, and honours UNLOCK-VERIFICATION
#   verify.sh agent-start   SubagentStart hook: records the tree a subagent
#                           started from
#   verify.sh run           The same checks from a shell; exit 0 = green.
#                           This is the orchestrator's verify command.
#
# WHAT IT RUNS, in order: tamper check (protected paths vs
# refs/verify/baseline), php -l over every shipped file, the Unit suite, the
# Integration suite against Local's MySQL. Anything that cannot be checked
# (Local stopped, test library missing, baseline missing) is a failure.
#
# WHEN IT RUNS. The tree is fingerprinted (git tree hash of the working copy,
# ignored files and .orchestrator/ excluded) together with the baseline ref:
#   - a fingerprint that already passed passes again without re-running;
#   - a turn (or subagent) that changed nothing is not blocked for a red tree
#     it did not cause — it is warned instead;
#   - anything else runs the gate.
#
# A red gate blocks (exit 2) up to MAX_BLOCKS times in a row, then lets the
# turn end with a loud NOT VERIFIED message. Without the cap a gate the agent
# cannot satisfy is an endless, billed loop (this repo had one: 2026-10-29).
# A subagent let through red is caught again by the main session's Stop
# gate, because the tree is still red.
#
# UNLOCK. A prompt whose first line is exactly UNLOCK-VERIFICATION unlocks the
# main session's guard for that turn (guard.php reads the marker). Subagents
# stay locked. The lock returns when the turn actually ends — green, or let
# through after MAX_BLOCKS — so an unlocked turn can finish its change,
# including moving refs/verify/baseline, before the tamper check runs. Every
# unlock, and any baseline move made under it, is appended to unlock.log.

set -uo pipefail

MAX_BLOCKS=3
LOCK_WAIT_SECONDS=600
UNLOCK_TTL_MS=$((2 * 60 * 60 * 1000))

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# PFORMS_GATE_STATE_DIR is for testing the hooks without touching real state.
# Hooks run in the harness's environment, which an agent cannot set.
STATE_DIR="${PFORMS_GATE_STATE_DIR:-$HOME/.claude/verification-gate/form-runtime-engine}"
WP_TESTS_DIR="$HOME/.wp-tests/wordpress-tests-lib"
DB_LOCK="$HOME/.wp-tests/.gate.lock"
BASELINE=refs/verify/baseline
PHPUNIT_STRICT=(--fail-on-risky --fail-on-incomplete)
mkdir -p "$STATE_DIR/logs"

# Every path whose content decides what "verified" means.
PROTECTED=(tests phpunit.xml phpunit.xml.dist composer.json composer.lock .gitignore bin/install-wp-tests.sh .github .claude/settings.json .claude/hooks)

caveats() {
	cat <<EOF
NOT covered by this gate (see "Verification gates" in CLAUDE.md):
  - PHP version: ran on local PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo '?'). CI's 7.4 and 8.2 legs
    did not run, so minimum-version (7.4) support is NOT proven.
  - The stack: only this plugin's checks ran. Nothing verified it against FlowMint,
    which depends on the entry classes and pforms_submission_complete.
EOF
}

plugin_root() { # $1 = a directory; prints the plugin checkout containing it
	local r
	r=$(git -C "$1" rev-parse --show-toplevel 2>/dev/null) || return 1
	[ -f "$r/form-runtime-engine.php" ] || return 1
	echo "$r"
}

fingerprint() { # $1 = repo root; working-tree hash + baseline, minus bookkeeping
	local idx tree base
	idx=$(mktemp "$STATE_DIR/index.XXXXXX")
	cp "$(git -C "$1" rev-parse --path-format=absolute --git-path index)" "$idx" 2>/dev/null
	GIT_INDEX_FILE="$idx" git -C "$1" add -A -- . >/dev/null 2>&1
	GIT_INDEX_FILE="$idx" git -C "$1" rm -r -q --cached --ignore-unmatch -- .orchestrator >/dev/null 2>&1
	tree=$(GIT_INDEX_FILE="$idx" git -C "$1" write-tree 2>/dev/null)
	rm -f "$idx"
	# The baseline is part of what was verified: the same tree checked against
	# a different baseline is a different result.
	base=$(git -C "$1" rev-parse -q --verify "$BASELINE" 2>/dev/null || echo none)
	[ -n "$tree" ] && echo "$tree-${base:0:12}"
}

is_green() { [ -f "$STATE_DIR/green" ] && grep -qx "$1" "$STATE_DIR/green"; }

record_green() {
	echo "$1" >> "$STATE_DIR/green"
	tail -n 50 "$STATE_DIR/green" > "$STATE_DIR/green.tmp" && mv "$STATE_DIR/green.tmp" "$STATE_DIR/green"
	rm -f "$STATE_DIR/red-$1"
}

# One Integration run at a time across every checkout: each run rebuilds the
# wptests_ tables in the same database, so parallel runs corrupt each other.
acquire_db_lock() {
	local waited=0 holder
	until mkdir "$DB_LOCK" 2>/dev/null; do
		holder=$(cat "$DB_LOCK/pid" 2>/dev/null)
		if [ -n "$holder" ] && ! kill -0 "$holder" 2>/dev/null; then
			rm -rf "$DB_LOCK"; continue # holder died; the lock is stale
		fi
		[ "$waited" -ge "$LOCK_WAIT_SECONDS" ] && return 1
		sleep 1; waited=$((waited + 1))
	done
	echo $$ > "$DB_LOCK/pid"
}
release_db_lock() { rm -rf "$DB_LOCK"; }

# Every check against repo root $1. Prints what it did; returns 0 only when
# every check ran and passed. The first failing check ends the run.
run_checks() {
	local root=$1 out
	out=$(mktemp)
	cd "$root" || return 1

	# --- tamper -------------------------------------------------------------
	if ! git rev-parse -q --verify "$BASELINE" > /dev/null; then
		echo "GATE FAILED at tamper: $BASELINE is missing, so the verification layer cannot be checked."
		echo "The user restores it with .claude/hooks/rebaseline.sh."
		return 1
	fi

	local bad=() status path
	# Baseline tree vs working tree: catches edits, deletions and commits alike.
	# A file added since the baseline is allowed only under tests/ (new tests).
	while IFS=$'\t' read -r status path; do
		[ -z "$status" ] && continue
		[ "$status" = A ] && [[ "$path" == tests/* ]] && continue
		bad+=("  $status  $path")
	done < <(git diff --name-status --no-renames "$BASELINE" -- "${PROTECTED[@]}")
	# A new test may be edited until it is committed; after that it is locked.
	while IFS=$'\t' read -r status path; do
		[ -z "$status" ] || [ "$status" = A ] && continue
		bad+=("  $status  $path (committed test changed since HEAD)")
	done < <(git diff --name-status --no-renames HEAD -- tests)
	# Untracked files that would change config: a new workflow, hook, phpunit.xml.dist.
	while IFS= read -r path; do
		[ -z "$path" ] || [[ "$path" == tests/* ]] && continue
		bad+=("  ?  $path (untracked)")
	done < <(git ls-files --others --exclude-standard -- "${PROTECTED[@]}")

	if [ ${#bad[@]} -gt 0 ]; then
		echo "GATE FAILED at tamper: the verification layer differs from $BASELINE ($(git rev-parse --short "$BASELINE")):"
		printf '%s\n' "${bad[@]}"
		echo
		echo "Agents: put these back (git diff $BASELINE -- <path> shows the change); weakening a check is not a fix."
		echo "User: if you made this change deliberately, commit it and run .claude/hooks/rebaseline.sh,"
		echo "or have Claude do it in a turn that starts with UNLOCK-VERIFICATION."
		return 1
	fi
	echo "Tamper: verification layer matches $BASELINE ($(git rev-parse --short "$BASELINE"))."

	# --- lint ---------------------------------------------------------------
	if [ ! -f vendor/autoload.php ]; then
		echo "GATE FAILED at lint: vendor/ is missing: run composer install in $root."
		return 1
	fi
	if ! find . -name '*.php' -not -path './vendor/*' -not -path './build/*' -not -path './release/*' -print0 |
		xargs -0 -n1 -P8 php -l > "$out" 2>&1; then
		echo "GATE FAILED at lint:"
		grep -E 'Parse error|Fatal error|Errors parsing' "$out" | head -20
		return 1
	fi
	echo "Lint: php -l clean."

	# --- unit ---------------------------------------------------------------
	if ! TEST_SUITE=unit ./vendor/bin/phpunit -c phpunit.xml --testsuite Unit "${PHPUNIT_STRICT[@]}" > "$out" 2>&1; then
		echo "GATE FAILED at unit (composer run test:unit):"
		tail -60 "$out"
		return 1
	fi
	echo "Unit: $(grep -E '^(OK|Tests:)' "$out" | tail -1)"

	# --- integration --------------------------------------------------------
	local config="$WP_TESTS_DIR/wp-tests-config.php" db_host socket
	if [ ! -f "$WP_TESTS_DIR/includes/functions.php" ] || [ ! -f "$config" ]; then
		echo "GATE FAILED at integration: WordPress test library not found at $WP_TESTS_DIR."
		echo "Install it (as the user): see 'Verification gates' in CLAUDE.md."
		return 1
	fi
	db_host="$(sed -n "s/.*'DB_HOST'[^']*'\([^']*\)'.*/\1/p" "$config")"
	socket="${db_host#*:}"
	if [ "$socket" != "$db_host" ] && [ ! -S "$socket" ]; then
		echo "GATE FAILED at integration: the Local site's MySQL socket is not there: $socket"
		echo "Start the 'AI Section Builder' site in Local. (The environment, not the code.)"
		return 1
	fi
	if ! acquire_db_lock; then
		echo "GATE FAILED at integration: another gate run held $DB_LOCK for ${LOCK_WAIT_SECONDS}s."
		return 1
	fi
	TEST_SUITE=integration WP_TESTS_DIR="$WP_TESTS_DIR" ./vendor/bin/phpunit -c phpunit.xml --testsuite Integration "${PHPUNIT_STRICT[@]}" > "$out" 2>&1
	local rc=$?
	release_db_lock
	if [ "$rc" -ne 0 ]; then
		echo "GATE FAILED at integration:"
		tail -60 "$out"
		return 1
	fi
	echo "Integration: $(grep -E '^(OK|Tests:)' "$out" | tail -1)"
	rm -f "$out"
	return 0
}

# One full run. Prints the log path; returns the gate's result.
gate() { # $1 = root, $2 = fingerprint
	local log="$STATE_DIR/logs/$(date +%Y%m%d-%H%M%S)-${2:0:12}.log" rc
	{ echo "== Verification gate: $1"; echo "== $(date '+%Y-%m-%d %H:%M:%S')"; } > "$log"
	(run_checks "$1") >> "$log" 2>&1; rc=$?
	ls -1t "$STATE_DIR/logs" | tail -n +21 | sed "s|^|$STATE_DIR/logs/|" | xargs rm -f 2>/dev/null
	if [ "$rc" -eq 0 ]; then record_green "$2"; else echo "$log" > "$STATE_DIR/red-$2"; fi
	echo "$log"
	return "$rc"
}

end_unlock() { # $1 = session, $2 = root; the turn is over, so is the unlock
	local f="$STATE_DIR/unlock-$1" was now
	[ -f "$f" ] || return 0
	was=$(sed -n 2p "$f")
	now=$(git -C "$2" rev-parse -q --verify "$BASELINE" 2>/dev/null || echo none)
	if [ "$was" != "$now" ]; then
		echo "$(date '+%F %T') session $1: baseline moved ${was:0:12} -> ${now:0:12} during an unlocked turn ($(git -C "$2" log -1 --format='%h %s' "$now" 2>/dev/null))" >> "$STATE_DIR/unlock.log"
	fi
	echo "$(date '+%F %T') session $1: locked again (turn ended)" >> "$STATE_DIR/unlock.log"
	rm -f "$f"
}

cmd_stop() {
	local input event session agent active cwd root now start key n log rc
	input=$(cat)
	event=$(jq -r '.hook_event_name // "Stop"' <<<"$input")
	session=$(jq -r '.session_id // "none"' <<<"$input")
	agent=$(jq -r '.agent_id // empty' <<<"$input")
	active=$(jq -r '.stop_hook_active // false' <<<"$input")
	cwd=$(jq -r '.cwd // empty' <<<"$input")
	root=$(plugin_root "${cwd:-$PWD}") || root=$(plugin_root "$ROOT") || exit 0

	allow() { [ "$event" = "Stop" ] && end_unlock "$session" "$root"; exit 0; }

	now=$(fingerprint "$root")
	if [ -z "$now" ]; then
		echo "Verification gate could not fingerprint $root (git failed); refusing to vouch for it." >&2
		exit 2
	fi
	is_green "$now" && allow

	if [ "$event" = "SubagentStop" ] && [ -n "$agent" ]; then
		start=$(cat "$STATE_DIR/agent-$agent" 2>/dev/null); key="agent-$agent"
	else
		start=$(cat "$STATE_DIR/turn-$session" 2>/dev/null); key="main-$session"
	fi

	if [ -n "$start" ] && [ "$start" = "$now" ]; then
		# Nothing changed since this turn / subagent began. Whatever state the
		# tree is in, it is not this turn's doing, so warn rather than block.
		if [ -f "$STATE_DIR/red-$now" ]; then
			jq -n --arg log "$(cat "$STATE_DIR/red-$now")" \
				'{systemMessage: ("Verification gate: the tree is still RED (unchanged this turn). Last run: " + $log)}'
		fi
		allow
	fi

	if [ "$active" = "true" ]; then
		n=$(( $(cat "$STATE_DIR/attempts-$key" 2>/dev/null || echo 0) + 1 ))
	else
		n=1
	fi
	echo "$n" > "$STATE_DIR/attempts-$key"

	log=$(gate "$root" "$now"); rc=$?
	if [ "$rc" -eq 0 ]; then
		rm -f "$STATE_DIR/attempts-$key"
		jq -n --arg c "$(caveats)" --arg s "$(grep -E '^(Tamper|Lint|Unit|Integration):' "$log")" \
			'{systemMessage: ("Verification gate: GREEN.\n" + $s + "\n" + $c)}'
		allow
	fi

	if [ "$n" -gt "$MAX_BLOCKS" ]; then
		rm -f "$STATE_DIR/attempts-$key"
		jq -n --arg log "$log" --arg n "$MAX_BLOCKS" --arg f "$(grep -m1 'GATE FAILED' "$log")" \
			'{systemMessage: ("Verification gate: RED. NOT VERIFIED. Blocked " + $n + " times and let the turn end so it does not loop.\n" + $f + "\nLog: " + $log)}'
		allow
	fi

	{
		echo "Verification gate: RED — you may not finish yet (block $n of $MAX_BLOCKS)."
		echo "Fix the code, not the checks: tests, their configs and the hooks are protected."
		echo "If a check is genuinely wrong, say so and stop; the person decides."
		echo "Full log: $log"
		echo
		grep -v '^==' "$log" | head -80
	} >&2
	exit 2
}

cmd_prompt() {
	local input session root now first
	input=$(cat)
	session=$(jq -r '.session_id // "none"' <<<"$input")
	root=$(plugin_root "$(jq -r '.cwd // empty' <<<"$input")") || root=$(plugin_root "$ROOT") || root=""
	if [ -n "$root" ] && now=$(fingerprint "$root"); then
		echo "$now" > "$STATE_DIR/turn-$session"
	fi
	# Only the first line counts, and it must be exactly the word, so an app
	# event or a pasted log that merely contains it cannot unlock anything.
	first=$(jq -r '.prompt // ""' <<<"$input" | head -n 1 | tr -d '\r' | sed 's/[[:space:]]*$//')
	if [ "$first" = "UNLOCK-VERIFICATION" ]; then
		{
			echo $(( $(date +%s) * 1000 + UNLOCK_TTL_MS ))
			git -C "${root:-$ROOT}" rev-parse -q --verify "$BASELINE" 2>/dev/null || echo none
		} > "$STATE_DIR/unlock-$session"
		echo "$(date '+%F %T') session $session: UNLOCKED by the user's prompt" >> "$STATE_DIR/unlock.log"
		echo "The person has unlocked the verification layer (tests, check configs, CI, hooks, the baseline ref) for this turn only, for the change they describe. It locks again when this turn ends. Subagents stay locked: make protected changes yourself, not through a subagent. To finish a deliberate change to the layer: commit it, then run git update-ref refs/verify/baseline HEAD, so the tamper check accepts it; the move is logged."
	fi
	exit 0
}

cmd_agent_start() {
	local input agent root now
	input=$(cat)
	agent=$(jq -r '.agent_id // empty' <<<"$input")
	[ -n "$agent" ] || exit 0
	root=$(plugin_root "$(jq -r '.cwd // empty' <<<"$input")") || root=$(plugin_root "$ROOT") || exit 0
	now=$(fingerprint "$root") && echo "$now" > "$STATE_DIR/agent-$agent"
	find "$STATE_DIR" -maxdepth 1 \( -name 'agent-*' -o -name 'turn-*' -o -name 'attempts-*' -o -name 'red-*' \) -mtime +7 -delete 2>/dev/null
	exit 0
}

cmd_run() {
	local root now log rc
	root=$(plugin_root "$PWD") || root=$(plugin_root "$ROOT") || { echo "Not inside a form-runtime-engine checkout." >&2; exit 1; }
	now=$(fingerprint "$root") || { echo "Could not fingerprint $root." >&2; exit 1; }
	log=$(gate "$root" "$now"); rc=$?
	grep -v '^==' "$log"
	echo
	if [ "$rc" -eq 0 ]; then echo "VERIFICATION GATE: GREEN (tree $now)"; else echo "VERIFICATION GATE: RED — log: $log"; fi
	caveats
	exit "$rc"
}

case "${1:-}" in
	stop) cmd_stop ;;
	prompt) cmd_prompt ;;
	agent-start) cmd_agent_start ;;
	run) cmd_run ;;
	*) echo "usage: $0 stop|prompt|agent-start|run" >&2; exit 64 ;;
esac
