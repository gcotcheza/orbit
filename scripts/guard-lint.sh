#!/usr/bin/env bash
# usage: scripts/guard-lint.sh — the gate's guard-diff lint over every script under scripts/.
# Why it first lints a copy it broke itself: docs/DECISIONS.md, the-gate-lints-every-guards-diff-reads
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

LINT=/usr/local/sbin/fleet-lint-guard-diff
GUARD=scripts/hooks/pre-commit

if [ ! -x "$LINT" ]; then
    printf 'guard-lint: %s is missing or not executable, so no guard'\''s diff reads were\n' "$LINT" >&2
    printf '  checked. A skipped lint is a silent pass.\n' >&2
    exit 1
fi

"$LINT" scripts || exit $?

work=$(mktemp -d) || exit 1
trap 'rm -rf -- "$work"' EXIT

if ! grep -q 'diff --cached.* --no-ext-diff' "$GUARD"; then
    printf 'guard-lint: %s has no `diff --cached ... --no-ext-diff` read left, so the canary\n' "$GUARD" >&2
    printf '  below could not be built. Point it at the read the hook makes now.\n' >&2
    exit 1
fi
sed '/diff --cached/s/ --no-ext-diff//' "$GUARD" > "$work/pre-commit"

canary=$("$LINT" "$work/pre-commit" 2>&1)
rc=$?
if [ "$rc" -ne 1 ] || [[ $canary != *'--no-ext-diff'* ]]; then
    printf 'guard-lint: the lint passed a copy of %s with --no-ext-diff deleted (exit %s),\n' "$GUARD" "$rc" >&2
    printf '  so its green on scripts/ above says nothing.\n%s\n' "$canary" >&2
    exit 1
fi

printf 'guard-lint: scripts/ is clean, and a copy of the hook with --no-ext-diff deleted is not.\n'
