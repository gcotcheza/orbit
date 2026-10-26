# shellcheck shell=bash
# Sourced by scripts/e2e.sh, which runs as root inside an app-owned tree; root itself
# writes, chowns and chmods nothing there, and keeps the env in /run. decision: e2e-writes-refuse-links

e2e_refuse_links() { # PATH...
    local p
    for p in "$@"; do
        if [ -L "$p" ]; then
            printf '%s is a symbolic link; refusing to write through it\n' "$p" >&2
            return 1
        fi
    done
}

e2e_refuse_foreign_owner() { # DIR UID — every file here is made as UID, so the tree must be its
    local owner
    owner="$(stat -c %u -- "$1")" || return 1
    if [ "$owner" != "$2" ]; then
        printf '%s is owned by uid %s; the browser gate makes its files as uid %s, so it needs a tree uid %s owns\n' \
            "$1" "$owner" "$2" "$2" >&2
        return 1
    fi
}

e2e_as_owner() { # UID GID COMMAND... — root drops to that uid; e2e.sh runs only as root
    local uid=$1 gid=$2
    shift 2
    setpriv --reuid="$uid" --regid="$gid" --clear-groups -- "$@"
}

e2e_env_copy_dir() { # PARENT DIR GID — DIR made 0750 root:GID under a root-only PARENT, and checked after
    e2e_refuse_links "$1" "$2" || return 1
    install -d -o 0 -g 0 -m 0755 -- "$1" && install -d -o 0 -g "$3" -m 0750 -- "$2" || return 1
    if [ -L "$1" ] || [ -L "$2" ] || [ "$(stat -c '%u' -- "$1")" != 0 ] || [ "$(stat -c '%u:%a' -- "$2")" != 0:750 ]; then
        printf '%s is not a folder only root writes; refusing to keep the e2e env there\n' "$2" >&2
        return 1
    fi
}

e2e_env_copy_write() { # FILE GID — the content arrives on stdin; FILE is 0640 root:GID
    rm -f -- "$1" && install -m 0640 -o 0 -g "$2" /dev/null "$1" && cat >"$1"
}

e2e_hand_over_dirs() { # UID GID — e2e/artifacts and e2e/baselines, made as the browser's user
    e2e_refuse_links e2e e2e/artifacts e2e/baselines || return 1
    e2e_as_owner "$1" "$2" mkdir -p e2e/artifacts e2e/baselines
}
