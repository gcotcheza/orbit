#!/usr/bin/env bash
# Guards scripts/worktree.sh against a throwaway clone in a temp directory, a
# fake docker and a fake git that records every path git is handed.
#
#   scripts/worktree-test.sh
#   cp -r scripts /tmp/o && WORKTREE_SH=/tmp/o/worktree.sh scripts/worktree-test.sh
#
# It never writes to /var/www, never runs docker and never reaches the network.
set -uo pipefail

if [ "$(id -u)" != 0 ]; then
    printf 'worktree-test.sh needs root: it builds a root-owned clone and drops to nobody to prove one refusal.\n' >&2
    exit 1
fi

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
WORKTREE_SH="${WORKTREE_SH:-${SCRIPT_DIR}/worktree.sh}"
REAL_GIT="$(command -v git)"

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

exists()  { if [ -e "$2" ]; then pass "$1"; else fail "$1 — $2 is not there"; fi; }
missing() { if [ -e "$2" ]; then fail "$1 — $2 is still there"; else pass "$1"; fi; }
nonzero() { if [ "$2" -ne 0 ]; then pass "$1 refused (exit $2)"; else fail "$1 exited 0 and had to refuse"; fi; }

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
chmod 711 "${WORK}"

BIN="${WORK}/bin"
mkdir -p "${BIN}"
export FAKE_DOCKER_LOG="${WORK}/docker.argv"
export GIT_ARGV_LOG="${WORK}/git.argv"
export FAKE_DOCKER_PROJECTS=''
export FAKE_DOCKER_DIR=''
export FAKE_DOCKER_EXIT=''

cat >"${BIN}/docker" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${FAKE_DOCKER_LOG}"
[ -z "${FAKE_DOCKER_EXIT:-}" ] || exit "${FAKE_DOCKER_EXIT}"
[ -n "${FAKE_DOCKER_PROJECTS:-}" ] || exit 0
case "$*" in
    *"working_dir=${FAKE_DOCKER_DIR:-}"*)
        for p in ${FAKE_DOCKER_PROJECTS}; do printf '%s\n' "$p"; done ;;
esac
exit 0
SH

cat >"${BIN}/git" <<SH
#!/bin/sh
printf '%s\n' "\$*" >> "\${GIT_ARGV_LOG}"
exec ${REAL_GIT} "\$@"
SH
chmod 755 "${BIN}/docker" "${BIN}/git"

export GIT_CONFIG_GLOBAL="${WORK}/gitconfig"
export GIT_CONFIG_NOSYSTEM=1
export GIT_AUTHOR_NAME='Orbit Test' GIT_AUTHOR_EMAIL='test@example.invalid'
export GIT_COMMITTER_NAME='Orbit Test' GIT_COMMITTER_EMAIL='test@example.invalid'
: >"${GIT_CONFIG_GLOBAL}"

UPSTREAM="${WORK}/upstream"
CLONE="${WORK}/clone"
WT="${WORK}/worktrees"
mkdir -p "${UPSTREAM}"
"${REAL_GIT}" -C "${UPSTREAM}" init -q -b main
printf 'vendor/\n' >"${UPSTREAM}/.gitignore"
printf 'orbit\n' >"${UPSTREAM}/README.md"
"${REAL_GIT}" -C "${UPSTREAM}" add .gitignore README.md
"${REAL_GIT}" -C "${UPSTREAM}" commit -qm 'first'
"${REAL_GIT}" clone -q "${UPSTREAM}" "${CLONE}"
ORIGIN_MAIN="$("${REAL_GIT}" -C "${CLONE}" rev-parse origin/main)"

export PATH="${BIN}:${PATH}"
export ORBIT_CLONE="${CLONE}"
export ORBIT_WORKTREES_ROOT="${WT}"

OUT=''
RC=0
run_script() {
    local script="$1"
    shift
    : >"${GIT_ARGV_LOG}"
    : >"${FAKE_DOCKER_LOG}"
    OUT="$(bash "${script}" "$@" 2>&1)"
    RC=$?
}
run() { run_script "${WORKTREE_SH}" "$@"; }

