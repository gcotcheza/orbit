#!/usr/bin/env bash
# Usage: scripts/install-hooks.sh — points this clone's core.hooksPath at
# scripts/hooks. Linked worktrees share it: one clone, one setting.
set -euo pipefail

cd "$(dirname "$0")/.."

[ -x scripts/hooks/pre-commit ] || {
    printf 'install-hooks: scripts/hooks/pre-commit is missing or not executable.\n' >&2
    exit 1
}

git config core.hooksPath scripts/hooks

printf 'core.hooksPath = %s\n' "$(git config core.hooksPath)"
