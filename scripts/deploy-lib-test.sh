#!/usr/bin/env bash
# Guards scripts/lib/gate/deploy-lib.sh: the gate reads the deploy lib only from root's clone at
# its origin/main, no caller's DEPLOY_LIB is read, the test seam is refused as root, and a missing,
# emptied, dirty or unreadable lib is refused out loud. docs/DECISIONS.md, deploy-lib-is-not-vendored
#
#   bash scripts/deploy-lib-test.sh <tree>    as root, over the tree whose scripts/ it judges
set -uo pipefail

if [ "$(id -u)" -ne 0 ]; then
    printf 'deploy-lib-test.sh: runs only as root: the gate does, and the seam cases drop to nobody\n' >&2
    exit 1
fi
[ $# -eq 1 ] && [ -d "$1/scripts" ] || { printf 'usage: deploy-lib-test.sh <tree with a scripts/ directory>\n' >&2; exit 2; }
TREE="$(cd -- "$1" && pwd -P)"
SCRIPTS="${TREE}/scripts"

# shellcheck source=scripts/lib/gate/deploy-lib.sh
. "${SCRIPTS}/lib/gate/deploy-lib.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK:?}"' EXIT
chmod 755 "${WORK}"
HELPER="${WORK}/deploy-lib.sh"
cp "${SCRIPTS}/lib/gate/deploy-lib.sh" "${HELPER}"
chmod 644 "${HELPER}"

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
        *"$3"*) fail "$1 — [$3] is in:"$'\n'"$2" ;;
        *) pass "$1" ;;
    esac
}

equals() {
    if [ "$2" = "$3" ]; then pass "$1 is [$3]"; else fail "$1 is [$2], expected [$3]"; fi
}

as_nobody() { runuser -u nobody -- env HOME="${WORK}" "$@"; }

# <user> [VAR=value...] -> stderr, then "<rc> <DEPLOY_LIB>"
ready() {
    local user=$1
    shift
    if [ "${user}" = root ]; then
        env -u DEPLOY_LIB_TEST_CLONE -u DEPLOY_LIB_HARNESS "$@" \
            bash -c '. "$0"; deploy_lib_ready; printf "%s %s\n" "$?" "$DEPLOY_LIB"' "${HELPER}" 2>&1
    else
        as_nobody env -u DEPLOY_LIB_TEST_CLONE -u DEPLOY_LIB_HARNESS "$@" \
            bash -c '. "$0"; deploy_lib_ready; printf "%s %s\n" "$?" "$DEPLOY_LIB"' "${HELPER}" 2>&1
    fi
}

rc_of() { printf '%s\n' "$1" | tail -1 | cut -d' ' -f1; }

