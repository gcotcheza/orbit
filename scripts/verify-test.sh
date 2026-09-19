#!/usr/bin/env bash
# Guards scripts/verify.sh — the post-deploy battery — against fakes in a temp
# directory. It is the parsing that needed a test: a `docker compose ps` layout,
# a StartedAt through `date -d`, an awk over log timestamps, and the cookie lift.
#
#   scripts/verify-test.sh
#   cp -r scripts /tmp/o && VERIFY_SH=/tmp/o/verify.sh scripts/verify-test.sh
#
# It never reads /var/www/orbit, never runs docker and never reaches the network.
set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
VERIFY_SH="${VERIFY_SH:-${SCRIPT_DIR}/verify.sh}"

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

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

NOW=$(date -u +%s)
iso()   { date -u -d "@$1" '+%Y-%m-%dT%H:%M:%S.000000000Z'; }
logts() { date -u -d "@$1" '+%F %T'; }

T_OLD=$((NOW - 3600))
T_RESTART=$((NOW - 60))
STARTED_OLD="app=$(iso "$T_OLD");horizon=$(iso "$T_OLD");scheduler=$(iso "$T_OLD");web=$(iso "$T_OLD")"
STARTED_NEW="app=$(iso "$T_RESTART");horizon=$(iso "$T_RESTART");scheduler=$(iso "$T_RESTART");web=$(iso "$T_RESTART")"
LOG_CLEAN="[$(logts "$T_OLD")] production.INFO: a poll before the restart"
LOG_DIRTY="[$(logts "$T_OLD")] production.ERROR: before the restart, so it does not count
[$(logts "$NOW")] production.ERROR: after the restart, so it does"

write_fakes() {
    cat >"${BIN}/docker" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${FAKE_LOG_DIR}/docker.argv"
field() { printf '%s' "$2" | tr ';' '\n' | sed -n "s/^$1=//p"; }
case "$1" in
    inspect) field "$4" "${FAKE_STARTED:-}"; exit 0 ;;
    compose) shift ;;
    *) exit 0 ;;
esac
case "$*" in
    'ps -q '*) printf '%s\n' "${*#ps -q }" ;;
    'ps web')
        printf 'NAME         IMAGE   COMMAND  SERVICE  CREATED  STATUS     PORTS\n'
        printf 'orbit-web-1  nginx   "sh"     web      2 days   Up 2 days  %s\n' \
            "${FAKE_WEB_PORTS:-127.0.0.1:3085->8080/tcp}" ;;
    'ps '*)
        svc=${*#ps }
        st=$(field "$svc" "${FAKE_STATUS:-}")
        [ -n "$st" ] || st='Up 2 days (healthy)'
        printf 'NAME           IMAGE  COMMAND  SERVICE  CREATED  STATUS\n'
        printf 'orbit-%s-1  img    "sh"     %s       2 days   %s\n' "$svc" "$svc" "$st" ;;
    *'[ -n '*SEED_USER_PASSWORD*) exit "${FAKE_PASSWORD_RC:-0}" ;;
    *SEED_USER_PASSWORD*)         printf '%s\n' "${FAKE_SEED_PASSWORD:-hunter2}" ;;
    *SEED_USER_EMAIL*)            printf '%s\n' "${FAKE_SEED_EMAIL:-owner@example.com}" ;;
    *horizon:status*) printf '%s\n' "${FAKE_HORIZON:-Horizon is running.}"; exit "${FAKE_HORIZON_RC:-0}" ;;
    *queue:failed*)   printf '%s\n' "${FAKE_FAILED:-No failed jobs found.}" ;;
    *mail.log*)
        if [ "${FAKE_MAIL_RC:-0}" != 0 ]; then
            echo 'Error response from daemon: container is not running' >&2
            exit "${FAKE_MAIL_RC}"
        fi
        printf '%s' "${FAKE_MAIL:-__ABSENT__}" ;;
    *laravel.log*) printf '%s\n' "${FAKE_APP_LOG:-}"; exit "${FAKE_APP_LOG_RC:-0}" ;;
