#!/usr/bin/env bash
# Guards scripts/lib/gate/run-capped.sh with children that ignore TERM, in a temp directory only.
#
#   scripts/run-capped-test.sh
set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/gate/run-capped.sh
. "${RUN_CAPPED_LIB:-${SCRIPT_DIR}/lib/gate/run-capped.sh}"
RUN_CAPPED_GRACE=2
WATCHDOG=20

W="$(mktemp -d)" || exit 1
trap 'rm -rf "${W:?}"' EXIT

fails=0
pass() { printf 'ok   %s\n' "$*"; }
fail() { printf 'FAIL %s\n' "$*" >&2; fails=$((fails + 1)); }

printf '%s\n' "trap '' TERM" 'printf "%s\n" "$$" >"$1"' 'while :; do sleep 0.1; done' >"${W}/stubborn.sh"

# <pid> <case>: waits at most WATCHDOG seconds, then kills the group itself and fails the case.
bounded_wait() {
    local pid=$1 i
    for ((i = 0; i < WATCHDOG * 10; i++)); do
        kill -0 "${pid}" 2>/dev/null || { wait "${pid}"; RC=$?; return 0; }
        sleep 0.1
    done
    kill -9 -- -"${pid}" 2>/dev/null
    wait "${pid}" 2>/dev/null
    fail "$2: still running after ${WATCHDOG}s"
    return 1
}

child_gone() { # <pidfile> <case>
    local child
    child=$(cat "$1" 2>/dev/null)
    if [ -z "${child}" ]; then
        fail "$2: the child never started"
    elif kill -0 "${child}" 2>/dev/null; then
        kill -9 "${child}" 2>/dev/null
        fail "$2: the child outlived the cap"
    else
        pass "$2: and the child is gone"
    fi
}

start=${SECONDS}
run_capped 1 bash "${W}/stubborn.sh" "${W}/1.pid" &
if bounded_wait $! '1 a child that ignores TERM is ended at its own timeout'; then
    run_capped_timed_out "${RC}" && pass "1 a child that ignores TERM is ended at its own timeout (rc ${RC}, $((SECONDS - start))s)" \
        || fail "1 the rc ${RC} does not read as timed out"
fi
child_gone "${W}/1.pid" 1

run_capped 60 bash "${W}/stubborn.sh" "${W}/2.pid" &
pid=$!
for ((i = 0; i < 50; i++)); do [ -s "${W}/2.pid" ] && break; sleep 0.1; done
kill -- -"${pid}" 2>/dev/null
bounded_wait "${pid}" '2 the caller stopping the group ends a child that ignores TERM' \
    && pass '2 the caller stopping the group ends a child that ignores TERM'
child_gone "${W}/2.pid" 2

CAPPED_SEEN=from-caller run_capped 5 sh -c 'printf %s "${CAPPED_SEEN:-}"' >"${W}/3.out" &
bounded_wait $! '3 env' && [ "$(cat "${W}/3.out")" = from-caller ] \
    && pass '3 an assignment before run_capped reaches the command' \
    || fail "3 an assignment before run_capped did not reach the command: [$(cat "${W}/3.out")]"

run_capped 5 sh -c 'exit 3' &
bounded_wait $! '4 rc' && [ "${RC}" -eq 3 ] && ! run_capped_timed_out "${RC}" \
    && pass "4 a command's own exit status comes back and is not a timeout" \
    || fail "4 expected rc 3, got ${RC:-none}"

[ "${fails}" -eq 0 ] || { printf '%s case(s) failed\n' "${fails}" >&2; exit 1; }
printf 'run-capped: all cases passed\n'
