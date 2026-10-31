#!/usr/bin/env bash
#
# PostToolUse check after Edit/Write/MultiEdit: per-file checks only.
#
# Deliberately no test suite here. A multi-file change is red in the middle
# by definition; the suites run once, at turn end, in verify.sh. Exit 2 shows
# STDERR to the agent so a broken file is fixed while it is still in view.

set -u

path="$(jq -r '.tool_input.file_path // .tool_input.notebook_path // empty')"
[ -n "$path" ] && [ -f "$path" ] || exit 0

case "$path" in
	*.php) out="$(php -l "$path" 2>&1)" ;;
	*.json) out="$(jq empty "$path" 2>&1)" ;;
	*.sh) out="$(bash -n "$path" 2>&1)" ;;
	*.js) command -v node > /dev/null || exit 0; out="$(node --check "$path" 2>&1)" ;;
	*) exit 0 ;;
esac

if [ $? -ne 0 ]; then
	echo "Per-file check failed for $path:" >&2
	echo "$out" >&2
	exit 2
fi
exit 0
