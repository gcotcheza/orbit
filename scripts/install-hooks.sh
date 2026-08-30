#!/usr/bin/env bash
# Usage: scripts/install-hooks.sh — arms scripts/hooks for this checkout.
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -x scripts/hooks/pre-commit ]; then
    printf 'install-hooks: scripts/hooks/pre-commit is missing or not executable.\n' >&2
    exit 1
fi

common=$(git rev-parse --git-common-dir)
common=$(cd "$common" && pwd -P)
here=$(pwd -P)

# core.hooksPath is shared config: setting it from a linked worktree arms the
# main checkout too, which on this box is production.
if [ "$common" != "$here/.git" ]; then
    printf 'install-hooks: this is a linked worktree of %s, and core.hooksPath is\n' "${common%/*}" >&2
    printf 'shared with it. Run this in that checkout instead. Refusing.\n' >&2
    exit 1
fi

global=$(git config --global --get core.hooksPath || true)

git config core.hooksPath scripts/hooks

printf 'core.hooksPath = %s\n' "$(git config --local --get core.hooksPath)"

if [ -n "$global" ]; then
    printf 'This overrides the global guard at %s, which no longer runs here.\n' "$global"
    printf 'scripts/hooks/pre-commit is a superset of it.\n'
fi
