#!/usr/bin/env bash
# usage: scripts/check.sh dev|overlay
# Why two runners, and what each may not lose: docs/DECISIONS.md, the-gate-is-one-script-two-runners
set -euo pipefail

cd "$(dirname "$0")/.."
here=$(pwd -P)

# CI_GIT is a COMMAND WITH ARGUMENTS, so $GIT is unquoted on purpose; it carries
# its own -C, so no call site below adds one. docs/DECISIONS.md, the-gate-scans-for-secrets-over-gits-view-of-the-tree
GIT=${CI_GIT:-git}

mode=${1-}
if [ $# -ne 1 ] || { [ "$mode" != dev ] && [ "$mode" != overlay ]; }; then
    {
        printf 'usage: scripts/check.sh dev|overlay\n\n'
        printf '  dev      the stack is already up from this directory; the PHP steps\n'
        printf '           run inside it with `docker compose exec`.\n'
        printf '  overlay  one throwaway container per step, with its own vendor/,\n'
        printf '           bootstrap/cache and node_modules/ bind-overlaid; what the\n'
        printf '           deploy runbook uses, because the live vendor/ is installed\n'
        printf '           --no-dev. Run it as root: it chowns its overlay to the uid\n'
        printf '           the containers use.\n\n'
        printf 'The mode is not guessed, and it is the only argument. Name it.\n'
        printf 'CI_GIT names the git the secrets step lists the tree with. The deploy\n'
        printf 'sets it to `git-as orbit -C /var/www/orbit`, because root git cannot\n'
        printf 'read that checkout at all; unset, it is plain `git`. Its -C, if it\n'
        printf 'carries one, must be THIS checkout: the list and the copy are paired.\n'
    } >&2
    exit 2
fi

# The step lists with $GIT and copies with `tar -C "$here"`; a seam pointing
# somewhere else would fill the copy from a tree nothing listed.
seam_dir=$(set -- $GIT; while [ $# -gt 0 ]; do case $1 in -C) printf '%s' "${2-}"; break ;; -C?*) printf '%s' "${1#-C}"; break ;; esac; shift; done)
if [ -n "$seam_dir" ] && [ "$(realpath -- "$seam_dir" 2>/dev/null)" != "$here" ]; then
    printf 'check.sh: CI_GIT points at %s but this script runs in %s — the list and\n' "$seam_dir" "$here" >&2
    printf '  the copy would disagree.\n' >&2
    exit 2
fi

# Refuses to gate a stack brought up from another directory: on the VPS a bare
# `docker compose` from a worktree resolves to production (`name: orbit`).
for id in $(docker compose ps -aq 2>/dev/null || true); do
    from=$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$id" 2>/dev/null || true)
    if [ -z "$from" ]; then continue; fi
    from=$(readlink -f -- "$from" 2>/dev/null || printf '%s' "$from")
    if [ "$from" = "$here" ]; then continue; fi
    project=$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}' "$id" 2>/dev/null || true)
    {
        printf 'check.sh: compose project %s has a container started from\n' "${project:-?}"
        printf '  %s, not from %s.\n' "$from" "$here"
        printf 'Refusing to run the gate against it. Bring a sandbox stack up from THIS directory\n'
        printf 'and name it on the same command line (web is left out: it publishes 127.0.0.1:3085):\n'
        printf '  COMPOSE_PROJECT_NAME=orbit-<name> docker compose up -d postgres redis app\n'
        printf '  COMPOSE_PROJECT_NAME=orbit-<name> bash scripts/check.sh %s\n' "$mode"
    } >&2
    exit 2
done

work=''
gate=''
cleanup() {
    if [ -n "$work" ]; then rm -rf "$work"; fi
    if [ -n "$gate" ]; then rm -rf "$gate"; fi
}
trap cleanup EXIT

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }

php_step() {
    if [ "$mode" = dev ]; then
        docker compose exec -T app "$@"
    else
        docker compose run --rm --no-deps \
            -v "$gate/vendor:/var/www/html/vendor" \
            -v "$gate/bootstrap-cache:/var/www/html/bootstrap/cache" \
            app "$@"
    fi
}

# `.package-lock.json` is npm's own marker of a finished install; a bare
# `[ -d node_modules ]` passes on an empty directory and lints nothing (exit 127).
node_install='[ -f node_modules/.package-lock.json ] || npm ci --no-audit --fund=false'

node_step() {
    if [ "$mode" = dev ]; then
        docker compose --profile build run --rm --entrypoint sh \
            assets -c "set -e; $node_install; $1"
    else
        docker compose --profile build run --rm --entrypoint sh \
            -v "$gate/node_modules:/var/www/html/node_modules" \
            assets -c "set -e; $node_install; $1"
    fi
}

if [ "$mode" = overlay ]; then
    step 'Overlay (dev dependencies, outside the live vendor/)'
    rm -rf /var/tmp/orbit-gate.*
    # bootstrap/cache is overlaid too: `composer install` runs package:discover,
    # whose provider list would otherwise 500 the live --no-dev app on next boot.
    gate=$(mktemp -d /var/tmp/orbit-gate.XXXXXXXX)
    mkdir -p "$gate/vendor" "$gate/bootstrap-cache" "$gate/node_modules"
    chown -R 115:119 "$gate"
    php_step composer install --no-interaction --no-progress
fi

step 'Gitleaks (secrets)'
# git's view of the tree, copied out: a gitignored .env is never in $work/scan.
# docs/DECISIONS.md: the-gate-scans-for-secrets-over-gits-view-of-the-tree
work=$(mktemp -d)
mkdir "$work/scan"

$GIT ls-files -z --cached --others --exclude-standard >"$work/list"
if [ ! -s "$work/list" ]; then
    printf 'check.sh: git listed no file to scan in %s. The secrets\n' "$here" >&2
    printf '  step would have scanned nothing and reported no leaks. That is\n' >&2
    printf '  a failure.\n' >&2
    exit 1
fi
if grep -qzE '(^|/)\.gitleaks(\.toml|ignore)$' "$work/list"; then
    printf 'check.sh: the tree carries a gitleaks allowlist file, which would let\n' >&2
    printf '  the scanned code decide what the scanner may find. Delete it.\n' >&2
    exit 1
fi

tar -C "$here" --null -T "$work/list" -cf - | tar -xf - -C "$work/scan"
# Belt-and-braces: reachable only if the list assertion above is removed.
if [ -z "$(ls -A "$work/scan")" ]; then
    printf 'check.sh: the scan directory came out empty. Refusing to call that\n' >&2
    printf '  clean.\n' >&2
    exit 1
fi

docker run --rm --network none -v "$work/scan:/scan:ro" zricethezav/gitleaks:v8.30.1 \
    dir /scan --no-banner --redact --verbose --ignore-gitleaks-allow

step 'Pint (code style)'
php_step vendor/bin/pint --test

step 'Composer advisories'
# --locked --no-dev: an advisory against phpunit or pint is not on the site.
php_step composer audit --locked --no-dev --abandoned=report

step 'Deptrac (architecture layers)'
php_step vendor/bin/deptrac analyse --no-progress --no-cache --fail-on-uncovered

step 'PHPStan (static analysis, level 8)'
# Larastan boots the framework to read the models; the default 128M is not enough.
php_step vendor/bin/phpstan analyse --no-progress --memory-limit=512M

step 'npm advisories'
node_step 'npm audit --omit=dev --audit-level=high'

step 'ESLint (front end)'
node_step 'npm run lint'

step 'Vitest (front-end unit tests)'
node_step 'npm run test:js'

step 'PHPUnit'
php_step php artisan test

printf '\n\033[1;32m==> all checks passed (%s runner)\033[0m\n' "$mode"
