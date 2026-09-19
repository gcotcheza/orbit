#!/usr/bin/env bash
# Every guard in scripts/deploy-test.sh, proved able to go red: each mutant
# breaks one thing in a copy of scripts/ and the checks named must fail.
#
#   scripts/deploy-mutants.sh
#
# A verify.sh mutation is run against scripts/verify-test.sh; everything else
# against scripts/deploy-test.sh, which fakes verify.sh out entirely.
#
# It never reads /var/www/orbit, never runs docker and never runs gh.
# shellcheck disable=SC2016  # every mutation below is sed source, not shell
set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
missed=0
n=0

mutant() {
    local name=$1 target=$2 expr=$3 out rc dir red expect harness
    shift 3
    n=$((n + 1))
    dir="${WORK}/${n}"
    mkdir -p "${dir}"
    cp -r "${SCRIPT_DIR}" "${dir}/scripts"
    sed -i "${expr}" "${dir}/scripts/${target}" || { printf 'BROKEN %s: sed failed\n' "${name}"; missed=$((missed + 1)); return; }
    harness="${dir}/scripts/deploy-test.sh"
    [ "${target}" = 'verify.sh' ] && harness="${dir}/scripts/verify-test.sh"
    out="$(DEPLOY_SH="${dir}/scripts/deploy.sh" VERIFY_SH="${dir}/scripts/verify.sh" \
        GATE_LEDGER_LIB="${dir}/scripts/lib/deploy/ledger.sh" bash "${harness}" 2>&1)"
    rc=$?
    for expect in "$@"; do
        red="$(printf '%s\n' "${out}" | grep -F "FAIL ${expect}" | head -1)"
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
    's/\$GIT diff --quiet "\$HEAD_SHA" "\$MERGE_SHA"/true/' \
    'a merge tree that is not the gated tree is refused'
mutant 'gated stops reading the ledger' lib/deploy/ledger.sh \
    's/^gated() {/gated() { GATED=ledger; return 0;/' \
    'a missing ledger is refused' 'a head absent from the ledger is refused' 'ci without e2e is refused'
mutant 'by hand stops saying so' lib/deploy/ledger.sh \
    's/GATED BY HAND: the ledger was not read./GATED BY HAND./' \
    '--gated-by-hand says so out loud'
mutant 'a dirty checkout is allowed' lib/deploy/preflight.sh \
    's/^refuse_if_dirty() {/refuse_if_dirty() { return 0;/' \
    'a dirty checkout is refused'
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
    's/-- composer.lock)/-- composer.lock.absent)/' \
    'composer.lock moving runs composer'
mutant 'the image guard never matches' deploy.sh \
    's#-- docker/app docker-compose.yml)#-- docker/app.absent)#' \
    'docker moving recreates rather than restarts' 'compose moving recreates rather than restarts'
mutant 'a compose-only change is restarted, not recreated' deploy.sh \
    's#-- docker/app docker-compose.yml)#-- docker/app)#' \
    'compose moving recreates rather than restarts'
mutant 'the front-end guard never matches' deploy.sh \
    's/-- \$FRONT_END)/-- composer.lock.absent)/' \
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
    's/^\$COMPOSE restart app horizon scheduler web$/$COMPOSE restart app scheduler web/' \
    'view:clear, then the drain, then the four restarts, in that order'
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
    's#find "\$ROOT" -user root -not -path "\$ROOT/\.claude/\*" -exec chown orbit:orbit {} +#chown -R orbit:orbit "\$ROOT"#' \
    'root-owned paths that appear during the deploy are repaired narrowly'
mutant 'every release verifies backend-only' deploy.sh \
    's#^\( *\)"\$ROOT/scripts/verify.sh" || rc=\$?$#\1"$ROOT/scripts/verify.sh" --backend-only || rc=$?#' \
    'and never passes --backend-only'
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
    'a sha an earlier log says DONE for is nothing to land'
mutant 'a head that is not in this checkout is never named' deploy.sh \
    's/^head_is_present() {/head_is_present() { return 0;/' \
    'a head this checkout never had is named, not blamed on the tree'

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
mutant 'an unchanged bundle is never a failure' verify.sh \
    "s/elif \[ \"\\\$backend_only\" = 'yes' \]; then/elif true; then/" \
    'an unchanged bundle with no --backend-only fails the deploy'
mutant 'an unreachable container reads as no mail yet' verify.sh \
    "s/bad 'could not read the app container, so mail.log is unchecked — that is not the same as no mail yet'/ok 'no mail.log yet'/" \
    'a container that cannot be reached fails check 9' \
    'and check 9 names the container, not a missing file'
mutant 'any policy header will do' verify.sh \
    "s/case \"\\\$csp\" in \*\"script-src 'self'\"\*)/case \"\\\$csp\" in *)/" \
    'a shell served with no policy fails'
mutant 'the edge is never compared' verify.sh \
    's/elif \[ "\$eb" = "\$b" \]; then/elif true; then/' \
    'an edge holding the previous release fails'
mutant 'the battery always exits 0' verify.sh \
    's/^exit \$((fails > 0))$/exit 0/' \
    'root-owned paths fail' 'a stack on the internet fails'
mutant 'a green run keeps its baseline' verify.sh \
    's/^    rm -f "\$SNAP" && note/    true \&\& note/' \
    'a green run consumes the baseline'

if [ "${missed}" -eq 0 ]; then
    printf '\ndeploy-mutants: %s mutation(s), every one caught\n' "${n}"
    exit 0
fi
printf '\ndeploy-mutants: %s mutation(s) went unnoticed\n' "${missed}" >&2
exit 1
