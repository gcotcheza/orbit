#!/usr/bin/env bash
# The post-deploy battery. Not set -e: every check must run, so the exit code is
# decided at the end. Nothing here writes: docs/DECISIONS.md, the-deploy-script-is-the-runbook
set -uo pipefail

APP_DIR=${ORBIT_DIR:-/var/www/orbit}
HOST=${ORBIT_HOST:-flights.ghiecode.io}
BASE=${ORBIT_BASE:-http://127.0.0.1:3085}
PUBLIC=${ORBIT_PUBLIC:-https://$HOST}
SNAP=${ORBIT_SNAPSHOT:-${HOME:-/root}/.orbit-deploy-baseline}

BASELINE_MAX_AGE=14400
RESTARTED='app horizon scheduler web'

mode=verify
backend_only=no
for arg in "$@"; do
    case "$arg" in
        --before) mode=before ;;
        --backend-only) backend_only=yes ;;
        *)
            printf 'usage: %s [--before] [--backend-only]\n' "$0" >&2
            exit 2
            ;;
    esac
done

fails=0
step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
ok()   { printf '    \033[1;32mPASS\033[0m %s\n' "$1"; }
bad()  { printf '    \033[1;31mFAIL\033[0m %s\n' "$1"; fails=$((fails + 1)); }
note() { printf '    \033[1;33mnote\033[0m %s\n' "$1"; }
detail() { printf '%s\n' "$1" | sed 's/^/         | /'; }

timeouts=(--max-time 15 --connect-timeout 5)
dc()   { (cd "$APP_DIR" && docker compose "$@"); }
get()  { curl -s "${timeouts[@]}" -H "Host: $HOST" "$BASE$1"; }
head_() { curl -sI "${timeouts[@]}" -H "Host: $HOST" "$BASE$1"; }
code() { curl -s -o /dev/null -w '%{http_code}' "${timeouts[@]}" -H "Host: $HOST" "$BASE$1"; }
jget() { curl -s "${timeouts[@]}" -H "Host: $HOST" -H 'Accept: application/json' -w '\n%{http_code}' "$BASE$1"; }
edge()  { curl -s "${timeouts[@]}" "$PUBLIC$1"; }
edge_head() { curl -sI "${timeouts[@]}" "$PUBLIC$1"; }

bundle()      { get / | grep -oE 'build/assets/app-[A-Za-z0-9_-]+\.js' | head -1; }
edge_bundle() { edge / | grep -oE 'build/assets/app-[A-Za-z0-9_-]+\.js' | head -1; }

started_at() { docker inspect -f '{{.State.StartedAt}}' "$(dc ps -q "$1" 2>/dev/null)" 2>/dev/null; }
# date -d '' is today's midnight at exit 0, so an unread StartedAt must not reach it.
epoch() { [ -n "$1" ] && date -d "$1" +%s 2>/dev/null; }

rooted() { find "$APP_DIR" -user root -not -path "$APP_DIR/.claude/*" | wc -l; }

if [ "$mode" = 'before' ]; then
    c=$(code /); b=$(bundle)
    if [ "$c" != '200' ] || [ -z "$b" ]; then
        bad "no baseline recorded: GET / -> $c, bundle '$b'"
        note "the app must answer on $BASE with Host: $HOST before the deploy starts"
        exit 1
    fi
    lines="recorded=$(date +%s)"$'\n'"bundle=$b"
    for s in $RESTARTED; do
        t=$(epoch "$(started_at "$s")")
        if [ -z "$t" ]; then
            bad "could not read $s's StartedAt, so the restart could not be proved afterwards"
            exit 1
        fi
        lines="$lines"$'\n'"started_$s=$t"
    done
    mkdir -p "$(dirname "$SNAP")" || { bad "cannot create the directory for $SNAP"; exit 1; }
    if ! printf '%s\n' "$lines" > "$SNAP" 2>/dev/null; then
        bad "could not write the baseline to $SNAP"
        exit 1
    fi
    written=$(cat "$SNAP" 2>/dev/null)
    if [ "$written" != "$lines" ]; then
        bad "$SNAP does not read back as written, so there is no baseline"
        exit 1
    fi
    ok "baseline recorded in $SNAP"
    detail "$written"
    exit 0
fi

snap_state=absent
snap_used=no
unjudged=0
if [ -r "$SNAP" ]; then
    recorded=$(sed -n 's/^recorded=//p' "$SNAP" | head -1)
    case "$recorded" in
        '' | *[!0-9]*) snap_state=corrupt ;;
        *) [ $(($(date +%s) - recorded)) -le "$BASELINE_MAX_AGE" ] && snap_state=fresh || snap_state=stale ;;
    esac
fi

