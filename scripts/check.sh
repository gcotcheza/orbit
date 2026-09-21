#!/usr/bin/env bash
# usage: scripts/check.sh dev|overlay
# Why two runners, and what each may not lose: docs/DECISIONS.md, the-gate-is-one-script-two-runners
set -Eeuo pipefail

cd "$(dirname "$0")/.."
here=$(pwd -P)

# Vendored from gcotcheza/engineering-standards and not edited here:
# tests/Unit/Standards/DeployLibDriftTest.php recomputes each file's own hash.
# shellcheck source=scripts/lib/deploy/ledger.sh
. "$here/scripts/lib/deploy/ledger.sh"

# Filtered until the arguments and the stack have been vetted, so a run that
# dies in its own usage or refuses the box it found records nothing at all.
GATE_FILTERED=1

# CI_GIT is a COMMAND WITH ARGUMENTS, so $GIT is unquoted on purpose; it carries
# its own -C, so no call site below adds one. docs/DECISIONS.md, the-gate-scans-for-secrets-over-gits-view-of-the-tree
GIT=${CI_GIT:-git}
GATE_LEDGER_GIT="${GIT}"

mode=${1-}
if [ $# -ne 1 ] || { [ "$mode" != dev ] && [ "$mode" != overlay ]; }; then
    {
        printf 'usage: scripts/check.sh dev|overlay\n\n'
        printf '  dev      the stack is already up from this directory; the PHP steps\n'
        printf '           run inside it with `docker compose exec`.\n'
        printf '  overlay  one throwaway container per step, with its own vendor/,\n'
        printf '           bootstrap/cache and node_modules/ bind-overlaid; the runner\n'
        printf '           for a checkout with no stack up, and the one the deploy\n'
        printf '           script names when a head is not in the gate ledger yet. Run\n'
        printf '           it as root: it chowns its overlay to the uid the containers use.\n\n'
        printf 'The mode is not guessed, and it is the only argument. Name it.\n'
        printf 'CI_GIT names the git the secrets step lists the tree with. A run inside\n'
        printf '/var/www/orbit needs `git-as orbit -C /var/www/orbit`, because root git\n'
        printf 'cannot read that checkout at all; unset, it is plain `git`. Its -C, if it\n'
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

# 124 is GNU timeout on the host, 143 is BusyBox timeout in the test container.
docker_answer() {
    case $1 in
        124 | 143) printf 'timed out' ;;
        *) printf 'exited %s' "$1" ;;
    esac
}

# Refuses a stack brought up from another directory, because a bare `docker compose`
# here resolves to production; not the browser gate's guard: docs/DECISIONS.md.
stack_is_foreign() {
    local ids id from project rc
    foreign_reason=''

    rc=0
    ids=$(timeout 10 docker compose ps -aq 2>/dev/null) || rc=$?
    if [ "$rc" -ne 0 ]; then
        foreign_reason="docker did not list this project's containers ($(docker_answer "$rc"))"
        return 0
    fi

    for id in $ids; do
        rc=0
        from=$(timeout 10 docker inspect --format '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$id" 2>/dev/null) || rc=$?
        if [ "$rc" -ne 0 ]; then
            foreign_reason="docker did not say where container $id was started from ($(docker_answer "$rc"))"
            return 0
        fi
        if [ -z "$from" ]; then
            foreign_reason="container $id carries no working-directory label, so where it was started from cannot be told"
            return 0
        fi
        from=$(readlink -f -- "$from" 2>/dev/null || printf '%s' "$from")
        if [ "$from" = "$here" ]; then continue; fi
        project=$(timeout 10 docker inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}' "$id" 2>/dev/null || true)
        foreign_reason="compose project ${project:-?} has a container started from $from, not from $here"
        return 0
    done

    return 1
}

if stack_is_foreign; then
    {
        printf 'check.sh: %s.\n' "$foreign_reason"
        printf 'Refusing to run the gate against it. Bring a sandbox stack up from THIS directory\n'
        printf 'and name it on the same command line (web is left out: it publishes 127.0.0.1:3085):\n'
        printf '  COMPOSE_PROJECT_NAME=orbit-<name> docker compose up -d postgres redis app\n'
        printf '  COMPOSE_PROJECT_NAME=orbit-<name> bash scripts/check.sh %s\n' "$mode"
    } >&2
    exit 2
fi

GATE_FILTERED=0

work=''
gate=''
cleanup() {
    if [ -n "$work" ]; then rm -rf "$work"; fi
    if [ -n "$gate" ]; then rm -rf "$gate"; fi
}
trap cleanup EXIT

# The ledger is written from this shell. An EXIT trap reads teardown's $?, which
# is how a half-run gate once recorded itself green. docs/DECISIONS.md
gate_record() {
    [ "${GATE_RECORDED:-0}" -eq 0 ] || return 0
    GATE_RECORDED=1
    if [ "$GATE_FILTERED" -eq 1 ]; then
        printf 'gate-ledger: a partial or refused run records nothing; scripts/deploy.sh wants a full scripts/check.sh\n' >&2
        return 0
    fi
    gate_ledger_record ci "$1" "${ORBIT_GATE_LOG:--}"
}

trap 'gate_record "$?"' ERR

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

step 'ShellCheck (shell scripts)'
# Listed the way the secrets step lists, and for the same reason: an untracked
# editor backup is not the branch. docs/DECISIONS.md, the-gate-lints-shell-at-warning
shell_files=()
while IFS= read -r -d '' file; do
    case $file in *.sh) shell_files+=("$file"); continue ;; esac
    if head -2 "$file" | grep -qE '^#!.*sh|^# shellcheck shell='; then shell_files+=("$file"); fi
