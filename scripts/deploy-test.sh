#!/usr/bin/env bash
# Guards scripts/deploy.sh and its vendored scripts/lib/deploy/: every refusal,
# both landing paths, every conditional step and the ledger writer, against
# fakes in a temp directory.
#
#   scripts/deploy-test.sh          the whole list
#   cp -r scripts /tmp/o && DEPLOY_SH=/tmp/o/deploy.sh scripts/deploy-test.sh
#                                   same list against a mutated copy (red proofs);
#                                   GATE_LEDGER_LIB= does the same for the writer
#
# It never reads /var/www/orbit, never runs docker, never runs gh and never runs
# git-as: every one of those is a fake whose argv is what the assertions read.
set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_SH="${DEPLOY_SH:-${SCRIPT_DIR}/deploy.sh}"
LEDGER_LIB="${GATE_LEDGER_LIB:-${SCRIPT_DIR}/lib/deploy/ledger.sh}"
CHECK_SH="${CHECK_SH:-${SCRIPT_DIR}/check.sh}"
E2E_SH="${E2E_SH:-${SCRIPT_DIR}/e2e.sh}"
PR_NUMBER=90

fails=0
pass() { printf 'ok   %s\n' "$*"; }
fail() { printf 'FAIL %s\n' "$*" >&2; fails=$((fails + 1)); }

contains() {
    case "$2" in
        *"$3"*) pass "$1" ;;
        *) fail "$1 — no [$3] in:"$'\n'"$2" ;;
    esac
}

absent() {
    case "$2" in
        *"$3"*) fail "$1 — [$3] is there and must not be:"$'\n'"$2" ;;
        *) pass "$1" ;;
    esac
}

equals() {
    if [ "$2" = "$3" ]; then pass "$1 is [$3]"; else fail "$1 is [$2], expected [$3]"; fi
}

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

git_at() { git -C "$ROOT" -c core.hooksPath=/dev/null -c user.name=t -c user.email=t@example.invalid "$@"; }

write_fakes() {
    cat >"${BIN}/gh" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${FAKE_LOG_DIR}/gh.argv"
case " $* " in
    *' -R '*) : ;;
    *) echo 'gh: no repository resolved (use -R owner/repo)' >&2; exit 1 ;;
esac
cat "${FAKE_GH_JSON}"
SH
    # Nothing may reach the real wrapper: DEPLOY_GIT is the seam, and this proves it.
    cat >"${BIN}/git-as" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${FAKE_LOG_DIR}/git-as.argv"
echo 'git-as: this test must never reach the real checkout' >&2
exit 99
SH
    cat >"${BIN}/heavy-work" <<'SH'
#!/bin/sh
[ "$1" = '--status' ] && { echo 'free'; exit 0; }
printf '%s\n' "$*" >> "${FAKE_LOG_DIR}/heavy.argv"
[ -n "${FAKE_HEAVY_PREJOB:-}" ] && "${FAKE_HEAVY_PREJOB}"
shift
[ "$1" = '--' ] && shift
exec "$@"
SH
    cat >"${BIN}/compose" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${FAKE_LOG_DIR}/compose.argv"
if [ -n "${FAKE_COMPOSE_FAIL:-}" ]; then
    case "$*" in
        *"${FAKE_COMPOSE_FAIL}"*) printf 'compose: %s failed\n' "${FAKE_COMPOSE_FAIL}" >&2; exit 1 ;;
    esac
fi
case "$*" in
    *ps*) printf 'NAME STATUS\norbit-app-1 Up 2 hours\n' ;;
esac
exit 0
SH
    cat >"${BIN}/curl" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${FAKE_LOG_DIR}/curl.argv"
case " $* " in
    *'Host: '*) printf '%s' "${FAKE_CURL_CODE:-200}" ;;
    *) printf '%s' "${FAKE_EDGE_CODE:-200}" ;;
esac
exit 0
SH
    cat >"${BIN}/chown" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${FAKE_LOG_DIR}/chown.argv"
exit 0
SH
    # The fixture tree is root-owned, so the count is faked. FAKE_ROOT_OWNED_FROM is
    # which call starts reporting, so the mid-deploy repair can be told from the
    # before-anything proof; the -exec arm is what turns the reporting off.
    cat >"${BIN}/find" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${FAKE_LOG_DIR}/find.argv"
case " $* " in
    *' -exec '*) : >"${FAKE_LOG_DIR}/repaired"; exit 0 ;;
esac
calls=$(( $(cat "${FAKE_LOG_DIR}/find.calls" 2>/dev/null || echo 0) + 1 ))
printf '%s' "${calls}" > "${FAKE_LOG_DIR}/find.calls"
[ -f "${FAKE_LOG_DIR}/repaired" ] && exit 0
[ "${calls}" -ge "${FAKE_ROOT_OWNED_FROM:-1}" ] || exit 0
i=1
while [ "$i" -le "${FAKE_ROOT_OWNED:-0}" ]; do
    printf 'root-owned-%s\n' "$i"
    i=$((i + 1))
