#!/usr/bin/env bash
# Runs a copy of <tree>'s scripts/e2e.sh end to end against a fake docker (a shell function), as root.
# Usage: bash scripts/e2e-gate-test.sh [tree]
set -uo pipefail

TREE="$(cd "${1:-$(dirname "$0")/..}" && pwd)" || exit 1
[ "$(id -u)" -eq 0 ] || { echo 'e2e-gate-test: run as root; the browser gate drops to uid 115 itself' >&2; exit 1; }

fails=0
pass() { printf 'ok   %s\n' "$*"; }
fail() { printf 'FAIL %s\n' "$*" >&2; fails=$((fails + 1)); }
equals() { if [ "$2" = "$3" ]; then pass "$1 is [$3]"; else fail "$1 is [$2], expected [$3]"; fi; }

WORK="$(mktemp -d /srv/worker-scratch/orbit-e2e-gate-test.XXXXXXXX)" || exit 1
trap 'rm -rf "${WORK:?}"' EXIT
chmod 0711 "${WORK}"
BIN="${WORK}/bin"
mkdir -m 0755 "${BIN}"
FAKE_SHA=0123456789abcdef0123456789abcdef01234567

printf '#!/bin/sh\ncase "$1" in rev-parse) echo %s ;; esac\n' "${FAKE_SHA}" >"${BIN}/fake-git"
printf '#!/bin/bash\ndocker "$@"\n' >"${BIN}/docker"
chmod 0755 "${BIN}/fake-git" "${BIN}/docker"

# Every docker call is logged; composer install may plant a link (FAKE_PLANT); `up` fails.
docker() {
    printf '%s\n' "$*" >>"${FAKE_DIR}/docker.argv"
    case " $* " in
        *' composer install '*)
            if [ -n "${FAKE_PLANT:-}" ]; then
                rm -f -- "${FAKE_TREE}/.env.e2e" "${FAKE_TREE}/.env" \
                    && ln -s "${FAKE_PLANT}" "${FAKE_TREE}/.env.e2e" && ln -s "${FAKE_PLANT}" "${FAKE_TREE}/.env"
            fi ;;
        *' up -d --wait '*)
            local prev='' a
            for a in "$@"; do
                [ "${prev}" = '--env-file' ] && cat -- "$a" >"${FAKE_DIR}/up-env-file" 2>&1
                prev=$a
            done
            # Through a child, as compose sees it: this fake is a function, which sees unexported names too.
            local mount
            mount="$(printenv E2E_ENV_MOUNT || true)"
            printf '%s' "${mount}" >"${FAKE_DIR}/up-mount"
            cat -- "${mount:-/nonexistent}" >"${FAKE_DIR}/up-mount-content" 2>&1
            stat -c '%u:%g %a' -- "${mount:-/nonexistent}" "$(dirname -- "${mount:-/nonexistent}")" \
                >"${FAKE_DIR}/up-perm" 2>&1
            { pwd; stat -c %u .; } >"${FAKE_DIR}/up-where"
            tr '\0' '|' <"/proc/$$/cmdline" >"${FAKE_DIR}/up-cmdline"
            [ -z "${FAKE_UP_TERM:-}" ] || kill -TERM "${PPID}"
            [ -z "${FAKE_UP_EXIT:-}" ] || exit "${FAKE_UP_EXIT}"
            return 1 ;;
        *' down -v --remove-orphans '*) return "${FAKE_DOWN_EXIT:-0}" ;;
    esac
    return 0
}
export -f docker

