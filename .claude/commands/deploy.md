# Deploy Orbit

**Project:** Orbit (Laravel 13 + Vue 3 SPA, PHP 8.5, Postgres 18, Redis, Horizon)
**Directory:** `/var/www/orbit` · **URL:** https://flights.ghiecode.io · **Remote:** git@github.com:gcotcheza/orbit.git
**Compose project:** `orbit` (top-level `name: orbit`) · **Linux user:** `orbit` (115:119) · **Upstream:** the stack's nginx
sidecar, published on **127.0.0.1:3085 only**

`scripts/deploy.sh` **is** this runbook. Every command the old procedure typed by hand lives inside it, in the same order, and
`scripts/deploy-test.sh` is what holds it there. What is left here is what a person still decides and what each printed line
means. The first deploy of all is `docs/GO-LIVE.md` and is not repeated here.

## Before you run it

1. **Ghie merged the pull request.** Nobody else merges, and only a merged pull request deploys.
2. **The ledger holds a green `ci` and a green `e2e` for the merged head.** Both gates append their own line to
   `/var/lib/fleet/gate-ledger` from the clone that ran them, so they are proved before the merge and **not** re-run here: the
   merge's tree is identical to the gated head's, which `resolve` checks.
3. **Run as root**, from the box. Talking to `/var/run/docker.sock` is a group membership `orbit` does not have; every git line
   goes through `git-as`, and every container already runs as `115:119`.
4. **One deploy a day, and this is that one.** Ghie's rule, not a technical limit.

**The first landing of the script itself** is the one deploy `/var/www/orbit` cannot run: the script and its helpers arrive
with the merge that needs them, so that checkout has neither file until it has landed. Run the merge commit's own copy, from a
worktree rather than the shared clone — detaching that clone is a trap for whoever opens it next:

```bash
git -C /srv/sessions/orbit/repo fetch origin
git -C /srv/sessions/orbit/repo worktree add /srv/worker-scratch/orbit-land-pr<N> <merge-sha>
DEPLOY_ROOT=/var/www/orbit bash /srv/worker-scratch/orbit-land-pr<N>/scripts/deploy.sh <N>
```

The worktree is **at the merge commit** because a tree still on the old main runs the OLD script, which is the bug this recipe exists for.

## The one command

```bash
cd /var/www/orbit && scripts/deploy.sh <PR#>
```

It takes a pull request number and nothing else. One switch exists — `--gated-by-hand` skips the ledger and says so in its own
line and in `DONE` — and **Orbit does not use it**: the recipe below is never longer than gating properly.

**A docs-only merge is the same command.** The script asks `scripts/docs-only.sh` what the merge changes; documentation and
nothing else is fast-forwarded onto the box, proved with one `/up`, one public GET and the ownership count, and stopped there —
no gate, no build, no restart, because not one running process reads a Markdown file.

## If it says NOT GATED

The script prints this recipe with the shas filled in. **The gate runs in a worktree cut from the root-owned clone, never in
`/var/www/orbit`** — that checkout is bind-mounted into three containers, so a gate there installs dev dependencies in
production (`docs/DECISIONS.md`). An accepted hazard, until it did not have to be.

```bash
git -C /srv/sessions/orbit/repo worktree add /srv/worker-scratch/orbit-gate-pr<N> <the merged head>
cd /srv/worker-scratch/orbit-gate-pr<N>
export COMPOSE_PROJECT_NAME=orbit-gate-pr<N>
heavy-work orbit-gate-pr<N> -- bash scripts/check.sh overlay
heavy-work orbit-e2e-pr<N> -- bash scripts/e2e.sh
```

Both write their ledger line at the end of a green run; then re-run `scripts/deploy.sh <PR#>`. A head without both greens is
re-gated, not argued with. `docs/DEVELOPMENT.md` lists the five paths `scripts/e2e.sh` needs handed over in a root-owned worktree.

## What it prints

One line per phase on stdout, the whole run in `/root/personal-vps-deploys/orbit/<utc>-pr<N>.log`.

| line | what it means |
|---|---|
| `RESOLVED #N head … merge …` | gh says MERGED, the merge commit **is** `origin/main`, and its tree is the tree that was gated |
| `CLASSIFIED code` / `LANDED docs-only …` | the classifier's answer; a landing ends the run |
| `GATED … ci and e2e both green` | the ledger was read. `NOT GATED` prints the recipe above and stops |
| `PRE-FLIGHT load … available …` | the box as it was; it never refuses |
| `STEP 0 baseline recorded` | the served bundle hash and the four containers' start times, before anything moves |
| `STEP 1 rollback target <sha>` | the sha to go back to. It is also `was …` in `DONE` |
| `STEPS 1-10 ok in one heavy-work job` | fetch, fast-forward, migrate, the restarts, and only the steps whose files moved; it names which ran |
| `HEALTH … healthy …` | docker's own healthchecks for the restarted services have all gone green, which is the state the battery's check 6 demands. `HEALTH TIMEOUT` means the release is landed and serving and the battery was **not** run — it names the container and its last healthchecks, and it is not a rollback |
| `VERIFY --backend-only` / `VERIFY full` | `--backend-only` when step 5 did not run, so an unchanged bundle is expected |
| `HOST VHOST NEEDED, NOT RUN …` | `deploy/nginx` moved, and nginx reads `/etc/nginx/sites-available/flights.ghiecode.io`, which no pull touches. By hand, in this order: `nginx -t` · copy the file · `nginx -t` · `systemctl reload nginx`. Both tests say `syntax is ok`; never reload on a failed second one |
| `DONE #N live … was … gated … root-owned 0 verify …` | the deploy is finished. `root-owned` must read `0` |
| `PAPERWORK PR #N deployed …` | backlog, handoff and the fleet-docs page still want a line from you |
| `REFUSED: …` | nothing moved. `FAILED rc=…` with a 20-line tail means something did — read the log, do not re-run a step |

