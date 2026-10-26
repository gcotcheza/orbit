#!/usr/bin/env bash
# Orbit deploy — this script IS the runbook (.claude/commands/deploy.md).
#
#   fleet-deploy orbit <PR#> [--gated-by-hand]                     by a person
#   scripts/deploy.sh --from-main [--decide-only]                   as deploy-on-merge's judge
#
# Root runs it only from fleet-deploy's export of scripts/ at the merge commit;
# the lib's summary.sh refuses any other copy. Every git goes through git-as, the moving
# half goes through ONE heavy-work job, and every phase prints one line here
# while the full output goes to $DEPLOY_LOG_DIR/<utc>-pr<N>.log.
set -u

# The helpers are this script's own, not the deployed checkout's: root runs only
# fleet-deploy's export, and the checkout's helpers are the app user's to edit.
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source=scripts/lib/gate/deploy-lib.sh
. "$SCRIPT_DIR/lib/gate/deploy-lib.sh"
# fleet-deploy lays root's box clone of the lib beside this file, never the
# commit's copy; set here, so no caller's DEPLOY_LIB is read. docs/DECISIONS.md, deploy-lib-is-not-vendored
DEPLOY_LIB="$SCRIPT_DIR/lib/deploy"
deploy_lib_ready || { printf 'REFUSED: run this only as fleet-deploy orbit <PR#>, which lays the deploy lib beside it\n' >&2; exit 2; }
# shellcheck source=/srv/engineering-standards/scripts/lib/deploy/summary.sh
. "$DEPLOY_LIB/summary.sh"
# shellcheck source=/srv/engineering-standards/scripts/lib/deploy/resolve.sh
. "$DEPLOY_LIB/resolve.sh"
# shellcheck source=/srv/engineering-standards/scripts/lib/deploy/ledger.sh
. "$DEPLOY_LIB/ledger.sh"
# shellcheck source=/srv/engineering-standards/scripts/lib/deploy/preflight.sh
. "$DEPLOY_LIB/preflight.sh"
# shellcheck source=/srv/engineering-standards/scripts/lib/deploy/compose.sh
. "$DEPLOY_LIB/compose.sh"

WEBFLOW_FPR=968479A1AFF927E37D1A566BB5690EEEBB952194

usage() {
    printf 'usage: fleet-deploy orbit <PR#> [--gated-by-hand] | scripts/deploy.sh --from-main [--decide-only]\n' >&2
    exit 64
}

# From main: 0 live, 2 refused with nothing moved, 3 live with RED, 4 failed after STEP 1;
# 5 lock busy either way. The codes fleet-deploy-on-merge reads.
on_exit() {
    local rc=$1
    if [ "$LOCK_BUSY" -eq 1 ]; then
        rc=5
    elif [ "$FROM_MAIN" -eq 0 ]; then
        :
    elif [ "$rc" -eq 0 ]; then
        case "$EXTRA_DONE" in *RED*) rc=3 ;; esac
    elif [ "$PHASE" = moving ]; then
        rc=4
    else
        rc=2
    fi
    exit "$rc"
}

take_lock() {
    exec 8>>"$LOCK" || refuse "cannot open the deploy lock $LOCK."
    flock -n 8 || { LOCK_BUSY=1; refuse "another deploy holds $LOCK: one deploy at a time, by hand or from main."; }
    say "LOCK $LOCK held"
}

# Root's live record, a file only this uid can write and no job's output reaches.
read_record() {
    RECORD=''
    deploy_record_safe "$RECORD_FILE" || refuse "root's record $RECORD_FILE is not a 600 file in a 700 directory, both owned by uid $(id -u): nothing is judged from a record someone else could write."
    [ ! -f "$RECORD_FILE" ] \
        || RECORD=$(awk '$1 == "DONE" || $1 == "ROLLBACK" || $1 == "SEED" { s = $2 } END { printf "%s", s }' "$RECORD_FILE")
}

ssh_key_safe() {
    [ -f "$SSH_KEY" ] && [ ! -L "$SSH_KEY" ] && [ "$(stat -c %u "$SSH_KEY")" = "$(id -u)" ]
}

