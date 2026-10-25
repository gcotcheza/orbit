#!/usr/bin/env bash
# Guards the paths scripts/e2e.sh makes in an app-owned tree: links and directories are refused,
# and as root the writes run as the owner (nobody stands in), proved by uid through PATH shims.
# E2E_ENV_LIB/E2E_SH test a copy.
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

WORK="$(mktemp -d -p /tmp)" || exit 1
trap 'rm -rf -- "${WORK:?}"' EXIT
chmod 755 "${WORK}"

if [ "$(id -u)" -eq 0 ]; then
    OWNER_UID=65534 OWNER_GID=65534
else
    OWNER_UID="$(id -u)" OWNER_GID="$(id -g)"
fi

# shellcheck source=scripts/lib/e2e-env.sh
. "${E2E_ENV_LIB}"

SHIMS="${WORK}/shims" UIDS="${WORK}/uids.log"
mkdir "${SHIMS}"
: >"${UIDS}"
chmod 666 "${UIDS}"
for tool in install tee mkdir; do
    real="$(command -v "${tool}")" || exit 1
    printf '#!/bin/sh\nprintf "%%s %%s\\n" %s "$(id -u)" >>%s\nexec %s "$@"\n' "${tool}" "${UIDS}" "${real}" >"${SHIMS}/${tool}"
    chmod 755 "${SHIMS}/${tool}"
done

ran_as_owner() { # TOOL... — each ran at least once, and every logged run had the owner's uid
    local tool
    for tool in "$@"; do grep -q "^${tool} " "${UIDS}" || return 1; done
    ! grep -qv " ${OWNER_UID}\$" "${UIDS}"
}

fresh_case() { # NAME — a tree the owner holds, and a sentinel outside it the owner cannot write
    CASE="${WORK}/$1"
    mkdir -p "${CASE}/tree" "${CASE}/outside"
    chown "${OWNER_UID}:${OWNER_GID}" "${CASE}/tree"
    printf 'SENTINEL\n' >"${CASE}/outside/sentinel"
    chmod 644 "${CASE}/outside/sentinel"
    SENTINEL_STAT="$(stat -c '%u:%g %a' "${CASE}/outside/sentinel")"
}

sentinel_untouched() {
    [ "$(cat "${CASE}/outside/sentinel")" = 'SENTINEL' ] \
        && [ "$(stat -c '%u:%g %a' "${CASE}/outside/sentinel")" = "${SENTINEL_STAT}" ]
}

root_made_nothing() { test -z "$(find "${CASE}/tree" -mindepth 1 -user 0 -not -path '*/e2e/specs')"; }

place() { # CONTENT — e2e_env_place from inside the case's tree
    : >"${UIDS}"
    (cd "${CASE}/tree" && PATH="${SHIMS}:${PATH}" \
        && printf '%s\n' "$1" | e2e_env_place .env.e2e "${OWNER_UID}" "${OWNER_GID}") 2>"${CASE}/stderr"
}

hand_over() {
    : >"${UIDS}"
    (cd "${CASE}/tree" && PATH="${SHIMS}:${PATH}" && e2e_hand_over_dirs "${OWNER_UID}" "${OWNER_GID}") 2>"${CASE}/stderr"
}

# --- 1. a link or a directory at .env.e2e is refused, and what it points at survives --
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
check 'and is still the same link' test "$(readlink "${CASE}/tree/.env.e2e")" = "${CASE}/outside/nowhere"
check 'and its target is never created' test ! -e "${CASE}/outside/nowhere"

fresh_case env-directory
mkdir "${CASE}/tree/.env.e2e"
printf 'kept\n' >"${CASE}/tree/.env.e2e/kept"
place 'APP_KEY=planted'
check 'a directory at .env.e2e is refused' test $? -ne 0
check 'and nothing is written into it' test "$(ls -A "${CASE}/tree/.env.e2e")" = 'kept'
check 'and the refusal names the path' grep -q '^.env.e2e is not a regular file' "${CASE}/stderr"

# --- 2. the normal path, written by the owner and never by root ------------------------
fresh_case env-new
place 'APP_KEY=fresh'
check 'a missing .env.e2e is written' test $? -eq 0
check 'as a regular file with the content given' test "$(cat "${CASE}/tree/.env.e2e")" = 'APP_KEY=fresh'
check 'mode 600, created by the owner rather than by root' test "$(stat -c '%u:%g %a' "${CASE}/tree/.env.e2e")" = "${OWNER_UID}:${OWNER_GID} 600"
check 'and root made nothing in the tree' root_made_nothing
check 'and install and tee each ran with the owner uid' ran_as_owner install tee

