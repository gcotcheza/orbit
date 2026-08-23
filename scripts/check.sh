#!/usr/bin/env bash
# =============================================================================
# Orbit — the pre-merge gate
# =============================================================================
#   scripts/check.sh
#
# Seven checks, in this order, stopping at the first failure:
#
#   1. pint --test      code style (report only; `pint` alone rewrites)
#   2. composer audit   advisories against the production PHP packages
#   3. phpstan          static analysis at level 8, no baseline
#   4. npm audit        advisories against the production node packages
#   5. eslint           the browser half, which no PHP test can see
#   6. vitest           the browser half's own unit tests (PR6 onwards)
#   7. artisan test     the suite
#
# ORDER IS DELIBERATE: cheapest and most mechanical first. A style failure that
# takes four seconds to find should not be discovered after a ninety-second
# analysis run, and a lint error in a component should surface before the PHP
# suite spends time proving the back end is fine.
#
# EVERYTHING RUNS IN THE CONTAINERS, not on the host. The host has its own PHP
# and its own node, at their own versions, with their own extensions — and a
# gate that passes against a PHP the production image does not have is worse
# than no gate, because it is trusted. `docker compose exec -T app` is the same
# php:8.5-fpm-alpine that serves the site; the node step uses the same
# node:24-alpine as the asset build.
#
# EVERY PR FROM PR3 ONWARDS MUST PASS THIS BEFORE IT IS MERGED. See docs/PLAN.md.
#
# Requires the stack to be up (`docker compose up -d`) for the PHP steps; the
# node step brings its own container and does not.
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")/.."

# Refuses to gate a stack brought up from another directory: on the VPS a bare
# `docker compose` from a worktree resolves to production (`name: orbit`).
here=$(pwd -P)
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
        printf '  COMPOSE_PROJECT_NAME=orbit-<name> bash scripts/check.sh\n'
    } >&2
    exit 2
done

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }

step 'Pint (code style)'
docker compose exec -T app vendor/bin/pint --test

step 'Composer advisories'
# --locked --no-dev: the lockfile, production packages only. An advisory against
# phpunit or pint is not on the site and must not redden the gate. DECISIONS.md
docker compose exec -T app composer audit --locked --no-dev

step 'PHPStan (static analysis, level 8)'
# The memory limit is PHPStan's own default of 128M otherwise, and Larastan
# boots the whole framework to read the models — 512M is what one process needs.
docker compose exec -T app vendor/bin/phpstan analyse --no-progress --memory-limit=512M

step 'npm advisories'
# Beside ESLint rather than beside the composer half: this is the first step
# that needs node_modules, and a cold `npm ci` must not precede PHPStan.
docker compose --profile build run --rm --entrypoint sh assets -c \
    '[ -d node_modules ] || npm ci --no-audit --fund=false && npm audit --omit=dev --audit-level=high'

step 'ESLint (front end)'
# `npm ci` only when node_modules is missing: it deletes and reinstalls the tree
# every time it runs, which is right for a fresh checkout and pure waste on the
# fifth run of the afternoon. The lockfile is committed, so the tree it produces
# is the same either way.
#
# `run --rm` rather than `exec`: the assets service is profile-gated and is not
# running, because it is a task and not a service.
docker compose --profile build run --rm --entrypoint sh assets -c \
    '[ -d node_modules ] || npm ci --no-audit --fund=false && npm run lint'

step 'Vitest (front-end unit tests)'
# The globe's flight arithmetic and the tour's timetable (resources/js/lib/),
# which are pure functions precisely so that they can be checked here rather
# than by watching a planet spin and deciding it looked about right.
#
# No `npm ci` guard: the ESLint step above has just made sure node_modules
# exists, and it runs in the same image.
docker compose --profile build run --rm --entrypoint sh assets -c 'npm run test:js'

step 'PHPUnit'
docker compose exec -T app php artisan test

printf '\n\033[1;32m==> all checks passed\033[0m\n'
