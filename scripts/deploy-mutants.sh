#!/usr/bin/env bash
# Every guard in scripts/deploy-test.sh, proved able to go red: each mutant
# breaks one thing in a copy of scripts/ and the checks named must fail.
#
#   scripts/deploy-mutants.sh
#
# A verify.sh mutation is run against scripts/verify-test.sh; everything else
# against scripts/deploy-test.sh, which fakes verify.sh out entirely.
#
# It never reads /var/www/orbit, never runs docker and never runs gh. It runs as
# root, because scripts/deploy-test.sh does: every copy lives in a root 700 directory.
#
# `--worker <n>` runs mutation n alone and exits 0 only if it was caught; that
# is how the parent feeds its workers. docs/DECISIONS.md, the-mutant-harness-is-a-gate-step
# shellcheck disable=SC2016  # every mutation below is sed source, not shell
set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SELF="${SCRIPT_DIR}/${BASH_SOURCE[0]##*/}"
# shellcheck source=scripts/lib/gate/deploy-lib.sh
. "${SCRIPT_DIR}/lib/gate/deploy-lib.sh"
deploy_lib_ready || exit 1
WORK="$(mktemp -d /srv/worker-scratch/orbit-deploy-mutants.XXXXXXXX)" || exit 1
trap 'rm -rf "${WORK:?}"' EXIT
HARNESS_TIMEOUT=180
missed=0
n=0
ran=0
ONLY=''