# Every mirror git is bounded: one that never answers is refused, not waited on.
mirror_run() {
    local rc=0
    /usr/bin/timeout -k 5 "${DEPLOY_GIT_TIMEOUT:-300}" env -i PATH=/usr/bin:/bin HOME="$MIRROR_HOME" GNUPGHOME="$GNUPG" GIT_NO_REPLACE_OBJECTS=1 \
        GIT_SSH_COMMAND="ssh -i $SSH_KEY -o IdentitiesOnly=yes -o BatchMode=yes" \
        "$MIRROR_GIT" -C "$MIRROR" -c gpg.program="$GPG" "$@" || rc=$?
    case "$rc" in 124|137) refuse "git did not answer in ${DEPLOY_GIT_TIMEOUT:-300}s in the mirror $MIRROR: git $1" ;; esac
    return "$rc"
}

# The deployable rule, judged in root's mirror from verified shas only, as Moneywise's and GhieCode's.
judge_main() {
    local raw parents nparents committer ts sig gf gk subject mtree htree now live
    read_record
    [ -n "$RECORD" ] || refuse "no DONE, ROLLBACK or SEED row in $RECORD_FILE, so root has no live record: fleet-deploy-on-merge --seed orbit writes the first one."
    [[ $RECORD =~ ^[0-9a-f]{40}$ ]] \
        || refuse "root's newest live record ($RECORD in $RECORD_FILE) is not a full sha, and a short one is never expanded: deploy by hand once, or seed."
    [ -d "$MIRROR" ] || refuse "no mirror at $MIRROR; fleet-deploy-on-merge's INSTALL.md creates it, and nothing is judged from the live checkout's own objects."
    ssh_key_safe || refuse "the deploy key $SSH_KEY is not a regular file owned by uid $(id -u): INSTALL.md makes root's copy."
    mirror_run fetch --quiet --no-tags "$MIRROR_URL" '+refs/heads/main:refs/heads/main' \
        || refuse "fetching main from $MIRROR_URL into $MIRROR failed; nothing is judged on a stale mirror."
    MERGE_SHA=$(mirror_run rev-parse --verify --quiet 'refs/heads/main^{commit}') \
        || refuse "the mirror $MIRROR names no main after its fetch."
    say "JUDGING main $MERGE_SHA from $MIRROR"
    if [ "$MERGE_SHA" = "$RECORD" ]; then
        say "ALREADY LIVE main $MERGE_SHA is root's record ($RECORD_FILE); nothing to deploy."
        exit 0
    fi

    raw=$(mirror_run cat-file commit "$MERGE_SHA") || refuse "the mirror cannot read main $MERGE_SHA."
    parents=$(printf '%s\n' "$raw" | awk '/^$/ { exit } $1 == "parent" { print $2 }')
    nparents=$(printf '%s\n' "$parents" | awk 'NF { n++ } END { print n + 0 }')
    [ "$nparents" -eq 2 ] \
        || refuse "main $MERGE_SHA has $nparents parent(s): only a two-parent merge from GitHub's merge button deploys from main; a squash or rebase merge goes by fleet-deploy."
    committer=$(printf '%s\n' "$raw" | awk '/^$/ { exit } $1 == "committer"')
    [[ $committer =~ ^committer\ GitHub\ \<noreply@github\.com\>\ ([0-9]+)\ [-+][0-9]{4}$ ]] \
        || refuse "main $MERGE_SHA was not committed by GitHub <noreply@github.com>: a merge made anywhere but GitHub does not deploy itself."
    ts=${BASH_REMATCH[1]}
    IFS='|' read -r sig gf gk <<<"$(mirror_run log -1 --format='%G?|%GF|%GK' "$MERGE_SHA")"
    case "$sig" in
        G|U) [ "$gf" = "$WEBFLOW_FPR" ] \
                 || refuse "main $MERGE_SHA is signed by ${gf:-an unnamed key}, not GitHub's web-flow key $WEBFLOW_FPR." ;;
        E)   refuse "main $MERGE_SHA carries a signature by key ${gk:-unknown} that $GNUPG cannot check: GitHub may have rotated its web-flow key. Import it as step 2 of fleet-deploy-on-merge's INSTALL.md says, comparing the fingerprint, and deploy this one with fleet-deploy." ;;
        *)   refuse "main $MERGE_SHA carries no good GitHub signature (%G? ${sig:-empty}): a merge made anywhere but GitHub's merge button does not deploy itself." ;;
    esac
    subject=$(printf '%s\n' "$raw" | awk 'body { print; exit } /^$/ { body = 1 }')
    PR=$(printf '%s\n' "$subject" | sed -n 's/^Merge pull request #\([1-9][0-9]*\) from .*/\1/p')
    PR=${PR:-unnamed}

    HEAD_SHA=$(printf '%s\n' "$parents" | sed -n 2p)
    mtree=$(printf '%s\n' "$raw" | awk 'NR == 1 && $1 == "tree" { print $2 }')
    htree=$(mirror_run cat-file commit "$HEAD_SHA" | awk 'NR == 1 && $1 == "tree" { print $2 }') \
        || refuse "the mirror cannot read head $HEAD_SHA, main's second parent."
    [ -n "$mtree" ] && [ "$mtree" = "$htree" ] \
        || refuse "the tree of main $MERGE_SHA is not the tree of its head $HEAD_SHA, so what would deploy is not what was gated: merge main into the branch, gate that head, merge again."

    now=$($CLOCK)
    DEADLINE=$(( ts + FRESH_S ))
    [ "$now" -le "$DEADLINE" ] \
        || refuse "main $MERGE_SHA was merged $(( (now - ts) / 60 )) min ago, past the $(( FRESH_S / 60 ))-min window an unattended deploy has: a person deploys it with fleet-deploy."

    live=$(deploy_head_file) || refuse "$ROOT/.git/HEAD does not read as main at a full sha, so the live commit is unknown."
    [ "$live" = "$RECORD" ] \
        || refuse "LIVE TREE MOVED: $ROOT/.git/HEAD is $live but root's record is $RECORD ($RECORD_FILE). Main moved in the live tree without a deploy; find out why before anything deploys."
    mirror_run merge-base --is-ancestor "$RECORD" "$MERGE_SHA" \
        || refuse "root's record $RECORD is not an ancestor of main $MERGE_SHA in the mirror, so this is not a fast-forward."
    BEFORE=$RECORD
    owed_rows_green
    GATED="$GATED; deployed by deploy-on-merge"
    say "JUDGED #$PR main $MERGE_SHA: GitHub's signed merge of head $HEAD_SHA, same tree, every gate row its test scope owes green, merged $(( (now - ts) / 60 )) min ago, a fast-forward from root's record $RECORD"
}

