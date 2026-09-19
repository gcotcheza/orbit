#!/usr/bin/env bash
# Orbit deploy — this script IS the runbook (.claude/commands/deploy.md).
#
#   scripts/deploy.sh <PR#> [--gated-by-hand]
#
# Run as root, from /var/www/orbit or from a clone with DEPLOY_ROOT naming the
# checkout. Every git goes through git-as, the moving
# half goes through ONE heavy-work job, and every phase prints one line here
# while the full output goes to $DEPLOY_LOG_DIR/<utc>-pr<N>.log.
#
# When this is the checkout's own copy the job fast-forwards the file bash is
# reading, so the body lives in main() and bash has it all before the disk moves.
set -u

# The helpers are this script's own, not the deployed checkout's: a first landing
# runs from a clone, and the checkout has neither file until the merge arrives.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

# Vendored from gcotcheza/engineering-standards and not edited here:
# tests/Unit/Standards/DeployLibDriftTest.php recomputes each file's own hash.
# shellcheck source=scripts/lib/deploy/summary.sh
. "$(dirname "$0")/lib/deploy/summary.sh"
# shellcheck source=scripts/lib/deploy/resolve.sh
. "$(dirname "$0")/lib/deploy/resolve.sh"
# shellcheck source=scripts/lib/deploy/ledger.sh
. "$(dirname "$0")/lib/deploy/ledger.sh"
# shellcheck source=scripts/lib/deploy/preflight.sh
. "$(dirname "$0")/lib/deploy/preflight.sh"

usage() {
    printf 'usage: scripts/deploy.sh <PR#> [--gated-by-hand]\n' >&2
    exit 64
}

# The sidecar answers 400 without the Host header: the allowlist working.
loopback_code() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 --connect-timeout 5 -H "Host: $HOST" "$BASE$1"; }
edge_code()     { curl -s -o /dev/null -w '%{http_code}' --max-time 15 --connect-timeout 5 "$PUBLIC$1"; }

# .claude/ is carved out by OWNER, not by what ships: the orbit session's Claude
# Code runtime leaves root-owned runtime files there. docs/DECISIONS.md
rooted_count() { find "$ROOT" -user root -not -path "$ROOT/.claude/*" | wc -l; }

land() {
    local head rooted code edge
    $GIT merge --ff-only "$MERGE_SHA" || { say 'NOT LANDED: fast-forward refused, checkout unchanged'; exit 1; }
    head=$($GIT rev-parse --short HEAD)
    rooted=$(rooted_count)
    if [ "$rooted" -ne 0 ]; then
        say "NOT LANDED CLEANLY: $rooted path(s) under $ROOT are root-owned. Repair the paths the find names, never the tree."
        exit 1
    fi
    code=$(loopback_code /up)
    edge=$(edge_code /)
    if [ "$code" != 200 ] || [ "$edge" != 200 ]; then
        say "LANDING FAILED: /up answered $code on the sidecar and $PUBLIC answered $edge. The landing is on disk; look before re-running."
        exit 1
    fi
    $COMPOSE ps
    say "LANDED docs-only ${MERGE_SHA:0:7}: head $head up $code edge $edge root-owned $rooted — no build, no restart"
    GATED='not read: a docs-only landing runs no code'
    finish "$head"
}

classify() {
    local rc
    DOCS_ONLY_GIT="$GIT" "$SCRIPT_DIR/docs-only.sh" "$MERGE_SHA"
    rc=$?
    case "$rc" in
        0) land ;;
        1) say 'CLASSIFIED code: the full deploy path' ;;
        2) nothing_to_land ;;
        3) say 'STOP: the classifier refused. A landing is a fast-forward or it is not a landing, and nothing was classified.'; exit 1 ;;
        64) say 'STOP: the classifier was called wrong; nothing was classified.'; exit 1 ;;
        *) say "STOP: git itself failed (rc=$rc). A failed command must never be read as 'nothing to land'."; exit 1 ;;
    esac
}