**⚠ The containers boot the code once**, so a deploy that stops before the restarts looks entirely successful and serves the old
app. `scripts/verify.sh` proves each of the four restarted from its `StartedAt`; the drain and the horizon-container trap that
cost months of silent SIGTERMs are in `docs/DECISIONS.md`.

## What stays human

- **Which pull request**, and whether it is the one Ghie merged.
- **One look at the site, on a phone** when the release touches anything you tap, against the pull request's "What you'll
  notice". The script proves the code is live, not that it is right, and nothing in the gate has thumbs.
- **The host vhost**, when the notice fires; the script never edits `/etc`. And **the paperwork**: backlog, handoff, and the
  project's page on docs.ghiecode.io.
- **A surprise in the log** — a migration you did not expect is a conversation, not a deploy step.

## Authenticated writes — not part of the battery

**⚠ EVERYTHING IN THIS SECTION CHANGES PRODUCTION DATA.** Nothing in `scripts/verify.sh` does. Prefer not to run any of it:
`scripts/e2e.sh` drives every one of these writes through a real browser, against a sandbox where a mistake costs nothing.

`$H`, `$B` and `$AUTHED` are the shell `scripts/verify.sh` check 3 mechanises — set `H='Host: flights.ghiecode.io'`,
`B='http://127.0.0.1:3085'` and lift the cookies the way that check does. **A write needs the CSRF token lifted again, from the
login response**: `login()` regenerates the session and mints a new token, so the pre-login one answers **419**, which reads
exactly like "CSRF is broken on this deploy" and is not.

```bash
AUTH_XSRF=$(printf '%s' "$AUTHED" | sed -n 's/.*XSRF-TOKEN=\([^;]*\).*/\1/p' \
            | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
```

**Pausing a route, and putting it back.** Both halves are written here as one block on purpose. A paused route is skipped by the
06:10 poll in silence — no alert fires for it and nothing anywhere says so — until somebody notices by eye. Do not run the
pause without running the restore.

```bash
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
```

**Good:** `200` from each PATCH, and `[True]` from the read. A `419` from a PATCH means the token, not the app. Anything other
than `[True]` at the end means a production route is still paused — put it back before you walk away.

**⚠ A bare `PUT /api/profile/password` is 419, not 401.** `ValidateCsrfToken` runs before `auth`, so a request with no cookies
is refused for having no token and never reaches the guard. Only the full lift above makes a 401 mean "unauthenticated".

## Rollback

The deploy is a merge commit, so what has to reach `main` is a revert of that merge: a reset on the box alone leaves `main`
carrying the bad code and the next deploy pulls it straight back. **⚠ NOTHING ON THIS BOX CAN PUSH** — `git-as` uses the app's
read-only deploy key and root's git cannot enter this tree — so the rollback is two separate things.

**On disk — put the checkout back on the sha `DONE` printed as `was`:**

```bash
git-as orbit -C /var/www/orbit --no-optional-locks log --oneline -5   # confirm what is live
git-as orbit -C /var/www/orbit reset --hard <the sha DONE printed as was>
find /var/www/orbit -user root -not -path '/var/www/orbit/.claude/*' | wc -l
```

The count must print `0`. Then **rebuild what the deploy built** — the asset build, `build:retain`, `view:clear`, the drain and
the four restarts, then `scripts/verify.sh` — because reverting and not restarting leaves the bad build serving. Reverting the
merge and deploying that is the shorter path whenever there is time for it.

**For the record — the revert PR, from a root-owned private clone, never from this tree nor a worktree of it** (a worktree here
is `orbit`-owned too, so `git-as` would push it with the same read-only key):

```bash
git clone git@github.com:gcotcheza/orbit.git /srv/worker-scratch/orbit-revert
cd /srv/worker-scratch/orbit-revert
git switch -c revert/<sha>                             # the clone is on main; a PR needs its own head
git revert -m 1 --no-edit <sha>                        # -m 1 = keep main's side
git push -u origin revert/<sha>
gh pr create --draft --fill --base main --head revert/<sha>
```

Ghie merges it; the next deploy lands it, and the `reset --hard` above is what holds until then.

**Migrations are not reverted, and so far do not need to be**: every one in this repo is additive, so the reverted code ignores
the new columns. Check before assuming — a destructive one needs its own written-down rollback. **Assets survive a rollback on
purpose**: `build:retain` keeps the newest three builds, so a phone holding a reference to the previous one still resolves while
the revert is deploying.