# <dir>: a clone the seam can name, origin/main on HEAD; moved puts origin/main one commit past it
fake_clone() {
    local dir=$1 tree one two
    mkdir -p "${dir}/scripts/lib/deploy"
    cp "${DEPLOY_LIB}"/*.sh "${dir}/scripts/lib/deploy/"
    chown -R nobody "${dir}"
    as_nobody git init -q -b main "${dir}"
    as_nobody git -C "${dir}" add scripts/lib/deploy
    tree="$(as_nobody git -C "${dir}" write-tree)"
    one="$(as_nobody git -C "${dir}" -c user.name=t -c user.email=t@example.invalid commit-tree "${tree}" -m one)"
    as_nobody git -C "${dir}" update-ref refs/heads/main "${one}"
    if [ "${2:-same}" = moved ]; then
        two="$(as_nobody git -C "${dir}" -c user.name=t -c user.email=t@example.invalid commit-tree "${tree}" -p "${one}" -m two)"
        as_nobody git -C "${dir}" update-ref refs/remotes/origin/main "${two}"
    else
        as_nobody git -C "${dir}" update-ref refs/remotes/origin/main "${one}"
    fi
}

STANDIN="${WORK}/standin"
mkdir -p "${STANDIN}"
for f in summary.sh resolve.sh ledger.sh preflight.sh compose.sh; do printf 'true\n' >"${STANDIN}/${f}"; done

OUT="$(ready root DEPLOY_LIB="${STANDIN}")"
equals 'an exported DEPLOY_LIB is ignored: the lib is root'"'"'s clone, and it is ready' \
    "$(printf '%s\n' "${OUT}" | tail -1)" "0 ${DEPLOY_LIB_CLONE}/scripts/lib/deploy"

fake_clone "${WORK}/clone-same"
fake_clone "${WORK}/clone-moved" moved

OUT="$(ready nobody DEPLOY_LIB_TEST_CLONE="${WORK}/clone-same")"
contains 'the test seam outside the harness is refused' "${OUT}" 'REFUSED: DEPLOY_LIB_TEST_CLONE is set'
equals 'and the refusal is a failure' "$(rc_of "${OUT}")" 1

OUT="$(ready root DEPLOY_LIB_HARNESS=deploy-lib-test DEPLOY_LIB_TEST_CLONE="${WORK}/clone-same")"
contains 'root has the test seam refused, harness or not' "${OUT}" 'REFUSED: DEPLOY_LIB_TEST_CLONE is set'
equals 'and that root refusal is a failure' "$(rc_of "${OUT}")" 1

OUT="$(ready nobody DEPLOY_LIB_HARNESS=deploy-lib-test DEPLOY_LIB_TEST_CLONE="${WORK}/clone-same")"
equals 'the harness, unprivileged, reaches a clean clone on its origin/main' \
    "$(printf '%s\n' "${OUT}" | tail -1)" "0 ${WORK}/clone-same/scripts/lib/deploy"

OUT="$(ready nobody DEPLOY_LIB_HARNESS=deploy-lib-test DEPLOY_LIB_TEST_CLONE="${WORK}/clone-moved")"
contains 'a clone whose HEAD is not its origin/main is refused' "${OUT}" \
    "REFUSED: the deploy lib is not what a deploy reads: ${WORK}/clone-moved is at"
equals 'and that refusal is a failure' "$(rc_of "${OUT}")" 1

mkdir -p "${WORK}/fakebin"
printf '#!/bin/sh\ncase "$1" in --no-optional-locks) exit 0 ;; esac\necho deadbeef\n' >"${WORK}/fakebin/git"
chmod 755 "${WORK}/fakebin/git"
OUT="$(ready nobody PATH="${WORK}/fakebin:${PATH}" DEPLOY_LIB_HARNESS=deploy-lib-test DEPLOY_LIB_TEST_CLONE="${WORK}/clone-moved")"
contains 'a git first in PATH cannot vouch for a clone off its origin/main' "${OUT}" \
    "REFUSED: the deploy lib is not what a deploy reads: ${WORK}/clone-moved is at"

mkdir -p "${WORK}/nogit/scripts/lib"
cp -r "${WORK}/clone-same/scripts/lib/deploy" "${WORK}/nogit/scripts/lib/"
chown -R nobody "${WORK}/nogit"
OUT="$(ready nobody DEPLOY_LIB_HARNESS=deploy-lib-test DEPLOY_LIB_TEST_CLONE="${WORK}/nogit")"
contains 'a clone with no .git is refused' "${OUT}" \
    "REFUSED: the deploy lib is not what a deploy reads: ${WORK}/nogit is at nothing"
equals 'and the no-.git refusal is a failure' "$(rc_of "${OUT}")" 1

fake_clone "${WORK}/clone-modified"
printf '# local edit\n' >>"${WORK}/clone-modified/scripts/lib/deploy/preflight.sh"
OUT="$(ready nobody DEPLOY_LIB_HARNESS=deploy-lib-test DEPLOY_LIB_TEST_CLONE="${WORK}/clone-modified")"
contains 'a lib with a modified tracked file is refused' "${OUT}" \
    "REFUSED: the deploy lib in ${WORK}/clone-modified differs from its origin/main"
equals 'and the modified-lib refusal is a failure' "$(rc_of "${OUT}")" 1

fake_clone "${WORK}/clone-untracked"
printf 'true\n' >"${WORK}/clone-untracked/scripts/lib/deploy/extra.sh"
chown nobody "${WORK}/clone-untracked/scripts/lib/deploy/extra.sh"
OUT="$(ready nobody DEPLOY_LIB_HARNESS=deploy-lib-test DEPLOY_LIB_TEST_CLONE="${WORK}/clone-untracked")"
contains 'a lib with an untracked file is refused' "${OUT}" \
    "REFUSED: the deploy lib in ${WORK}/clone-untracked differs from its origin/main"
equals 'and the untracked-lib refusal is a failure' "$(rc_of "${OUT}")" 1

fake_clone "${WORK}/clone-badindex"
printf 'not an index\n' >"${WORK}/clone-badindex/.git/index"
OUT="$(ready nobody DEPLOY_LIB_HARNESS=deploy-lib-test DEPLOY_LIB_TEST_CLONE="${WORK}/clone-badindex")"
contains 'a git status that fails is refused' "${OUT}" "REFUSED: git status failed in ${WORK}/clone-badindex"
equals 'and the failed-status refusal is a failure' "$(rc_of "${OUT}")" 1

fake_clone "${WORK}/clone-nocompose"
rm -f "${WORK}/clone-nocompose/scripts/lib/deploy/compose.sh"
OUT="$(ready nobody DEPLOY_LIB_HARNESS=deploy-lib-test DEPLOY_LIB_TEST_CLONE="${WORK}/clone-nocompose")"
contains 'a lib missing compose.sh, which deploy.sh sources, is refused' "${OUT}" \
    "The deploy lib is not here: ${WORK}/clone-nocompose/scripts/lib/deploy/compose.sh is missing or empty"
equals 'and the missing-file refusal is a failure' "$(rc_of "${OUT}")" 1

# The gates ask deploy_lib_ready before they source the ledger, and from DEPLOY_LIB only.
for script in check.sh e2e.sh; do
    TEXT="$(cat "${SCRIPTS}/${script}")"
    ORDER="$(awk '/^ *deploy_lib_ready \|\|/ && !r { r = NR } /^ *\. "\$\{?DEPLOY_LIB\}?\/ledger\.sh"$/ && !l { l = NR } END { print (r && l && r < l) ? "ready-first" : "r=" r " l=" l }' "${SCRIPTS}/${script}")"
    equals "${script} asks deploy_lib_ready before it sources the ledger" "${ORDER}" ready-first
    case "${TEXT}" in
        *'lib/deploy/ledger.sh"'*) fail "${script} still sources a vendored lib/deploy/ledger.sh" ;;
        *) pass "${script} sources no vendored ledger" ;;
    esac
done

ORDER="$(awk '/^deploy_lib_ready \|\| \{.*exit 2; \}$/ && !r { r = NR } /^\. "\$DEPLOY_LIB\// && !l { l = NR } END { print (r && l && r < l) ? "ready-first" : "r=" r " l=" l }' "${SCRIPTS}/deploy.sh")"
equals 'deploy.sh asks deploy_lib_ready before it sources the lib' "${ORDER}" ready-first

equals 'no vendored copy of the lib is back in this repository' \
    "$([ -e "${SCRIPTS}/lib/deploy" ] && echo present || echo absent)" absent

# fleet-deploy refuses a service key off the lib's compose allowlist (cap_add, network_mode among them).
ALLOWED="$(sed -n 's/.*(keys\[\] | fleet_off(\[\([^]]*\)\]) | "A\\t\\(\$s)\\t\\(\.)").*/\1/p' "${DEPLOY_LIB}/compose.sh" | tr -d ' "' | tr ',' '\n' | sort)"
case $'\n'"${ALLOWED}"$'\n' in
    *$'\nuser\n'*) pass 'the lib'"'"'s compose allowlist was read, and it lists user' ;;
    *) fail "the lib's compose allowlist was not read from ${DEPLOY_LIB}/compose.sh: [${ALLOWED}]" ;;
esac
OFF_LIST="$(python3 -I -c '
import sys, yaml
allowed = set(sys.argv[2].split())
for name, service in (yaml.safe_load(open(sys.argv[1])) or {}).get("services", {}).items():
    for key in sorted(set(service) - allowed):
        print(name + ": " + key)
' "${TREE}/docker-compose.yml" "${ALLOWED}" 2>&1)" || OFF_LIST="the compose file could not be read: ${OFF_LIST}"
equals 'every service key in docker-compose.yml is on root'"'"'s compose allowlist' "${OFF_LIST}" ''

if [ "${fails}" -ne 0 ]; then
    printf '\ndeploy-lib-test: %s check(s) failed\n' "${fails}" >&2
    exit 1
fi
printf '\ndeploy-lib-test: all checks passed\n'