done < <($GIT ls-files -z --cached -- scripts)

if [ ${#shell_files[@]} -eq 0 ]; then
    printf 'check.sh: git listed no shell script under scripts/, so this step linted nothing.\n' >&2
    exit 1
fi

docker run --rm --network none -v "$here:/mnt:ro" -w /mnt koalaman/shellcheck:v0.11.0 \
    -S warning "${shell_files[@]}"

if [ "$mode" = overlay ]; then
    step 'Overlay (dev dependencies, outside the live vendor/)'
    rm -rf /var/tmp/orbit-gate.*
    # bootstrap/cache is overlaid too: `composer install` runs package:discover,
    # whose provider list would otherwise 500 the live --no-dev app on next boot.
    gate=$(mktemp -d /var/tmp/orbit-gate.XXXXXXXX)
    mkdir -p "$gate/vendor" "$gate/bootstrap-cache" "$gate/node_modules"
    chown -R 115:119 "$gate"
    # storage/ is the app's own writable directory, not a build product, so it is
    # handed over rather than overlaid. docs/DECISIONS.md, worktrees-live-outside-the-served-tree
    chown -R 115:119 "$here/storage"
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

step 'The deploy script (scripts/deploy-test.sh)'
# On the host, not in a container, and below the first containerised step:
# CheckGitSeamTest stubs docker and must stop before this one runs under its PATH.
"$here/scripts/deploy-test.sh"
"$here/scripts/verify-test.sh"

step 'Image tags (T9)'
# A host step: the canonical clone is on this box and inside no container.
# docs/DECISIONS.md, the-gate-is-one-script-two-runners
tag_check=/srv/engineering-standards/scripts/gate-image-tags.sh
if [ ! -x "$tag_check" ]; then
    printf 'check.sh: %s is missing or not executable, so nothing read which image\n' "$tag_check" >&2
    printf '  tags this gate builds. A skipped check is a silent pass.\n' >&2
    exit 1
fi

if ! tag_report=$("$tag_check" "$here" 2>&1); then
    printf '%s\n' "$tag_report" >&2
    exit 1
fi
printf '%s\n' "$tag_report"

# It exits 0 over a root with no compose file, so its status alone is not evidence.
case "$tag_report" in
    *'built tags:'*) ;;
    *)
        printf 'check.sh: the image-tag check counted no built tags in %s, so it\n' "$here" >&2
        printf '  examined nothing. That is not a pass.\n' >&2
        exit 1
        ;;
esac

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

# Set in this shell on the one path that reaches it: the ledger records rc 0 only
# when the suite itself says so.
GATE_SUITE_PASSED=1
gate_record 0
