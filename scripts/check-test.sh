#!/usr/bin/env bash
# Runs scripts/check.sh from a copy of <tree>'s scripts/ in a throwaway root, git and docker faked:
# the CI_GIT seam, the stack guard, the lint list, the T9 step and the overlay's mount points.
# On the host as root, as the gate's other host suites. docs/DECISIONS.md, deploy-lib-is-not-vendored
#
#   bash scripts/check-test.sh <tree>
set -uo pipefail

if [ "$(id -u)" -ne 0 ]; then
    printf 'check-test.sh: runs only as root: the gate does, and check.sh reads the deploy lib as root\n' >&2
    exit 1
fi
[ $# -eq 1 ] && [ -f "$1/scripts/check.sh" ] || { printf 'usage: check-test.sh <tree with scripts/check.sh>\n' >&2; exit 2; }
TREE="$(cd -- "$1" && pwd -P)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK:?}"' EXIT
chmod 755 "${WORK}"
HOST_PATH="${PATH}"
ZEROS=0000000000000000000000000000000000000000

fails=0
pass() { printf 'ok   %s\n' "$*"; }
fail() { printf 'FAIL %s\n' "$*" >&2; fails=$((fails + 1)); }
contains() { case "$2" in *"$3"*) pass "$1" ;; *) fail "$1 — no [$3] in:"$'\n'"$2" ;; esac; }
absent() { case "$2" in *"$3"*) fail "$1 — [$3] is in:"$'\n'"$2" ;; *) pass "$1" ;; esac; }
equals() { if [ "$2" = "$3" ]; then pass "$1 is [$3]"; else fail "$1 is [$2], expected [$3]"; fi; }

# A docker that logs its argv; the FAKE_* variables answer the stack guard, fail one call, or
# make each `-v <src>:/var/www/html/<dir>` target of the first `compose run` and fail it.
FAKE_DOCKER="${WORK}/docker"
cat >"${FAKE_DOCKER}" <<'SH'
#!/bin/bash
printf '%s\n' "$*" >>"${FAKE_LOG}"
case "$*" in
    'compose ps -aq') eval "${FAKE_PS:-:}"; exit $? ;;
    *project.working_dir*) eval "${FAKE_INSPECT:-:}"; exit $? ;;
    *'com.docker.compose.project"'*) printf 'orbit-elsewhere\n'; exit 0 ;;
