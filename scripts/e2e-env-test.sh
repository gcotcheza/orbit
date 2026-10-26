#!/usr/bin/env bash
# Guards the paths scripts/e2e.sh makes: in the app-owned tree links are refused and root's writes run
# as the owner (nobody stands in, proved by uid); the env is root's copy in a folder only root writes.
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

[ "$(id -u)" -eq 0 ] || { echo 'e2e-env-test: run as root, as e2e.sh must; nobody plays the owner' >&2; exit 1; }
OWNER_UID=65534 OWNER_GID=65534

WORK="$(mktemp -d -p /tmp)" || exit 1
trap 'rm -rf -- "${WORK:?}"' EXIT
chmod 755 "${WORK}"

# shellcheck source=scripts/lib/e2e-env.sh
. "${E2E_ENV_LIB}"

SHIMS="${WORK}/shims" UIDS="${WORK}/uids.log"
mkdir "${SHIMS}"
: >"${UIDS}"
chmod 666 "${UIDS}"
real_mkdir="$(command -v mkdir)" || exit 1
printf '#!/bin/sh\nprintf "mkdir %%s\\n" "$(id -u)" >>%s\nexec %s "$@"\n' "${UIDS}" "${real_mkdir}" >"${SHIMS}/mkdir"
chmod 755 "${SHIMS}/mkdir"

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

hand_over() {
    : >"${UIDS}"
    (cd "${CASE}/tree" && PATH="${SHIMS}:${PATH}" && e2e_hand_over_dirs "${OWNER_UID}" "${OWNER_GID}") 2>"${CASE}/stderr"
}

# --- 1. the env is root's copy, outside the tree, in a folder only root writes (card 716) --
REAL_INSTALL="$(command -v install)" || exit 1
ENV_ROOT="${WORK}/env-root"
ENV_DIR="${ENV_ROOT}/orbit-e2e"
copy_env() { # CONTENT [PATH-DIR] — e2e_env_copy_dir, then e2e_env_copy_write, as e2e.sh calls them
    (PATH="${2:+$2:}${PATH}" && e2e_env_copy_dir "${ENV_ROOT}" "${ENV_DIR}" "${OWNER_GID}" \
        && printf '%s\n' "$1" | e2e_env_copy_write "${ENV_DIR}/e2e.env" "${OWNER_GID}") 2>"${WORK}/env-stderr"
}

copy_env 'APP_KEY=fresh'
check 'the env copy is written' test $? -eq 0
check 'with the content given' test "$(cat "${ENV_DIR}/e2e.env")" = 'APP_KEY=fresh'
check 'the copy is 0640 root:<app group>' test "$(stat -c '%u:%g %a' "${ENV_DIR}/e2e.env")" = "0:${OWNER_GID} 640"
check 'its folder is 0750 root:<app group>' test "$(stat -c '%u:%g %a' "${ENV_DIR}")" = "0:${OWNER_GID} 750"
copy_env 'APP_KEY=new'
check 'a second write replaces it' test "$(cat "${ENV_DIR}/e2e.env")" = 'APP_KEY=new'

fresh_case env-dir-link
OUTSIDE_STAT="$(stat -c '%u:%g %a' "${CASE}/outside")"
rm -rf -- "${ENV_ROOT:?}" && mkdir -m 755 "${ENV_ROOT}" && ln -s "${CASE}/outside" "${ENV_DIR}"
copy_env 'APP_KEY=planted'
check 'a link planted at the env folder is refused' test $? -ne 0
check 'and the refusal names it' grep -q "^${ENV_DIR} is a symbolic link" "${WORK}/env-stderr"
check 'and nothing is written behind it' test "$(ls -A "${CASE}/outside")" = sentinel
check "and the folder behind it keeps its owner and mode" test "$(stat -c '%u:%g %a' "${CASE}/outside")" = "${OUTSIDE_STAT}"
check "and the sentinel's content, owner and mode are unchanged" sentinel_untouched

