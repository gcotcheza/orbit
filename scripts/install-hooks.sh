#!/usr/bin/env bash
# Usage: scripts/install-hooks.sh — arms scripts/hooks for this checkout.
set -euo pipefail

cd "$(dirname "$0")/.."

for hook in pre-commit commit-msg; do
    if [ ! -x "scripts/hooks/$hook" ]; then
        printf 'install-hooks: scripts/hooks/%s is missing or not executable.\n' "$hook" >&2
        exit 1
    fi
done

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

fleet=$(git config --system --type=path --get core.hooksPath || true)

git config core.hooksPath scripts/hooks

printf 'core.hooksPath = %s\n' "$(git config --local --get core.hooksPath)"

if [ -n "$fleet" ]; then
    printf 'scripts/hooks hands each commit on to the fleet hooks in %s.\n' "$fleet"
else
    printf 'No system core.hooksPath is set, so these hooks have no fleet hook to hand over to.\n'
fi