new_case() { # NAME — a fresh uid-115 copy of the tree and a fresh fake log
    FAKE_DIR="${WORK}/$1"
    FAKE_TREE="${FAKE_DIR}/tree"
    mkdir -p "${FAKE_TREE}"
    tar -C "${TREE}" --exclude=./.git --exclude=./vendor --exclude=./node_modules \
        --exclude=./public/build --exclude=./.env.e2e -cf - . | tar -xf - -C "${FAKE_TREE}"
    chown -R 115:119 "${FAKE_TREE}"
    : >"${FAKE_DIR}/ledger"
    # e2e.sh reads no env-root seam: the copy under test has its pinned folder moved into WORK.
    ENV_ROOT="${FAKE_DIR}/env-root"
    sed -i "s|^E2E_ENV_ROOT=/run/orbit-e2e\$|E2E_ENV_ROOT=${ENV_ROOT}|" "${FAKE_TREE}/scripts/e2e.sh"
    grep -qx "E2E_ENV_ROOT=${ENV_ROOT}" "${FAKE_TREE}/scripts/e2e.sh" \
        || { echo "e2e-gate-test: REFUSED: could not move E2E_ENV_ROOT into ${FAKE_DIR}" >&2; exit 1; }
    # Nor a ledger-git seam: the copy names HEAD with the fake, since the copy has no .git.
    sed -i "s|^GATE_LEDGER_GIT=\"git-as orbit -C \${ROOT}\"\$|GATE_LEDGER_GIT=${BIN}/fake-git|" "${FAKE_TREE}/scripts/e2e.sh"
    grep -qx "GATE_LEDGER_GIT=${BIN}/fake-git" "${FAKE_TREE}/scripts/e2e.sh" \
        || { echo "e2e-gate-test: REFUSED: could not point the copy's GATE_LEDGER_GIT at the fake git" >&2; exit 1; }
    export FAKE_DIR FAKE_TREE
}

run_gate() { # ENV... — the copy's e2e.sh, with the fakes first on PATH
    env PATH="${BIN}:${PATH}" GATE_LEDGER="${FAKE_DIR}/ledger" \
        ORBIT_GATE_LOG=/dev/null "$@" bash "${FAKE_TREE}/scripts/e2e.sh" >"${FAKE_DIR}/out" 2>&1
}

# --- 1. a caller's GATE_RECORDED never stops the run's own row (card 702) -------------------
new_case recorded
run_gate GATE_RECORDED=1
equals '1 the fake reached compose up' "$(grep -c ' up -d --wait' "${FAKE_DIR}/docker.argv")" '1'
equals "1 and a caller's GATE_RECORDED=1 still leaves the red row" \
    "$(awk '{ print $1, $2, $4 }' "${FAKE_DIR}/ledger")" "${FAKE_SHA} e2e 1"

# --- 2. compose and the mount read root's copy, never a link planted in the tree (card 716) --
new_case planted
SENTINEL="${WORK}/root-only/secret"
mkdir -m 700 "${WORK}/root-only" && printf 'SENTINEL-716\n' >"${SENTINEL}"
run_gate FAKE_PLANT="${SENTINEL}"
equals '2 the fake reached compose up' "$(grep -c ' up -d --wait' "${FAKE_DIR}/docker.argv")" '1'
equals '2 and the links were planted during the composer step' "$(readlink "${FAKE_TREE}/.env.e2e")" "${SENTINEL}"
equals '2 compose read the generated env as --env-file' "$(grep -c '^ORBIT_E2E=1$' "${FAKE_DIR}/up-env-file")" '1'
equals '2 the mount is the copy in the pinned folder' "$(cat "${FAKE_DIR}/up-mount")" "${ENV_ROOT}/orbit-e2e/e2e.env"
equals '2 and holds the generated env' "$(grep -c '^ORBIT_E2E=1$' "${FAKE_DIR}/up-mount-content")" '1'
equals '2 neither read the planted sentinel' "$(cat "${FAKE_DIR}/up-env-file" "${FAKE_DIR}/up-mount-content" | grep -c SENTINEL-716)" '0'
equals '2 the copy is 0640 root:119 in a 0750 root:119 folder' "$(tr '\n' ' ' <"${FAKE_DIR}/up-perm")" '0:119 640 0:119 750 '
equals '2 the sentinel survives' "$(cat "${SENTINEL}")" 'SENTINEL-716'
equals '2 teardown removes the copy and its folder' "$(ls -A "${ENV_ROOT}")" ''

# --- 3. the gate refuses to run as anyone but root ------------------------------------------
new_case not-root
setpriv --reuid=115 --regid=119 --clear-groups env PATH="${BIN}:${PATH}" GATE_LEDGER="${FAKE_DIR}/ledger" \
    ORBIT_GATE_LOG=/dev/null bash "${FAKE_TREE}/scripts/e2e.sh" >"${FAKE_DIR}/out" 2>&1