# install -d re-owns a folder already there, so only a swap straight after it reaches the check.
RACE="${WORK}/race"
mkdir -m 755 "${RACE}"
cat >"${RACE}/install" <<RACE_SHIM
#!/bin/sh
for last; do :; done
${REAL_INSTALL} "\$@" || exit
[ "\$1" = -d ] && [ "\$last" = "${ENV_DIR}" ] || exit 0
case "\$RACE_MODE" in
    link) rm -rf -- "\$last" && ln -s "\$RACE_TARGET" "\$last" ;;
    nobody-owned) chown 65534 -- "\$last" ;;
esac
RACE_SHIM
chmod 755 "${RACE}/install"
for mode in nobody-owned link; do
    fresh_case "env-race-${mode}"
    rm -rf -- "${ENV_ROOT:?}"
    export RACE_MODE="${mode}" RACE_TARGET="${CASE}/outside"
    copy_env 'APP_KEY=raced' "${RACE}"
    check "an env folder made ${mode} right after install is refused" test $? -ne 0
    check 'and the refusal says why' grep -q 'is not a folder only root writes' "${WORK}/env-stderr"
    check 'and no copy is written' test ! -e "${ENV_DIR}/e2e.env" -a "$(ls -A "${CASE}/outside")" = sentinel
done
unset RACE_MODE RACE_TARGET

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
check 'e2e.sh writes the env only through e2e_env_copy_write' grep -qE '^ +e2e_env_copy_write "\$E2E_ENV_MOUNT" ' "${E2E_SH}"
check 'e2e.sh makes e2e/ only through e2e_hand_over_dirs' grep -qE '^e2e_hand_over_dirs ' "${E2E_SH}"
writes_there="$(grep -vE '^ *#' "${E2E_SH}" \
    | grep -nE '(>|\b(tee|cp|install|mv|chown|chmod|mkdir|touch|ln)\b)[^#]*(\.env\.e2e|(^|[ "'\''=])e2e(/|[ "'\'']|$))')"
check 'and nothing else writes, moves or chowns those paths' test -z "${writes_there}"
refusal_line="$(grep -nE '^e2e_refuse_links e2e e2e/artifacts e2e/baselines ' "${E2E_SH}" | cut -d: -f1)"
first_write="$(grep -nE '^    write_env_file$' "${E2E_SH}" | head -1 | cut -d: -f1)"
check 'e2e.sh refuses links up front, before the first write' \
    test -n "${refusal_line}" -a -n "${first_write}" -a "${refusal_line:-0}" -lt "${first_write:-0}"
owner_line="$(grep -nE '^e2e_refuse_foreign_owner "\$ROOT" "\$APP_UID" \|\| fail ' "${E2E_SH}" | cut -d: -f1)"
check 'e2e.sh refuses a tree uid 115 does not hold, before the first write' \
    test -n "${owner_line}" -a -n "${first_write}" -a "${owner_line:-0}" -lt "${first_write:-0}"
dir_line="$(grep -nE '^e2e_env_copy_dir "\$E2E_ENV_ROOT" "\$E2E_ENV_DIR" "\$APP_GID" \|\| fail ' "${E2E_SH}" | cut -d: -f1)"
check "e2e.sh checks the env folder is root's alone, before the first write" \
    test -n "${dir_line}" -a -n "${first_write}" -a "${dir_line:-0}" -lt "${first_write:-0}"
check 'e2e.sh keeps the env under a pinned folder, read from no caller' grep -qx 'E2E_ENV_ROOT=/run/orbit-e2e' "${E2E_SH}"
check 'the browser reads the copy, mounted read-only outside the tree' \
    grep -qF -- '-v "${E2E_ENV_MOUNT}":/run/orbit-e2e.env:ro' "${E2E_SH}"
check 'and nothing in e2e.sh reads or writes .env.e2e' \
    test -z "$(grep -vE '^ *#' "${E2E_SH}" | grep -F '.env.e2e')"

if [ "$fails" -gt 0 ]; then
    printf '\n%d check(s) failed\n' "$fails" >&2
    exit 1
fi
printf '\nall checks passed\n'