esac
if [ -n "${FAKE_MOUNTS:-}" ] && [ "$1 $2" = 'compose run' ]; then
    for arg in "$@"; do
        case "${arg}" in *:/var/www/html/*) dir=${arg#*:/var/www/html/}; mkdir -p "${dir}"; printf '%s\n' "${dir}" >>"${FAKE_MADE}" ;; esac
    done
    [ "${FAKE_MOUNTS}" != fill ] || touch vendor/autoload.php
    exit 1
fi
# shellcheck disable=SC2254
case "$*" in ${FAKE_FAIL_ON:-@@}) exit 1 ;; esac
exit 0
SH

# A git that logs its argv. FAKE_GIT_FIRST is the flag a seam carries and must come first.
FAKE_GIT="${WORK}/git"
cat >"${FAKE_GIT}" <<'SH'
#!/bin/bash
if [ -n "${FAKE_GIT_FIRST:-}" ]; then
    [ "$1" = "${FAKE_GIT_FIRST}" ] || { printf 'FAKE GIT: expected %s first, got %s\n' "${FAKE_GIT_FIRST}" "$1" >&2; exit 97; }
    shift
fi
case "$1" in -C) shift 2 ;; esac
printf '%s\n' "$*" >>"${FAKE_GIT_LOG}"
case "$*" in
    *'-- scripts') cat "${FAKE_SCRIPTS_LIST}" ;;
    ls-files*) printf 'composer.json\0scripts/check.sh\0' ;;
    rev-parse*) [ -z "${FAKE_GIT_HEAD:-}" ] && exit 98; printf '%s\n' "${FAKE_GIT_HEAD}" ;;
    *status*) : ;;
    *) printf 'FAKE GIT: unexpected subcommand %s\n' "$*" >&2; exit 98 ;;
esac
SH
chmod 755 "${FAKE_DOCKER}" "${FAKE_GIT}"

# new_root <name>: C is the case, R a throwaway root holding a copy of the tree's scripts/, B its PATH.
new_root() {
    C="${WORK}/$1"
    B="${C}/bin"
    mkdir -p "${C}/root" "${B}"
    cp -a "${TREE}/scripts" "${C}/root/scripts"
    printf '{}\n' >"${C}/root/composer.json"
    R="$(cd "${C}/root" && pwd -P)"
    ln -s "${FAKE_DOCKER}" "${B}/docker"
    ln -s "${FAKE_GIT}" "${B}/git"
    (cd "${R}" && find scripts -type f -print0 | sort -z) >"${C}/scripts.list"
}

# run_check <mode|-> [VAR=value...]: OUT, RC and SECONDS_TAKEN; - runs check.sh with no argument.
run_check() {
    local mode=$1 started
    shift
    started=$(date +%s)
    OUT="$(cd "${R}" && env -u CI_GIT -u GATE_RECORDED -u GATE_SUITE_PASSED -u COMPOSE_PROJECT_NAME \
        PATH="${B}:${HOST_PATH}" GATE_LEDGER="${C}/ledger" ORBIT_GATE_LOG=- \
        FAKE_LOG="${C}/docker.log" FAKE_GIT_LOG="${C}/git.log" FAKE_SCRIPTS_LIST="${C}/scripts.list" \
        FAKE_MADE="${C}/made.log" FAKE_GIT_HEAD="${ZEROS}" "$@" \
        timeout 120 bash "${R}/scripts/check.sh" ${mode/#-/} 2>&1)"
    RC=$?
    SECONDS_TAKEN=$(($(date +%s) - started))
}

# swap <find> <replace> <count> <why>: in the root's copy of check.sh, exactly <count> times.
swap() {
    local n
    n=$(grep -cF -- "$1" "${R}/scripts/check.sh")
    [ "${n}" -eq "$3" ] || fail "$4 (found ${n}, expected $3)"
    sed -i "s#$(printf '%s' "$1" | sed 's/[#.[*^$]/\\&/g')#$2#g" "${R}/scripts/check.sh"
}

# --- 1. the secrets step lists the tree through CI_GIT --------------------------------------
SEAM_CALLS="rev-parse HEAD"$'\n'"ls-files -z --cached -- scripts"$'\n'"ls-files -z --cached --others --exclude-standard"$'\n'"rev-parse HEAD"
new_root seam
ln -s "${FAKE_GIT}" "${B}/fake-git"
run_check dev CI_GIT="${B}/fake-git --as=orbit" FAKE_GIT_FIRST=--as=orbit FAKE_GIT_HEAD= FAKE_GIT_LOG="${C}/seam.log" FAKE_FAIL_ON='*pint*'
equals '1 the stubbed Pint step stops the seamed run' "${RC}" 1
contains '1 the run reached the secrets step' "${OUT}" 'Gitleaks (secrets)'
contains '1 and the step after it' "${OUT}" 'Pint (code style)'
equals '1 every git call goes through the seam, with no -C of its own' "$(cat "${C}/seam.log" 2>/dev/null)" "${SEAM_CALLS}"

new_root plain
run_check dev FAKE_GIT_HEAD= FAKE_FAIL_ON='*pint*'
equals '1 without CI_GIT the stubbed Pint step stops the run' "${RC}" 1
equals '1 without CI_GIT every git call is plain git' "$(cat "${C}/git.log" 2>/dev/null)" "${SEAM_CALLS}"

new_root elsewhere
ln -s "${FAKE_GIT}" "${B}/fake-git"
run_check dev CI_GIT="${B}/fake-git --as=orbit -C /elsewhere" FAKE_GIT_FIRST=--as=orbit FAKE_GIT_HEAD= FAKE_GIT_LOG="${C}/seam.log"
equals '1 a seam naming another tree stops the run' "${RC}" 2
contains '1 and the refusal names both directories' "${OUT}" 'check.sh: CI_GIT points at /elsewhere but this script runs in'
absent '1 and nothing was listed against the wrong tree' "$(cat "${C}/seam.log" 2>/dev/null)" 'ls-files'

new_root matching
ln -s "${FAKE_GIT}" "${B}/fake-git"
run_check dev CI_GIT="${B}/fake-git --as=orbit -C ${R}" FAKE_GIT_FIRST=--as=orbit FAKE_GIT_HEAD= FAKE_GIT_LOG="${C}/seam.log" FAKE_FAIL_ON='*pint*'
equals "1 the runbook's own pair, one tree named twice, passes the guard" "${RC}" 1
equals '1 and still lists through the seam' "$(cat "${C}/seam.log" 2>/dev/null)" "${SEAM_CALLS}"

new_root usage
run_check -
equals '1 a mode-less call prints the usage and stops' "${RC}" 2
contains '1 and the usage names the seam' "${OUT}" 'CI_GIT'

# --- 2. the stack guard ------------------------------------------------------------------------
REFUSAL='Refusing to run the gate against it.'
FIRST_STEP='ShellCheck (shell scripts)'
RECIPE='COMPOSE_PROJECT_NAME=orbit-<name> docker compose -f docker-compose.yml -f docker-compose.ci.yml up -d --build postgres redis app'
refused() { # <label> <reason>
    equals "$1 is refused" "${RC}" 2
    contains "$1, and the refusal names why" "${OUT}" "$2"
    contains "$1, and keeps the recipe" "${OUT}" "${RECIPE}"
    absent "$1, and gates nothing" "${OUT}" "${FIRST_STEP}"
}

new_root sandbox
run_check dev FAKE_PS="printf '%s\n' c0ffee1234" FAKE_INSPECT="printf '%s\n' '${R}'" FAKE_FAIL_ON='*shellcheck*'
absent '2 a stack started from this directory is not refused' "${OUT}" "${REFUSAL}"
contains '2 and the run reaches its first step' "${OUT}" "${FIRST_STEP}"
equals '2 and the stubbed lint is what fails it' "${RC}" 1
LINT="$(grep shellcheck "${C}/docker.log")"
equals '2 the gate lints the shell exactly once' "$(printf '%s\n' "${LINT}" | grep -c .)" 1
contains '2 from a version-pinned image' "${LINT}" 'koalaman/shellcheck:v0.11.0'
contains '2 at warning' "${LINT}" '-S warning'
contains '2 with the tree mounted read-only' "${LINT}" "${R}:/mnt:ro"
LINTED="$(printf '%s\n' "${LINT#* -S warning }" | tr ' ' '\n' | sort)"
ON_DISK="$(cd "${R}" && find scripts -type f | sort | while IFS= read -r f; do
    case "${f}" in *.sh) printf '%s\n' "${f}"; continue ;; esac
    head -2 "${f}" | grep -qE '^#!.*sh|^# shellcheck shell=' && printf '%s\n' "${f}"
done)"
equals '2 the lint covers every shell file under scripts/, sourced libraries included' "${LINTED}" "${ON_DISK}"
for f in $(cd "${R}" && find scripts -type f -name '*.sh'); do
    case $'\n'"${LINTED}"$'\n' in *$'\n'"${f}"$'\n'*) ;; *) fail "2 ${f} is a shell script by its name and was not linted" ;; esac
done

new_root undeclared
printf 'echo hi\n' >"${R}/scripts/undeclared.sh"
printf 'not shell\n' >"${R}/scripts/plain.txt"
printf 'scripts/check.sh\0scripts/plain.txt\0scripts/undeclared.sh\0' >"${C}/scripts.list"
run_check dev FAKE_FAIL_ON='*shellcheck*'
LINT="$(grep shellcheck "${C}/docker.log")"
equals '2 a .sh with no declaration is linted, a file that is neither is not' \
    "$(printf '%s\n' "${LINT#* -S warning }" | tr ' ' '\n' | sort | paste -sd ' ')" 'scripts/check.sh scripts/undeclared.sh'

new_root elsewhere-stack
run_check dev FAKE_PS="printf '%s\n' c0ffee1234" FAKE_INSPECT="printf '%s\n' /var/www/orbit"
refused '2 a stack started from another directory' 'has a container started from /var/www/orbit'

new_root no-list
run_check dev FAKE_PS='exit 1'
refused '2 a container list docker will not give' "docker did not list this project's containers (exited 1)"

new_root no-inspect
run_check dev FAKE_PS="printf '%s\n' c0ffee1234" FAKE_INSPECT='exit 1'
refused '2 a container docker will not describe' 'docker did not say where container c0ffee1234 was started from (exited 1)'

new_root no-label
run_check dev FAKE_PS="printf '%s\n' c0ffee1234" FAKE_INSPECT="printf '%s\n' ''"
refused '2 a container with no working-directory label' 'container c0ffee1234 carries no working-directory label'

new_root hang
run_check dev FAKE_PS='exec sleep 40'
refused '2 a docker that hangs' "docker did not list this project's containers (timed out)"
[ "${SECONDS_TAKEN}" -lt 25 ] && pass '2 and it was timed out, not waited on' || fail "2 the guard waited ${SECONDS_TAKEN}s for a hung docker"

new_root nothing-up
run_check dev FAKE_FAIL_ON='*shellcheck*'
absent "2 a project with nothing up, the deploy's own gate, is not refused" "${OUT}" "${REFUSAL}"
contains '2 and it reaches its first step' "${OUT}" "${FIRST_STEP}"
equals '2 and the stubbed lint is what fails it' "${RC}" 1

# --- 3. the image-tag step (T9), run with a stand-in checker ------------------------------------
STEP='Image tags (T9)'
NEXT_STEP='Deploy mutants (scripts/deploy-mutants.sh)'
t9_root() { # <name> <exit> <stderr|stdout> <report, %s = root>
    new_root "$1"
    printf '#!/bin/sh\nprintf "%%s\\n" "$*" >>%s\n' "${C}/checker.log" >"${B}/gate-image-tags"
    # shellcheck disable=SC2059
    printf "$4" "${R}" "${R}" >"${C}/report"
    if [ "$3" = stderr ]; then printf 'cat %s >&2\n' "${C}/report"; else printf 'cat %s\n' "${C}/report"; fi >>"${B}/gate-image-tags"
    printf 'exit %s\n' "$2" >>"${B}/gate-image-tags"
    chmod 700 "${B}/gate-image-tags"
    swap /srv/engineering-standards/scripts/gate-image-tags.sh "${B}/gate-image-tags" 1 \
        '3 the canonical checker path is not in check.sh exactly once, so this is not the step it says'
    swap 'chown -R ' ': ' 2 '3 the overlay hands two paths to the app user; a third would run for real'
    for step in $(cd "${R}/scripts" && grep -oE '"\$here/scripts/[a-z-]+\.sh"' check.sh | sed 's#.*/##; s#"##' | sort -u); do
        printf '#!/bin/sh\nexit 0\n' >"${R}/scripts/${step}"
        chmod 700 "${R}/scripts/${step}"
    done
}
OK_REPORT='gate-image-tags: %s
  gate files:       docker-compose.ci.yml, docker-compose.e2e.yml
  production files: docker-compose.yml
  images:           14 resolved, 0 unresolved
  built tags:       2
