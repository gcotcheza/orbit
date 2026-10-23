# Deploy Orbit

`/var/www/orbit` · https://flights.ghiecode.io · compose project `orbit` · user `orbit` (115:119) · nginx on 127.0.0.1:3085 only

**Merging to `main` does not deploy:** nothing ships until this is run. `scripts/deploy.sh` **is** this runbook, held there by
`scripts/deploy-test.sh`. Reasons: `docs/DECISIONS.md`, the-deploy-runbook-keeps-commands-not-reasons.

## Before you run it

1. **Ghie merged the pull request.** Only a merged pull request deploys.
2. **The ledger holds a green `ci` and `e2e` for the commit `RESOLVED` names.** They are **not** re-run here.
3. **Run as root**, from any directory; git goes through `git-as`, or reads root's mirror `/var/lib/fleet/deploy-src/orbit.git`.
4. **One deploy a day, and this is that one.** Ghie's rule, not a technical limit.

## The one command

```bash
fleet-deploy orbit <PR#>
```

**`scripts/deploy.sh` run any other way refuses** (`REFUSED: … Deploy with: fleet-deploy <app> <PR#>`, or on
`FLEET_DEPLOY_REPO` unset); never work around it. **Orbit does not use `--gated-by-hand`.** **A docs-only merge is the same
command**: it lands, is proved and stops.

## If it says NOT GATED

It prints this recipe, shas filled in. **Gate in a worktree of the root-owned clone, never in `/var/www/orbit`.**

```bash
git -C /srv/sessions/orbit/repo worktree add /srv/worker-scratch/orbit-gate-pr<N> <the sha NOT GATED printed>
cd /srv/worker-scratch/orbit-gate-pr<N>
export COMPOSE_PROJECT_NAME=orbit-gate-pr<N>
heavy-work orbit-gate-pr<N> -- bash scripts/check.sh overlay
heavy-work orbit-e2e-pr<N> -- bash scripts/e2e.sh
```

Then re-run `fleet-deploy orbit <PR#>`. Gate only the sha the line named; without both greens it is re-gated, not argued
with. `docs/DEVELOPMENT.md` lists the five paths `scripts/e2e.sh` needs handed over.

## What it prints

One line per phase; the whole run in `/root/personal-vps-deploys/orbit/<utc>-pr<N>.log`.

| line | means |
|---|---|
| `RESOLVED #N head … merge … trees identical` | the **head** is gated |
| `RESOLVED #N merge … is origin/main and its tree is not head …'s` | the **merge commit** is gated |
| `CLASSIFIED code` / `LANDED docs-only …` | a landing ends the run |
| `GATED … ci and e2e both green` | `NOT GATED` prints the recipe above and stops |
| `PRE-FLIGHT load … available …` | never refuses |
| `STEP 0 baseline recorded` | bundle hash, four start times |
| `STEP 1 rollback target <sha>` | the sha to go back to |
| `STEPS 1-10 ok in one heavy-work job` | names the steps that ran |
| `HEALTH … healthy …` | `HEALTH TIMEOUT`: landed, battery **not** run, not a rollback |
| `VERIFY --backend-only` / `VERIFY full` | bundle not rebuilt / rebuilt and served |
| `HOST VHOST NEEDED, NOT RUN …` | by hand: `nginx -t` · copy the file · `nginx -t` · `systemctl reload nginx`; reload only on a second `syntax is ok` |
| `DONE #N live <full merge sha> was … gated … root-owned 0 verify …` | finished; `root-owned` must read `0` |
| `PAPERWORK PR #N deployed …` | backlog, handoff, fleet-docs page |
| `REFUSED: git did not answer in Ns: <git command>` / `STOP: git did not answer in Ns while the classifier read …` | past `DEPLOY_GIT_TIMEOUT`; nothing moved. Find why git hangs first |
| `STEP 1 FAILED: git did not answer in Ns …` / `NOT LANDED: git did not answer …` | read `git status` as `orbit`; roll back to the `STEP 1` sha |
| `head unknown (…)` | landed; read the head as `orbit` |
| `HOST VHOST UNKNOWN: …` | run the `git diff` it prints, then the `HOST VHOST NEEDED` steps if it lists a file |
| `REFUSED: …` | before `STEPS 1-10` nothing moved; inside the job it is a `FAILED`. `FAILED rc=…`: read the log, never re-run a step (at `VERIFY`: below) |

**After a `FAILED` at `VERIFY`** the live-tripwire fires. Check, in this order:

1. The only `orbit` live-tripwire line is `HEAD is <sha>, and no deploy log … names it`. Any `changed outside a deploy`, `hooks … changed` or `HEAD moved from` line is something else: stop and investigate.
2. `HEAD` = `origin/main` in `/var/www/orbit` (`git-as orbit -C /var/www/orbit rev-parse HEAD origin/main`).
3. Re-run the read-only battery from a root 700 copy out of root's mirror. Green → accept once with
   `vps-health-check.sh --accept-tripwire orbit`. Red → roll back as the script prints.

