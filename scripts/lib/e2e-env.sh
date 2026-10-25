# shellcheck shell=bash
# Sourced by scripts/e2e.sh, which runs as root inside an app-owned tree: a link
# planted at a path it writes would aim root's write anywhere. decision: e2e-writes-refuse-links

e2e_refuse_links() { # PATH...
    local p
    for p in "$@"; do
        if [ -L "$p" ]; then
            printf '%s is a symbolic link; refusing to write through it\n' "$p" >&2
            return 1
        fi
    done
}

# The content arrives on stdin; root only ever writes into its own mktemp -d.
e2e_env_place() { # TARGET UID GID
    local dir rc own=()
    e2e_refuse_links "$1" || return 1
    [ "$(id -u)" -ne 0 ] || own=(-o "$2" -g "$3")
    dir=$(mktemp -d) || return 1
    cat >"$dir/env" && install "${own[@]}" -m 600 "$dir/env" "$1"
    rc=$?
    rm -rf -- "${dir:?}"
    return "$rc"
}

e2e_hand_over_dirs() { # UID GID — e2e/artifacts and e2e/baselines, made and handed to the browser's user
    e2e_refuse_links e2e e2e/artifacts e2e/baselines || return 1
    mkdir -p e2e/artifacts e2e/baselines || return 1
    [ "$(id -u)" -ne 0 ] || chown -R -h "$1:$2" e2e
}