ok %s: 2 built image tag(s), none shared between the gate and production\n'
for mode in dev overlay; do
    t9_root "t9-ok-${mode}" 0 stdout "${OK_REPORT}"
    run_check "${mode}"
    equals "3 the ${mode} runner hands this checkout to the check" "$(cat "${C}/checker.log" 2>/dev/null)" "${R}"
    equals "3 and a check that counted a built tag lets the ${mode} gate on" "${RC}" 0
    contains '3 and the report reaches the operator' "${OUT}" 'built tags:       2'
done
t9_case() { # <label> <reason>
    run_check dev
    [ "${RC}" -ne 0 ] && pass "$1 fails the gate" || fail "$1 let the gate pass"
    contains "$1, after reaching the step" "${OUT}" "${STEP}"
    absent "$1, and the gate stops there" "${OUT}" "${NEXT_STEP}"
    contains "$1, and says why" "${OUT}" "$2"
}
t9_root t9-refused 1 stdout 'gate-image-tags: %s\nrefused %s: orbit/app:latest is built by the gate and run in production\n'
t9_case '3 a tag the gate builds and production runs' 'is built by the gate and run in production'
t9_root t9-no-compose 2 stderr 'gate-image-tags: %s\ngate-image-tags: no compose file beside this root — nothing was examined, so %s is refused, not passed (T9)\n'
t9_case '3 a root with no compose file' 'no compose file beside this root'
t9_root t9-zero 0 stdout "${OK_REPORT//built tags:       2/built tags:       0}"
t9_case '3 a summary that counted no built tag' 'counted 0 built image tag(s) in'
t9_root t9-unjudged 0 stdout "${OK_REPORT//14 resolved, 0 unresolved/13 resolved, 1 unresolved}"
t9_case '3 an image value it could not judge' 'left 1 image value(s) unjudged in'

