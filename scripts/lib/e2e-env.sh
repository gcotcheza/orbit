# shellcheck shell=bash
# Sourced by scripts/e2e.sh, which runs as root inside an app-owned tree; root itself
# writes, chowns and chmods nothing there. decision: e2e-writes-refuse-links

e2e_refuse_links() { # PATH...
    local p
    for p in "$@"; do
        if [ -L "$p" ]; then
            printf '%s is a symbolic link; refusing to write through it\n' "$p" >&2
            return 1
        fi
    done
}

e2e_as_owner() { # UID GID COMMAND... — as that uid when root, as the caller otherwise
    local uid=$1 gid=$2
    shift 2
    if [ "$(id -u)" -eq 0 ]; then
        setpriv --reuid="$uid" --regid="$gid" --clear-groups -- "$@"
    else
        "$@"
    fi
}

e2e_env_place() { # TARGET UID GID — the content arrives on stdin
    e2e_refuse_links "$1" || return 1
    if [ -e "$1" ] && [ ! -f "$1" ]; then
        printf '%s is not a regular file; refusing to write into it\n' "$1" >&2
        return 1
    fi
    e2e_as_owner "$2" "$3" install -m 600 /dev/null "$1" \
        && e2e_as_owner "$2" "$3" tee "$1" >/dev/null
}

e2e_hand_over_dirs() { # UID GID — e2e/artifacts and e2e/baselines, made as the browser's user
    e2e_refuse_links e2e e2e/artifacts e2e/baselines || return 1
    e2e_as_owner "$1" "$2" mkdir -p e2e/artifacts e2e/baselines
}