snap_field() { [ "$snap_state" = 'fresh' ] && sed -n "s/^$1=//p" "$SNAP" | head -1; }

step '1. The shell loads, and the bundle moved'
case "$snap_state" in
    corrupt) bad "the baseline in $SNAP has no readable timestamp — it is corrupt, not old; delete it and run --before"
             snap_state=absent ;;
    stale)   bad "the baseline in $SNAP is older than $BASELINE_MAX_AGE s, so it belongs to an earlier deploy — delete it or run --before"
             snap_state=absent ;;
esac
c=$(code /); b=$(bundle)
[ "$c" = '200' ] && ok 'GET / -> 200' || bad "GET / -> $c, expected 200"
[ -n "$b" ] && ok "bundle $b" || bad 'no app-<hash>.js in the shell'
was=$(snap_field bundle)
if [ "$snap_state" != 'fresh' ]; then
    note "no usable baseline, so 'the bundle moved' cannot be judged — run --before next deploy"
    unjudged=$((unjudged + 1))
elif [ -z "$was" ]; then
    note 'the baseline records no bundle, so "the bundle moved" cannot be judged'
    unjudged=$((unjudged + 1))
elif ! printf '%s' "$was" | grep -qE '^build/assets/app-[A-Za-z0-9_-]+\.js$'; then
    bad "the baseline's bundle '$was' is malformed, so it is not compared — delete $SNAP and run --before"
else
    snap_used=yes
    if [ -z "$b" ]; then
        bad "there is no bundle to compare against the baseline's $was"
    elif [ "$b" != "$was" ]; then
        ok "bundle moved: $was -> $b"
    elif [ "$backend_only" = 'yes' ]; then
        note "bundle unchanged since --before ($was) — expected, --backend-only was passed"
    else
        bad "bundle unchanged since --before ($was) — the front-end build did not land; pass --backend-only if that is deliberate"
    fi
fi

step '2. The health endpoint'
# The BODY, not the status: /up answering the SPA shell at 200 would mean routing is wrong.
[ "$(get /up | grep -c 'Application up')" = '1' ] && ok '/up says Application up' || bad '/up did not say Application up'

# SESSION_SECURE_COOKIE=true makes curl refuse to STORE either cookie over plain
# loopback HTTP, so -c/-b authenticate nothing, silently. DECISIONS.md
step '3. The authenticated read, with the cookies lifted off Set-Cookie'
HDR=$(mktemp); OUT=$(mktemp)
trap 'rm -f "$HDR" "$OUT"' EXIT
csrf=$(curl -s -D "$HDR" -o /dev/null -w '%{http_code}' "${timeouts[@]}" -H "Host: $HOST" "$BASE/sanctum/csrf-cookie")
COOKIE=$(awk 'tolower($1)=="set-cookie:"{split($2,a,";"); printf "%s%s", (n++?"; ":""), a[1]}' "$HDR")
XSRF=$(printf '%s' "$COOKIE" | sed -n 's/.*XSRF-TOKEN=\([^;]*\).*/\1/p' \
    | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))' 2>/dev/null)
if [ "$csrf" = '204' ] && [ -n "$XSRF" ]; then
    ok "/sanctum/csrf-cookie -> 204 and an XSRF-TOKEN was lifted"
else
    bad "/sanctum/csrf-cookie -> $csrf and the XSRF-TOKEN is $([ -n "$XSRF" ] && echo present || echo absent)"
fi
# Refused at auth, so it changes nothing: 401 proves CSRF passed, 419 that it did not.
probe=$(curl -s -o /dev/null -w '%{http_code}' "${timeouts[@]}" -X POST -H "Host: $HOST" \
    -H "Cookie: $COOKIE" -H "X-XSRF-TOKEN: $XSRF" -H 'Accept: application/json' "$BASE/api/watchlist")
[ "$probe" = '401' ] && ok 'the session and token are accepted (401, refused at auth)' \
    || bad "the CSRF plumbing answered $probe, not 401 — 419 means the token, not the app"
seed_email=$(dc exec -T app sh -c 'sed -n "s/^SEED_USER_EMAIL=//p" .env | head -1' 2>/dev/null | tr -d '\r')
if [ -z "$seed_email" ] || ! dc exec -T app sh -c '[ -n "$(sed -n "s/^SEED_USER_PASSWORD=//p" .env | head -1)" ]' 2>/dev/null; then
    note 'no seeded password in .env, so the login is not spent; the guest smoke below is the runbook fallback'
    guest=$(jget /api/me)
    if [ "$(printf '%s' "$guest" | tail -1)" = '401' ] && printf '%s' "$guest" | grep -q 'Unauthenticated.'; then
        ok '/api/me 401 {"message":"Unauthenticated."} — JSON, not a redirect and not HTML'
    else
        bad "/api/me answered: $(printf '%s' "$guest" | tr '\n' ' ' | cut -c1-90)"
    fi