# resolve compares the head's tree with the merge's to pick GATE_SHA. A head this
# checkout has never had — a squash or a rebase merge — is a different fact.
head_is_present() {
    local repo json head
    repo=${DEPLOY_GH_REPO:-$(gh_repo)} || return 0
    [ -n "$repo" ] || return 0
    json=$($GH pr view "$PR" -R "$repo" --json headRefOid) || return 0
    head=$(json_value "$json" headRefOid)
    [ -n "$head" ] || return 0
    $GIT fetch origin >/dev/null 2>&1 || return 0
    $GIT cat-file -e "$head^{commit}" 2>/dev/null && return 0
    refuse "PR #$PR's head ${head:0:7} is not a commit in this checkout, so nothing can compare its tree with the merge's — which is what a squash or a rebase merge looks like. Gate the merge commit itself in a worktree and deploy it with --gated-by-hand."
}

# The library's gated() refuses and exits, so the recipe goes out ahead of it. The
# probe is a subshell with the summary fd closed: only the real call may print.
gate_or_recipe() {
    local wt="/srv/worker-scratch/orbit-gate-pr$PR"
    if ! ( exec 3>/dev/null; gated ) >/dev/null 2>&1; then
        say "NOT GATED ${GATE_SHA:0:7}: gate that $GATE_WHAT in a worktree cut from the root-owned clone, never in this checkout — it is bind-mounted into the running containers."
        say "  git -C /srv/sessions/orbit/repo worktree add $wt $GATE_SHA"
        say "  cd $wt"
        say "  COMPOSE_PROJECT_NAME=orbit-gate-pr$PR heavy-work orbit-gate-pr$PR -- bash scripts/check.sh overlay"
        say "  heavy-work orbit-e2e-pr$PR -- bash scripts/e2e.sh"
        say "  Both append to $LEDGER. Then: scripts/deploy.sh $PR"
    fi
    gated
}

