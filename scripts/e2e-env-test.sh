#!/usr/bin/env bash
# Guards the paths scripts/e2e.sh writes as root in an app-owned tree (.env.e2e and
# e2e/'s two directories): a planted link is refused and its target is untouched.
#
#   scripts/e2e-env-test.sh                                    the whole list
#   E2E_ENV_LIB=/path/e2e-env.sh E2E_SH=/path/e2e.sh scripts/e2e-env-test.sh   a mutated copy
#
# Temp directories only; no docker, and nothing outside its own work directory.
set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
E2E_ENV_LIB="${E2E_ENV_LIB:-${SCRIPT_DIR}/lib/e2e-env.sh}"
E2E_SH="${E2E_SH:-${SCRIPT_DIR}/e2e.sh}"

fails=0
pass() { printf 'ok   %s\n' "$*"; }
fail() { printf 'FAIL %s\n' "$*" >&2; fails=$((fails + 1)); }
check() { # LABEL COMMAND...
    local label=$1
    shift
    if "$@"; then pass "$label"; else fail "$label"; fi
}

for file in "${E2E_ENV_LIB}" "${E2E_SH}"; do
    [ -f "${file}" ] || { printf 'FAIL %s is not a file\n' "${file}" >&2; exit 1; }
done

WORK="$(mktemp -d)" || exit 1
trap 'rm -rf -- "${WORK:?}"' EXIT

if [ "$(id -u)" -eq 0 ]; then
    OWNER_UID=65534 OWNER_GID=65534
else
    OWNER_UID="$(id -u)" OWNER_GID="$(id -g)"
fi

# shellcheck source=scripts/lib/e2e-env.sh
. "${E2E_ENV_LIB}"

fresh_case() { # NAME — a tree of its own, a sentinel outside it, and a TMPDIR to watch
    CASE="${WORK}/$1"
    mkdir -p "${CASE}/tree" "${CASE}/outside" "${CASE}/tmp"
    printf 'SENTINEL\n' >"${CASE}/outside/sentinel"
    chmod 644 "${CASE}/outside/sentinel"
    SENTINEL_STAT="$(stat -c '%u:%g %a' "${CASE}/outside/sentinel")"
}

sentinel_untouched() {
    [ "$(cat "${CASE}/outside/sentinel")" = 'SENTINEL' ] \
        && [ "$(stat -c '%u:%g %a' "${CASE}/outside/sentinel")" = "${SENTINEL_STAT}" ]
}

place() { # CONTENT — e2e_env_place from inside the case's tree
    (cd "${CASE}/tree" && printf '%s\n' "$1" | TMPDIR="${CASE}/tmp" e2e_env_place .env.e2e "${OWNER_UID}" "${OWNER_GID}") 2>"${CASE}/stderr"
}

hand_over() {
    (cd "${CASE}/tree" && e2e_hand_over_dirs "${OWNER_UID}" "${OWNER_GID}") 2>"${CASE}/stderr"
}

# --- 1. a link at .env.e2e is refused and its target survives ------------------
fresh_case env-link
ln -s "${CASE}/outside/sentinel" "${CASE}/tree/.env.e2e"
place 'APP_KEY=planted'
check 'a link at .env.e2e is refused' test $? -ne 0
check 'and is still the same link' test "$(readlink "${CASE}/tree/.env.e2e")" = "${CASE}/outside/sentinel"
check "and the sentinel's content, owner and mode are unchanged" sentinel_untouched
check 'and the refusal names the path' grep -q '^.env.e2e is a symbolic link' "${CASE}/stderr"

fresh_case env-dangling
ln -s "${CASE}/outside/nowhere" "${CASE}/tree/.env.e2e"
place 'APP_KEY=planted'
check 'a dangling link at .env.e2e is refused' test $? -ne 0
check 'and its target is never created' test ! -e "${CASE}/outside/nowhere"

# --- 2. the normal path -----------------------------------------------------------
fresh_case env-new
place 'APP_KEY=fresh'
check 'a missing .env.e2e is written' test $? -eq 0
check 'as a regular file with the content given' test "$(cat "${CASE}/tree/.env.e2e")" = 'APP_KEY=fresh'
check 'mode 600, owned by the app user' test "$(stat -c '%u:%g %a' "${CASE}/tree/.env.e2e")" = "${OWNER_UID}:${OWNER_GID} 600"
check 'and the temp directory is removed' test -z "$(ls -A "${CASE}/tmp")"

fresh_case env-replace
printf 'APP_KEY=old\n' >"${CASE}/tree/.env.e2e"
chmod 644 "${CASE}/tree/.env.e2e"
place 'APP_KEY=new'
check 'an existing .env.e2e is replaced' test "$(cat "${CASE}/tree/.env.e2e")" = 'APP_KEY=new'
check 'and is mode 600 again' test "$(stat -c '%a' "${CASE}/tree/.env.e2e")" = '600'

# --- 3. e2e/'s directories ----------------------------------------------------------
for planted in e2e e2e/artifacts e2e/baselines; do
    fresh_case "dirs-${planted//\//-}"
    mkdir -p "${CASE}/tree/$(dirname "${planted}")"
    ln -s "${CASE}/outside" "${CASE}/tree/${planted}"
    hand_over
    check "a link at ${planted} is refused" test $? -ne 0
    check "and nothing is made through it" test -z "$(find "${CASE}/outside" -mindepth 1 -not -name sentinel)"
    check "and the sentinel is unchanged" sentinel_untouched
done

fresh_case dirs-new
mkdir -p "${CASE}/tree/e2e/specs"
hand_over
check 'e2e/artifacts and e2e/baselines are made' test -d "${CASE}/tree/e2e/artifacts" -a -d "${CASE}/tree/e2e/baselines"
check 'and e2e/ is handed to the app user' test "$(stat -c '%u:%g' "${CASE}/tree/e2e/specs")" = "${OWNER_UID}:${OWNER_GID}"

# --- 4. scripts/e2e.sh goes through the library, and refuses before any write -----
check 'e2e.sh sources the library' grep -qF '. "${ROOT}/scripts/lib/e2e-env.sh"' "${E2E_SH}"
check 'e2e.sh writes .env.e2e only through e2e_env_place' grep -qE '^ +e2e_env_place \.env\.e2e ' "${E2E_SH}"
check 'e2e.sh makes e2e/ only through e2e_hand_over_dirs' grep -qE '^e2e_hand_over_dirs ' "${E2E_SH}"
check 'and nothing else writes or chowns those paths' \
    test -z "$(grep -nE '> *\.env\.e2e|chown[^#]*\.env\.e2e|chmod[^#]*\.env\.e2e|mkdir[^#]* e2e/|chown -R[^#]* e2e' "${E2E_SH}")"
refusal_line="$(grep -nE '^e2e_refuse_links \.env\.e2e e2e e2e/artifacts e2e/baselines ' "${E2E_SH}" | cut -d: -f1)"
first_write="$(grep -nE '^    write_env_file$' "${E2E_SH}" | head -1 | cut -d: -f1)"
check 'e2e.sh refuses links up front, before the first write' \
    test -n "${refusal_line}" -a -n "${first_write}" -a "${refusal_line:-0}" -lt "${first_write:-0}"

if [ "$fails" -gt 0 ]; then
    printf '\n%d check(s) failed\n' "$fails" >&2
    exit 1
fi
printf '\nall checks passed\n'