esac
exit 0
SH
    cat >"${BIN}/curl" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${FAKE_LOG_DIR}/curl.argv"
url=''; dfile=''; prev=''
for a in "$@"; do
    [ "$prev" = '-D' ] && dfile=$a
    prev=$a
    url=$a
done
head_only=0; code_only=0; nl_code=0; side=edge
case " $* " in *' -sI '*) head_only=1 ;; esac
case "$*" in *'-o /dev/null'*) code_only=1 ;; esac
case "$*" in *'\n%{http_code}'*) nl_code=1 ;; esac
case "$*" in *'Host: '*) side=loop ;; esac

bundle=${FAKE_BUNDLE_LOOP:-build/assets/app-aaa111.js}
[ "$side" = edge ] && bundle=${FAKE_BUNDLE_EDGE:-$bundle}
[ "$bundle" = none ] && bundle=''
headers=''; body=''; code=200
case "$url" in
    */up) body='Application up' ;;
    */sw.js)
        headers='content-type: application/javascript; charset=utf-8'
        body="const PRECACHE = [\"/${FAKE_SW_BUNDLE:-$bundle}\"];" ;;
    */manifest.webmanifest) headers='content-type: application/manifest+json' ;;
    */build/assets/*.js) headers="cache-control: ${FAKE_ASSET_CC:-public, max-age=31536000, immutable}" ;;
    */sanctum/csrf-cookie)
        code=${FAKE_CSRF_CODE:-204}
        headers="set-cookie: XSRF-TOKEN=${FAKE_XSRF:-tok%3D}; Path=/
set-cookie: orbit-session=guest1; Path=/; HttpOnly" ;;
    */login)
        code=${FAKE_LOGIN_CODE:-200}
        headers="set-cookie: XSRF-TOKEN=tok2%3D; Path=/
set-cookie: orbit-session=${FAKE_AUTHED:-authed1}; Path=/; HttpOnly" ;;
    */api/watchlist) code=${FAKE_PROBE_CODE:-401} ;;
    */api/me)
        case "$*" in
            *"${FAKE_AUTHED:-authed1}"*)
                code=${FAKE_ME_AUTHED_CODE:-200}
                body='{"email":"owner@example.com","id":1,"name":"Ghie"}' ;;
            *)
                code=${FAKE_ME_GUEST_CODE:-401}
                body='{"message":"Unauthenticated."}' ;;
        esac ;;
    *)
        headers="${FAKE_SHELL_HEADERS:-content-security-policy: default-src 'self'; script-src 'self'
cf-cache-status: DYNAMIC}"
        body="<script src=\"/${bundle}\"></script>" ;;
esac

if [ -n "$dfile" ]; then
    { printf 'HTTP/1.1 %s\n' "$code"; [ -n "$headers" ] && printf '%s\n' "$headers"; } >"$dfile"
fi
if [ "$head_only" = 1 ]; then
    printf 'HTTP/1.1 %s\r\n' "$code"
    [ -n "$headers" ] && printf '%s\r\n' "$headers"
    exit 0
fi
if [ "$code_only" = 1 ]; then printf '%s' "$code"; exit 0; fi
[ -n "$body" ] && printf '%s\n' "$body"
[ "$nl_code" = 1 ] && printf '\n%s' "$code"
exit 0
SH
    cat >"${BIN}/find" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "${FAKE_LOG_DIR}/find.argv"
i=1
while [ "$i" -le "${FAKE_ROOT_OWNED:-0}" ]; do
    printf 'root-owned-%s\n' "$i"
    i=$((i + 1))