fresh_case env-replace
printf 'APP_KEY=old\n' >"${CASE}/tree/.env.e2e"
chown "${OWNER_UID}:${OWNER_GID}" "${CASE}/tree/.env.e2e"
chmod 644 "${CASE}/tree/.env.e2e"
place 'APP_KEY=new'
check 'an existing .env.e2e is replaced' test "$(cat "${CASE}/tree/.env.e2e")" = 'APP_KEY=new'
check 'and is mode 600 again, still the owner' test "$(stat -c '%u:%g %a' "${CASE}/tree/.env.e2e")" = "${OWNER_UID}:${OWNER_GID} 600"
check 'and install and tee each ran with the owner uid' ran_as_owner install tee

# --- 3. e2e/'s directories ---------------------------------------------------------------
for planted in e2e e2e/artifacts e2e/baselines; do
    fresh_case "dirs-${planted//\//-}"
    mkdir -p "${CASE}/tree/$(dirname "${planted}")"
    chown "${OWNER_UID}:${OWNER_GID}" "${CASE}/tree/$(dirname "${planted}")" "${CASE}/outside"
    ln -s "${CASE}/outside" "${CASE}/tree/${planted}"
    hand_over
    check "a link at ${planted} is refused" test $? -ne 0
    check "and the refusal names it" grep -q "^${planted} is a symbolic link" "${CASE}/stderr"
    check "and nothing is made through it" test -z "$(find "${CASE}/outside" -mindepth 1 -not -name sentinel)"
    check "and the sentinel is unchanged" sentinel_untouched
done

fresh_case dirs-new
mkdir -p "${CASE}/tree/e2e/specs"
chown "${OWNER_UID}:${OWNER_GID}" "${CASE}/tree/e2e"
SPECS_STAT="$(stat -c '%u:%g' "${CASE}/tree/e2e/specs")"
hand_over
check 'e2e/artifacts and e2e/baselines are made' test -d "${CASE}/tree/e2e/artifacts" -a -d "${CASE}/tree/e2e/baselines"
check 'by the owner rather than by root' test "$(stat -c '%u:%g' "${CASE}/tree/e2e/artifacts") $(stat -c '%u:%g' "${CASE}/tree/e2e/baselines")" = "${OWNER_UID}:${OWNER_GID} ${OWNER_UID}:${OWNER_GID}"
check 'and nothing already there is chowned' test "$(stat -c '%u:%g' "${CASE}/tree/e2e/specs")" = "${SPECS_STAT}"
check 'and root made nothing in the tree' root_made_nothing
check 'and mkdir ran with the owner uid' ran_as_owner mkdir

# --- 4. a tree the owner does not hold is refused --------------------------------------
fresh_case owner-held
check 'a tree held by the uid the files are made as is accepted' e2e_refuse_foreign_owner "${CASE}/tree" "${OWNER_UID}"
fresh_case owner-foreign
other_uid=$((OWNER_UID + 1))
e2e_refuse_foreign_owner "${CASE}/tree" "${other_uid}" 2>"${CASE}/stderr"
check 'a tree held by another uid is refused' test $? -ne 0
check 'and the refusal names the owner it needs' grep -qF "so it needs a tree uid ${other_uid} owns" "${CASE}/stderr"

# --- 5. scripts/e2e.sh goes through the library, and refuses before any write -------------
check 'e2e.sh sources the library' grep -qF '. "${ROOT}/scripts/lib/e2e-env.sh"' "${E2E_SH}"
check 'e2e.sh writes .env.e2e only through e2e_env_place' grep -qE '^ +e2e_env_place \.env\.e2e ' "${E2E_SH}"
check 'e2e.sh makes e2e/ only through e2e_hand_over_dirs' grep -qE '^e2e_hand_over_dirs ' "${E2E_SH}"
writes_there="$(grep -vE '^ *#' "${E2E_SH}" \
    | grep -nE '(>|\b(tee|cp|install|mv|chown|chmod|mkdir|touch|ln)\b)[^#]*(\.env\.e2e|(^|[ "'\''=])e2e(/|[ "'\'']|$))')"
check 'and nothing else writes, moves or chowns those paths' test -z "${writes_there}"
refusal_line="$(grep -nE '^e2e_refuse_links \.env\.e2e e2e e2e/artifacts e2e/baselines ' "${E2E_SH}" | cut -d: -f1)"
first_write="$(grep -nE '^    write_env_file$' "${E2E_SH}" | head -1 | cut -d: -f1)"
check 'e2e.sh refuses links up front, before the first write' \
    test -n "${refusal_line}" -a -n "${first_write}" -a "${refusal_line:-0}" -lt "${first_write:-0}"
owner_line="$(grep -nE '^e2e_refuse_foreign_owner "\$ROOT" "\$APP_UID" \|\| fail ' "${E2E_SH}" | cut -d: -f1)"
check 'e2e.sh refuses a tree uid 115 does not hold, before the first write' \
    test -n "${owner_line}" -a -n "${first_write}" -a "${owner_line:-0}" -lt "${first_write:-0}"

if [ "$fails" -gt 0 ]; then
    printf '\n%d check(s) failed\n' "$fails" >&2
    exit 1
fi
printf '\nall checks passed\n'