done
exit 0
SH
    chmod 0755 "${BIN}"/*
}

# Committed, like the real checkout's own: an untracked file would make every
# fixture dirty and every case would stop at the dirty-tree refusal.
write_checkout() {
    printf 'lock\n' >"${ROOT}/composer.lock"
    printf 'lock\n' >"${ROOT}/package-lock.json"
    printf 'base\n' >"${ROOT}/app/base.txt"
    printf 'FROM php\n' >"${ROOT}/docker/app/Dockerfile"
    printf 'server {}\n' >"${ROOT}/deploy/nginx/flights-ghiecode.conf"
    printf 'export default {}\n' >"${ROOT}/resources/js/app.js"
    printf 'export default {}\n' >"${ROOT}/vite.config.js"
    printf '<svg/>\n' >"${ROOT}/public/icons/pin.svg"
    printf '{"name":"orbit"}\n' >"${ROOT}/package.json"
    printf 'audit=false\n' >"${ROOT}/.npmrc"
    printf 'name: orbit\n' >"${ROOT}/docker-compose.yml"
    # Like the real checkout: public/build is the build's OUTPUT and .gitignore holds
    # it, which is why the front-end pathspec needs no exclusion for it.
    printf '/public/build\n' >"${ROOT}/.gitignore"
    cat >"${ROOT}/scripts/docs-only.sh" <<'SH'
#!/bin/sh
printf 'fake classifier: rc=%s for %s\n' "${FAKE_CLASSIFY_RC}" "$1"
exit "${FAKE_CLASSIFY_RC}"
SH
    cat >"${ROOT}/scripts/verify.sh" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${FAKE_LOG_DIR}/verify.argv"
case "$*" in
    *--before*) exit "${FAKE_BASELINE_RC:-0}" ;;
esac
exit "${FAKE_VERIFY_RC:-0}"
SH
    chmod 0755 "${ROOT}/scripts/docs-only.sh" "${ROOT}/scripts/verify.sh"
}

# <name> [moved...]: a checkout on L, origin/main on the merge M of the pull
# request head H. Each `moved` word adds one file to the release.
fixture() {
    CASE="${WORK}/$1"
    ROOT="${CASE}/root"
    BIN="${CASE}/bin"
    LEDGER="${CASE}/ledger"
    LOGS="${CASE}/logs"
    shift
    mkdir -p "${ROOT}/scripts" "${ROOT}/app" "${ROOT}/docker/app" "${ROOT}/deploy/nginx" \
        "${ROOT}/resources/js" "${ROOT}/public/icons" "${ROOT}/public/build" "${BIN}" "${LOGS}"
    : >"${LEDGER}"

    git init -q -b main "${ROOT}"
    write_checkout
    git_at add -A
    git_at commit -q --no-verify -m base
    LIVE_SHA="$(git_at rev-parse HEAD)"

    git_at checkout -q -b pr
    printf 'feature\n' >"${ROOT}/app/feature.txt"
    local moved
    for moved in "$@"; do
        case "${moved}" in
            composer)    printf 'moved\n' >>"${ROOT}/composer.lock" ;;
            npm)         printf 'moved\n' >>"${ROOT}/package-lock.json" ;;
            docker)      printf 'RUN true\n' >>"${ROOT}/docker/app/Dockerfile" ;;
            nginx)       printf '# moved\n' >>"${ROOT}/deploy/nginx/flights-ghiecode.conf" ;;
            frontend)    printf '// moved\n' >>"${ROOT}/resources/js/app.js" ;;
            vite)        printf '// moved\n' >>"${ROOT}/vite.config.js" ;;
            publicasset) printf '<!-- moved -->\n' >>"${ROOT}/public/icons/pin.svg" ;;
            publicbuild) printf '{"moved":1}\n' >>"${ROOT}/public/build/manifest.json" ;;
            pkgjson)     printf '{"name":"orbit","scripts":{"build":"vite build"}}\n' >"${ROOT}/package.json" ;;
            npmrc)       printf 'fund=false\n' >>"${ROOT}/.npmrc" ;;
            compose)     printf '  app: {}\n' >>"${ROOT}/docker-compose.yml" ;;
        esac
    done
    git_at add -A
    git_at commit -q --no-verify -m feature
    HEAD_SHA="$(git_at rev-parse HEAD)"

    git_at checkout -q main
    if [ "${1:-}" = 'trees-differ' ]; then
        git_at merge -q --no-ff --no-commit pr >/dev/null 2>&1
        printf 'smuggled\n' >"${ROOT}/app/smuggled.txt"
        git_at add -A
        git_at commit -q --no-verify -m merge
    else
        git_at merge -q --no-ff --no-verify -m merge pr
    fi
    MERGE_SHA="$(git_at rev-parse HEAD)"
    MERGE_SHORT="$(git_at rev-parse --short HEAD)"

    git clone -q --bare "${ROOT}" "${CASE}/origin.git"
    git_at remote add origin "${CASE}/origin.git"
    git_at reset -q --hard "${LIVE_SHA}"
    git_at fetch -q origin

    printf '{"headRefOid":"%s","mergeCommit":{"oid":"%s"},"state":"MERGED"}\n' \
        "${HEAD_SHA}" "${MERGE_SHA}" >"${CASE}/gh.json"
    printf '%s ci 2026-09-19T06:00:00Z 0 -\n%s e2e 2026-09-19T06:30:00Z 0 -\n' \
        "${HEAD_SHA}" "${HEAD_SHA}" >"${LEDGER}"
    write_fakes
}

run_deploy() {
    OUT="$(env \
        PATH="${BIN}:${PATH}" \
        FAKE_LOG_DIR="${CASE}" \
        FAKE_GH_JSON="${CASE}/gh.json" \
        FAKE_CLASSIFY_RC="${CLASSIFY_RC:-1}" \
        FAKE_COMPOSE_FAIL="${COMPOSE_FAIL:-}" \
        FAKE_CURL_CODE="${CURL_CODE:-200}" \
        FAKE_EDGE_CODE="${EDGE_CODE:-200}" \
        FAKE_ROOT_OWNED="${ROOT_OWNED:-0}" \
        FAKE_ROOT_OWNED_FROM="${ROOT_OWNED_FROM:-1}" \
        FAKE_BASELINE_RC="${BASELINE_RC:-0}" \
        FAKE_VERIFY_RC="${VERIFY_RC:-0}" \
        FAKE_HEAVY_PREJOB="${HEAVY_PREJOB:-}" \
        DEPLOY_ROOT="${ROOT}" \
        DEPLOY_GIT="git -C ${ROOT}" \
        DEPLOY_GH="${BIN}/gh" \
        DEPLOY_GH_REPO=gcotcheza/orbit \
        DEPLOY_HEAVY="${BIN}/heavy-work" \
        DEPLOY_COMPOSE="${BIN}/compose" \
        DEPLOY_LEDGER="${LEDGER}" \
        DEPLOY_LOG_DIR="${LOGS}" \
        ORBIT_PUBLIC='https://orbit.test' \
        ORBIT_BASE='http://127.0.0.1:3085' \
        bash "${DEPLOY_SH}" "$@" 2>&1)"
    CLASSIFY_RC=''
    COMPOSE_FAIL=''
    CURL_CODE=''
    EDGE_CODE=''
    ROOT_OWNED=''
    ROOT_OWNED_FROM=''
    BASELINE_RC=''
    VERIFY_RC=''
    HEAVY_PREJOB=''
}

logged() {
    if [ -f "${CASE}/$1" ]; then cat "${CASE}/$1"; fi
}

log_file() { printf '%s\n' "${OUT}" | sed -n 's/^DONE .* log \(.*\)$/\1/p'; }

CLASSIFY_RC=''
COMPOSE_FAIL=''
CURL_CODE=''
EDGE_CODE=''
ROOT_OWNED=''
ROOT_OWNED_FROM=''
BASELINE_RC=''
VERIFY_RC=''
HEAVY_PREJOB=''

# --- 1. the arguments ---------------------------------------------------------
fixture usage
run_deploy
contains 'no pull request number is a usage error' "${OUT}" 'usage: scripts/deploy.sh <PR#>'
run_deploy 90x
contains 'a pull request number that is not digits is a usage error' "${OUT}" 'usage: scripts/deploy.sh <PR#>'
run_deploy 90 91
contains 'two pull request numbers deploy neither' "${OUT}" 'usage: scripts/deploy.sh <PR#>'
equals 'a usage error reaches no fake' "$(logged gh.argv)" ''

# --- 2. gh says the pull request is not merged --------------------------------
fixture not-merged
sed -i 's/"MERGED"/"OPEN"/' "${CASE}/gh.json"
run_deploy "${PR_NUMBER}"
contains 'an unmerged PR is refused' "${OUT}" \
    "REFUSED: PR #90 is OPEN, not MERGED. Only a merged pull request deploys."

fixture main-moved
git_at checkout -q -b later "${MERGE_SHA}"
git_at commit -q --no-verify --allow-empty -m later
git_at push -q origin later:main
git_at checkout -q main
run_deploy "${PR_NUMBER}"
contains 'a merge behind the tip is refused' "${OUT}" \
    'REFUSED: main moved since the merge: re-gate.'

fixture trees-differ trees-differ
run_deploy "${PR_NUMBER}"
contains 'a merge tree that is not the gated tree is refused' "${OUT}" \
    'REFUSED: merge tree differs from the gated head: re-gate the merge commit.'

# --- 3. the ledger ------------------------------------------------------------
fixture no-ledger
rm -f "${LEDGER}"
run_deploy "${PR_NUMBER}"
contains 'a missing ledger is refused' "${OUT}" 'REFUSED: no gate ledger at'

fixture head-absent
: >"${LEDGER}"
run_deploy "${PR_NUMBER}"
contains 'a head absent from the ledger is refused' "${OUT}" 'the ledger holds no green ci for'
contains 'and the refusal is preceded by the worktree recipe' "${OUT}" \
    'worktree add /srv/worker-scratch/orbit-gate-pr90'
contains 'which gates outside the served checkout, and says why' "${OUT}" \
    'never in this checkout — it is bind-mounted into the running containers'
contains 'and names both gates' "${OUT}" 'bash scripts/check.sh overlay'
contains 'including the browser one' "${OUT}" 'bash scripts/e2e.sh'
equals 'an ungated head builds nothing' "$(logged heavy.argv)" ''

fixture ci-only
printf '%s ci 2026-09-19T06:00:00Z 0 -\n' "${HEAD_SHA}" >"${LEDGER}"
run_deploy "${PR_NUMBER}"
contains 'ci without e2e is refused' "${OUT}" 'the ledger holds no green e2e for'

fixture by-hand
: >"${LEDGER}"
run_deploy "${PR_NUMBER}" --gated-by-hand
contains '--gated-by-hand says so out loud' "${OUT}" \
    "GATED BY HAND: the ledger was not read. #90 deploys on a human's word — transition and rescue only."
absent 'and the recipe is not printed at it' "${OUT}" 'worktree add'

# --- 4. a dirty checkout ------------------------------------------------------
fixture dirty-tree
printf 'uncommitted\n' >>"${ROOT}/app/base.txt"
run_deploy "${PR_NUMBER}"
contains 'a dirty checkout is refused' "${OUT}" \
    'REFUSED: the checkout is dirty; a deploy never fast-forwards over uncommitted work.'
equals 'heavy-work argv after the dirty refusal' "$(logged heavy.argv)" ''

fixture dirty-docs-only
CLASSIFY_RC=0
printf 'uncommitted\n' >>"${ROOT}/app/base.txt"
run_deploy "${PR_NUMBER}"
contains 'a dirty docs-only merge is refused before it lands' "${OUT}" \
    'REFUSED: the checkout is dirty; a deploy never fast-forwards over uncommitted work.'
absent 'and a docs-only landing never fast-forwards over the dirt' "${OUT}" 'LANDED docs-only'
equals 'and the checkout is where it was' "$(git_at rev-parse HEAD)" "${LIVE_SHA}"

# --- 5. the classifier decides ------------------------------------------------
fixture docs-only
CLASSIFY_RC=0
run_deploy "${PR_NUMBER}"
contains 'a docs-only merge lands' "${OUT}" "LANDED docs-only ${MERGE_SHA:0:7}"
contains 'the landing proves the site at both ends' "${OUT}" 'up 200 edge 200 root-owned 0'
contains 'the landing stops with DONE' "${OUT}" "DONE #90 live ${MERGE_SHORT} was"
equals 'a landing runs no heavy-work job' "$(logged heavy.argv)" ''
equals 'a landing runs no baseline and no verification' "$(logged verify.argv)" ''
equals 'a landing asks docker for nothing but the stack it left alone' "$(logged compose.argv)" 'ps'
equals 'a landing moves the checkout' "$(git_at rev-parse HEAD)" "${MERGE_SHA}"

fixture docs-only-dead-site
CLASSIFY_RC=0
CURL_CODE=502
run_deploy "${PR_NUMBER}"
contains 'a landing onto a site that stopped answering is a failure' "${OUT}" 'LANDING FAILED: /up answered 502'
absent 'and it never says DONE' "${OUT}" 'DONE #90'

fixture docs-only-root-owned
CLASSIFY_RC=0
ROOT_OWNED=1
run_deploy "${PR_NUMBER}"
contains 'a landing onto a root-owned tree is not a clean landing' "${OUT}" 'NOT LANDED CLEANLY: 1 path(s)'
absent 'and the repair is the reader"s, not a blanket chown' "$(logged chown.argv)" '-R'

fixture nothing-to-land
CLASSIFY_RC=2
run_deploy "${PR_NUMBER}"
contains 'rc 2 says nothing to land' "${OUT}" 'STOP: NOTHING TO LAND — the merge changes nothing here.'

fixture classifier-refused
CLASSIFY_RC=3
run_deploy "${PR_NUMBER}"
contains 'rc 3 stops the deploy' "${OUT}" 'STOP: the classifier refused.'

fixture classifier-misused
CLASSIFY_RC=64
run_deploy "${PR_NUMBER}"
contains 'rc 64 stops the deploy' "${OUT}" 'STOP: the classifier was called wrong; nothing was classified.'

fixture classifier-broke
CLASSIFY_RC=9
run_deploy "${PR_NUMBER}"
contains 'any other rc is git failing, never a landing' "${OUT}" 'STOP: git itself failed (rc=9)'

# --- 6. the ordinary deploy ---------------------------------------------------
fixture deploy
run_deploy "${PR_NUMBER}"
contains 'code takes the full path' "${OUT}" 'CLASSIFIED code: the full deploy path'
contains 'the deploy finishes' "${OUT}" "DONE #90 live ${MERGE_SHORT} was"
contains 'the deploy is gated by the ledger' "${OUT}" 'gated ledger'
contains 'DONE carries the root-owned count and the verify mode' "${OUT}" 'root-owned 0 verify backend-only'
equals 'the steps are ONE heavy-work job' "$(logged heavy.argv | grep -c 'orbit-deploy')" '1'
equals 'nothing reached the real git-as wrapper' "$(logged git-as.argv)" ''
equals 'migrate is the first command the job asks of compose when no lockfile moved' \
    "$(logged compose.argv | grep -v '^ps$' | head -1)" 'exec -T app php artisan migrate --force'
contains 'horizon is drained IN the horizon container' "$(logged compose.argv)" \
    'exec -T horizon php artisan horizon:terminate'
absent 'never in app, where it exits 0 having terminated nothing' "$(logged compose.argv)" \
    'exec -T app php artisan horizon:terminate'
equals 'view:clear, then the drain, then the four restarts, in that order' \
    "$(logged compose.argv | grep -E 'view:clear|horizon:terminate|^restart' | tr '\n' '|')" \
    'exec -T app php artisan view:clear|exec -T horizon php artisan horizon:terminate|restart app horizon scheduler web|'
absent 'postgres and redis are never restarted' "$(logged compose.argv)" 'restart app horizon scheduler web postgres'
contains 'the record the parent parses cannot be forged by a dedent' "$(cat "${CASE}/logs/"*.log 2>/dev/null)" '@@ROOT-OWNED 0'
contains 'the ownership proof carves out the agent runtime' "$(logged find.argv)" \
    "${ROOT} -user root -not -path ${ROOT}/.claude/*"
equals 'a good run stays inside 40 lines of stdout' \
    "$(printf '%s\n' "${OUT}" | wc -l | awk '{print ($1 <= 40) ? "yes" : $1}')" 'yes'
LOGFILE="$(log_file)"
if [ -f "${LOGFILE}" ]; then
    pass 'the log named in DONE exists'
    contains 'the job fast-forwards to the resolved merge, never to origin/main' \
        "$(cat "${LOGFILE}")" "merge --ff-only ${MERGE_SHA}"
    absent 'nothing in the job merges origin/main' "$(cat "${LOGFILE}")" 'merge --ff-only origin/main'
    contains 'the job asserts what it landed' "$(cat "${LOGFILE}")" 'is not the resolved merge'
    equals 'the steps ran in the runbook order' \
        "$(grep -oE '^(step [0-9.]+|@@STEP [0-9.]+ RAN)' "${LOGFILE}" | sed 's/^@@//' | tr '\n' ' ')" \
        'step 1 step 2 step 3 step 4 step 5 step 8 '
else
    fail "the log named in DONE does not exist: [${LOGFILE}]"
fi
contains 'the baseline is taken before anything moves' "$(logged verify.argv)" '--before'
contains 'an unmoved front end verifies with --backend-only' "$(logged verify.argv)" '--backend-only'
equals 'and the baseline comes before the verification' \
    "$(logged verify.argv | tr '\n' '|')" '--before|--backend-only|'

# --- 7. each moved file turns its own step on --------------------------------
fixture moved-composer composer
run_deploy "${PR_NUMBER}"
contains 'composer.lock moving runs composer' "${OUT}" 'conditional steps ran: 3'
contains 'and the install is the runbook line' "$(logged compose.argv)" \
    'exec -T app composer install --no-dev --optimize-autoloader --no-interaction'
contains 'and the permission repair follows it' "$(logged compose.argv)" 'exec -T app chmod -R go-w vendor'
equals 'composer runs before migrate when the lockfile moved' \
    "$(logged compose.argv | grep -E 'composer install|artisan migrate' | tr '\n' '|')" \
    'exec -T app composer install --no-dev --optimize-autoloader --no-interaction|exec -T app php artisan migrate --force|'

for moved in docker compose; do
    fixture "moved-${moved}" "${moved}"
    run_deploy "${PR_NUMBER}"
    contains "${moved} moving recreates rather than restarts" "${OUT}" 'conditional steps ran: 4.5'
    contains 'and the three containers on that image are recreated' "$(logged compose.argv)" 'up -d app horizon scheduler'
    contains 'and web after them' "$(logged compose.argv)" 'up -d --force-recreate web'
done

fixture moved-docker-order docker
run_deploy "${PR_NUMBER}"
contains 'docker/app moving rebuilds the image' "${OUT}" 'conditional steps ran: 4.5'
contains 'and the three containers on that image are recreated, never restarted alone' \
    "$(logged compose.argv)" 'up -d app horizon scheduler'
contains 'and web is recreated after them, because nginx resolves app once at start' \
    "$(logged compose.argv)" 'up -d --force-recreate web'
equals 'the image is built before anything is recreated' \
    "$(logged compose.argv | grep -E '^(build|up -d)' | tr '\n' '|')" \
    'build app horizon scheduler|up -d app horizon scheduler|up -d --force-recreate web|'

for moved in frontend npm vite publicasset pkgjson npmrc; do
    fixture "moved-${moved}" "${moved}"
    run_deploy "${PR_NUMBER}"
    contains "${moved} moving builds the assets" "${OUT}" 'conditional steps ran: 5 7'
    contains 'and the build is the profile-gated task' "$(logged compose.argv)" '--profile build run --rm assets'
    equals 'and build:retain follows the build and precedes the restart' \
        "$(logged compose.argv | grep -E 'assets$|build:retain|^restart' | tr '\n' '|')" \
        '--profile build run --rm assets|exec -T app php artisan build:retain|restart app horizon scheduler web|'
    contains 'so the verification expects the bundle to have moved' "${OUT}" 'VERIFY full'
    absent 'and never passes --backend-only' "$(logged verify.argv)" '--backend-only'
done

fixture moved-publicbuild publicbuild
run_deploy "${PR_NUMBER}"
contains 'a write under the ignored public/build is not a change at all' "${OUT}" 'conditional steps ran: none'
absent 'no asset build' "$(logged compose.argv)" 'assets'
absent 'and no retain, which would snapshot the build already on disk' "$(logged compose.argv)" 'build:retain'
contains 'so an unchanged bundle is what the verification expects' "${OUT}" 'VERIFY --backend-only'

fixture moved-nothing
run_deploy "${PR_NUMBER}"
contains 'nothing moved, so no conditional step ran' "${OUT}" 'conditional steps ran: none'
absent 'no composer install' "$(logged compose.argv)" 'composer install'
absent 'no image build' "$(logged compose.argv)" 'build app horizon scheduler'
absent 'and nothing is said about the host vhost' "${OUT}" 'HOST VHOST NEEDED'
absent 'which DONE does not carry either' "${OUT}" 'vhost-needed'
contains 'the restart still happens, because the code moved' "$(logged compose.argv)" \
    'restart app horizon scheduler web'

fixture moved-nginx nginx
run_deploy "${PR_NUMBER}"
contains 'deploy/nginx moving says the host vhost is still a hand step' "${OUT}" \
    'HOST VHOST NEEDED, NOT RUN (deploy/nginx moved: deploy/nginx/flights-ghiecode.conf)'
contains 'and names the two commands a person runs' "${OUT}" 'nginx -t, then systemctl reload nginx'
contains 'and DONE carries it, because one line mid-run is missable' "${OUT}" \
    'root-owned 0 verify backend-only vhost-needed'
absent 'and the script never touches the host file itself' "$(logged compose.argv)" 'nginx'

# --- 8. the root-owned count --------------------------------------------------
fixture root-owned-before
ROOT_OWNED=1
run_deploy "${PR_NUMBER}"
contains 'a root-owned path before anything is built stops the job' "${OUT}" 'STEPS 1-10 FAILED'
absent 'and nothing is built on it' "$(logged compose.argv)" 'migrate'

fixture root-owned-during
ROOT_OWNED=2
ROOT_OWNED_FROM=2
run_deploy "${PR_NUMBER}"
contains 'root-owned paths that appear during the deploy are repaired narrowly' "$(logged find.argv)" \
    "${ROOT} -user root -not -path ${ROOT}/.claude/* -exec chown orbit:orbit {} +"
absent 'never with a blanket chown over the tree' "$(logged chown.argv)" "-R orbit:orbit ${ROOT}"
contains 'and the deploy finishes once the count is 0' "${OUT}" 'root-owned 0 verify'

# --- 8b. a run that died after the fast-forward -------------------------------
fixture half-finished
git_at merge -q --ff-only "${MERGE_SHA}"
CLASSIFY_RC=2
run_deploy "${PR_NUMBER}"
contains 'a checkout already on the merge with no finished deploy is refused, not called nothing to land' \
    "${OUT}" "REFUSED: ${MERGE_SHORT} is on disk and no log in"
contains 'and it says what is live and what may not be' "${OUT}" 'the containers may still be booted on the previous release'
contains 'and which records say what ran' "${OUT}" "'@@STEP n RAN' lines are what did run"
contains 'and the steps left, in order' "${OUT}" 'horizon:terminate IN the horizon container'
contains 'and the rollback' "${OUT}" 'roll back with the block in .claude/commands/deploy.md'
equals 'and it builds nothing' "$(logged heavy.argv)" ''
absent 'and it never says DONE' "${OUT}" 'DONE #90'

fixture already-deployed
git_at merge -q --ff-only "${MERGE_SHA}"
printf 'DONE #90 live %s was 1234567 gated ledger root-owned 0 verify full log x\n' \
    "$(git_at rev-parse --short HEAD)" >"${LOGS}/20260918T000000Z-pr90.log"
CLASSIFY_RC=2
run_deploy "${PR_NUMBER}"
contains 'a sha an earlier log says DONE for is nothing to land' "${OUT}" 'is already deployed'
absent 'and that is not a refusal' "${OUT}" 'REFUSED'
equals 'and it builds nothing' "$(logged heavy.argv)" ''

fixture head-absent-from-checkout
sed -i 's/"headRefOid":"[0-9a-f]*"/"headRefOid":"0123456789abcdef0123456789abcdef01234567"/' "${CASE}/gh.json"
run_deploy "${PR_NUMBER}"
contains 'a head this checkout never had is named, not blamed on the tree' "${OUT}" \
    "REFUSED: PR #90's head 0123456 is not a commit in this checkout"
contains 'and it names the shape that causes it' "${OUT}" 'a squash or a rebase merge'
absent 'and it does not blame the tree' "${OUT}" 'merge tree differs from the gated head'

# --- 9. a phase that fails ----------------------------------------------------
fixture failing-baseline
BASELINE_RC=1
run_deploy "${PR_NUMBER}"
contains 'a baseline that cannot be taken stops the deploy' "${OUT}" 'STEP 0 FAILED rc=1'
equals 'and nothing is built' "$(logged heavy.argv)" ''
absent 'and it never says DONE' "${OUT}" 'DONE #90'

fixture failing-step composer
COMPOSE_FAIL=composer
run_deploy "${PR_NUMBER}"
contains 'a failing step says which rc' "${OUT}" 'STEPS 1-10 FAILED rc=1'
contains 'a failing step prints the tail of its log' "${OUT}" 'the last 20 lines of'
absent 'a failing deploy never says DONE' "${OUT}" 'DONE #90'

fixture failing-verify
VERIFY_RC=1
run_deploy "${PR_NUMBER}"
contains 'a red verification is a failed deploy' "${OUT}" 'VERIFY FAILED rc=1'
contains 'and it names the rollback' "${OUT}" 'Roll back to'
absent 'and it never says DONE' "${OUT}" 'DONE #90'

# --- 10. the job lands the merge that was gated, never what main became -------
fixture checkout-ahead
git_at merge -q --ff-only "${MERGE_SHA}"
git_at commit -q --no-verify --allow-empty -m 'committed on the box'
run_deploy "${PR_NUMBER}"
contains 'a checkout ahead of the gated merge fails the job' "${OUT}" 'STEPS 1-10 FAILED'
contains 'and says HEAD is not the resolved merge' "${OUT}" 'is not the resolved merge'
absent 'and never reaches DONE' "${OUT}" 'DONE #90'

fixture main-moves-late
git_at checkout -q -b later "${MERGE_SHA}"
printf 'late\n' >"${ROOT}/app/late.txt"
git_at add -A
git_at commit -q --no-verify -m late
LATE_SHA="$(git_at rev-parse HEAD)"
git_at checkout -q main
git_at push -q origin later:refs/heads/later
git_at branch -q -D later
printf '#!/bin/sh\ngit -C "%s" update-ref refs/heads/main %s\n' \
    "${CASE}/origin.git" "${LATE_SHA}" >"${CASE}/prejob.sh"
chmod 0755 "${CASE}/prejob.sh"
HEAVY_PREJOB="${CASE}/prejob.sh"
run_deploy "${PR_NUMBER}"
contains 'main moving under the lock changes nothing' "${OUT}" "DONE #90 live ${MERGE_SHORT} was"
equals 'the job lands the resolved merge' "$(git_at rev-parse HEAD)" "${MERGE_SHA}"
absent 'the commit that arrived late never reaches the disk' "$(ls "${ROOT}/app")" 'late.txt'

# --- 11. the gates record, and only for a whole run ---------------------------
if grep -q 'PW_ARGS\[@\]}" -eq 0' "${E2E_SH}"; then
    pass 'the browser gate records nothing when it was handed a filter'
else
    fail 'scripts/e2e.sh records a filtered run as a full green, which a deploy would then accept'
fi
# check.sh takes its runner as its one argument, so what it may not record is a
# run that never reached the list: the usage error and the two refusals.
CHECK_CLEARED="$(grep -n '^GATE_FILTERED=0$' "${CHECK_SH}" | head -1 | cut -d: -f1)"
CHECK_LAST_REFUSAL="$(grep -n '^ *exit 2$' "${CHECK_SH}" | tail -1 | cut -d: -f1)"
if [ -n "${CHECK_CLEARED}" ] && [ -n "${CHECK_LAST_REFUSAL}" ] && [ "${CHECK_CLEARED}" -gt "${CHECK_LAST_REFUSAL}" ]; then
    pass "the check gate clears GATE_FILTERED at line ${CHECK_CLEARED}, below its last refusal at ${CHECK_LAST_REFUSAL}"
else
    fail "scripts/check.sh records a run that never reached the list (cleared at [${CHECK_CLEARED}], last refusal at [${CHECK_LAST_REFUSAL}])"
fi
if grep -qE '^gate_record 0$' "${E2E_SH}" && grep -qE '^exit "\$TEARDOWN_STATUS"$' "${E2E_SH}"; then
    pass 'the browser gate records the SUITE and still fails the run on a bad teardown'
else
    fail 'scripts/e2e.sh records teardown status as the suite result: a down -v that fails then writes a red line for a green suite, last-line-wins makes deploy.sh refuse, and an operator re-runs six minutes of browsers for nothing'
fi
for gate in "${CHECK_SH}" "${E2E_SH}"; do
    if grep -q '^set -Eeuo pipefail' "${gate}"; then
        pass "$(basename "${gate}") fires its ERR trap from inside a function"
    else
        fail "$(basename "${gate}") loses errexit failures: without set -E a step that fails inside a function exits the shell without firing the ERR trap, so the red is never recorded"
    fi
done
if grep -qE "trap .*gate_record.* EXIT" "${CHECK_SH}" "${E2E_SH}"; then
    fail 'a gate records from an EXIT trap, which records the teardown and not the suite'
else
    pass 'neither gate records from an EXIT trap'
fi

# --- 11b. the browser gate refuses on the box, and records nothing for it -----
fixture e2e-preflight
cat >"${BIN}/docker" <<'SH'
#!/bin/sh
[ "$1" = 'info' ] && { echo 'fake docker: no daemon' >&2; exit 1; }
exit 0
SH
chmod 0755 "${BIN}/docker"
E2E_LEDGER="${CASE}/e2e-ledger"
: >"${E2E_LEDGER}"
E2E_OUT="$(env PATH="${BIN}:${PATH}" GATE_LEDGER="${E2E_LEDGER}" GATE_LEDGER_GIT="git -C ${ROOT}" \
    ORBIT_GATE_LOG=/dev/null bash "${E2E_SH}" 2>&1)" || true
contains 'a browser gate that cannot reach docker stops in its pre-flight' "${E2E_OUT}" \
    'cannot talk to docker'
equals 'and the ledger holds no line for an environmental refusal' \
    "$(wc -l <"${E2E_LEDGER}")" '0'

# --- 12. the ledger writer ----------------------------------------------------
fixture ledger-writer
# shellcheck source=scripts/lib/deploy/ledger.sh
. "${LEDGER_LIB}"
LEDGER_OUT="${CASE}/written"
: >"${LEDGER_OUT}"
GATE_SUITE_PASSED=1 GATE_LEDGER="${LEDGER_OUT}" GATE_LEDGER_GIT="git -C ${ROOT}" \
    gate_ledger_record ci 0 /tmp/ci.log >/dev/null
CLEAN_LINE="$(tail -1 "${LEDGER_OUT}")"
if printf '%s' "${CLEAN_LINE}" | grep -qE "^${LIVE_SHA} ci [0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z 0 /tmp/ci\.log$"; then
    pass "the ledger line is <sha> <kind> <utc> <rc> <log>: ${CLEAN_LINE}"
else
    fail "the ledger line is not <sha> <kind> <utc> <rc> <log>: ${CLEAN_LINE}"
fi
unset GATE_SUITE_PASSED
GATE_LEDGER="${LEDGER_OUT}" GATE_LEDGER_GIT="git -C ${ROOT}" \
    gate_ledger_record ci 0 /tmp/ci.log >/dev/null 2>&1
contains 'rc 0 without the suite saying so is recorded red' "$(tail -1 "${LEDGER_OUT}")" ' ci '
equals 'and the rc recorded is 1' "$(tail -1 "${LEDGER_OUT}" | awk '{print $4}')" '1'
printf 'uncommitted\n' >>"${ROOT}/app/base.txt"
GATE_LEDGER="${LEDGER_OUT}" GATE_LEDGER_GIT="git -C ${ROOT}" \
    gate_ledger_record e2e 1 /tmp/e2e.log >/dev/null
DIRTY_LINE="$(tail -1 "${LEDGER_OUT}")"
if printf '%s' "${DIRTY_LINE}" | grep -qE "^${LIVE_SHA}-dirty e2e [0-9-]+T[0-9:]+Z 1 /tmp/e2e\.log$"; then
    pass "a dirty tree records <sha>-dirty: ${DIRTY_LINE}"
else
    fail "a dirty tree does not record <sha>-dirty: ${DIRTY_LINE}"
fi

if [ "${fails}" -eq 0 ]; then
    printf '\ndeploy-test: all checks passed\n'
    exit 0
fi
printf '\ndeploy-test: %s check(s) failed\n' "${fails}" >&2
exit 1
