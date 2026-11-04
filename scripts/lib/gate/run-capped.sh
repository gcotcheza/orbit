# shellcheck shell=bash
# Without -k, timeout waits forever on a child that outlives its TERM (GNU and uutils alike).
# Call it as `run_capped ... &`: exec makes $! the session leader, so `kill -- -$!` reaches all of it.
RUN_CAPPED_GRACE=10

run_capped() { # <seconds> <command...>
    exec setsid timeout -k "${RUN_CAPPED_GRACE}" "$@"
}

# 124 and 137 are timeout's own endings; a run stopped early by its caller can end either way too.
run_capped_timed_out() { # <rc>
    [ "$1" -eq 124 ] || [ "$1" -eq 137 ]
}
