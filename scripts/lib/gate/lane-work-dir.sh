# shellcheck shell=bash
# A harness's root 700 work dir goes beside its tree, in that tree's lane. docs/DECISIONS.md, gate-work-dir-in-the-lane
GATE_SCRATCH_ROOT=/srv/worker-scratch

gate_lane_work_dir() { # <tree> <prefix>: prints the new dir
    local parent scratch
    parent="$(cd -- "$1/.." && pwd -P)" || return 1
    scratch="$(cd -- "${GATE_SCRATCH_ROOT}" 2>/dev/null && pwd -P)" || scratch="${GATE_SCRATCH_ROOT}"
    if [ "${parent}" = "${scratch}" ]; then
        printf 'REFUSED: %s sits directly in the shared scratch root %s, so its %s work dir would land there loose; run it from %s/<lane>/repo.\n' \
            "$1" "${scratch}" "$2" "${scratch}" >&2
        return 1
    fi
    mktemp -d "${parent}/$2.XXXXXXXX"
}