# A finished deploy of $1 leaves a DONE line in an earlier log. Its absence is what
# separates "already deployed" from "an earlier run died after the fast-forward".
finished_log() {
    local dir file
    dir=$(dirname "$LOG")
    for file in "$dir"/*.log; do
        [ -f "$file" ] || continue
        [ "$file" = "$LOG" ] && continue
        if grep -qE "^DONE #[0-9]+ live $1 " "$file"; then
            printf '%s' "$file"
            return 0
        fi
    done
    return 1
}

nothing_to_land() {
    local short done_in
    if [ "$($GIT rev-parse HEAD)" != "$MERGE_SHA" ]; then
        say 'STOP: NOTHING TO LAND — the merge changes nothing here.'
        exit 0
    fi
    short=$($GIT rev-parse --short HEAD)
    if done_in=$(finished_log "$short"); then
        say "STOP: NOTHING TO LAND — $short is already deployed ($done_in said DONE)."
        exit 0
    fi
    say "REFUSED: $short is on disk and no log in $(dirname "$LOG") says a deploy of it ever finished, so an earlier run died after the fast-forward. The code is live, the containers may still be booted on the previous release, and the schema may be ahead of both."
    say "  Read the newest log in $(dirname "$LOG"): its '@@STEP n RAN' lines are what did run."
    say "  Finish the rest by hand, in this order: migrate --force, the assets profile, build:retain, view:clear, horizon:terminate IN the horizon container, restart app horizon scheduler web."
    say "  Or roll back with the block in .claude/commands/deploy.md. Either way a half-finished deploy is not 'nothing to land'."
    exit 1
}

baseline() {
    local rc=0
    ORBIT_DIR="$ROOT" "$SCRIPT_DIR/verify.sh" --before || rc=$?
    if [ "$rc" -ne 0 ]; then
        fail_tail 'STEP 0' "$rc"
        exit "$rc"
    fi
    $COMPOSE ps
    say "STEP 0 baseline recorded: the served bundle and the four start times are in $LOG"
}

# The front end's inputs, including the build script itself and the npm config.
# public/build needs no exclusion: .gitignore holds it, so it never reaches a diff.
FRONT_END="resources/ package.json package-lock.json .npmrc vite.config.js public/"

# The services the job restarts; await_health asks docker which of them it checks.
RESTARTED='app horizon scheduler web'

# The pull opens one window — new code, old schema — and migrate closes it, so
# nothing slow goes between them. docs/DECISIONS.md: the-deploy-script-is-the-runbook
deploy_job() {
    cat <<EOF
set -e
cd "$ROOT"
rooted_count() { find "$ROOT" -user root -not -path "$ROOT/.claude/*" | wc -l; }
echo "step 1 fetch + fast-forward"
$GIT --no-optional-locks status --short
$GIT fetch origin
$GIT merge --ff-only $MERGE_SHA
landed=\$($GIT rev-parse HEAD)
[ "\$landed" = "$MERGE_SHA" ] || { echo "STEP 1 FAILED: HEAD \$landed is not the resolved merge $MERGE_SHA, and nothing is built on a commit that was not gated"; exit 1; }
echo "step 2 root-owned proof"
rooted=\$(rooted_count)
[ "\$rooted" -eq 0 ] || { echo "STEP 2 FAILED: \$rooted root-owned path(s) before anything was built, and git running as orbit cannot write into a root-owned .git"; exit 1; }
if [ -n "\$($GIT diff --name-only $BEFORE $MERGE_SHA -- composer.lock)" ]; then
    echo "@@STEP 3 RAN: composer.lock moved"
    $COMPOSE exec -T app composer install --no-dev --optimize-autoloader --no-interaction
    $COMPOSE exec -T app chmod -R go-w vendor
else
    echo "step 3 not needed"
fi
echo "step 4 migrate"
$COMPOSE exec -T app php artisan migrate --force
if [ -n "\$($GIT diff --name-only $BEFORE $MERGE_SHA -- docker/app docker-compose.yml)" ]; then
    echo "@@STEP 4.5 RAN: docker/app or docker-compose.yml moved"
    $COMPOSE build app horizon scheduler
    $COMPOSE up -d app horizon scheduler
    $COMPOSE up -d --force-recreate web
fi
if [ -n "\$($GIT diff --name-only $BEFORE $MERGE_SHA -- $FRONT_END)" ]; then
    echo "@@STEP 5 RAN: the front end moved"
    $COMPOSE --profile build run --rm assets
    echo "@@STEP 7 RAN: build:retain snapshots the build that is on disk"
    $COMPOSE exec -T app php artisan build:retain
else
    echo "step 5 not needed, so step 7 is not either: retain would snapshot the build already on disk"
fi
echo "step 8 view:clear, then terminate horizon IN horizon, then restart the four"
$COMPOSE exec -T app php artisan view:clear
$COMPOSE exec -T horizon php artisan horizon:terminate
$COMPOSE restart $RESTARTED
rooted=\$(rooted_count)
if [ "\$rooted" -ne 0 ]; then
    echo "STEP 10 REPAIR: \$rooted root-owned path(s)"
    find "$ROOT" -user root -not -path "$ROOT/.claude/*" -exec chown orbit:orbit {} +
    rooted=\$(rooted_count)
fi
echo "@@ROOT-OWNED \$rooted"
EOF
}

deploy_steps() {
    local job rc=0
    say "STEP 1 rollback target $BEFORE"
    say "STEPS 1-10 are one heavy-work job and can queue behind another; nothing prints here until it returns. Watch it with: tail -f $LOG"
    job=$(deploy_job)
    detail "$job"
    $HEAVY orbit-deploy -- bash -c "$job" || rc=$?
    if [ "$rc" -ne 0 ]; then
        fail_tail 'STEPS 1-10' "$rc"
        exit "$rc"
    fi
    ROOTED=$(sed -n 's/^@@ROOT-OWNED //p' "$LOG" | tail -1)
    if [ "${ROOTED:-none}" != 0 ]; then
        say "STEPS 1-10 ran but the root-owned count came back '${ROOTED:-none}', not 0. Read $LOG before anything else."
        exit 1
    fi
    RAN=$(sed -n 's/^@@STEP \([0-9.]*\) RAN.*/\1/p' "$LOG" | tr '\n' ' ')
    say "STEPS 1-10 ok in one heavy-work job, conditional steps ran: ${RAN:-none}"
}