else
    # POST /login is throttled 5/min on email|ip and the throttle runs before
    # validation, so a fumbled password costs a slot.
    login=$(dc exec -T app sh -c 'sed -n "s/^SEED_USER_PASSWORD=//p" .env | head -1' 2>/dev/null | tr -d '\r' \
        | python3 -c 'import json,sys;print(json.dumps({"email":sys.argv[1],"password":sys.stdin.read().strip()}))' "$seed_email" \
        | curl -s -D "$OUT" -o /dev/null -w '%{http_code}' "${timeouts[@]}" -H "Host: $HOST" \
            -H "Cookie: $COOKIE" -H "X-XSRF-TOKEN: $XSRF" \
            -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @- "$BASE/login")
    # login() regenerates the session, so the read must use the NEW cookie.
    AUTHED=$(awk 'tolower($1)=="set-cookie:"{split($2,a,";"); printf "%s%s", (n++?"; ":""), a[1]}' "$OUT")
    [ "$login" = '200' ] && ok 'POST /login -> 200' || bad "POST /login -> $login"
    me=$(curl -s "${timeouts[@]}" -H "Host: $HOST" -H "Cookie: ${AUTHED:-$COOKIE}" \
        -H 'Accept: application/json' -w '\n%{http_code}' "$BASE/api/me")
    if [ "$(printf '%s' "$me" | tail -1)" = '200' ] && printf '%s' "$me" | grep -q '"email"'; then
        ok '/api/me 200 with the signed-in account'
    else
        bad "/api/me answered $(printf '%s' "$me" | tail -1) to the signed-in session"
    fi
fi

step '4. The PWA surface — Content-Type, never the status code'
mt=$(head_ /manifest.webmanifest | grep -i '^content-type' | tr -d '\r')
st=$(head_ /sw.js | grep -i '^content-type' | tr -d '\r')
case "$mt" in *application/manifest+json*) ok "manifest $mt";; *) bad "manifest content-type is '$mt' — text/html means the SPA catch-all is answering";; esac
case "$st" in *application/javascript*)    ok "sw.js $st";;    *) bad "sw.js content-type is '$st' — text/html means the SPA catch-all is answering";; esac
if [ -n "$b" ] && get /sw.js | grep -qF "${b##*/}"; then
    ok "the service worker precaches ${b##*/}"
else
    bad "the service worker does not name the live bundle '$b' — the build ran in the wrong order"
fi

step '5. The hashed bundle is cached for a year'
if [ -z "$b" ]; then
    bad 'no bundle to ask about, so its cache-control is unchecked'
else
    cc=$(head_ "/$b" | grep -i '^cache-control' | tr -d '\r')
    case "$cc" in *immutable*) ok "$cc";; *) bad "the bundle's cache-control is '$cc', and a hashed filename must be immutable";; esac
fi

step '6. The stack itself'
ps_says() {
    local out rc
    out=$(dc ps "$1" 2>&1); rc=$?
    if [ "$rc" -ne 0 ]; then
        bad "docker compose ps $1 could not run, so '$1 $2' is unknown"
        detail "$out"
        return
    fi
    printf '%s' "$out" | grep -qF "$2" && ok "$1 $2" || bad "$1 is not $2"
}
for s in app scheduler web; do ps_says "$s" 'Up'; done
# Matched WITH its brackets: `(unhealthy)` contains `healthy`, and `Up 3 days
# (health: starting)` contains `Up`. Only these three declare a healthcheck.
for s in horizon postgres redis; do ps_says "$s" '(healthy)'; done
port=$(printf '%s' "$BASE" | grep -oE ':[0-9]+$' | tr -d ':')
port=${port:-3085}
ports=$(dc ps web 2>/dev/null | grep -oE "[^[:space:]]+:$port->8080/tcp")
case "$ports" in 127.0.0.1:*) ok "web published on $ports";;
    *) bad "web publishes '$ports', not 127.0.0.1:$port — 0.0.0.0 or ::: would mean the stack is on the internet";; esac

step '7. The restart happened, and the queue is alive'
app_epoch=''
for s in $RESTARTED; do
    now=$(epoch "$(started_at "$s")")
    [ "$s" = 'app' ] && app_epoch="$now"
    then_=$(snap_field "started_$s")
    if [ -z "$now" ]; then
        bad "could not read $s's StartedAt, so its restart is unconfirmed"
    elif [ -z "$then_" ]; then
        note "the baseline records no start time for $s, so its restart cannot be judged"
        unjudged=$((unjudged + 1))
    elif [ "$now" -gt "$then_" ]; then
        snap_used=yes
        ok "$s restarted $((now - then_))s after the baseline was taken"
    else
        snap_used=yes
        bad "$s has not restarted since the baseline — it is still running the previous release from opcache"
    fi