done
exit 0
SH
    chmod 0755 "${BIN}"/*
}

fixture() {
    CASE="${WORK}/$1"
    ROOT="${CASE}/root"
    BIN="${CASE}/bin"
    SNAP="${CASE}/baseline"
    mkdir -p "${ROOT}" "${BIN}"
    write_fakes
}

run_verify() {
    OUT="$(env \
        PATH="${BIN}:${PATH}" \
        FAKE_LOG_DIR="${CASE}" \
        FAKE_STARTED="${STARTED:-${STARTED_NEW}}" \
        FAKE_STATUS="${STATUS:-}" \
        FAKE_WEB_PORTS="${WEB_PORTS:-127.0.0.1:3085->8080/tcp}" \
        FAKE_BUNDLE_LOOP="${BUNDLE_LOOP:-build/assets/app-aaa111.js}" \
        FAKE_BUNDLE_EDGE="${BUNDLE_EDGE:-}" \
        FAKE_SW_BUNDLE="${SW_BUNDLE:-}" \
        FAKE_ASSET_CC="${ASSET_CC:-}" \
        FAKE_SHELL_HEADERS="${SHELL_HEADERS:-}" \
        FAKE_CSRF_CODE="${CSRF_CODE:-204}" \
        FAKE_LOGIN_CODE="${LOGIN_CODE:-200}" \
        FAKE_PROBE_CODE="${PROBE_CODE:-401}" \
        FAKE_ME_AUTHED_CODE="${ME_AUTHED_CODE:-200}" \
        FAKE_ME_GUEST_CODE="${ME_GUEST_CODE:-401}" \
        FAKE_PASSWORD_RC="${PASSWORD_RC:-0}" \
        FAKE_HORIZON="${HORIZON:-Horizon is running.}" \
        FAKE_HORIZON_RC="${HORIZON_RC:-0}" \
        FAKE_FAILED="${FAILED:-No failed jobs found.}" \
        FAKE_MAIL="${MAIL:-__ABSENT__}" \
        FAKE_MAIL_RC="${MAIL_RC:-0}" \
        FAKE_APP_LOG="${APP_LOG:-${LOG_CLEAN}}" \
        FAKE_APP_LOG_RC="${APP_LOG_RC:-0}" \
        FAKE_ROOT_OWNED="${ROOT_OWNED:-0}" \
        ORBIT_DIR="${ROOT}" \
        ORBIT_HOST='flights.test' \
        ORBIT_BASE='http://127.0.0.1:3085' \
        ORBIT_PUBLIC='https://flights.test' \
        ORBIT_SNAPSHOT="${SNAP}" \
        bash "${VERIFY_SH}" "$@" 2>&1)"
    STATUS_RC=$?
    STARTED=''; STATUS=''; WEB_PORTS=''; BUNDLE_LOOP=''; BUNDLE_EDGE=''; SW_BUNDLE=''
    ASSET_CC=''; SHELL_HEADERS=''; CSRF_CODE=''; LOGIN_CODE=''; PROBE_CODE=''
    ME_AUTHED_CODE=''; ME_GUEST_CODE=''; PASSWORD_RC=''; HORIZON=''; HORIZON_RC=''
    FAILED=''; MAIL=''; MAIL_RC=''; APP_LOG=''; APP_LOG_RC=''; ROOT_OWNED=''
}

STARTED=''; STATUS=''; WEB_PORTS=''; BUNDLE_LOOP=''; BUNDLE_EDGE=''; SW_BUNDLE=''
ASSET_CC=''; SHELL_HEADERS=''; CSRF_CODE=''; LOGIN_CODE=''; PROBE_CODE=''
ME_AUTHED_CODE=''; ME_GUEST_CODE=''; PASSWORD_RC=''; HORIZON=''; HORIZON_RC=''
FAILED=''; MAIL=''; MAIL_RC=''; APP_LOG=''; APP_LOG_RC=''; ROOT_OWNED=''

# `--before` writes the baseline the whole battery is judged against, so a deploy
# that could not take one stops there.
baseline_for() {
    STARTED="${STARTED_OLD}"
    BUNDLE_LOOP="$1"
    run_verify --before
}

# --- 1. the baseline round trip -----------------------------------------------
fixture baseline
baseline_for 'build/assets/app-old000.js'
equals 'a baseline that could be taken exits 0' "${STATUS_RC}" '0'
contains 'and says where it went' "${OUT}" "baseline recorded in ${SNAP}"
contains 'and records the served bundle' "$(cat "${SNAP}")" 'bundle=build/assets/app-old000.js'
equals 'and one start time per restarted service' "$(grep -c '^started_' "${SNAP}")" '4'
contains 'app' "$(cat "${SNAP}")" "started_app=${T_OLD}"
contains 'horizon' "$(cat "${SNAP}")" "started_horizon=${T_OLD}"
contains 'scheduler' "$(cat "${SNAP}")" "started_scheduler=${T_OLD}"
contains 'web' "$(cat "${SNAP}")" "started_web=${T_OLD}"

fixture baseline-dead-site
STARTED="${STARTED_OLD}"
BUNDLE_LOOP='none'
run_verify --before
equals 'a baseline with no bundle in the shell refuses' "${STATUS_RC}" '1'
contains 'and says the app has to answer first' "${OUT}" 'must answer on'

fixture baseline-no-start-time
STARTED='none'
run_verify --before
equals 'a baseline that cannot read a start time refuses' "${STATUS_RC}" '1'
contains 'and names the service' "${OUT}" "could not read app's StartedAt"

# --- 2. the whole battery green ------------------------------------------------
fixture green
baseline_for 'build/assets/app-old000.js'
run_verify
equals 'a deploy that moved everything it should passes' "${STATUS_RC}" '0'
contains 'all of it' "${OUT}" 'all post-deploy checks passed'
contains '1 the bundle moved' "${OUT}" 'bundle moved: build/assets/app-old000.js -> build/assets/app-aaa111.js'
contains '2 the health endpoint body' "${OUT}" '/up says Application up'
contains '3 the csrf cookie was lifted' "${OUT}" 'an XSRF-TOKEN was lifted'
contains '3 the session and token are accepted' "${OUT}" 'refused at auth'
contains '3 the login' "${OUT}" 'POST /login -> 200'
contains '3 the authenticated read' "${OUT}" '/api/me 200 with the signed-in account'
contains '4 the manifest content type' "${OUT}" 'manifest content-type: application/manifest+json'
contains '4 the service worker names the live bundle' "${OUT}" 'precaches app-aaa111.js'
contains '5 the bundle is immutable' "${OUT}" 'immutable'
contains '6 web is on the loopback only' "${OUT}" 'web published on 127.0.0.1:3085->8080/tcp'
contains '7 the restart is proved from StartedAt' "${OUT}" 'app restarted 3540s after the baseline was taken'
contains '7 horizon is up' "${OUT}" 'Horizon is running'
contains '7 no failed jobs' "${OUT}" 'no failed jobs'
contains '8 nothing threw since the restart' "${OUT}" 'no production errors since the restart'
contains '9 mail.log' "${OUT}" 'no mail.log yet'
contains '10 nothing root-owned' "${OUT}" 'root-owned 0 under'
contains '11 a policy reaches the browser' "${OUT}" "script-src 'self'"
contains '12 the edge agrees with the sidecar' "${OUT}" 'both serve build/assets/app-aaa111.js'
absent 'and not one check failed' "${OUT}" 'FAIL'
equals 'a green run consumes the baseline' "$([ -f "${SNAP}" ] && echo present || echo gone)" 'gone'

# --- 3. the bundle, both modes ------------------------------------------------
fixture bundle-unchanged-full
baseline_for 'build/assets/app-aaa111.js'
run_verify
equals 'an unchanged bundle with no --backend-only fails the deploy' "${STATUS_RC}" '1'
contains 'and says the build did not land' "${OUT}" 'the front-end build did not land'
equals 'and a failed run keeps the baseline for the next look' "$([ -f "${SNAP}" ] && echo present || echo gone)" 'present'

fixture bundle-unchanged-backend
baseline_for 'build/assets/app-aaa111.js'
run_verify --backend-only
equals 'an unchanged bundle with --backend-only passes' "${STATUS_RC}" '0'
contains 'and says it was expected' "${OUT}" '--backend-only was passed'

fixture bundle-changed-backend
baseline_for 'build/assets/app-old000.js'
run_verify --backend-only
equals 'a moved bundle is never a failure' "${STATUS_RC}" '0'
contains 'and it is reported' "${OUT}" 'bundle moved'

fixture bundle-no-baseline
run_verify
contains 'no baseline at all cannot judge the bundle' "${OUT}" "'the bundle moved' cannot be judged"
contains 'and it says so in the summary' "${OUT}" 'not judged'

# --- 4. check 6, the ps layout ------------------------------------------------
fixture ports-public
baseline_for 'build/assets/app-old000.js'
WEB_PORTS='0.0.0.0:3085->8080/tcp'
run_verify
equals 'a stack on the internet fails' "${STATUS_RC}" '1'
contains 'and the value it saw is in the line' "${OUT}" "web publishes '0.0.0.0:3085->8080/tcp'"

fixture ports-absent
baseline_for 'build/assets/app-old000.js'
WEB_PORTS='8080/tcp'
run_verify
equals 'a web with no published port fails' "${STATUS_RC}" '1'
contains 'and says what it wanted' "${OUT}" 'not 127.0.0.1:3085'

# --- 5. check 6, the healthcheck ---------------------------------------------
fixture health-unhealthy
baseline_for 'build/assets/app-old000.js'
STATUS='horizon=Up 2 days (unhealthy)'
run_verify
equals 'an unhealthy horizon fails' "${STATUS_RC}" '1'
contains 'and it is not read as healthy, which the word contains' "${OUT}" 'horizon is not (healthy)'

fixture health-starting
baseline_for 'build/assets/app-old000.js'
STATUS='postgres=Up 3 seconds (health: starting)'
run_verify
equals 'a container still starting its healthcheck fails' "${STATUS_RC}" '1'
contains 'and not on the word Up, which that line also contains' "${OUT}" 'postgres is not (healthy)'

fixture health-ok
baseline_for 'build/assets/app-old000.js'
STATUS="horizon=Up 2 days (healthy);postgres=Up 2 days (healthy);redis=Up 2 days (healthy)"
run_verify
equals 'the three that declare a healthcheck pass on (healthy)' "${STATUS_RC}" '0'
contains 'horizon' "${OUT}" 'horizon (healthy)'
contains 'postgres' "${OUT}" 'postgres (healthy)'
contains 'redis' "${OUT}" 'redis (healthy)'

# --- 6. check 7, the restart proof -------------------------------------------
fixture restart-not-done
baseline_for 'build/assets/app-old000.js'
STARTED="${STARTED_OLD}"
run_verify
equals 'containers that never restarted fail the deploy' "${STATUS_RC}" '1'
contains 'and it says what that means' "${OUT}" 'still running the previous release from opcache'

fixture restart-older-than-baseline
baseline_for 'build/assets/app-old000.js'
STARTED="app=$(iso $((T_OLD - 600)));horizon=$(iso "${T_RESTART}");scheduler=$(iso "${T_RESTART}");web=$(iso "${T_RESTART}")"
run_verify
equals 'a container older than the baseline fails' "${STATUS_RC}" '1'
contains 'and names it' "${OUT}" 'app has not restarted since the baseline'

fixture restart-unreadable
baseline_for 'build/assets/app-old000.js'
STARTED='horizon=;scheduler=;web='
run_verify
equals 'a StartedAt that cannot be read is never a pass' "${STATUS_RC}" '1'
contains 'and check 7 names the service whose StartedAt it could not read' "${OUT}" \
    "could not read app's StartedAt, so its restart is unconfirmed"
absent 'and date -d never turns an empty string into midnight' "${OUT}" 'app restarted'

fixture horizon-down
baseline_for 'build/assets/app-old000.js'
HORIZON='Horizon is inactive.'
run_verify
equals 'a supervisor that did not come back fails' "${STATUS_RC}" '1'
contains 'and quotes it' "${OUT}" 'horizon:status said: Horizon is inactive.'

fixture horizon-unrunnable
baseline_for 'build/assets/app-old000.js'
HORIZON_RC=1
run_verify
equals 'a command that could not run is a failure, never a pass' "${STATUS_RC}" '1'
contains 'and says so' "${OUT}" 'horizon:status could not run'

fixture failed-jobs
baseline_for 'build/assets/app-old000.js'
FAILED='ID  Connection  Queue  Class  Failed At'
run_verify
equals 'failed jobs fail the deploy' "${STATUS_RC}" '1'
contains 'and they are listed' "${OUT}" 'there are failed jobs'

# --- 7. check 8, the log timestamp compare -----------------------------------
fixture log-error-after-restart
baseline_for 'build/assets/app-old000.js'
APP_LOG="${LOG_DIRTY}"
run_verify
equals 'a production.ERROR newer than the restart fails' "${STATUS_RC}" '1'
contains 'and only the newer one is counted' "${OUT}" '1 production.ERROR/CRITICAL since the restart'

fixture log-error-before-restart
baseline_for 'build/assets/app-old000.js'
APP_LOG="[$(logts "$T_OLD")] production.ERROR: before the restart, so it does not count"
run_verify
equals 'a production.ERROR older than the restart is not this deploy' "${STATUS_RC}" '0'
contains 'and the window it used is printed' "${OUT}" "no production errors since the restart ($(logts "${T_RESTART}"))"

fixture log-unreadable
baseline_for 'build/assets/app-old000.js'
APP_LOG_RC=1
run_verify
equals 'a log that could not be read is a failure' "${STATUS_RC}" '1'
contains 'and says errors are unchecked' "${OUT}" 'errors are unchecked'

# --- 8. check 3, the login and its fallback ----------------------------------
fixture login-guest-fallback
baseline_for 'build/assets/app-old000.js'
PASSWORD_RC=1
run_verify
equals 'no seeded password means no login is spent' "${STATUS_RC}" '0'
contains 'and it says why' "${OUT}" 'no seeded password in .env, so the login is not spent'
contains 'and the guest smoke stands in' "${OUT}" 'JSON, not a redirect and not HTML'
absent 'and nothing was posted to /login' "$(cat "${CASE}/curl.argv")" '/login'

fixture login-refused
baseline_for 'build/assets/app-old000.js'
LOGIN_CODE=419
run_verify
equals 'a login the app refuses fails the battery' "${STATUS_RC}" '1'
contains 'and the code is in the line' "${OUT}" 'POST /login -> 419'

fixture csrf-broken
baseline_for 'build/assets/app-old000.js'
PROBE_CODE=419
run_verify
equals 'a 419 from the plumbing probe fails' "${STATUS_RC}" '1'
contains 'and says which half it means' "${OUT}" '419 means the token, not the app'

fixture csrf-no-cookie
baseline_for 'build/assets/app-old000.js'
CSRF_CODE=500
run_verify
equals 'no csrf cookie at all fails' "${STATUS_RC}" '1'
contains 'and reports the code' "${OUT}" '/sanctum/csrf-cookie -> 500'

fixture me-refuses-the-session
baseline_for 'build/assets/app-old000.js'
ME_AUTHED_CODE=401
run_verify
equals 'a 401 to the signed-in session fails' "${STATUS_RC}" '1'
contains 'and says what answered' "${OUT}" '/api/me answered 401 to the signed-in session'

# --- 9. check 9, mail.log ----------------------------------------------------
fixture mail-exec-failed
baseline_for 'build/assets/app-old000.js'
MAIL_RC=1
run_verify
equals 'a container that cannot be reached fails check 9' "${STATUS_RC}" '1'
contains 'and check 9 names the container, not a missing file' "${OUT}" 'that is not the same as no mail yet'

fixture mail-quiet
baseline_for 'build/assets/app-old000.js'
MAIL='[2026-09-19 06:10:00] production.DEBUG: Symfony\Component\Mime\Email'
run_verify
equals 'rendered messages are not a failure' "${STATUS_RC}" '0'
contains 'and they are counted' "${OUT}" 'mail.log is quiet: 1 rendered message(s)'

fixture mail-error
baseline_for 'build/assets/app-old000.js'
MAIL='[2026-09-19 06:10:00] production.ERROR: transport failed'
run_verify
equals 'a production.ERROR in mail.log fails' "${STATUS_RC}" '1'
contains 'and is quoted' "${OUT}" 'mail.log carries a production.ERROR'

# --- 10. the remaining checks ------------------------------------------------
fixture asset-not-immutable
baseline_for 'build/assets/app-old000.js'
ASSET_CC='no-store'
run_verify
equals 'a hashed bundle that is not immutable fails' "${STATUS_RC}" '1'
contains 'and the header it saw is in the line' "${OUT}" "cache-control is 'cache-control: no-store'"

fixture sw-names-the-old-bundle
baseline_for 'build/assets/app-old000.js'
SW_BUNDLE='build/assets/app-old000.js'
run_verify
equals 'a service worker precaching the previous bundle fails' "${STATUS_RC}" '1'
contains 'and says what that means' "${OUT}" 'the build ran in the wrong order'

fixture no-policy
baseline_for 'build/assets/app-old000.js'
SHELL_HEADERS='cf-cache-status: DYNAMIC'
run_verify
equals 'a shell served with no policy fails' "${STATUS_RC}" '1'
contains 'and names what it wanted' "${OUT}" "no content-security-policy naming script-src 'self'"

fixture stale-edge
baseline_for 'build/assets/app-old000.js'
BUNDLE_EDGE='build/assets/app-old000.js'
run_verify
equals 'an edge holding the previous release fails' "${STATUS_RC}" '1'
contains 'and both values are in the line' "${OUT}" 'the edge is holding the previous release'

fixture root-owned
baseline_for 'build/assets/app-old000.js'
ROOT_OWNED=2
run_verify
equals 'root-owned paths fail' "${STATUS_RC}" '1'
contains 'and it says what they break' "${OUT}" "2 root-owned path(s)"
contains 'the ownership proof carves out the agent runtime' "$(cat "${CASE}/find.argv")" '.claude/*'

fixture up-is-the-body
baseline_for 'build/assets/app-old000.js'
run_verify
contains 'the health check reads the BODY, not the status' "$(cat "${CASE}/curl.argv")" '/up'
absent 'so it never asks for a status code there' "$(grep -F '/up' "${CASE}/curl.argv")" '%{http_code}'

# --- 11. every curl is bounded ------------------------------------------------
fixture timeouts
baseline_for 'build/assets/app-old000.js'
run_verify
untimed=$(grep -cv -- '--max-time' "${CASE}/curl.argv")
equals 'every curl the battery makes carries --max-time' "${untimed}" '0'
untimed=$(grep -cv -- '--connect-timeout' "${CASE}/curl.argv")
equals 'and --connect-timeout' "${untimed}" '0'

if [ "${fails}" -eq 0 ]; then
    printf '\nverify-test: all checks passed\n'
    exit 0
fi
printf '\nverify-test: %s check(s) failed\n' "${fails}" >&2
exit 1