# A status carrying one of the three is a service docker healthchecks and a bare
# `Up` is one it does not; a `ps` that FAILED is neither. docs/DECISIONS.md
health_of() {
    local out
    out=$($COMPOSE ps "$1") || return 1
    printf '%s' "$out" | grep -oE '\(healthy\)|\(unhealthy\)|\(health: starting\)' | tail -1
    return 0
}

# `ps` says a container is not healthy yet; only its own checks say why.
health_log() {
    local id
    id=$($COMPOSE ps -q "$1" 2>/dev/null | head -1)
    [ -n "$id" ] || { printf '  no container id for %s\n' "$1"; return 0; }
    $DOCKER inspect --format '{{range .State.Health.Log}}exit {{.ExitCode}}: {{.Output}}
{{end}}' "$id" 2>&1 | grep -v '^[[:space:]]*$' | tail -3 | sed 's/^/  /'
}

# The release is on disk and serving, so only the waiting failed and undoing it
# would be the destructive answer to a question nobody asked. docs/DECISIONS.md
health_failed() {
    local log
    say "HEALTH $1: $2 is $3 ${4}s after its restart, so the battery was not run. THE RELEASE IS LANDED AND SERVING and this is NOT a rollback."
    log=$(health_log "$2")
    [ -n "$log" ] && say "Its last healthchecks:"$'\n'"$log"
    say "Watch it with '$COMPOSE ps $2'; when it reports healthy, run scripts/verify.sh against $ROOT."
    exit 1
}

# Docker not answering is not an answer, and reading it as one is how a stack
# that never reported healthy gets verified anyway. docs/DECISIONS.md
ps_refused() {
    say "HEALTH UNKNOWN: docker would not say what $1 is, so the battery was not run and no restarted container has been proved healthy. THE RELEASE IS LANDED AND SERVING and this is NOT a rollback. The failure docker printed is in $LOG."
    exit 1
}

# A restart leaves a healthchecked container at `health: starting` until its first
# check answers, and the battery is right to refuse that. docs/DECISIONS.md
await_health() {
    local s state waited=0 watched='' pending waiting
    for s in $RESTARTED; do
        state=$(health_of "$s") || ps_refused "$s"
        [ -n "$state" ] && watched="$watched $s"
    done
    watched=${watched# }
    if [ -z "$watched" ]; then
        say 'HEALTH nothing to wait for: docker healthchecks none of the restarted services'
        return 0
    fi
    while :; do
        pending=''
        waiting=''
        for s in $watched; do
            state=$(health_of "$s") || ps_refused "$s"
            [ "$state" = '(healthy)' ] && continue
            [ "$state" = '(unhealthy)' ] && health_failed UNHEALTHY "$s" "$state" "$waited"
            pending="$pending $s"
            waiting="$waiting$s $state"$'\n'
        done
        [ -n "$pending" ] || break
        if [ "$waited" -ge "$HEALTH_TIMEOUT" ]; then
            while read -r s state; do
                health_failed TIMEOUT "$s" "$state" "$waited"
            done <<<"$waiting"
        fi
        sleep "$HEALTH_INTERVAL"
        waited=$((waited + HEALTH_INTERVAL))
    done
    say "HEALTH ${watched} healthy ${waited}s after the restart"
}

# The file nginx reads is /etc/nginx/sites-available/flights.ghiecode.io; no pull
# touches it, so a moved deploy/nginx is a host step this script announces.
vhost_notice() {
    local moved
    moved=$($GIT diff --name-only "$BEFORE" "$MERGE_SHA" -- deploy/nginx | tr '\n' ' ')
    [ -n "$moved" ] || return 0
    VHOST_EXTRA=' vhost-needed'
    say "HOST VHOST NEEDED, NOT RUN (deploy/nginx moved: ${moved% }) — by hand: nginx -t, then systemctl reload nginx"
}

# The bundle moved if and only if step 5 ran, so the job's own record decides the
# mode rather than a second reading of the same diff.
verify() {
    local rc=0
    case " $RAN " in
        *' 5 '*)
            VERIFY_MODE=full
            say 'VERIFY full: step 5 built the front end, so the bundle must have moved'
            ORBIT_DIR="$ROOT" "$SCRIPT_DIR/verify.sh" || rc=$?
            ;;
        *)
            VERIFY_MODE=backend-only
            say 'VERIFY --backend-only: step 5 did not run, so an unchanged bundle is expected'
            ORBIT_DIR="$ROOT" "$SCRIPT_DIR/verify.sh" --backend-only || rc=$?
            ;;
    esac
    if [ "$rc" -ne 0 ]; then
        fail_tail 'VERIFY' "$rc"
        say "Roll back to $BEFORE with the rollback block in .claude/commands/deploy.md rather than re-running a step in the middle."
        exit "$rc"
    fi
    ROOTED=$(rooted_count)
}