rc=$?
equals '3 run as uid 115 the gate exits' "${rc}" '1'
equals '3 and says it needs root' "$(grep -c "run this as root: the env is root's own copy in ${ENV_ROOT}" "${FAKE_DIR}/out")" '1'
equals '3 before docker is asked anything' "$(cat "${FAKE_DIR}/docker.argv" 2>/dev/null | wc -l)" '0'
equals '3 and before the env folder is made' "$([ -e "${ENV_ROOT}" ] && echo made || echo absent)" 'absent'

# --- 4. --down tears the stack down and removes the env copy --------------------------------
new_case down
install -d -o 0 -g 0 -m 0755 "${ENV_ROOT}" && install -d -o 0 -g 119 -m 0750 "${ENV_ROOT}/orbit-e2e" \
    && printf 'ORBIT_E2E=1\n' >"${ENV_ROOT}/orbit-e2e/e2e.env"
env PATH="${BIN}:${PATH}" GATE_LEDGER="${FAKE_DIR}/ledger" \
    ORBIT_GATE_LOG=/dev/null bash "${FAKE_TREE}/scripts/e2e.sh" --down >"${FAKE_DIR}/out" 2>&1
rc=$?
equals '4 --down exits 0' "${rc}" '0'
equals '4 and ran compose down' "$(grep -c ' down -v --remove-orphans' "${FAKE_DIR}/docker.argv")" '1'
equals '4 and removed the copy and its folder' "$(ls -A "${ENV_ROOT}")" ''

new_case down-fails
run_gate FAKE_DOWN_EXIT=3
rc=$?
equals '4 a failed run whose compose down fails keeps its own exit' "${rc}" '1'
equals '4 and says the teardown failed (card 1005)' "$(grep -c 'compose down of orbit-e2e failed (exit 3)' "${FAKE_DIR}/out")" '1'
equals '4 and keeps the env copy --down needs' "$(ls -A "${ENV_ROOT}")" 'orbit-e2e'

# --- 5. a fresh tree gets its .env mount point from the tree's owner, never the daemon ------
new_case fresh-env
rm -f -- "${FAKE_TREE}/.env"
run_gate
equals '5 the fake reached compose up' "$(grep -c ' up -d --wait' "${FAKE_DIR}/docker.argv")" '1'
equals '5 .env exists, empty, 0600 and owned by 115:119' \
    "$(stat -c '%u:%g %a %s' -- "${FAKE_TREE}/.env" 2>&1)" '115:119 600 0'

# --- 6. a .env that is a link is refused before docker is asked anything --------------------
new_case env-link
rm -f -- "${FAKE_TREE}/.env" && ln -s "${WORK}/nowhere" "${FAKE_TREE}/.env"
run_gate
equals '6 the gate says a path it writes is a link' "$(grep -c 'a path this script writes is a link' "${FAKE_DIR}/out")" '1'
equals '6 before docker is asked anything' "$(cat "${FAKE_DIR}/docker.argv" 2>/dev/null | wc -l)" '0'
equals '6 and the link still points nowhere' "$(readlink "${FAKE_TREE}/.env")" "${WORK}/nowhere"

# --- 7. a root-owned clean tree runs from a uid-115 clone that is removed after (880) -------
new_root_case() { # NAME — a committed, root-owned git tree; its e2e.sh pointed at the test's folders
    new_case "$1"
    local repo="${FAKE_DIR}/root-tree"
    LANES="${FAKE_DIR}/lanes"
    mkdir -m 0755 "${LANES}" "${repo}"
    cp -a "${FAKE_TREE}/." "${repo}/"
    sed -i "s|^E2E_LANE_ROOT=/srv/worker-scratch\$|E2E_LANE_ROOT=${LANES}|" "${repo}/scripts/e2e.sh"
    grep -qx "E2E_LANE_ROOT=${LANES}" "${repo}/scripts/e2e.sh" \
        || { echo "e2e-gate-test: REFUSED: could not move E2E_LANE_ROOT into ${FAKE_DIR}" >&2; exit 1; }
    chown -R 0:0 "${repo}"
    git -C "${repo}" init -q -b main && git -C "${repo}" add -- . \
        && git -C "${repo}" commit -q -m fixture || { echo 'e2e-gate-test: REFUSED: fixture commit failed' >&2; exit 1; }
    FAKE_TREE="${repo}"
    export FAKE_TREE
}