```bash
(
  set -u
  VDIR=$(mktemp -d /var/lib/fleet/deploy-src/verify.XXXXXXXX) || exit
  trap 'rm -rf "${VDIR:?}"' EXIT
  git --git-dir=/var/lib/fleet/deploy-src/orbit.git show main:scripts/verify.sh >"${VDIR:?}/verify.sh" || exit
  bash "${VDIR:?}/verify.sh"
)
```

**⚠ The containers boot the code once**: a deploy that stops before the restarts looks successful and serves the old app.

## What stays human

**Which pull request**; **one look at the site on a phone** when the release touches anything you tap; **the host vhost** when
the notice fires; **the paperwork**; and **a surprise in the log** — an unexpected migration is a conversation, not a step.

## Authenticated writes — not part of the battery

**⚠ EVERYTHING IN THIS SECTION CHANGES PRODUCTION DATA.** Prefer `scripts/e2e.sh`, which makes these writes in a sandbox. A
write needs the CSRF token lifted **again, from the login response**; the pre-login one answers **419**.

```bash
(
H='Host: flights.ghiecode.io'
B='http://127.0.0.1:3085'
HDR=$(mktemp) || exit 1; OUT=$(mktemp) || { rm -f "${HDR:?}"; exit 1; }
curl -s -D "$HDR" -o /dev/null -w '%{http_code}\n' --connect-timeout 5 --max-time 15 -H "$H" "$B/sanctum/csrf-cookie"
COOKIE=$(awk 'tolower($1)=="set-cookie:"{split($2,a,";"); printf "%s%s", (n++?"; ":""), a[1]}' "$HDR")
XSRF=$(printf '%s' "$COOKIE" | sed -n 's/.*XSRF-TOKEN=\([^;]*\).*/\1/p' \
       | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
DC="docker compose -f /var/www/orbit/docker-compose.yml"   # production's own project, by name, from any cwd
EMAIL=$($DC exec -T app sh -c 'sed -n "s/^SEED_USER_EMAIL=//p" .env | head -1' | tr -d '\r')
LOGIN=$($DC exec -T app sh -c 'sed -n "s/^SEED_USER_PASSWORD=//p" .env | head -1' | tr -d '\r' \
  | python3 -c 'import json,sys;print(json.dumps({"email":sys.argv[1],"password":sys.stdin.read().strip()}))' "$EMAIL" \
  | curl -s -D "$OUT" -o /dev/null -w '%{http_code}' --connect-timeout 5 --max-time 15 -H "$H" \
         -H "Cookie: $COOKIE" -H "X-XSRF-TOKEN: $XSRF" \
         -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @- "$B/login")
printf '%s\n' "$LOGIN"
AUTHED=$(awk 'tolower($1)=="set-cookie:"{split($2,a,";"); printf "%s%s", (n++?"; ":""), a[1]}' "$OUT")
AUTH_XSRF=$(printf '%s' "$AUTHED" | sed -n 's/.*XSRF-TOKEN=\([^;]*\).*/\1/p' \
            | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
rm -f "${HDR:?}" "${OUT:?}"
[ "$LOGIN" = 200 ] || { printf 'login answered %s, not 200: stopping\n' "$LOGIN"; exit 1; }
)
```

**Good:** `204`, then `200`. A write goes inside its own copy of the lift, as below.

**Pausing a route, and putting it back** — one block: a paused route is skipped in silence. Never pause without the restore.