# --- 4. the overlay runner removes the mount points it caused, and only those -------------------
mount_root() {
    new_root "$1"
    swap 'chown -R ' ': ' 2 '4 the overlay hands two paths to the app user; a third would run for real'
    printf 'scripts/check.sh\0' >"${C}/scripts.list"
}
mount_root mounts-failed
mkdir -p "${R}/bootstrap/cache"
printf '*\n' >"${R}/bootstrap/cache/.gitignore"
run_check overlay FAKE_MOUNTS=1
equals '4 the fake composer install fails the overlay run mid-way' "${RC}" 1
contains '4 the fake docker made vendor/' "$(cat "${C}/made.log" 2>/dev/null)" vendor
[ ! -e "${R}/vendor" ] && pass '4 a vendor/ the run created is gone after it failed' || fail '4 vendor/ was absent before the gate and is still there'
[ -e "${R}/bootstrap/cache/.gitignore" ] && pass '4 a directory that was there before keeps what it held' || fail '4 bootstrap/cache lost its .gitignore'

mount_root mounts-kept
mkdir -p "${R}/vendor" "${R}/node_modules" "${R}/bootstrap/cache"
run_check overlay FAKE_MOUNTS=1
equals '4 the overlay run over existing directories fails at the fake install' "${RC}" 1
[ -d "${R}/vendor" ] && [ -d "${R}/node_modules" ] && [ -d "${R}/bootstrap/cache" ] \
    && pass '4 empty directories that predate the run are left alone' || fail '4 the gate removed an empty directory it did not create'