run add feat/My-Thing
equals 'add exit status' "${RC}" 0
exists 'add created the worktree under ORBIT_WORKTREES_ROOT' "${WT}/feat-my-thing"
equals 'the worktree sits on origin/main' \
    "$("${REAL_GIT}" -C "${CLONE}" rev-parse feat/My-Thing 2>/dev/null)" "${ORIGIN_MAIN}"
contains 'add fetched origin first' "$(cat "${GIT_ARGV_LOG}")" 'fetch origin'
contains 'add prints the sandbox line' "${OUT}" \
    'COMPOSE_PROJECT_NAME=orbit-feat-my-thing docker compose -f docker-compose.yml -f docker-compose.ci.yml up -d --build postgres redis app'
contains 'add prints the overlay gate' "${OUT}" 'bash scripts/check.sh overlay'

run add feat/My-Thing
nonzero 'add over an existing directory' "${RC}"
contains 'add says the directory is taken' "${OUT}" 'already exists'

SAVED_CLONE="${ORBIT_CLONE}"
export ORBIT_CLONE="${WORK}/no-such-clone"
run list
nonzero 'a clone that is not there' "${RC}"
contains 'it says the clone is missing' "${OUT}" 'does not exist'

mkdir -p "${WORK}/plain"
export ORBIT_CLONE="${WORK}/plain"
run list
nonzero 'a clone that is no repository' "${RC}"
contains 'it says the clone is no repository' "${OUT}" 'not a git repository'

mkdir -p "${WORK}/theirs"
chown 65534:65534 "${WORK}/theirs"
export ORBIT_CLONE="${WORK}/theirs"
run list
nonzero 'a clone owned by someone else' "${RC}"
contains 'it says the clone is not root-owned' "${OUT}" 'not root-owned'
export ORBIT_CLONE="${SAVED_CLONE}"

mkdir -p "${WORK}/elsewhere/scripts"
cp "${WORKTREE_SH}" "${WORK}/elsewhere/scripts/worktree.sh"
"${REAL_GIT}" -C "${WORK}/elsewhere" init -q -b main
run_script "${WORK}/elsewhere/scripts/worktree.sh" add feat/from-a-copy
equals 'a copy placed elsewhere still exits 0' "${RC}" 0
exists 'a copy placed elsewhere cuts from ORBIT_CLONE' "${WT}/feat-from-a-copy"
missing 'a copy placed elsewhere touches no tree beside itself' "${WORK}/elsewhere-worktrees"
equals 'the branch landed in the clone, not beside the copy' \
    "$("${REAL_GIT}" -C "${WORK}/elsewhere" rev-parse --verify -q feat/from-a-copy 2>/dev/null)" ''

# `list` and not `add`: the guard runs before the command, so a mutant that
# loses it must still not be able to write under /var/www from this harness.
SAVED_CLONE="${ORBIT_CLONE}"
export ORBIT_CLONE='/var/www/orbit'
run list
nonzero 'a clone under /var/www' "${RC}"
contains 'the clone refusal is the served-tree guard, not a later one' "${OUT}" 'which is the served tree'
absent 'no git call was handed /var/www' "$(cat "${GIT_ARGV_LOG}")" '/var/www'
export ORBIT_CLONE="${SAVED_CLONE}"

SAVED_WT="${ORBIT_WORKTREES_ROOT}"
export ORBIT_WORKTREES_ROOT='/var/www/orbit-worktrees'
run list
nonzero 'a worktree root under /var/www' "${RC}"
contains 'the root refusal is the served-tree guard, not a later one' "${OUT}" 'which is the served tree'
absent 'no git call was handed the /var/www root' "$(cat "${GIT_ARGV_LOG}")" '/var/www'
export ORBIT_WORKTREES_ROOT="${SAVED_WT}"