done
hs=$(dc exec -T app php artisan horizon:status 2>&1); rc=$?
if [ "$rc" -ne 0 ]; then
    bad 'horizon:status could not run, so the supervisor is unchecked'
    detail "$hs"
elif printf '%s' "$hs" | grep -q 'Horizon is running'; then
    ok 'Horizon is running'
else
    bad "horizon:status said: $(printf '%s' "$hs" | tr '\n' ' ' | cut -c1-90)"
fi
qf=$(dc exec -T app php artisan queue:failed 2>&1); rc=$?
if [ "$rc" -ne 0 ]; then
    bad 'queue:failed could not run, so failed jobs are unchecked'
    detail "$qf"
elif printf '%s' "$qf" | grep -q 'No failed jobs'; then
    ok 'no failed jobs'
else
    bad 'there are failed jobs'
    detail "$qf"
fi

# The gate's own PHPUnit run writes testing.* through the same bind mount; only production.* counts.
step '8. Nothing threw since the restart'
since=$([ -n "$app_epoch" ] && date -u -d "@$app_epoch" '+%F %T')
log=$(dc exec -T app tail -n 200 storage/logs/laravel.log 2>&1); rc=$?
if [ -z "$since" ]; then
    bad 'could not read the app container start time, so "since the restart" cannot be judged'
elif [ "$rc" -ne 0 ]; then
    bad 'could not read storage/logs/laravel.log in the app container, so errors are unchecked'
    detail "$log"
else
    errs=$(printf '%s\n' "$log" | grep -E 'production\.(ERROR|CRITICAL)' | awk -v s="$since" 'substr($0,2,19) > s' | wc -l)
    [ "$errs" = '0' ] && ok "no production errors since the restart ($since)" || bad "$errs production.ERROR/CRITICAL since the restart"
fi

step '9. The alert mail nobody is receiving yet'
mail=$(dc exec -T app sh -c 'if [ -f storage/logs/mail.log ]; then tail -n 40 storage/logs/mail.log; else printf __ABSENT__; fi' 2>&1); rc=$?
if [ "$rc" -ne 0 ]; then
    bad 'could not read the app container, so mail.log is unchecked — that is not the same as no mail yet'
    detail "$mail"
elif [ "$mail" = '__ABSENT__' ]; then
    ok 'no mail.log yet — the file appears with the first alert'
elif printf '%s' "$mail" | grep -q 'production\.ERROR'; then
    bad 'mail.log carries a production.ERROR'
    detail "$mail"
else
    ok "mail.log is quiet: $(printf '%s' "$mail" | grep -c 'Symfony\\Component\\Mime\\Email') rendered message(s) in the last 40 lines"
fi

step '10. Nothing in the checkout is root-owned'
n=$(rooted)
[ "$n" = '0' ] && ok "root-owned 0 under $APP_DIR" \
    || bad "$n root-owned path(s) under $APP_DIR — that is what breaks the next deploy's fast-forward"

step '11. A policy reaches the browser'
csp=$(edge_head / | grep -i '^content-security-policy' | tr -d '\r')
case "$csp" in *"script-src 'self'"*) ok "$csp";;
    *) bad "$PUBLIC serves no content-security-policy naming script-src 'self': '$csp'";; esac

step '12. The edge serves what the sidecar serves'
eb=$(edge_bundle)
cf=$(edge_head / | grep -i '^cf-cache-status' | tr -d '\r')
detail "${cf:-cf-cache-status: absent}"
if [ -z "$eb" ]; then
    bad "$PUBLIC served no app-<hash>.js at all"
elif [ "$eb" = "$b" ]; then
    ok "$PUBLIC and the sidecar both serve $eb"
else
    bad "$PUBLIC serves $eb while the sidecar serves $b — the edge is holding the previous release"
fi

printf '\n'
if [ "$snap_used" = 'yes' ] && [ "$fails" = '0' ]; then
    rm -f "$SNAP" && note "baseline consumed: $SNAP removed, so the next deploy cannot compare against it"
fi
if [ "$fails" != '0' ]; then
    printf '\033[1;31m%s check(s) failed\033[0m\n' "$fails"
elif [ "$unjudged" != '0' ]; then
    printf '\033[1;33m%s check(s) not judged, the rest passed\033[0m\n' "$unjudged"
else
    printf '\033[1;32mall post-deploy checks passed\033[0m\n'
fi
exit $((fails > 0))