mount_root mounts-filled
mkdir -p "${R}/bootstrap/cache"
run_check overlay FAKE_MOUNTS=fill
equals '4 the filling overlay run fails at the fake install' "${RC}" 1
[ -e "${R}/vendor/autoload.php" ] && pass '4 rmdir only: what was written into a mount point is never deleted' || fail '4 the gate deleted what was written into vendor/'
contains '4 and the gate says it left it' "${OUT}" "check.sh: left ${R}/vendor in place: absent when the gate started, and it could not rmdir it"

mount_root mounts-other
FOREIGN="$(mktemp -d /var/tmp/orbit-gate.test-XXXXXXXX)"
run_check overlay FAKE_MOUNTS=1
[ -d "${FOREIGN}" ] && pass "4 another run's scratch survives the gate" || fail "4 the gate deleted ${FOREIGN}, another run's scratch"
contains '4 and the notice says it was not removed' "${OUT}" 'not removed: another gate may own them; remove by name'
contains '4 and names it' "${OUT}" "${FOREIGN}"
rmdir -- "${FOREIGN}" 2>/dev/null

# --- 5. a caller's GATE_RECORDED never stops the run's own row (card 702) ---------------------
new_root recorded
run_check dev GATE_RECORDED=1 FAKE_FAIL_ON='*pint*'
equals '5 the stubbed Pint step stops the run' "${RC}" 1
equals '5 and a caller'"'"'s GATE_RECORDED=1 still leaves the red row' "$(awk '{ print $1, $2, $4 }' "${C}/ledger" 2>/dev/null)" "${ZEROS} ci 1"

# --- 6. storage/ is handed over without following a link planted in it ------------------------
new_root chown-link
swap 'chown -R 115:119 "$gate"' ': "$gate"' 1 '6 the overlay scratch chown is stubbed; only storage/ is chowned for real'
printf 'scripts/check.sh\0' >"${C}/scripts.list"
mkdir -p "${R}/storage"
touch "${C}/sentinel"
ln -s "${C}/sentinel" "${R}/storage/planted"
run_check overlay FAKE_MOUNTS=1
equals '6 the overlay run fails at the fake install, after the storage chown' "${RC}" 1
equals '6 storage/ itself went to the app user' "$(stat -c %u "${R}/storage")" 115
equals '6 a file a link in storage/ points at keeps its owner' "$(stat -c %u "${C}/sentinel")" 0

if [ "${fails}" -ne 0 ]; then
    printf '\ncheck-test: %s check(s) failed\n' "${fails}" >&2
    exit 1
fi
printf '\ncheck-test: all checks passed\n'