export FAKE_DOCKER_DIR="${WT}/feat-my-thing"
export FAKE_DOCKER_PROJECTS='orbit-feat-my-thing orbit-gate-my-thing'
run remove feat/My-Thing
nonzero 'remove with containers still up' "${RC}"
contains 'it hands over the first teardown line' "${OUT}" 'docker compose -p orbit-feat-my-thing down -v'
contains 'it hands over the second teardown line' "${OUT}" 'docker compose -p orbit-gate-my-thing down -v'
exists 'the worktree survives a refused remove' "${WT}/feat-my-thing"
absent 'the script ran no teardown itself' "$(cat "${FAKE_DOCKER_LOG}")" 'down'
absent 'the script ran no volume command' "$(cat "${FAKE_DOCKER_LOG}")" 'volume'
absent 'the script ran no prune' "$(cat "${FAKE_DOCKER_LOG}")" 'prune'
export FAKE_DOCKER_PROJECTS='' FAKE_DOCKER_DIR=''

FAKE_DOCKER_EXIT=124
run remove feat/My-Thing
nonzero 'remove when docker does not answer' "${RC}"
contains 'a timeout reads as unknown, not as no containers' "${OUT}" 'did not answer within 10s'
exists 'the worktree survives a docker that timed out' "${WT}/feat-my-thing"
FAKE_DOCKER_EXIT=''

printf 'unsaved\n' >"${WT}/feat-my-thing/scratch.txt"
run remove feat/My-Thing
nonzero 'remove over an untracked file' "${RC}"
exists 'the untracked work is still on disk' "${WT}/feat-my-thing/scratch.txt"
rm -f "${WT}/feat-my-thing/scratch.txt"

mkdir -p "${WT}/feat-my-thing/vendor"
printf 'built\n' >"${WT}/feat-my-thing/vendor/autoload.php"
run remove feat/My-Thing
equals 'remove past an ignored build directory' "${RC}" 0
missing 'the worktree is gone' "${WT}/feat-my-thing"
contains 'remove leaves the branch and says so' "${OUT}" "branch -d feat/My-Thing"
equals 'the branch is still there' \
    "$("${REAL_GIT}" -C "${CLONE}" rev-parse feat/My-Thing 2>/dev/null)" "${ORIGIN_MAIN}"

run remove ///
nonzero 'remove with a branch name that sanitizes to nothing' "${RC}"
contains 'it says the name is unusable' "${OUT}" 'sanitizes to nothing'

ln -s "${WT}" "${WORK}/linked-worktrees"
ORBIT_WORKTREES_ROOT="${WORK}/linked-worktrees"
run add feat/symlinked
equals 'add through a symlinked worktree root' "${RC}" 0
FAKE_DOCKER_DIR="$(readlink -f "${WT}")/feat-symlinked"
FAKE_DOCKER_PROJECTS='orbit-symlinked'
run remove feat/symlinked
nonzero 'remove through a symlinked worktree root' "${RC}"
contains 'docker was asked about the resolved directory' "${OUT}" \
    'docker compose -p orbit-symlinked down -v'
exists 'the symlinked worktree survives' "${WT}/feat-symlinked"
FAKE_DOCKER_PROJECTS='' FAKE_DOCKER_DIR=''
ORBIT_WORKTREES_ROOT="${WT}"

if [ -x /usr/bin/setpriv ]; then
    cp "${WORKTREE_SH}" "${WORK}/as-nobody.sh"
    chmod 755 "${WORK}/as-nobody.sh"
    OUT="$(/usr/bin/setpriv --reuid=65534 --regid=65534 --clear-groups \
        bash "${WORK}/as-nobody.sh" list 2>&1)"
    RC=$?
    nonzero 'a run by a non-root user' "${RC}"
    contains 'the refusal says root' "${OUT}" 'root'
else
    fail 'setpriv is missing, so the non-root refusal was not exercised'
fi

SOURCE="$(cat "${WORKTREE_SH}")"
absent 'worktree.sh never forces a removal' "${SOURCE}" '--force'
absent 'worktree.sh never names a docker volume' "${SOURCE}" 'docker volume'

if [ "${fails}" -ne 0 ]; then
    printf '\n%s check(s) failed\n' "${fails}" >&2
    exit 1
fi
printf '\nworktree.sh: all checks passed\n'