```bash
(
H='Host: flights.ghiecode.io'
B='http://127.0.0.1:3085'
HDR=$(mktemp) || exit 1; OUT=$(mktemp) || { rm -f "${HDR:?}"; exit 1; }
curl -s -D "$HDR" -o /dev/null -w '%{http_code}\n' --connect-timeout 5 --max-time 15 -H "$H" "$B/sanctum/csrf-cookie"
COOKIE=$(awk 'tolower($1)=="set-cookie:"{split($2,a,";"); printf "%s%s", (n++?"; ":""), a[1]}' "$HDR")
XSRF=$(printf '%s' "$COOKIE" | sed -n 's/.*XSRF-TOKEN=\([^;]*\).*/\1/p' \
       | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
DC="docker compose -f /var/www/orbit/docker-compose.yml"   # production's own project, by name, from any cwd
EMAIL=$($DC exec -T app sh -c 'sed -n "s/^SEED_USER_EMAIL=//p" .env | head -1' | tr -d '\r')
LOGIN=$($DC exec -T app sh -c 'sed -n "s/^SEED_USER_PASSWORD=//p" .env | head -1' | tr -d '\r' \
  | python3 -c 'import json,sys;print(json.dumps({"email":sys.argv[1],"password":sys.stdin.read().strip()}))' "$EMAIL" \
  | curl -s -D "$OUT" -o /dev/null -w '%{http_code}' --connect-timeout 5 --max-time 15 -H "$H" \
         -H "Cookie: $COOKIE" -H "X-XSRF-TOKEN: $XSRF" \
         -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @- "$B/login")
printf '%s\n' "$LOGIN"
AUTHED=$(awk 'tolower($1)=="set-cookie:"{split($2,a,";"); printf "%s%s", (n++?"; ":""), a[1]}' "$OUT")
AUTH_XSRF=$(printf '%s' "$AUTHED" | sed -n 's/.*XSRF-TOKEN=\([^;]*\).*/\1/p' \
            | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
rm -f "${HDR:?}" "${OUT:?}"
[ "$LOGIN" = 200 ] || { printf 'login answered %s, not 200: stopping\n' "$LOGIN"; exit 1; }

# PAUSE — AMS-LIS stops being polled from this moment.
curl -s -o /dev/null -w '%{http_code}\n' --connect-timeout 5 --max-time 15 -X PATCH -H "$H" \
     -H "Cookie: $AUTHED" -H "X-XSRF-TOKEN: $AUTH_XSRF" \
     -H 'Accept: application/json' -H 'Content-Type: application/json' \
     -d '{"active":false}' "$B/api/watchlist/AMS-LIS"

# RESTORE — and then prove it, rather than trusting the 200.
curl -s -o /dev/null -w '%{http_code}\n' --connect-timeout 5 --max-time 15 -X PATCH -H "$H" \
     -H "Cookie: $AUTHED" -H "X-XSRF-TOKEN: $AUTH_XSRF" \
     -H 'Accept: application/json' -H 'Content-Type: application/json' \
     -d '{"active":true}' "$B/api/watchlist/AMS-LIS"

curl -s --connect-timeout 5 --max-time 15 -H "$H" -H "Cookie: $AUTHED" \
     -H 'Accept: application/json' "$B/api/watchlist" \
  | python3 -c 'import sys,json;print([r["active"] for r in json.load(sys.stdin)["data"] if r["code"]=="AMS-LIS"])'
)
```

**Good:** `204`, `200`, `200` from each PATCH, `[True]` from the read. A `419` from a PATCH is the token, not the app.
Anything but `[True]` means a production route is still paused — put it back. **⚠ A bare `PUT /api/profile/password` is
419, not 401.**

## Rollback

What has to reach `main` is a revert of the merge commit. **⚠ NOTHING ON THIS BOX CAN PUSH**, so the rollback is two things.

**On disk — put the checkout back on the sha `DONE` printed as `was`:**

```bash
WAS=   # the sha DONE printed as `was`; the reset below refuses while this is empty
git-as orbit -C /var/www/orbit --no-optional-locks log --oneline -5   # confirm what is live
git-as orbit -C /var/www/orbit reset --hard "${WAS:?set WAS to the sha DONE printed as was}" \
  && find /var/www/orbit -user root -not -path '/var/www/orbit/.claude/*' | wc -l
```

The count must print `0`. Then write the rollback into root's record, with the library copied out of root's mirror:

```bash
(
  set -u
  LIBDIR=$(mktemp -d /var/lib/fleet/deploy-src/rollback.XXXXXXXX) || exit
  trap 'rm -rf "${LIBDIR:?}"' EXIT
  git --git-dir=/var/lib/fleet/deploy-src/orbit.git show main:scripts/lib/deploy/summary.sh >"${LIBDIR:?}/summary.sh" || exit
  SHA=$(git-as orbit -C /var/www/orbit rev-parse --verify HEAD) || exit
  ROOT=/var/www/orbit bash -c '. "$1/summary.sh" && deploy_record_rollback "$2" runbook' _ "${LIBDIR:?}" "${SHA:?}"
)
```

It prints `ROLLBACK <sha> recorded` or a `REFUSED:` line naming why. Then **rebuild what the deploy built** — asset build,
`build:retain`, `view:clear`, the drain, the four restarts, then the `verify.sh` block above; unrestarted, it serves the bad
build. ⚠ Root's compose here and in the lift blocks reads the checkout's compose and `.env` (card 536).

**For the record — the revert PR, from a root-owned private clone, never this tree nor a worktree of it:**

```bash
git clone git@github.com:gcotcheza/orbit.git /srv/worker-scratch/orbit-revert
cd /srv/worker-scratch/orbit-revert
git switch -c revert/<sha>                             # the clone is on main; a PR needs its own head
git revert -m 1 --no-edit <sha>                        # -m 1 = keep main's side
git push -u origin revert/<sha>
gh pr create --draft --fill --base main --head revert/<sha>
```

Ghie merges it; the next deploy lands it, and the `reset --hard` holds until then. **Migrations are not reverted** — check
each is additive first; a destructive one needs its own written-down rollback.