# The library's gated, read in root's mirror against root's live record: the rows the test
# scope owes, as a hand deploy reads them (card 684).
owed_rows_green() {
    local GIT="env -i PATH=/usr/bin:/bin HOME=$MIRROR_HOME GIT_NO_REPLACE_OBJECTS=1 $MIRROR_GIT -C $MIRROR"
    local GATE_SHA=$HEAD_SHA GATE_WHAT=head GATE_BASE=$RECORD
    local GATE_ROUTE="deploy-on-merge deploys a head only when every gate row its test scope owes is green (scripts/check.sh writes ci, scripts/e2e.sh writes e2e, both run on the head before the merge): gate the head that way, or a person deploys this one with fleet-deploy."
    gated
}

# The job's TOO LATE, or heavy-work's own give-up line, written after byte $1 of the log.
too_late() {
    tail -c +"$(( $1 + 1 ))" "$LOG" | grep -qE "giving up on 'orbit-deploy'|^TOO LATE: "
}

# A fast-forward killed mid-checkout has written files but not moved HEAD: HEAD alone is no
# unmoved tree, so the status must match the one read before the job.
live_tree_clean() {
    # shellcheck disable=SC2086
    deploy_git_bounded $GIT --no-optional-locks status --porcelain --untracked-files=normal && [ "$DEPLOY_GIT_OUT" = "$STATUS_BEFORE" ]
}

