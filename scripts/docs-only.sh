#!/usr/bin/env bash
# usage: scripts/docs-only.sh <merge-sha> | --paths
# Why a docs-only merge lands instead of deploying: docs/DECISIONS.md, a-docs-only-merge-lands-it-does-not-deploy
set -euo pipefail

cd "$(dirname "$0")/.."

usage() {
    {
        printf 'usage: scripts/docs-only.sh <merge-sha> | --paths\n\n'
        printf '  <merge-sha>  classify what pulling <merge-sha> into THIS checkout would\n'
        printf '               change. Run it BEFORE the pull: the comparison is against\n'
        printf '               this checkout HEAD, which is the code that is running.\n'
        printf '  --paths      classify a newline-separated path list read from stdin.\n\n'
        printf 'Exit 0 docs-only, 1 code, 2 nothing to land, 3 refused, 64 misused.\n'
    } >&2
    exit 64
}

refuse() {
    printf 'docs-only.sh: %s\n' "$1" >&2
    printf '  Refusing to call this a docs-only landing. Take the full deploy path.\n' >&2
    exit 3
}

# An allowlist: anything unlisted is code. STANDARDS.md is loaded as agent
# instructions and GO-LIVE.md is a procedure, so both change what runs.
is_documentation() {
    case "$1" in
        docs/STANDARDS.md | docs/GO-LIVE.md) return 1 ;;
        docs/* | design/*) return 0 ;;
        # `*` spans `/` in a case pattern, so the three root files are matched
        # only after every deeper path has been sent to code.
        */*) return 1 ;;
        README* | CHANGELOG* | LICENSE*) return 0 ;;
        *) return 1 ;;
    esac
}

classify() {
    local path documentation=0 code=0

    while IFS= read -r path; do
        if [ -z "$path" ]; then continue; fi
        if is_documentation "$path"; then
            documentation=$((documentation + 1))
        else
            code=$((code + 1))
            printf 'CODE: %s\n' "$path"
        fi
    done

    if [ "$code" -gt 0 ]; then
        exit 1
    fi
    if [ "$documentation" -eq 0 ]; then
        printf 'NOTHING TO LAND\n'
        exit 2
    fi

    printf 'DOCS-ONLY: %d file(s)\n' "$documentation"
    exit 0
}

if [ $# -ne 1 ]; then usage; fi

if [ "$1" = --paths ]; then
    classify
fi

merge=$(git rev-parse --verify --quiet "${1}^{commit}") \
    || refuse "$1 is not a commit in this checkout — fetch it first."

live=$(git rev-parse HEAD)

# A landing is a fast-forward or it is not a landing: anything else means this
# checkout carries a commit the merge does not.
git merge-base --is-ancestor "$live" "$merge" \
    || refuse "HEAD ($(git rev-parse --short HEAD)) is not an ancestor of ${1} ($(git rev-parse --short "$merge"))."

# --no-renames, or a file moved out of app/ into docs/ is reported as the
# destination alone and lands as documentation.
changed=$(git diff --no-renames --name-only "$live" "$merge")

classify <<<"$changed"
