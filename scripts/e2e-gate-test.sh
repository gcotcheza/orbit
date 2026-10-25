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
                rm -f -- "${FAKE_TREE}/.env.e2e" && ln -s "${FAKE_PLANT}" "${FAKE_TREE}/.env.e2e"
            fi ;;
        *' up -d --wait '*)
            local prev='' a
            for a in "$@"; do
                [ "${prev}" = '--env-file' ] && cat -- "$a" >"${FAKE_DIR}/up-env-file" 2>&1
                prev=$a
            done
            printf '%s' "${E2E_ENV_MOUNT:-}" >"${FAKE_DIR}/up-mount"
            return 1 ;;
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
    export FAKE_DIR FAKE_TREE
}

run_gate() { # ENV... — the copy's e2e.sh, with the fakes first on PATH
    env PATH="${BIN}:${PATH}" GATE_LEDGER="${FAKE_DIR}/ledger" GATE_LEDGER_GIT="${BIN}/fake-git" \
        ORBIT_GATE_LOG=/dev/null "$@" bash "${FAKE_TREE}/scripts/e2e.sh" >"${FAKE_DIR}/out" 2>&1
}

# --- 1. a caller's GATE_RECORDED never stops the run's own row (card 702) -------------------
new_case recorded
run_gate GATE_RECORDED=1
equals '1 the fake reached compose up' "$(grep -c ' up -d --wait' "${FAKE_DIR}/docker.argv")" '1'
equals "1 and a caller's GATE_RECORDED=1 still leaves the red row" \
    "$(awk '{ print $1, $2, $4 }' "${FAKE_DIR}/ledger")" "${FAKE_SHA} e2e 1"

if [ "${fails}" -gt 0 ]; then
    printf '\ne2e-gate-test: %d check(s) failed\n' "${fails}" >&2
    exit 1
fi
printf '\ne2e-gate-test: all checks passed\n'