# The sidecar answers 400 without the Host header: the allowlist working.
loopback_code() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 --connect-timeout 5 -H "Host: $HOST" "$BASE$1"; }
edge_code()     { curl -s -o /dev/null -w '%{http_code}' --max-time 15 --connect-timeout 5 "$PUBLIC$1"; }

# .claude/ is carved out by OWNER, not by what ships: the orbit session's Claude
# Code runtime leaves root-owned runtime files there. docs/DECISIONS.md
rooted_count() { find "$ROOT" -user root -not -path "$ROOT/.claude/*" | wc -l; }

land() {
    local head rooted code edge
    PHASE=moving
    # shellcheck disable=SC2086
    if ! deploy_git_bounded $GIT merge --ff-only "$MERGE_SHA"; then
        [ -n "$DEPLOY_GIT_ERR" ] || { PHASE=decide; say 'NOT LANDED: fast-forward refused, checkout unchanged'; exit 1; }
        say "NOT LANDED: $DEPLOY_GIT_ERR merging $MERGE_SHA. A fast-forward cut off mid-checkout writes files before it moves HEAD: read git status, and roll back to $BEFORE by the runbook."
        exit 4
    fi
    printf '%s\n' "$DEPLOY_GIT_OUT"
    # shellcheck disable=SC2086
    if deploy_git_bounded $GIT rev-parse --short HEAD; then head=$DEPLOY_GIT_OUT
    else head="unknown (${DEPLOY_GIT_ERR:-git rev-parse failed})"; EXTRA_DONE='RED head unknown'; fi
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
    finish "$MERGE_SHA"
}

# The classifier's own git is unbounded, so the classifier runs under the deploy's git timeout.
classify() {
    local rc=0 n=${DEPLOY_GIT_TIMEOUT:-300}
    DOCS_ONLY_GIT="$GIT" /usr/bin/timeout -k 5 "$n" "$SCRIPT_DIR/docs-only.sh" "$MERGE_SHA" </dev/null || rc=$?
    case "$rc" in
        0) land ;;
        124|137) say "STOP: git did not answer in ${n}s while the classifier read $ROOT, so nothing was classified and nothing moved. Find out why git is stuck (a lock, a FIFO, a full disk) before running it again."; exit 1 ;;
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
    repo=${FLEET_DEPLOY_REPO:-}
    [ -n "$repo" ] || return 0
    json=$($GH pr view "$PR" -R "$repo" --json headRefOid) || return 0
    head=$(json_value "$json" headRefOid)
    [ -n "$head" ] || return 0
    # shellcheck disable=SC2086
    deploy_git_or_refuse $GIT fetch origin 2>/dev/null || return 0
    # shellcheck disable=SC2086
    deploy_git_or_refuse $GIT cat-file -e "$head^{commit}" 2>/dev/null && return 0
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
        say "  Both append to $LEDGER. Then: fleet-deploy orbit $PR"
    fi
    gated
}