run_args() { # ARGS... — the root tree's e2e.sh with those arguments; rc kept in $rc
    env PATH="${BIN}:${PATH}" GATE_LEDGER="${FAKE_DIR}/ledger" ORBIT_GATE_LOG=/dev/null \
        bash "${FAKE_TREE}/scripts/e2e.sh" "$@" >"${FAKE_DIR}/out" 2>&1
    rc=$?
}
lane_name() { printf 'root-tree-e2e-%s' "$(git -C "${FAKE_TREE}" rev-parse --short=8 HEAD)"; }

new_root_case lane
run_args
equals '7 a failed run returns the child rc' "${rc}" '1'
equals '7 compose up ran once' "$(grep -c ' up -d --wait' "${FAKE_DIR}/docker.argv")" '1'
equals '7 inside a lane clone owned by 115' "$(tr '\n' ' ' <"${FAKE_DIR}/up-where")" \
    "${LANES}/$(lane_name)/repo 115 "
equals '7 a failed run keeps the lane' "$(ls -A "${LANES}")" "$(lane_name)"
equals '7 and prints the removal line' "$(grep -c "remove it with: rm -rf -- ${LANES}/$(lane_name)\$" "${FAKE_DIR}/out")" '1'
run_args
equals '7 a leftover lane is refused' "$(grep -c 'is left over from an earlier run or in use by a running gate; once no gate uses it, remove it with: rm -rf --' "${FAKE_DIR}/out")" '1'
equals '7 with compose up not run again' "$(grep -c ' up -d --wait' "${FAKE_DIR}/docker.argv")" '1'

new_root_case lane-pass
FAKE_UP_EXIT=0 run_args -- 'a b' --c
equals '7 a clean pass returns 0' "${rc}" '0'
equals '7 with the arguments passed through unchanged' "$(grep -c "^bash|${LANES}/$(lane_name)/repo/scripts/e2e.sh|--|a b|--c|\$" "${FAKE_DIR}/up-cmdline")" '1'
equals '7 and removes the lane' "$(ls -A "${LANES}")" ''

new_root_case lane-keep
FAKE_UP_EXIT=0 run_args --keep
equals '7 --keep passes and returns 0' "${rc}" '0'
equals '7 and keeps the lane' "$(ls -A "${LANES}")" "$(lane_name)"

new_root_case lane-update
run_args -- --update-snapshots
equals '7 --update-snapshots is refused' "$(grep -c 'run --update-snapshots from a uid 115 clone instead' "${FAKE_DIR}/out")" '1'
equals '7 before docker or a lane' "$(cat "${FAKE_DIR}/docker.argv" 2>/dev/null | wc -l):$(ls -A "${LANES}")" '0:'

new_root_case lane-term
FAKE_UP_TERM=1 run_args
equals '7 a SIGTERM exits 143' "${rc}" '143'
equals '7 and removes the lane' "$(ls -A "${LANES}")" ''

new_root_case lane-dirty
printf 'x\n' >"${FAKE_TREE}/uncommitted"
run_gate
equals '7 a dirty root-owned tree is refused' "$(grep -c 'has uncommitted changes' "${FAKE_DIR}/out")" '1'
equals '7 before docker is asked anything' "$(cat "${FAKE_DIR}/docker.argv" 2>/dev/null | wc -l)" '0'
equals '7 and no lane is left' "$(ls -A "${LANES}")" ''

if [ "${fails}" -gt 0 ]; then
    printf '\ne2e-gate-test: %d check(s) failed\n' "${fails}" >&2
    exit 1
fi
printf '\ne2e-gate-test: all checks passed\n'
