# shellcheck shell=bash
# The deploy lib lives once, in root's engineering-standards clone; never vendored here. docs/DECISIONS.md, deploy-lib-is-not-vendored
# Pinned, never read from the caller: check.sh and e2e.sh write the ledger row a deploy trusts through it.
DEPLOY_LIB_CLONE=/srv/engineering-standards
DEPLOY_LIB=/srv/engineering-standards/scripts/lib/deploy
DEPLOY_LIB_SEAM_REFUSED=''
if [[ -n "${DEPLOY_LIB_TEST_CLONE+x}" ]]; then
    if [[ "${DEPLOY_LIB_HARNESS:-}" != deploy-lib-test ]] || (( EUID == 0 )); then
        DEPLOY_LIB_SEAM_REFUSED='DEPLOY_LIB_TEST_CLONE is set, and it is honoured only for deploy-lib-test.sh run unprivileged'
    else
        DEPLOY_LIB_CLONE=$DEPLOY_LIB_TEST_CLONE
        DEPLOY_LIB=$DEPLOY_LIB_CLONE/scripts/lib/deploy
    fi
fi

deploy_lib_ready() {
    local file head main dirty
    if [[ -n "$DEPLOY_LIB_SEAM_REFUSED" ]]; then
        printf 'REFUSED: %s. It would hand this run a stand-in deploy lib; unset it.\n' "$DEPLOY_LIB_SEAM_REFUSED" >&2
        return 1
    fi
    for file in summary.sh resolve.sh ledger.sh preflight.sh compose.sh; do
        [[ -s "$DEPLOY_LIB/$file" ]] && continue
        printf 'The deploy lib is not here: %s/%s is missing or empty, so nothing was read from it.\n' "$DEPLOY_LIB" "$file" >&2
        printf '  The gate reads it from /srv/engineering-standards; a deploy reads the copy fleet-deploy lays beside deploy.sh.\n' >&2
        return 1
    done
    head=$(/usr/bin/git --git-dir="$DEPLOY_LIB_CLONE/.git" rev-parse -q --verify HEAD 2>/dev/null) || head=''
    main=$(/usr/bin/git --git-dir="$DEPLOY_LIB_CLONE/.git" rev-parse -q --verify refs/remotes/origin/main 2>/dev/null) || main=''
    if [[ -z "$head" || "$head" != "$main" ]]; then
        printf 'REFUSED: the deploy lib is not what a deploy reads: %s is at %s, its origin/main at %s.\n' "$DEPLOY_LIB_CLONE" "${head:-nothing}" "${main:-nothing}" >&2
        printf '  fleet-deploy and the rollback read origin/main, so a gate on this working tree would judge another lib.\n' >&2
        return 1
    fi
    if ! dirty=$(/usr/bin/git --no-optional-locks --git-dir="$DEPLOY_LIB_CLONE/.git" --work-tree="$DEPLOY_LIB_CLONE" -c core.fsmonitor=false \
        status --porcelain --untracked-files=all -- scripts/lib/deploy); then
        printf 'REFUSED: git status failed in %s, so its deploy lib cannot be shown to be origin/main.\n' "$DEPLOY_LIB_CLONE" >&2
        return 1
    fi
    if [[ -n "$dirty" ]]; then
        printf 'REFUSED: the deploy lib in %s differs from its origin/main:\n%s\n' "$DEPLOY_LIB_CLONE" "$dirty" >&2
        printf '  fleet-deploy exports origin/main, so a gate on this working tree would judge another lib.\n' >&2
        return 1
    fi
}