# A finished deploy of $1 (full sha) or $2 (short, logs before deploy-lib 2026-10-03) leaves a DONE
# line in an earlier log; its absence means an earlier run died after the fast-forward.
finished_log() {
    local dir file
    dir=$(dirname "$LOG")
    for file in "$dir"/*.log; do
        [ -f "$file" ] || continue
        [ "$file" = "$LOG" ] && continue
        if grep -qE "^DONE #[0-9]+ live ($1|$2) " "$file"; then
            printf '%s' "$file"
            return 0
        fi
    done
    return 1
}

nothing_to_land() {
    local short done_in
    # shellcheck disable=SC2086
    deploy_git_or_refuse $GIT rev-parse HEAD || refuse "cannot read the head of $ROOT, so whether the merge already landed is not known."
    if [ "$DEPLOY_GIT_OUT" != "$MERGE_SHA" ]; then
        say 'STOP: NOTHING TO LAND — the merge changes nothing here.'
        exit 0
    fi
    # shellcheck disable=SC2086
    deploy_git_or_refuse $GIT rev-parse --short HEAD || refuse "cannot read the short head of $ROOT that older logs name."
    short=$DEPLOY_GIT_OUT
    if done_in=$(finished_log "$MERGE_SHA" "$short"); then
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
EOF
    [ -z "$DEADLINE" ] || cat <<EOF
[ "\$($CLOCK)" -le $DEADLINE ] || { echo "TOO LATE: the slot came after main's freshness window closed, and nothing moved"; exit 1; }
EOF
    # The job is its own bash, so it carries the library's bounded git verbatim. docs/DECISIONS.md
    declare -f deploy_git_bounded
    cat <<EOF
DEPLOY_GIT_TIMEOUT=$(printf '%q' "${DEPLOY_GIT_TIMEOUT:-300}")
moved() { deploy_git_bounded $GIT diff --name-only $BEFORE $MERGE_SHA -- "\$@" || { echo "STEP \$step FAILED: \${DEPLOY_GIT_ERR:-git diff exited \$?} reading what moved"; exit 1; }; [ -n "\$DEPLOY_GIT_OUT" ]; }
echo "step 1 fetch + fast-forward"
deploy_git_bounded $GIT --no-optional-locks status --short || { echo "STEP 1 FAILED: \${DEPLOY_GIT_ERR:-git status exited \$?}, and the live tree did not move"; exit 1; }
printf '%s\n' "\$DEPLOY_GIT_OUT"
deploy_git_bounded $GIT fetch origin || { echo "STEP 1 FAILED: \${DEPLOY_GIT_ERR:-git fetch exited \$?}, and the live tree did not move"; exit 1; }
deploy_git_bounded $GIT merge --ff-only $MERGE_SHA || { echo "STEP 1 FAILED: \${DEPLOY_GIT_ERR:-git merge --ff-only exited \$?} merging $MERGE_SHA"; exit 1; }
printf '%s\n' "\$DEPLOY_GIT_OUT"
deploy_git_bounded $GIT rev-parse HEAD || { echo "STEP 1 FAILED: \${DEPLOY_GIT_ERR:-git rev-parse HEAD exited \$?}, so the landed commit is unknown"; exit 1; }
landed=\$DEPLOY_GIT_OUT
[ "\$landed" = "$MERGE_SHA" ] || { echo "STEP 1 FAILED: HEAD \$landed is not the resolved merge $MERGE_SHA, and nothing is built on a commit that was not gated"; exit 1; }
echo "step 2 root-owned proof"
rooted=\$(rooted_count)
[ "\$rooted" -eq 0 ] || { echo "STEP 2 FAILED: \$rooted root-owned path(s) before anything was built, and git running as orbit cannot write into a root-owned .git"; exit 1; }
step=3
if moved composer.lock; then
    echo "@@STEP 3 RAN: composer.lock moved"
    $COMPOSE exec -T app composer install --no-dev --optimize-autoloader --no-interaction
    $COMPOSE exec -T app chmod -R go-w vendor
else
    echo "step 3 not needed"
fi
echo "step 4 migrate"
$COMPOSE exec -T app php artisan migrate --force
step=4.5
if moved docker/app docker-compose.yml; then
    echo "@@STEP 4.5 RAN: docker/app or docker-compose.yml moved"
    $COMPOSE build app horizon scheduler
    $COMPOSE up -d app horizon scheduler
    $COMPOSE up -d --force-recreate web
fi
step=5
if moved $FRONT_END; then
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
    find -P "$ROOT" -user root -not -path "$ROOT/.claude/*" -exec chown -h orbit:orbit {} +
    rooted=\$(rooted_count)
fi
echo "@@ROOT-OWNED \$rooted"
EOF
}

deploy_steps() {
    local job rc=0 mark max_wait
    say "STEP 1 rollback target $BEFORE"
    say "STEPS 1-10 are one heavy-work job and can queue behind another; nothing prints here until it returns. Watch it with: tail -f $LOG"
    job=$(deploy_job)
    detail "$job"
    mark=$(stat -c %s "$LOG")
    PHASE=moving
    if [ -n "$DEADLINE" ]; then
        max_wait=$(( DEADLINE - $($CLOCK) ))
        [ "$max_wait" -ge 1 ] || max_wait=1
        env HEAVY_WORK_MAX_WAIT="$max_wait" $HEAVY orbit-deploy -- bash -c "$job" || rc=$?
    else
        $HEAVY orbit-deploy -- bash -c "$job" || rc=$?
    fi
    if [ "$rc" -ne 0 ]; then
        fail_tail 'STEPS 1-10' "$rc"
        if [ "$FROM_MAIN" -eq 1 ] && [ "$(deploy_head_file)" = "$BEFORE" ] && live_tree_clean; then
            PHASE=decide
            if too_late "$mark"; then
                refuse "TOO LATE: main $MERGE_SHA did not get the heavy-work slot inside its freshness window, and the live tree did not move; a person deploys it with fleet-deploy."
            fi
            refuse "the job failed before the live tree moved: $ROOT/.git/HEAD is still $BEFORE and the tree is clean, so there is nothing to roll back; a person deploys main $MERGE_SHA with fleet-deploy."
        fi
        say "ROLLBACK TARGET $BEFORE — the rollback block is in .claude/commands/deploy.md"
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
    say "Watch it with '$COMPOSE ps $2'; when it reports healthy, run the runbook's verify.sh block, which reads root's mirror."
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
    # shellcheck disable=SC2086
    if ! deploy_git_bounded $GIT diff --name-only "$BEFORE" "$MERGE_SHA" -- deploy/nginx; then
        VHOST_EXTRA=' vhost-unknown'
        say "HOST VHOST UNKNOWN: ${DEPLOY_GIT_ERR:-git diff failed} reading whether deploy/nginx moved — look by hand: git diff --name-only $BEFORE $MERGE_SHA -- deploy/nginx"
        return 0
    fi
    moved=$(printf '%s' "$DEPLOY_GIT_OUT" | tr '\n' ' ')
    [ -n "$moved" ] || return 0
    VHOST_EXTRA=' vhost-needed'
    say "HOST VHOST NEEDED, NOT RUN (deploy/nginx moved: ${moved% }) — by hand: nginx -t, then systemctl reload nginx"
}

# Step 5 ran or it did not, so the job's own record decides the mode rather than a
# second reading of the same diff.
verify() {
    local rc=0
    case " $RAN " in
        *' 5 '*)
            VERIFY_MODE=full
            say 'VERIFY full: step 5 built the front end, so the served bundle must be the one it built'
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
    FROM_MAIN=0
    DECIDE_ONLY=0
    while [ $# -gt 0 ]; do
        case "$1" in
            --gated-by-hand) BY_HAND=1 ;;
            --from-main) FROM_MAIN=1 ;;
            --decide-only) DECIDE_ONLY=1 ;;
            -h|--help) usage ;;
            '' | *[!0-9]*) usage ;;
            *) [ -z "$PR" ] || usage; PR=$1 ;;
        esac
        shift
    done
    if [ "$FROM_MAIN" -eq 1 ]; then
        [ -z "$PR" ] && [ "$BY_HAND" -eq 0 ] || usage
    else
        [ -n "$PR" ] && [ "$DECIDE_ONLY" -eq 0 ] || usage
    fi

    ROOT=${DEPLOY_ROOT:-/var/www/orbit}
    # GIT and COMPOSE are COMMANDS WITH ARGUMENTS, so they are
    # unquoted at every call site on purpose: they have to split.
    GIT=${DEPLOY_GIT:-git-as orbit -C $ROOT}
    GH=${DEPLOY_GH:-gh}
    HEAVY=${DEPLOY_HEAVY:-heavy-work}
    DOCKER=${DEPLOY_DOCKER:-docker}
    HEALTH_TIMEOUT=${DEPLOY_HEALTH_TIMEOUT:-180}
    HEALTH_INTERVAL=${DEPLOY_HEALTH_INTERVAL:-3}
    LEDGER=${DEPLOY_LEDGER:-/var/lib/fleet/gate-ledger}
    HOST=${ORBIT_HOST:-flights.ghiecode.io}
    BASE=${ORBIT_BASE:-http://127.0.0.1:3085}
    PUBLIC=${ORBIT_PUBLIC:-https://$HOST}
    LOCK=${DEPLOY_LOCK:-/root/personal-vps-deploys/orbit.lock}
    MIRROR=${DEPLOY_MIRROR:-/var/lib/fleet/deploy-on-merge/orbit.git}
    MIRROR_URL=${DEPLOY_MIRROR_URL:-ssh://git@github.com/gcotcheza/orbit.git}
    MIRROR_GIT=${DEPLOY_MIRROR_GIT:-/usr/bin/git}
    MIRROR_HOME=${DEPLOY_MIRROR_HOME:-/root}
    GNUPG=${DEPLOY_GNUPGHOME:-/etc/fleet/deploy-on-merge/gnupg}
    GPG=${DEPLOY_GPG:-/usr/bin/gpg}
    SSH_KEY=${DEPLOY_SSH_KEY:-/etc/fleet/deploy-on-merge/keys/orbit}
    RECORD_FILE="${DEPLOY_RECORD_ROOT:-/var/lib/fleet/deploy-on-merge}/$(basename "$ROOT").record"
    # CLOCK is a COMMAND WITH ARGUMENTS, unquoted at its call sites like GIT.
    CLOCK=${DEPLOY_CLOCK:-date +%s}
    FRESH_S=1800
    LOCK_BUSY=0
    PHASE=decide
    DEADLINE=''
    STATUS_BEFORE=unread
    EXTRA_DONE=''
    BEFORE=''
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
    deploy_log_open "${PR:-main}"
    trap 'on_exit $?' EXIT
    take_lock
    under_lock 8>&-
}

# Bash keeps the lock on its own close-on-exec copy of fd 8 here, so no child inherits it.
under_lock() {
    local branch_rc=0
    # shellcheck disable=SC2086
    deploy_git_or_refuse $GIT rev-parse --abbrev-ref HEAD || branch_rc=$?
    [ "$branch_rc" = 0 ] || refuse "reading the branch of $ROOT exited $branch_rc, so it is not known to be main; a deploy never fast-forwards over it."
    [ "$DEPLOY_GIT_OUT" = main ] \
        || refuse "the checkout is not on main, and a deploy fast-forwards main."
    # shellcheck disable=SC2086
    deploy_git_or_refuse $GIT log --oneline -3 || :
    printf '%s\n' "$DEPLOY_GIT_OUT"

    if [ "$FROM_MAIN" -eq 1 ]; then
        judge_main
        # shellcheck disable=SC2086
        deploy_git_or_refuse $GIT fetch origin || refuse "fetching origin into $ROOT failed, so the classifier could not read main $MERGE_SHA."
    else
        head_is_present
        resolve
    fi
    # COMPOSE is a COMMAND WITH ARGUMENTS and has no seam: only deploy_compose_init sets it.
    deploy_compose_init "$MERGE_SHA"
    COMPOSE=$DEPLOY_COMPOSE
    if [ "$FROM_MAIN" -eq 0 ]; then
        # shellcheck disable=SC2086
        if deploy_git_or_refuse $GIT rev-parse --short HEAD; then BEFORE=$DEPLOY_GIT_OUT; fi
        [ -n "$BEFORE" ] || refuse "cannot read the current head of $ROOT, and that short sha is the rollback target STEP 1 prints."
    fi
    refuse_if_dirty
    if [ "$FROM_MAIN" -eq 1 ]; then
        preflight
        if [ "$DECIDE_ONLY" -eq 1 ]; then
            say "WOULD DEPLOY #$PR $MERGE_SHA head $HEAD_SHA was $BEFORE — --decide-only stops before STEP 1"
            exit 0
        fi
        # shellcheck disable=SC2086
        deploy_git_or_refuse $GIT --no-optional-locks status --porcelain --untracked-files=normal \
            || refuse "reading the status of $ROOT failed, so a failed job could not be told from a moved tree."
        STATUS_BEFORE=$DEPLOY_GIT_OUT
        classify
    else
        classify
        gate_or_recipe
        preflight
    fi
    baseline
    deploy_steps
    await_health
    vhost_notice
    verify
    EXTRA_DONE="root-owned $ROOTED verify $VERIFY_MODE$VHOST_EXTRA"
    finish "$MERGE_SHA"
}

main "$@"
