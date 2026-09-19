#!/usr/bin/env bash
# Every guard in scripts/deploy-test.sh, proved able to go red: each mutant
# breaks one thing in a copy of scripts/ and the checks named must fail.
#
#   scripts/deploy-mutants.sh
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
    local name=$1 target=$2 expr=$3 out rc dir red expect
    shift 3
    n=$((n + 1))
    dir="${WORK}/${n}"
    mkdir -p "${dir}"
    cp -r "${SCRIPT_DIR}" "${dir}/scripts"
    sed -i "${expr}" "${dir}/scripts/${target}" || { printf 'BROKEN %s: sed failed\n' "${name}"; missed=$((missed + 1)); return; }
    out="$(DEPLOY_SH="${dir}/scripts/deploy.sh" GATE_LEDGER_LIB="${dir}/scripts/lib/deploy/ledger.sh" \
        bash "${dir}/scripts/deploy-test.sh" 2>&1)"
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
    's#-- docker/app)#-- docker/app.absent)#' \
    'docker/app moving rebuilds the image'
mutant 'the front-end guard never matches' deploy.sh \
    's/-- \$FRONT_END)/-- composer.lock.absent)/' \
    'frontend moving builds the assets' 'npm moving builds the assets' \
    'vite moving builds the assets' 'publicasset moving builds the assets'
mutant 'the front-end guard loses the build-output exclusion' deploy.sh \
    "s/ ':(exclude)public\/build'//" \
    'public/build moving is the build OUTPUT and builds nothing'
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

if [ "${missed}" -eq 0 ]; then
    printf '\ndeploy-mutants: %s mutation(s), every one caught\n' "${n}"
    exit 0
fi
printf '\ndeploy-mutants: %s mutation(s) went unnoticed\n' "${missed}" >&2
exit 1
