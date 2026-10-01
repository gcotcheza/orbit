#!/usr/bin/env bash
# usage: scripts/standards-drift.sh [checkout]
# Why the canonical clone and not the vendored header: docs/DECISIONS.md
set -Eeuo pipefail

# A literal, like the image-tag check's path and for the same reason: a canonical
# path this step took from its environment could point a mismatch at a green.
canonical=/srv/engineering-standards

here=${1:-$(cd "$(dirname "$0")/.." && pwd -P)}
vendored=$here/docs/STANDARDS.md
version_file=$canonical/VERSION
standard=$canonical/ENGINEERING-STANDARDS.md

refuse() {
    printf 'standards-drift: %s\n' "$1" >&2
    exit 1
}

present() { [ -f "$1" ] && [ -r "$1" ] && [ -s "$1" ]; }

present "$vendored" \
    || refuse "$vendored is missing, unreadable or empty: the fleet standard is not vendored here, so this step has nothing to judge."

header=$(head -n1 "$vendored")
if [[ ! $header =~ ^\<!--\ standards-version:\ ([^[:space:]]+)\ ·\ sha256:\ ([0-9a-f]{64})\ --\>$ ]]; then
    refuse "the first line of $vendored is not the vendoring header: $header"
fi
declared=${BASH_REMATCH[1]}

for file in "$version_file" "$standard"; do
    present "$file" && continue
    # An empty file is that same absence and not drift: read as drift, the remedy
    # has you re-vendor nothing at all and the next run is green over it.
    refuse "$file is missing, unreadable or empty, so nothing said whether this copy is still the standard, and a step that examined nothing is not a pass. The image-tag step reads gate-image-tags.sh out of this clone and refuses without it, so the clone is there and this file is not: restore it from gcotcheza/engineering-standards."
done

current=$(tr -d '[:space:]' <"$version_file")
[ -n "$current" ] || refuse "$version_file names no version."

if [ "$declared" != "$current" ]; then
    refuse "docs/STANDARDS.md declares version $declared and $canonical is on $current. A copy that agrees with its own header agrees with it three amendments later too: re-vendor the canonical file whole and re-stamp the header."
fi

body=$(tail -n +2 "$vendored" | sha256sum | cut -d' ' -f1)
theirs=$(sha256sum <"$standard" | cut -d' ' -f1)

if [ "$body" != "$theirs" ]; then
    refuse "docs/STANDARDS.md hashes $body and $standard hashes $theirs, so the copy is not the file it claims to be a copy of, whatever its header says. Re-vendor the canonical file whole and stamp the header with $theirs."
fi

printf 'standards-drift: docs/STANDARDS.md is version %s and matches %s (%s)\n' "$declared" "$standard" "$body"