if [ $# -gt 0 ]; then
    if [ "$1" = '--worker' ] && [ $# -eq 2 ] && [ -n "$2" ] && [ -z "${2//[0-9]/}" ]; then
        ONLY=$2
    else
        printf 'usage: %s [--worker <n>]\n' "${0##*/}" >&2
        exit 2
    fi
fi

JOBS=$(nproc)
JOBS=$((JOBS - 1))
[ "${JOBS}" -lt 1 ] && JOBS=1
[ "${JOBS}" -gt 3 ] && JOBS=3

mutant() {
    n=$((n + 1))
    [ "${ONLY}" = "${n}" ] || return 0
    ran=$((ran + 1))
    run_mutant "$@"
}

# A check name must end where the harness ends it, or a name another one starts
# with answers for it. These six are how fail()'s callers end one.
find_red() {
    local name=$2 line
    while IFS= read -r line; do
        case ${line} in
            "FAIL ${name}" | "FAIL ${name},"* | "FAIL ${name}:"* | "FAIL ${name} ("* \
                | "FAIL ${name} — "* | "FAIL ${name} is ["*)
                printf '%s\n' "${line}"
                return 0
                ;;
        esac
    done <"$1"
    return 1
}

all_red() {
    local log=$1 expect
    shift
    for expect in "$@"; do
        find_red "${log}" "${expect}" >/dev/null || return 1
    done
}

run_mutant() {
    local name=$1 target=$2 expr=$3 rc dir red expect harness log pid watcher
    local -a harness_args=()
    shift 3
    dir="${WORK}/${n}"
    mkdir -p "${dir}"
    cp -r "${SCRIPT_DIR}" "${dir}/scripts"
    harness="${dir}/scripts/deploy-test.sh"
    [ "${target}" = 'verify.sh' ] && harness="${dir}/scripts/verify-test.sh"
    if [ "${target}" = 'lib/gate/deploy-lib.sh' ]; then
        harness="${dir}/scripts/deploy-lib-test.sh"
        harness_args=("${dir}")
    else
        # Laid where fleet-deploy lays it, so a mutant can break the lib there.
        mkdir -p "${dir}/scripts/lib/deploy"
        cp "${DEPLOY_LIB}"/*.sh "${dir}/scripts/lib/deploy/"
    fi
    sed -i "${expr}" "${dir}/scripts/${target}" || { printf 'BROKEN %s: sed failed\n' "${name}"; missed=$((missed + 1)); return; }
    # A sed that matched nothing leaves the guard intact, so the harness passes
    # while testing nothing. docs/DECISIONS.md, the-mutant-harness-is-a-gate-step
    if cmp -s "${SCRIPT_DIR}/${target}" "${dir}/scripts/${target}" \
        || { [ ! -e "${SCRIPT_DIR}/${target}" ] && cmp -s "${DEPLOY_LIB}/${target#lib/deploy/}" "${dir}/scripts/${target}"; }; then
        printf 'BROKEN %s: the mutation changed nothing in %s\n' "${name}" "${target}"
        missed=$((missed + 1))
        return
    fi
    # Nothing after the last expected red is read, so the harness is stopped there;
    # TMPDIR and DEPLOY_TEST_ROOT keep what a stopped one leaves inside this mutant's copy.
    log="${dir}/harness.out"
    mkdir -p "${dir}/tmp"
    : >"${log}"
    # setsid: stopping the harness must take the deploy it is running with it,
    # or an orphan writes into a directory this script is already removing.
    TMPDIR="${dir}/tmp" DEPLOY_TEST_ROOT="${dir}" DEPLOY_SH="${dir}/scripts/deploy.sh" VERIFY_SH="${dir}/scripts/verify.sh" \
        GATE_LEDGER_LIB="${dir}/scripts/lib/deploy/ledger.sh" \
        setsid timeout "${HARNESS_TIMEOUT}" bash "${harness}" "${harness_args[@]}" >"${log}" 2>&1 &
    pid=$!
    (
        while kill -0 "${pid}" 2>/dev/null; do
            all_red "${log}" "$@" && { kill -- -"${pid}" 2>/dev/null; break; }
            sleep 0.3
        done
    ) &
    watcher=$!
    wait "${pid}"
    rc=$?
    kill "${watcher}" 2>/dev/null
    wait "${watcher}" 2>/dev/null
    if [ "${rc}" -eq 124 ]; then
        printf 'BROKEN %s: timed out after %ss\n' "${name}" "${HARNESS_TIMEOUT}"
        missed=$((missed + 1))
        return
    fi
    for expect in "$@"; do
        red="$(find_red "${log}" "${expect}")"
        if [ "${rc}" -ne 0 ] && [ -n "${red}" ]; then
            printf 'red  %s | %s\n' "${name}" "${red%% — *}"
        else
            printf 'GREEN %s | [%s] never failed (test rc=%s)\n' "${name}" "${expect}" "${rc}"
            missed=$((missed + 1))
        fi
    done
}

mutant 'resolve accepts an unmerged PR' lib/deploy/resolve.sh \
    's/\[ "\$state" = MERGED \]/[ "$state" != MERGEDX ]/' \
    'an unmerged PR is refused'
mutant 'resolve stops comparing the tip' lib/deploy/resolve.sh \
    's/\[ "\$tip" = "\$MERGE_SHA" \]/true/' \
    'a merge behind the tip is refused'
mutant 'resolve stops comparing the trees' lib/deploy/resolve.sh \
    's/if deploy_git_or_refuse \$GIT diff --quiet "\$HEAD_SHA" "\$MERGE_SHA"; then/if true; then/' \
    'a merge tree that is not the head tree names the merge as the commit to gate' \
    'and a green head does not deploy a merge the ledger never saw' \
    'an ungated merge builds nothing' \
    'a gated merge commit deploys'
mutant 'the merge commit is announced as a head' lib/deploy/resolve.sh \
    "s/GATE_WHAT='merge'/GATE_WHAT='head'/" \
    'and the recipe says it is the merge that wants gating' \
    'and DONE says the merge was the commit that was gated'
mutant 'the differing-tree line stops naming the merge' lib/deploy/resolve.sh \
    's/so the merge commit itself is what must be gated/so re-gate/' \
    'a merge tree that is not the head tree names the merge as the commit to gate'
mutant 'gated reads the branch head, not the commit that was gated' lib/deploy/ledger.sh \
    's/awk -v sha="\$sha"/awk -v sha="$HEAD_SHA"/' \
    'a gated merge commit deploys' \
    'and the ledger read is the merge commit, not the branch head'
mutant 'the summary never says which commit was gated' lib/deploy/ledger.sh \
    's/GATED="\${ci_by:+\$ci_by + e2e }ledger \$what \${sha:0:7}\${bytree:+, by identical tree: \$bytree}"/GATED=ledger/' \
    'and DONE says the merge was the commit that was gated' \
    'and DONE says the head was the commit that was gated'
mutant 'the recipe sends the operator to the branch head' deploy.sh \
    's/worktree add \$wt \$GATE_SHA/worktree add $wt $HEAD_SHA/' \
    'and the recipe names the merge commit' \
    'never the branch head, gating which would change nothing'
mutant 'gated stops reading the ledger' lib/deploy/ledger.sh \
    's/^gated() {/gated() { GATED=ledger; return 0;/' \
    'a missing ledger is refused' 'a head absent from the ledger is refused' 'ci without e2e is refused'
mutant 'by hand stops saying so' lib/deploy/ledger.sh \
    's/ over the verdict above — transition/ — transition/' \
    '--gated-by-hand says so out loud'
mutant 'a dirty tree is recorded' lib/deploy/ledger.sh \
    '/^        return 2$/d' \
    'a dirty tree writes no row'
mutant 'the suite runs as anyone' deploy-test.sh \
    '/^\[ "\$(id -u)" -eq 0 \] ||/d' \
    'a run as anyone but root refuses in one line'
mutant 'a copy inside the checkout it deploys is run' lib/deploy/summary.sh \
    '/case "\${d%\/}\/" in/d' \
    "the checkout's own copy refuses and names fleet-deploy"
mutant 'a copy the app user owns is run' lib/deploy/summary.sh \
    '/stat -L -c %u "\$f"/d' \
    'a copy the app user owns refuses before anything runs'
mutant 'the repository falls back to nothing' lib/deploy/resolve.sh \
    '/FLEET_DEPLOY_REPO is unset/d' \
    'no FLEET_DEPLOY_REPO is refused, never read off the checkout'
mutant 'finish writes no record row' lib/deploy/summary.sh \
    's/deploy_record_row DONE "\$1" "\$LOG\$red" || refuse/true || refuse/' \
    "and the landing writes root's record row with the full merge sha" \
    "and root's record holds the full merge sha"
mutant 'the landing finishes on the short head again' deploy.sh \
    '/^land() {/,/^}/s/finish "\$MERGE_SHA"/finish "$head"/' \
    'the landing stops with DONE'
mutant 'the deploy finishes on the short head again' deploy.sh \
    '/^under_lock() {/,/^}/s/finish "\$MERGE_SHA"/finish "$($GIT rev-parse --short HEAD)"/' \
    'the deploy finishes'
mutant 'the check gate never arms the ledger' check.sh \
    '/^gate_ledger_arm$/d' \
    'check.sh never arms the ledger'
mutant 'the browser gate never arms the ledger' e2e.sh \
    '/^gate_ledger_arm$/d' \
    'e2e.sh never arms the ledger'
mutant 'a dirty checkout is allowed' lib/deploy/preflight.sh \
    's/^refuse_if_dirty() {/refuse_if_dirty() { return 0;/' \
    'a dirty checkout is refused'
mutant 'deploy.sh runs on with no lib beside it' deploy.sh \
    '/^deploy_lib_ready || /d' \
    'a deploy.sh with no lib beside it refuses and names fleet-deploy'
mutant 'the gate takes the caller'"'"'s DEPLOY_LIB again' lib/gate/deploy-lib.sh \
    's#^DEPLOY_LIB=/srv/engineering-standards/scripts/lib/deploy$#DEPLOY_LIB=${DEPLOY_LIB:-/srv/engineering-standards/scripts/lib/deploy}#' \
    'an exported DEPLOY_LIB is ignored'
mutant 'the test seam is honoured as root' lib/gate/deploy-lib.sh \
    's/ || (( EUID == 0 )); then$/; then/' \
    'root has the test seam refused, harness or not'
mutant 'the test seam is honoured outside the harness' lib/gate/deploy-lib.sh \
    's/\[\[ "\${DEPLOY_LIB_HARNESS:-}" != deploy-lib-test \]\] || //' \
    'the test seam outside the harness is refused'
mutant 'a clone off its origin/main is read anyway' lib/gate/deploy-lib.sh \
    's/^    if \[\[ -z "\$head" || "\$head" != "\$main" \]\]; then$/    if [[ -z "$head" ]]; then/' \
    'a clone whose HEAD is not its origin/main is refused'
mutant 'the helper finds git on PATH again' lib/gate/deploy-lib.sh \
    's#/usr/bin/git #git #g' \
    'a git first in PATH cannot vouch for a clone off its origin/main'
mutant 'a dirty lib is read anyway' lib/gate/deploy-lib.sh \
    's/^    if \[\[ -n "\$dirty" \]\]; then$/    if false; then/' \
    'a lib with a modified tracked file is refused' 'a lib with an untracked file is refused'
mutant 'untracked files in the lib are not looked at' lib/gate/deploy-lib.sh \
    's/--untracked-files=all/--untracked-files=no/' \
    'a lib with an untracked file is refused'
mutant 'a failing git status is stepped past' lib/gate/deploy-lib.sh \
    '/REFUSED: git status failed/{n;d}' \
    'and the failed-status refusal is a failure'
mutant 'a lib missing compose.sh is read anyway' lib/gate/deploy-lib.sh \
    's/ preflight.sh compose.sh; do$/ preflight.sh; do/' \
    'a lib missing compose.sh, which deploy.sh sources, is refused'
mutant 'the ungated head gets no recipe' deploy.sh \
    's/^    if ! ( exec 3>\/dev\/null; gated ) >\/dev\/null 2>&1; then$/    if false; then/' \
    'and the refusal is preceded by the worktree recipe'
mutant 'a docs-only merge is deployed' deploy.sh \
    's/^        0) land ;;/        0) say "docs-only" ;;/' \
    'a docs-only merge lands'
mutant 'the landing stops proving the site' deploy.sh \
    's/\[ "\$code" != 200 \] || \[ "\$edge" != 200 \]/false/' \
    'a landing onto a site that stopped answering is a failure'
mutant 'the landing stops proving ownership' deploy.sh \
    's/^    if \[ "\$rooted" -ne 0 \]; then$/    if false; then/' \
    'a landing onto a root-owned tree is not a clean landing'
mutant 'the composer guard never matches' deploy.sh \
    's/^if moved composer.lock; then/if moved composer.lock.absent; then/' \
    'composer.lock moving runs composer'
mutant 'the image guard never matches' deploy.sh \
    's#^if moved docker/app docker-compose.yml; then#if moved docker/app.absent; then#' \
    'docker moving recreates rather than restarts' 'compose moving recreates rather than restarts'
mutant 'a compose-only change is restarted, not recreated' deploy.sh \
    's#^if moved docker/app docker-compose.yml; then#if moved docker/app; then#' \
    'compose moving recreates rather than restarts'
mutant 'the front-end guard never matches' deploy.sh \
    's/^if moved \$FRONT_END; then/if moved composer.lock.absent; then/' \
    'frontend moving builds the assets' 'npm moving builds the assets' \
    'vite moving builds the assets' 'publicasset moving builds the assets' \
    'pkgjson moving builds the assets' 'npmrc moving builds the assets'
mutant 'the front end forgets its own build script' deploy.sh \
    's/ package.json package-lock.json/ package-lock.json/' \
    'pkgjson moving builds the assets'
mutant 'the front end forgets the npm config' deploy.sh \
    's/ .npmrc vite.config.js/ vite.config.js/' \
    'npmrc moving builds the assets'
mutant 'the marker the job writes and the one the parent reads disagree' deploy.sh \
    's/echo "@@STEP 3 RAN/echo "STEP 3 RAN/' \
    'composer.lock moving runs composer'
mutant 'retain snapshots the build it is about to replace' deploy.sh \
    '/exec -T app php artisan build:retain$/d
/^    \$COMPOSE --profile build run --rm assets$/i\
    $COMPOSE exec -T app php artisan build:retain' \
    'and build:retain follows the build and precedes the restart'
mutant 'horizon is drained in the app container' deploy.sh \
    's/exec -T horizon php artisan horizon:terminate/exec -T app php artisan horizon:terminate/' \
    'horizon is drained IN the horizon container' \
    'never in app, where it exits 0 having terminated nothing'
mutant 'the restart leaves horizon on old code' deploy.sh \
    "s/^RESTARTED='app horizon scheduler web'$/RESTARTED='app scheduler web'/" \
    'view:clear, then the drain, then the four restarts, in that order'
mutant 'the battery is run before the containers report healthy' deploy.sh \
    '/^    await_health$/d' \
    'a container that never reports healthy stops before the battery'
mutant 'a ps that failed reads as a service with no healthcheck' deploy.sh \
    's#out=\$(\$COMPOSE ps "\$1") || return 1#out=$($COMPOSE ps "$1" 2>\&1)#' \
    'docker refusing to say is not the same as no healthcheck'
mutant 'the classifier is addressed through the checkout being deployed' deploy.sh \
    's#"\$SCRIPT_DIR/docs-only.sh"#"$ROOT/scripts/docs-only.sh"#' \
    'a helper is read from the script directory, never from the checkout being deployed'
mutant 'the classifier moves into the export under a seam' docs-only.sh \
    's#^\[ -n "\${DOCS_ONLY_GIT:-}" \] || cd #cd #' \
    'the real classifier run from the export lands a docs-only merge' \
    'the real classifier run from the export sends a code merge down the full path'
mutant 'the classifier trusts a seam naming another tree' docs-only.sh \
    '/^if \[ -n "\$seam_dir" \]/,/^fi$/d' \
    'a seam naming another tree than the caller stands in exits' \
    'and the classifier refuses it'
mutant 'the migration never runs' deploy.sh \
    '/php artisan migrate --force/d' \
    'migrate is the first command the job asks of compose when no lockfile moved'
mutant 'composer is left until after the migration' deploy.sh \
    '/^echo "step 4 migrate"$/d
/artisan migrate --force$/d
/composer\.lock)" \]; then$/i\
echo "step 4 migrate"\
$COMPOSE exec -T app php artisan migrate --force' \
    'composer runs before migrate when the lockfile moved'
mutant 'the dirty check slips back below the classifier' deploy.sh \
    '/^    refuse_if_dirty$/d
/^    classify$/a\
    refuse_if_dirty' \
    'a dirty docs-only merge is refused before it lands'
mutant 'the host vhost guard never matches' deploy.sh \
    's#-- deploy/nginx#-- deploy/nginx.absent#' \
    'deploy/nginx moving says the host vhost is still a hand step'
mutant 'the ownership proof loses its carve-out' deploy.sh \
    's#-not -path "\$ROOT/\.claude/\*" ##' \
    'the ownership proof carves out the agent runtime' \
    'root-owned paths that appear during the deploy are repaired narrowly'
mutant 'the root-owned proof stops stopping' deploy.sh \
    '/STEP 2 FAILED/d' \
    'a root-owned path before anything is built stops the job'
mutant 'the root-owned repair is a blanket chown' deploy.sh \
    's#find -P "\$ROOT" -user root -not -path "\$ROOT/\.claude/\*" -exec chown -h orbit:orbit {} +#chown -R orbit:orbit "\$ROOT"#' \
    'root-owned paths that appear during the deploy are repaired narrowly'
mutant 'the root-owned repair follows a symlink' deploy.sh \
    's/-exec chown -h orbit:orbit/-exec chown orbit:orbit/' \
    'root-owned paths that appear during the deploy are repaired narrowly'
mutant "compose reads the checkout's files again" deploy.sh \
    's/^    COMPOSE=\$DEPLOY_COMPOSE$/    COMPOSE="$DOCKER compose"/' \
    "compose runs as root's argv over fleet-deploy's export" \
    'and no compose call goes around it'
mutant "fleet-deploy's export is never checked" deploy.sh \
    '/^    deploy_compose_init "\$MERGE_SHA"$/d' \
    'compose files exported at another commit are refused'
mutant 'every release verifies backend-only' deploy.sh \
    's#^\( *\)ORBIT_DIR="\$ROOT" "\$SCRIPT_DIR/verify.sh" || rc=\$?$#\1ORBIT_DIR="$ROOT" "$SCRIPT_DIR/verify.sh" --backend-only || rc=$?#' \
    'and never passes --backend-only'
mutant 'a helper is addressed through the checkout being deployed' deploy.sh \
    's#ORBIT_DIR="\$ROOT" "\$SCRIPT_DIR/verify.sh" --before#"$ROOT/scripts/verify.sh" --before#' \
    'a helper is read from the script directory, never from the checkout being deployed'
mutant 'the battery is never told which checkout to verify' deploy.sh \
    's#ORBIT_DIR="\$ROOT" "\$SCRIPT_DIR/verify.sh"#"$SCRIPT_DIR/verify.sh"#' \
    'and each call names the checkout being deployed, not the battery default'
mutant 'no baseline is taken at all' deploy.sh \
    '/^    baseline$/d' \
    'the baseline is taken before anything moves'
mutant 'a failing job prints no tail' deploy.sh \
    "s/fail_tail 'STEPS 1-10' \"\$rc\"/:/" \
    'a failing step says which rc'
mutant 'the browser gate un-filters on a playwright filter' e2e.sh \
    's/"\${#PW_ARGS\[@\]}" -eq 0/true/' \
    'scripts/e2e.sh records a filtered run as a full green'
mutant 'the check gate clears its filter above its refusals' check.sh \
    '/^GATE_FILTERED=0$/d
/^GATE_FILTERED=1$/a\
GATE_FILTERED=0' \
    'scripts/check.sh records a run that never reached the list'
mutant 'the check gate loses errexit inside its functions' check.sh \
    's/^set -Eeuo pipefail$/set -euo pipefail/' \
    'check.sh loses errexit failures'
mutant 'the browser gate loses errexit inside its functions' e2e.sh \
    's/^set -Eeuo pipefail$/set -euo pipefail/' \
    'e2e.sh loses errexit failures'
mutant 'the check gate records from an EXIT trap' check.sh \
    's/^\(trap .*gate_record.*\) ERR$/\1 EXIT/' \
    'a gate records from an EXIT trap'
mutant 'the browser gate records teardown as the suite' e2e.sh \
    's/^gate_record 0$/gate_record "$TEARDOWN_STATUS"/' \
    'scripts/e2e.sh records teardown status as the suite result'

# --- a half-finished deploy, a head that is not here, and the battery ---------
mutant 'rc 2 is always nothing to land' deploy.sh \
    's/^        2) nothing_to_land ;;$/        2) say "nothing to land"; exit 0 ;;/' \
    'a checkout already on the merge with no finished deploy is refused, not called nothing to land'
mutant 'every sha counts as already deployed' deploy.sh \
    's/^finished_log() {/finished_log() { printf earlier.log; return 0;/' \
    'a checkout already on the merge with no finished deploy is refused, not called nothing to land'
mutant 'no sha counts as already deployed' deploy.sh \
    's/^finished_log() {/finished_log() { return 1;/' \
    'a sha an earlier log says DONE for is nothing to land' \
    'a full merge sha an earlier log says DONE for is nothing to land'
mutant 'a full-sha DONE is not read' deploy.sh \
    's/live (\$1|\$2) /live ($2) /' \
    'a full merge sha an earlier log says DONE for is nothing to land'
mutant 'a short-sha DONE from an older log is not read' deploy.sh \
    's/live (\$1|\$2) /live ($1) /' \
    'a sha an earlier log says DONE for is nothing to land'
mutant 'a head that is not in this checkout is never named' deploy.sh \
    's/^head_is_present() {/head_is_present() { return 0;/' \
    'a head this checkout never had is named, not blamed on the tree'

# --- a git that never answers: each bounded call site, unbounded -------------
mutant "the job's status is unbounded" deploy.sh \
    's/^deploy_git_bounded \(\$GIT --no-optional-locks status --short\) /DEPLOY_GIT_OUT=\\$(\1) /' \
    "the job's status that never answers fails the job"
mutant "the job's fetch is unbounded" deploy.sh \
    's/^deploy_git_bounded \(\$GIT fetch origin\) /DEPLOY_GIT_OUT=\\$(\1) /' \
    "the job's fetch that never answers fails the job"
mutant "the job's fast-forward is unbounded" deploy.sh \
    's/^deploy_git_bounded \(\$GIT merge --ff-only \$MERGE_SHA\) /DEPLOY_GIT_OUT=\\$(\1) /' \
    "the job's fast-forward that never answers fails the job"
mutant "the job's landed-head read is unbounded" deploy.sh \
    's/^deploy_git_bounded \(\$GIT rev-parse HEAD\) /DEPLOY_GIT_OUT=\\$(\1) /' \
    "the job's landed-head read that never answers fails the job"
mutant "the job's what-moved read is unbounded" deploy.sh \
    's/^moved() { deploy_git_bounded \(\$GIT diff [^|]*\)|| /moved() { DEPLOY_GIT_OUT=\\$(\1) || /' \
    "the job's what-moved read that never answers fails the job"
mutant "the landing's fast-forward is unbounded" deploy.sh \
    's/^    if ! deploy_git_bounded \(\$GIT merge --ff-only "\$MERGE_SHA"\); then$/    if ! DEPLOY_GIT_OUT=$(\1); then/' \
    'a landing whose fast-forward never answers exits'
mutant 'the classifier is unbounded' deploy.sh \
    's|/usr/bin/timeout -k 5 "\$n" "\$SCRIPT_DIR/docs-only.sh"|"$SCRIPT_DIR/docs-only.sh"|' \
    'a classifier whose git never answers exits'

# --- from main: deploy-on-merge's judge (card 614) ------------------------------
mutant 'the deploy lock is never taken' deploy.sh \
    '/flock -n 8 ||/d' \
    'a held deploy lock is refused' 'a hand deploy takes the same lock'
mutant 'the job inherits the deploy lock' deploy.sh \
    's/^    under_lock 8>&-$/    under_lock/' \
    'and the job does not inherit the lock'
mutant '--from-main takes a PR number or --gated-by-hand' deploy.sh \
    's/\[ -z "\$PR" \] && \[ "\$BY_HAND" -eq 0 \] || usage/true/' \
    'the words [--from-main 90] exit' 'the words [--from-main --gated-by-hand] exit'
mutant '--decide-only is taken by hand' deploy.sh \
    's/\[ -n "\$PR" \] && \[ "\$DECIDE_ONLY" -eq 0 \] || usage/[ -n "$PR" ] || usage/' \
    'the words [90 --decide-only] exit'
mutant 'no live record is judged' deploy.sh \
    '/\[ -n "\$RECORD" \] || refuse/d' \
    'no live record is refused'
mutant 'a short-sha record is judged' deploy.sh \
    's/\[\[ \$RECORD =~ ^\[0-9a-f\]{40}\$ \]\]/true/' \
    'a short-sha record is refused, never expanded'
mutant 'a record others can write is judged' deploy.sh \
    's/deploy_record_safe "\$RECORD_FILE" || refuse/true || refuse/' \
    'a record others can read is refused'
mutant 'the deploy key is not checked' deploy.sh \
    's/^    ssh_key_safe || refuse/    true || refuse/' \
    'a missing deploy key is refused'
mutant 'a mirror git that never answers is waited on' deploy.sh \
    '/case "\$rc" in 124|137) refuse "git did not answer in/d' \
    'a mirror fetch that never answers is refused as a timeout'
mutant 'a main that is already live deploys again' deploy.sh \
    's/if \[ "\$MERGE_SHA" = "\$RECORD" \]; then/if false; then/' \
    'is already live'
mutant 'a one-parent main deploys' deploy.sh \
    's/\[ "\$nparents" -eq 2 \]/true/' \
    'a one-parent main is refused'
mutant 'a merge made off GitHub deploys' deploy.sh \
    's/\(\[\[ \$committer =~ \)/[[ x =~ x ]] || \1/' \
    'a merge someone else committed is refused'
mutant 'an unsigned merge deploys' deploy.sh \
    '/carries no good GitHub signature/d' \
    'an unsigned merge is refused'
mutant 'any key signs for GitHub' deploy.sh \
    's/\[ "\$gf" = "\$WEBFLOW_FPR" \]/true/' \
    'a merge signed by another key is refused'
mutant 'a merge whose tree is not its head deploys' deploy.sh \
    's/\[ -n "\$mtree" \] && \[ "\$mtree" = "\$htree" \]/true/' \
    'a merge whose tree is not its head is refused'
mutant 'a stale merge deploys' deploy.sh \
    's/\[ "\$now" -le "\$DEADLINE" \]/true/' \
    'a merge older than 30 minutes is refused'
mutant 'a live tree off the record deploys' deploy.sh \
    's/\[ "\$live" = "\$RECORD" \]/true/' \
    "a live tree that is not root's record is refused loudly"
mutant 'a main that does not descend from the record deploys' deploy.sh \
    's/mirror_run merge-base --is-ancestor "\$RECORD" "\$MERGE_SHA"/true/' \
    'a record main does not descend from is refused'
mutant 'from main, the gate rows are not read' deploy.sh \
    's/^    owed_rows_green$/    GATED=skipped/' \
    'from main, a red e2e row is refused'
mutant 'the DONE line hides who deployed it' deploy.sh \
    's/^    GATED="\$GATED; deployed by deploy-on-merge"$/    :/' \
    'and says deploy-on-merge deployed it'
mutant 'the live checkout never fetches main' deploy.sh \
    's/^        deploy_git_or_refuse \$GIT fetch origin || refuse "fetching origin into/        true || refuse "fetching origin into/' \
    'a live checkout that cannot fetch main is refused before the classifier'
mutant '--decide-only deploys' deploy.sh \
    's/^        if \[ "\$DECIDE_ONLY" -eq 1 \]; then$/        if false; then/' \
    'and stops before STEP 1' '--decide-only: the live tree did not move'
mutant 'the job never re-judges the window' deploy.sh \
    '/TOO LATE: the slot came after/d' \
    'a slot won after the window closed is refused and exits'
mutant 'heavy-work waits past the window' deploy.sh \
    's/env HEAVY_WORK_MAX_WAIT="\$max_wait" //' \
    'heavy-work waits no longer than the freshness window left'
mutant 'a give-up is not called too late' deploy.sh \
    's/^            if too_late "\$mark"; then$/            if false; then/' \
    'it says TOO LATE' 'as too late'
mutant 'an unmoved tree after a failed job is a rollback' deploy.sh \
    's/\[ "\$FROM_MAIN" -eq 1 \] && \[ "\$(deploy_head_file)" = "\$BEFORE" \] && live_tree_clean/false/' \
    'a slot won after the window closed is refused and exits'
mutant 'a half-written tree reads as unmoved' deploy.sh \
    's/^live_tree_clean() {/live_tree_clean() { return 0;/' \
    'a failed job that left a file in the live tree is a failure after STEP 1 and exits'
mutant 'a failure after STEP 1 is not code 4' deploy.sh \
    '/^on_exit() {/,/^}/s/^        rc=4$/        rc=1/' \
    'from main, a step that fails after the fast-forward exits'
mutant 'a docs-only landing never says it moved' deploy.sh \
    '/^land() {/,/^}/s/^    PHASE=moving$/    :/' \
    'from main, a docs-only landing onto a site that stopped answering exits'
mutant 'a refused fast-forward says it moved' deploy.sh \
    "s/{ PHASE=decide; say 'NOT LANDED/{ say 'NOT LANDED/" \
    'from main, a refused fast-forward, which moves nothing, exits'
mutant 'a refusal from main is not code 2' deploy.sh \
    '/^on_exit() {/,/^}/s/^        rc=2$/        rc=1/' \
    'an unsigned merge exits'
mutant 'a RED from main is not code 3' deploy.sh \
    's/\*RED\*) rc=3 ;;/*RED*) rc=0 ;;/' \
    'from main, live with a RED, exits'
mutant 'a busy lock is not code 5' deploy.sh \
    '/^on_exit() {/,/^}/s/^        rc=5$/        rc=1/' \
    'lock busy, a code of its own, exits'
mutant '--decide-only answers before the classifier' deploy.sh \
    '/^        if \[ "\$DECIDE_ONLY" -eq 1 \]; then$/,/^        fi$/s/^            classifier_rc$/            CLASS_RC=0/' \
    '--decide-only on a merge the classifier refuses exits' 'it is the classifier STOP' 'and never says it would deploy'
mutant 'a moved vhost deploys from main' deploy.sh \
    's/^        vhost_refuse$/        :/' \
    'from main, a moved deploy/nginx is a RED line naming the sha'
mutant 'a vhost red is not code 6' deploy.sh \
    '/^    elif \[ "\$VHOST_RED" -eq 1 \]; then$/,/^        rc=6$/d' \
    'a moved vhost, the alarm code, exits'
mutant 'a vhost red carries on to STEP 1' deploy.sh \
    '/^vhost_refuse() {/,/^}/{/^    exit 6$/d}' \
    'a moved vhost: nothing reaches STEP 1'
mutant 'a vhost alarms every tick' deploy.sh \
    '/! grep -qxF "\$MERGE_SHA" "\$VHOST_SEEN"/,/alarmed once already/d' \
    'the same moved vhost again exits' 'refuses without a second alarm'
mutant 'any sha alarmed silences every later one' deploy.sh \
    's/! grep -qxF "\$MERGE_SHA" "\$VHOST_SEEN"/! grep -q . "$VHOST_SEEN"/' \
    'a moved vhost on a sha not yet alarmed, the alarm code, exits' 'an earlier sha alarmed still leaves a RED line naming the new one'
mutant 'the alarmed sha is never kept' deploy.sh \
    '/>>"\$VHOST_SEEN"/d' \
    'and the alarmed sha is kept beside the lock' 'the same moved vhost again exits'
mutant 'an unwritable vhost marker refuses' deploy.sh \
    's/|| say "cannot note \$MERGE_SHA in \$VHOST_SEEN, so the next tick alarms again"/|| refuse "cannot note"/' \
    'a vhost marker that cannot be written, the alarm code, exits'
mutant 'an unanswered vhost diff deploys' deploy.sh \
    's/|| refuse "the mirror cannot say whether deploy\/nginx moved/|| true "/' \
    'a vhost diff the mirror cannot answer is refused'
mutant 'the shadow skips the vhost guard' deploy.sh \
    's/^        vhost_refuse$/        [ "$DECIDE_ONLY" -eq 1 ] || vhost_refuse/' \
    '--decide-only on a moved vhost, the alarm code, exits' '--decide-only on a moved vhost never says it would deploy'

mutant 'the battery asks a healthchecked service for bare Up' verify.sh \
    "s/for s in horizon postgres redis; do ps_says \"\\\$s\" '(healthy)'; done/for s in horizon postgres redis; do ps_says \"\\\$s\" 'Up'; done/" \
    'an unhealthy horizon fails' 'a container still starting its healthcheck fails'
mutant 'the battery accepts any published port' verify.sh \
    's/^case "\$ports" in 127.0.0.1:\*)/case "$ports" in *)/' \
    'a stack on the internet fails'
mutant 'the battery calls any start time a restart' verify.sh \
    's/elif \[ "\$now" -gt "\$then_" \]; then/elif true; then/' \
    'containers that never restarted fail the deploy'
mutant 'an unread StartedAt reaches date -d' verify.sh \
    's/^epoch() { \[ -n "\$1" \] && date/epoch() { date/' \
    'and check 7 names the service whose StartedAt it could not read'
mutant 'the log window is not applied' verify.sh \
    's/substr(\$0,2,19) > s/1/' \
    'a production.ERROR older than the restart is not this deploy'
mutant 'the served bundle is never matched to the build' verify.sh \
    's/elif \[ "\$b" != "\$built" \]; then/elif false; then/' \
    'a served bundle that is not the one on disk fails the deploy'
mutant 'a manifest older than the baseline passes' verify.sh \
    's/elif \[ "\$built_at" -lt "\$recorded_at" \]; then/elif false; then/' \
    'a manifest older than the baseline fails full mode'
mutant 'an unreachable container reads as no mail yet' verify.sh \
    "s/bad 'could not read the app container, so mail.log is unchecked — that is not the same as no mail yet'/ok 'no mail.log yet'/" \
    'a container that cannot be reached fails check 9' \
    'and check 9 names the container, not a missing file'
mutant 'any policy header will do' verify.sh \
    "s/case \"\\\$csp\" in \*\"script-src 'self'\"\*)/case \"\\\$csp\" in *)/" \
    'a shell served with no policy fails'
mutant 'an empty service worker is never fetched again' verify.sh \
    '/^\[ -n "\$sw" \] || { sleep 2;/d' \
    'a service worker that came back empty once is fetched again and passes'
mutant 'a failed service-worker fetch keeps its partial body' verify.sh \
    "s/^sw=\\\$(getf \/sw.js) || sw=''\$/sw=\$(getf \/sw.js)/" \
    'a service worker cut off mid-body once is fetched again and passes'
mutant 'a 5xx service worker counts as fetched' verify.sh \
    's/^getf() { curl -sf /getf() { curl -s /' \
    'a service worker answering 502 once is fetched again and passes'
mutant 'check 4 matches through a pipe again' verify.sh \
    's/^elif \[ -n "\$b" \] \&\& \[\[ \$sw == \*"\${b##\*\/}"\* \]\]; then$/elif [ -n "$b" ] \&\& get \/sw.js | grep -qF "${b##*\/}"; then/' \
    'a service worker still streaming after its name appears passes'
mutant 'the mail check matches through a pipe again' verify.sh \
    's/^elif grep -q \(.production..ERROR.\) <<<"\$mail"; then$/elif printf "%s" "$mail" | grep -q \1; then/' \
    'a production.ERROR with more mail.log behind it still fails'
mutant 'the edge is never compared' verify.sh \
    's/elif \[ "\$eb" = "\$b" \]; then/elif true; then/' \
    'an edge holding the previous release fails'
mutant 'the battery always exits 0' verify.sh \
    's/^exit \$((fails > 0))$/exit 0/' \
    'root-owned paths fail' 'a stack on the internet fails'
mutant 'a green run keeps its baseline' verify.sh \
    's/^    rm -f "\$SNAP" && note/    true \&\& note/' \
    'a green run consumes the baseline'

if [ -n "${ONLY}" ]; then
    if [ "${ran}" -ne 1 ]; then
        printf 'BROKEN mutation %s: the list has no such mutation\n' "${ONLY}"
        exit 1
    fi
    exit $((missed > 0))
fi

if [ "${n}" -eq 0 ]; then
    printf 'deploy-mutants: the list is empty, so this proved nothing\n' >&2
    exit 1
fi

# One unmutated run builds the fixture repos every mutant's suite then copies: each
# fixture commit runs the fleet hook. It must pass, or no mutant's red means anything.
export DEPLOY_TEST_TEMPLATES="${WORK}/templates"
mkdir -p "${WORK}/unmutated/tmp"
if ! TMPDIR="${WORK}/unmutated/tmp" DEPLOY_TEST_ROOT="${WORK}/unmutated" \
    bash "${SCRIPT_DIR}/deploy-test.sh" >"${WORK}/unmutated.out" 2>&1; then
    tail -20 "${WORK}/unmutated.out" >&2
    printf 'deploy-mutants: the unmutated suite fails, so no mutant was run\n' >&2
    exit 1
fi
if ! bash "${SCRIPT_DIR}/deploy-lib-test.sh" "${SCRIPT_DIR}/.." >"${WORK}/unmutated-lib.out" 2>&1; then
    grep '^FAIL ' "${WORK}/unmutated-lib.out" | cut -c1-200 >&2
    printf 'deploy-mutants: the unmutated deploy-lib-test.sh fails, so no mutant was run\n' >&2
    exit 1
fi

# One worker per mutation, ${JOBS} at a time. The verdict is the worker's exit
# status: a missing one is a worker that died, and counts as a miss.
for ((i = 1; i <= n; i++)); do printf '%s\n' "${i}"; done \
    | xargs -P "${JOBS}" -I{} sh -c '
        [ -e "$3/$1.rc" ] && exit 1
        bash "$2" --worker "$1" >"$3/$1.out" 2>&1
        printf %s "$?" >"$3/$1.rc"
    ' _ {} "${SELF}" "${WORK}"
xargs_rc=$?
if [ "${xargs_rc}" -ne 0 ]; then
    printf 'deploy-mutants: a worker could not be run (xargs exited %s)\n' "${xargs_rc}" >&2
    exit 1
fi

for ((i = 1; i <= n; i++)); do
    [ -f "${WORK}/${i}.out" ] && cat "${WORK}/${i}.out"
    if [ ! -s "${WORK}/${i}.rc" ]; then
        printf 'BROKEN mutation %s: its worker left no verdict\n' "${i}"
        missed=$((missed + 1))
    elif [ "$(cat "${WORK}/${i}.rc")" != 0 ]; then
        missed=$((missed + 1))
    fi
done

if [ "${missed}" -eq 0 ]; then
    printf '\ndeploy-mutants: %s mutation(s), every one caught\n' "${n}"
    exit 0
fi
printf '\ndeploy-mutants: %s mutation(s) went unnoticed\n' "${missed}" >&2
exit 1
