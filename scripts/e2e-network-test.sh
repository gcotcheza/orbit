#!/usr/bin/env bash
# Guards the browser container's network in scripts/e2e.sh: that it joins the
# network compose made for the sandbox and never the host's, that the app's
# hostname is answered there, and that the suite's port is the one APP_URL names.
#
#   scripts/e2e-network-test.sh                     the whole list
#   E2E_SH=/path/e2e.sh scripts/e2e-network-test.sh  same list against a mutated copy
#   (E2E_PATHS, E2E_CONFIG and E2E_COMPOSE take a copy the same way)
#
# It reads four files and talks to no docker daemon.
set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
E2E_SH="${E2E_SH:-${SCRIPT_DIR}/e2e.sh}"
E2E_PATHS="${E2E_PATHS:-${SCRIPT_DIR}/../e2e/paths.js}"
E2E_CONFIG="${E2E_CONFIG:-${SCRIPT_DIR}/../e2e/playwright.config.js}"
E2E_COMPOSE="${E2E_COMPOSE:-${SCRIPT_DIR}/../docker-compose.e2e.yml}"

fails=0
pass() { printf 'ok   %s\n' "$*"; }
fail() { printf 'FAIL %s\n' "$*" >&2; fails=$((fails + 1)); }

somewhere() { # LABEL FILE NEEDLE
    if grep -qF -- "$3" "$2"; then pass "$1"; else fail "$1 — no [$3] in $2"; fi
}

nowhere() { # LABEL FILE NEEDLE
    local hits
    hits="$(grep -nF -- "$3" "$2")"
    if [ -z "$hits" ]; then pass "$1"; else fail "$1 — [$3] at:"$'\n'"$hits"; fi
}

equals() {
    if [ -n "$2" ] && [ "$2" = "$3" ]; then pass "$1 is [$3]"; else fail "$1 is [$2], expected [$3]"; fi
}

for file in "${E2E_SH}" "${E2E_PATHS}" "${E2E_CONFIG}" "${E2E_COMPOSE}"; do
    [ -f "${file}" ] || { printf 'FAIL %s is not a file\n' "${file}" >&2; exit 1; }
done

# The browser's own `docker run` and nothing else: the install runs are indented.
BROWSER="$(mktemp)"
trap 'rm -f "${BROWSER}"' EXIT
sed -n '/^docker run --rm/,/playwright test/p' "${E2E_SH}" >"${BROWSER}"
[ -s "${BROWSER}" ] || fail "no browser \`docker run\` found in ${E2E_SH}"

# --- 1. the browser is never in the host's network namespace -------------------
# There, any container start or stop on this box is net::ERR_NETWORK_CHANGED.
nowhere 'nothing in the gate puts the browser on the host network' "${E2E_SH}" '--network host'
nowhere 'and it needs no host-file entry for the app' "${E2E_SH}" '--add-host'
nowhere 'nor a resolver rule mapping the app to a loopback' "${E2E_CONFIG}" 'host-resolver-rules'

# --- 2. it joins the network compose made for the sandbox ----------------------
somewhere 'the browser run names the sandbox network' "${BROWSER}" '--network "$E2E_NETWORK"'
somewhere 'which is read back off the compose project label' "${E2E_SH}" \
    'docker network ls --filter "label=com.docker.compose.project=${E2E_PROJECT}"'
# The comparison itself: `-ge 1` would take the first of two networks.
somewhere 'anything but exactly one network refuses' "${E2E_SH}" '| grep -c .)" -eq 1 ]'
somewhere 'and the refusal says so in words' "${E2E_SH}" 'exactly one network'

# --- 3. the project is the sandbox's, never production's ----------------------
# The same name reaches `down -v`: under `orbit` that is production's volumes.
PROJECT="$(sed -n "s/^E2E_PROJECT='\([A-Za-z0-9._-]\+\)'.*/\1/p" "${E2E_SH}")"
equals 'the compose project' "${PROJECT}" 'orbit-e2e'
somewhere 'and compose is driven by it' "${E2E_SH}" 'COMPOSE=(docker compose -p "$E2E_PROJECT" '

# --- 4. the app's hostname is answered on that network, by the web container ---
# Unanswered, it falls through to public DNS and the live site's address.
HOST="$(sed -n "s/^E2E_HOST='\([A-Za-z0-9.-]\+\)'.*/\1/p" "${E2E_SH}")"
ALIAS="$(sed -n '/^  web:/,/^  [a-z]/{/aliases:/,/^ *[a-z_]*:$/{s/^ *- *'"'"'\([A-Za-z0-9.-]\+\)'"'"'.*/\1/p}}' "${E2E_COMPOSE}")"
equals 'the web container answers the gate hostname' "${ALIAS}" "${HOST}"
somewhere 'and the gate refuses unless the name resolves to it' "${E2E_SH}" '[ "$RESOLVED" = "$WEB_ADDRESS" ]'

# --- 5. the app's origin: one port, written in two files ----------------------
somewhere 'APP_URL carries the in-network port' "${E2E_SH}" 'APP_URL=http://${E2E_HOST}:${E2E_APP_PORT}'
somewhere 'and so does Sanctum' "${E2E_SH}" 'SANCTUM_STATEFUL_DOMAINS=${E2E_HOST}:${E2E_APP_PORT},'
GATE_PORT="$(sed -n "s/^E2E_APP_PORT='\([0-9]\+\)'.*/\1/p" "${E2E_SH}")"
SUITE_PORT="$(sed -n 's/^const PORT = \([0-9]\+\).*/\1/p' "${E2E_PATHS}")"
SUITE_HOST="$(sed -n "s/^const HOST = '\([A-Za-z0-9.-]\+\)'.*/\1/p" "${E2E_PATHS}")"
equals 'the gate port' "${GATE_PORT}" '8080'
equals 'the port the suite asks for' "${SUITE_PORT}" "${GATE_PORT}"
equals 'the host the suite asks for' "${SUITE_HOST}" "${HOST}"

# --- 6. the browser container's caps (T7) -------------------------------------
somewhere 'the browser container is capped at 2 GB' "${BROWSER}" '--memory=2g'
somewhere 'Chromium spills shared memory to /tmp' "${E2E_CONFIG}" "'--disable-dev-shm-usage'"
WORKERS="$(sed -n 's/^ *workers: \([0-9]\+\),.*/\1/p' "${E2E_CONFIG}")"
if [ -n "${WORKERS}" ] && [ "${WORKERS}" -le 2 ]; then pass "workers is ${WORKERS}"; else fail "workers is [${WORKERS}], at most 2"; fi

if [ "${fails}" -eq 0 ]; then
    printf '\ne2e-network-test: all checks passed\n'
    exit 0
fi
printf '\ne2e-network-test: %s check(s) failed\n' "${fails}" >&2
exit 1