main() {
    PR=''
    BY_HAND=0
    while [ $# -gt 0 ]; do
        case "$1" in
            --gated-by-hand) BY_HAND=1 ;;
            -h|--help) usage ;;
            '' | *[!0-9]*) usage ;;
            *) [ -z "$PR" ] || usage; PR=$1 ;;
        esac
        shift
    done
    [ -n "$PR" ] || usage

    ROOT=${DEPLOY_ROOT:-/var/www/orbit}
    # DEPLOY_GIT and DEPLOY_COMPOSE are COMMANDS WITH ARGUMENTS, so they are
    # unquoted at every call site on purpose: they have to split.
    GIT=${DEPLOY_GIT:-git-as orbit -C $ROOT}
    GH=${DEPLOY_GH:-gh}
    HEAVY=${DEPLOY_HEAVY:-heavy-work}
    COMPOSE=${DEPLOY_COMPOSE:-docker compose}
    DOCKER=${DEPLOY_DOCKER:-docker}
    HEALTH_TIMEOUT=${DEPLOY_HEALTH_TIMEOUT:-180}
    HEALTH_INTERVAL=${DEPLOY_HEALTH_INTERVAL:-3}
    LEDGER=${DEPLOY_LEDGER:-/var/lib/fleet/gate-ledger}
    HOST=${ORBIT_HOST:-flights.ghiecode.io}
    BASE=${ORBIT_BASE:-http://127.0.0.1:3085}
    PUBLIC=${ORBIT_PUBLIC:-https://$HOST}
    GATED=''
    HEAD_SHA=''
    MERGE_SHA=''
    GATE_SHA=''
    GATE_WHAT=''
    ROOTED=''
    RAN=''
    VERIFY_MODE=''
    VHOST_EXTRA=''

    cd "$ROOT" || { printf 'deploy.sh: no checkout at %s\n' "$ROOT" >&2; exit 64; }
    deploy_log_open "$PR"

    [ "$($GIT rev-parse --abbrev-ref HEAD)" = main ] \
        || refuse "the checkout is not on main, and a deploy fast-forwards main."
    $GIT log --oneline -3

    head_is_present
    resolve
    BEFORE=$($GIT rev-parse --short HEAD)
    refuse_if_dirty
    classify
    gate_or_recipe
    preflight
    baseline
    deploy_steps
    await_health
    vhost_notice
    verify
    EXTRA_DONE="root-owned $ROOTED verify $VERIFY_MODE$VHOST_EXTRA"
    finish "$($GIT rev-parse --short HEAD)"
}

main "$@"
# The last byte bash reads: when this is the checkout's own copy, the job
# fast-forwards the file it is reading.
# shellcheck disable=SC2317
exit
