#!/usr/bin/env bash
# usage: scripts/worktree.sh add <branch> [base-ref] | list | remove <branch> | help
# The clone is fixed and never read from this file's own path: docs/DECISIONS.md, worktrees-are-cut-from-the-root-owned-clone
set -Eeuo pipefail

CLONE=${ORBIT_CLONE:-/srv/sessions/orbit/repo}
WORKTREES_ROOT=${ORBIT_WORKTREES_ROOT:-/srv/sessions/orbit/worktrees}

if [ -t 1 ]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'; C_RED=$'\033[31m'; C_BLUE=$'\033[34m'
else
    C_RESET=''; C_BOLD=''; C_DIM=''; C_RED=''; C_BLUE=''
fi
say()  { printf '%s==>%s %s\n' "${C_BLUE}${C_BOLD}" "${C_RESET}" "$*"; }
note() { printf '%s      %s%s\n' "${C_DIM}" "$*" "${C_RESET}"; }
die()  { printf '%s[error]%s %s\n' "${C_RED}${C_BOLD}" "${C_RESET}" "$*" >&2; exit 1; }

usage() {
    printf 'usage: scripts/worktree.sh add <branch> [base-ref]\n'
    printf '       scripts/worktree.sh list\n'
    printf '       scripts/worktree.sh remove <branch>\n\n'
    printf 'Cuts from the root-owned clone %s into %s, off origin/main unless a\n' "${CLONE}" "${WORKTREES_ROOT}"
    printf 'base-ref is named. /var/www/orbit is production and is never either end.\n'
}

served() {
    case "$(realpath -m -- "$1")" in
        /var/www | /var/www/*) return 0 ;;
    esac
    return 1
}

# 124 is GNU timeout, 143 a killed docker; neither answer is "no containers".
docker_answer() {
    case $1 in
        124 | 143) printf 'did not answer within 10s' ;;
        *) printf 'exited %s' "$1" ;;
    esac
}

sanitize() {
    printf '%s' "$1" | tr '[:upper:]' '[:lower:]' \
        | sed -e 's/[^a-z0-9_-]\+/-/g' -e 's/^[^a-z0-9]\+//' -e 's/-\+$//'
}

CMD=${1-}
case "${CMD}" in
    -h | --help | help) usage; exit 0 ;;
    '') usage >&2; exit 1 ;;
esac
shift

[ "$(id -u)" = 0 ] || die 'Run this as root: the clone and every worktree cut from it are root-owned.'

for path in "${CLONE}" "${WORKTREES_ROOT}"; do
    if served "${path}"; then
        die "${path} resolves under /var/www, which is the served tree; this script never reads or writes production."
    fi
done

[ -d "${CLONE}" ] || die "${CLONE} does not exist. Name the clone with ORBIT_CLONE."
[ "$(stat -c '%u' "${CLONE}")" = 0 ] || die "${CLONE} is not root-owned, so it is not the clone this script works from."
git -C "${CLONE}" rev-parse --git-dir >/dev/null 2>&1 || die "${CLONE} is not a git repository."

case "${CMD}" in
    add)
        BRANCH=${1-}
        [ -n "${BRANCH}" ] || die 'Usage: scripts/worktree.sh add <branch> [base-ref]'
        NAME=$(sanitize "${BRANCH}")
        [ -n "${NAME}" ] || die "Branch '${BRANCH}' sanitizes to nothing a directory can be named."
        DIR="${WORKTREES_ROOT}/${NAME}"
        if [ -e "${DIR}" ] || [ -L "${DIR}" ]; then die "${DIR} already exists."; fi

        BASE=${2-}
        if [ -z "${BASE}" ]; then
            say "fetching origin in ${CLONE}"
            git -C "${CLONE}" fetch origin
            BASE=origin/main
        fi
        BASE_SHA=$(git -C "${CLONE}" rev-parse --short "${BASE}") || die "${CLONE} cannot resolve '${BASE}'."

        mkdir -p "${WORKTREES_ROOT}"
        say "worktree ${DIR}"
        note "branch ${BRANCH} from ${BASE} (${BASE_SHA})"
        git -C "${CLONE}" worktree add -b "${BRANCH}" "${DIR}" "${BASE}"

        echo
        say 'next:'
        note "cd ${DIR}"
        note "COMPOSE_PROJECT_NAME=orbit-${NAME} docker compose -f docker-compose.yml -f docker-compose.ci.yml up -d postgres redis app"
        note 'bash scripts/check.sh overlay'
        ;;

    remove | rm)
        BRANCH=${1-}
        [ -n "${BRANCH}" ] || die 'Usage: scripts/worktree.sh remove <branch>'
        NAME=$(sanitize "${BRANCH}")
        [ -n "${NAME}" ] || die "Branch '${BRANCH}' sanitizes to nothing a directory can be named."
        DIR="${WORKTREES_ROOT}/${NAME}"
        [ -d "${DIR}" ] || die "No worktree at ${DIR}."

        # compose stamps the resolved directory it was run from on every container
        # it made; one still up is why a removal half-succeeds.
        REAL_DIR=$(readlink -f -- "${DIR}" 2>/dev/null || printf '%s' "${DIR}")
        PROJECTS=$(timeout 10 docker ps -a \
            --filter "label=com.docker.compose.project.working_dir=${REAL_DIR}" \
            --format '{{.Label "com.docker.compose.project"}}' | sort -u) && DOCKER_RC=0 || DOCKER_RC=$?
        if [ "${DOCKER_RC}" -ne 0 ]; then
            die "docker $(docker_answer "${DOCKER_RC}"), so which containers came up from ${DIR} is unknown; nothing was removed."
        fi
        if [ -n "${PROJECTS}" ]; then
            printf 'Containers are still up from %s. Take each stack down yourself, then run this again:\n' "${DIR}" >&2
            while IFS= read -r project; do
                if [ -n "${project}" ]; then printf '  docker compose -p %s down -v\n' "${project}" >&2; fi
            done <<<"${PROJECTS}"
            exit 1
        fi

        say "removing ${DIR}"
        git -C "${CLONE}" worktree remove "${DIR}"
        git -C "${CLONE}" worktree prune
        note "the branch is left behind: git -C ${CLONE} branch -d ${BRANCH}"
        ;;

    list | ls) git -C "${CLONE}" worktree list ;;
    *) die "Unknown command '${CMD}'. Try: add, list, remove, help" ;;
esac
