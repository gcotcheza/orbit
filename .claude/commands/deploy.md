# Deploy Orbit

**Project:** Orbit (Laravel 13 + Vue 3 SPA, PHP 8.5, Postgres 18, Redis)
**Directory:** `/var/www/orbit`
**URL:** https://flights.ghiecode.io
**Remote:** git@github.com:gcotcheza/orbit.git
**Branch:** main
**Linux user:** `orbit` (uid 115, gid 119)
**Compose project:** `orbit` (top-level `name: orbit` in `docker-compose.yml`) — plain `docker-compose.yml`, no `-f` needed
**Upstream:** the stack's nginx sidecar, published on **127.0.0.1:3085 only**

## How this is wired (read once before deploying)

- **Six services.** `app` (php-fpm), `horizon` (queue worker), `scheduler`
  (`schedule:work`), `web` (nginx sidecar, the only published port), `postgres`,
  `redis`. A seventh, `assets`, is `profiles: ['build']` — a task, not a service,
  so it is deliberately absent from `docker compose ps`.
- **Every container already runs as `115:119`**, which *is* the `orbit` host user.
  So `docker compose exec -T app php artisan …` writes files the app can read
  back. There is no `-u` flag anywhere in this runbook and none is wanted.
- **⚠ The containers boot the code once.** php-fpm, Horizon and `schedule:work`
  are long-lived processes holding an opcache and a booted framework. New code on
  disk changes nothing until they are told. This is why step 8 exists and is not
  optional — a deploy that skips it looks completely successful and serves the
  old app.
- **⚠ Root-run git re-owns files.** `git pull` as root leaves root-owned objects
  in the checkout *and in `.git`*, and the next `sudo -u orbit` git operation then
  fails on its own repository. Step 2 chowns the whole tree, `.git` included.
- **The host nginx vhost is a separate file.** `/etc/nginx/sites-available/flights-ghiecode`
  is what nginx reads; `deploy/nginx/flights-ghiecode.conf` in this repo is the
  reviewable copy. Editing the repo copy deploys nothing. See `docs/GO-LIVE.md`.
- **⚠ The SPA catch-all answers 200 for every unclaimed path.**
  `routes/web.php` ends in `Route::get('/{any?}')` with a negative lookahead of
  only `api|up|horizon`, so `/sw.js`, `/manifest.webmanifest` and
  `/definitely-not-a-route` all return **200 `text/html`** — the shell. A status
  code alone therefore proves nothing about those paths. **Assert
  `Content-Type`**, which is what step 9 does.
- **⚠ `SESSION_SECURE_COOKIE=true` breaks naive curl cookie replay.** Both cookies
  are `Secure`, so over the loopback's plain HTTP curl will not even **store**
  them: `-c jar` writes a file of comments and no rows. `-c`/`-b` therefore
  authenticate nothing, silently, and the 401 you get means "curl dropped the
  cookie" rather than "the app is broken". The cookies have to be lifted off
  `Set-Cookie` and sent back as an explicit `Cookie:` header — step 9.3 does it.

## Pre-flight checks

1. Confirm the branch in `/var/www/orbit` is `main`; if not, warn and stop.
   ```bash
   git -C /var/www/orbit rev-parse --abbrev-ref HEAD    # expect: main
   ```
2. Confirm the working tree is clean; if not, warn and ask before continuing.
   ```bash
   git -C /var/www/orbit status --porcelain             # expect: no output
   ```
3. Show the last 3 commits so the user can confirm what is already live.
   ```bash
   git -C /var/www/orbit log --oneline -3
   ```
4. **`scripts/check.sh` must be green on the merge commit being deployed.** From
   PR3 onwards this is the merge gate (`docs/PLAN.md`), so normally it was green
   on the branch before merge — but a merge commit is code no run has seen. Run it
   once here, on `main`, after step 1's pull:
   ```bash
   cd /var/www/orbit && sudo -u orbit ./scripts/check.sh
   ```
   **Good:** five headed steps — Pint, PHPStan, ESLint, Vitest, PHPUnit — and a
   final green `==> all checks passed`. It stops at the first failure; a failure
   is a stop, not a note.
   The PHP steps need the stack already up (`docker compose up -d`); the node
   steps bring their own container.

## Deploy steps

If any step fails, **stop and report** — do not continue to the next one.

```bash
cd /var/www/orbit
```

1. **Pull latest code**
   ```bash
   git -C /var/www/orbit pull origin main
   ```
   **Good:** a fast-forward, and `git log --oneline -1` is the merge commit you
   expected. If it is not a fast-forward, stop: something was committed on the box.

2. **Fix ownership** (root-run git re-owns files — see above)
   ```bash
   chown -R orbit:orbit /var/www/orbit
   ```
   **Good:** silent. Verify with `ls -ld /var/www/orbit/.git` → `orbit orbit`.
   **`.git` is included on purpose**; chowning only the worktree leaves the next
   `sudo -u orbit git` call failing on "dubious ownership".

3. **Build the front-end assets**
   ```bash
   docker compose --profile build run --rm assets
   ```
   **Good:** `npm ci` then `vite build`, ending in a table of chunks written to
   `public/build/`, and the container exits 0.
   `--profile build` is required — `assets` is a task and is not running, so
   `exec` cannot reach it and `run --rm` is the verb.

4. **Prune old builds**
   ```bash
   docker compose exec -T app php artisan build:retain
   ```
   **Good:** a `keeping …` line naming up to three build versions, and a count of
   deleted files.
   - **⚠ AFTER the asset build, never before.** It snapshots *the build that is
     currently on disk* — running it first would record the previous build and
     then prune the one you just made.
   - **⚠ Not optional.** `vite.config.js` sets `emptyOutDir: false` so a build
     *adds* chunks rather than replacing the directory (which is what keeps a page
     open across a deploy alive when it fires a lazy import). Nothing else removes
     them, so skipping this is a disk that fills up.
   - **⚠ FIRST RUN ONLY — it prunes everything predating its ledger.** Retention
     is "keep the newest N snapshots, keep the union of the files they name,
     delete every other file in `assets/`". Before the first run there are no
     snapshots, so the first run's ledger names exactly one build — the one you
     just built — and **every chunk from every earlier build is deleted**. Run it
     for the first time only *after* the first post-merge asset build, and accept
     that any page held open across that one deploy will fail its next lazy
     import. Every subsequent run is boring.
   - It also runs daily at 03:10 from `routes/console.php`, so a forgotten step
     here is a day of extra chunks rather than a full disk.

5. **Composer dependencies — ONLY if `composer.lock` moved**
   ```bash
   git -C /var/www/orbit diff --name-only HEAD@{1} HEAD -- composer.lock    # empty? skip this step
   docker compose exec -T app composer install --no-dev --optimize-autoloader --no-interaction
   ```
   **Good:** `Nothing to install, update or remove` (if you ran it anyway) or a
   package list ending in `Generating optimized autoload files`.
   `--no-dev` keeps phpunit, larastan, mockery and friends out of the production
   classmap. It also means **`php artisan test` and `vendor/bin/pint` stop working
   in the `app` container** — by design; `scripts/check.sh` is run before the
   merge, not here.

6. **Run migrations**
   ```bash
   docker compose exec -T app php artisan migrate --force
   ```
   **Good:** either `Nothing to migrate` or a list of migrations each ending
   `DONE`. `--force` is required: `APP_ENV=production` makes migrate refuse to
   run interactively-unconfirmed.

7. **Clear compiled views**
   ```bash
   docker compose exec -T app php artisan view:clear
   ```
   **Good:** `INFO  Compiled views cleared.`
   Blade compiles to `storage/framework/views/` keyed by source path, not by
   content hash, so a changed `app.blade.php` can otherwise keep serving the old
   compiled file.

8. **Restart the long-lived processes** — the step that actually ships the code
   ```bash
   docker compose exec -T app php artisan horizon:terminate
   docker compose restart app horizon scheduler web
   ```
   **Good:** `horizon:terminate` prints nothing much and exits 0; `restart` prints
   four `Restarting`/`Started` lines.
   - **`horizon:terminate` FIRST, and it is not redundant with `restart`.** It
     asks the workers to finish the job in hand and then exit, so an in-flight
     fare poll or alert send completes instead of being killed mid-transaction.
     `restart` alone would SIGTERM them.
   - **`web` is in the list** because the nginx sidecar reads
     `docker/web/nginx.conf` only at start — the `/globe/` and `/build/` cache
     locations live there, and a config change is invisible until the sidecar
     restarts.
   - `postgres` and `redis` are **not** in the list and must not be: nothing about
     a code deploy changes them, and bouncing redis makes every container holding
     a connection answer `NOAUTH` until it reconnects.

## Post-deploy verification

Everything below is a plain GET and safe to repeat. Before go-live the vhost is
not enabled, so these go at the loopback with an explicit `Host:` header —
**without it the sidecar answers `400`**, which is a correct answer to a
hostless request and not a fault.

```bash
H='Host: flights.ghiecode.io'
B='http://127.0.0.1:3085'
# After go-live, drop -H and use B='https://flights.ghiecode.io'.
```

1. **The shell loads.**
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' -H "$H" "$B/"          # expect 200
   curl -s -H "$H" "$B/" | grep -oE 'build/assets/app-[A-Za-z0-9_-]+\.js'
   ```
   **Good:** `200`, and an `app-<hash>.js` whose hash **changed** if step 3 rebuilt
   anything. An unchanged hash after a front-end change means the build did not
   land.

2. **The health endpoint.**
   ```bash
   curl -s -H "$H" "$B/up" | grep -c 'Application up'              # expect 1
   ```
   **Good:** `1`. **Check the body, not the status** — `/up` is excluded from the
   SPA catch-all, so if this ever returns the shell instead (`id="app"`), routing
   itself is wrong rather than the app being down.

3. **One authenticated API call.**

   **⚠ `-c`/`-b` DO NOT WORK HERE, and they fail silently.**
   `SESSION_SECURE_COOKIE=true` marks both cookies `Secure`, and over plain
   loopback HTTP curl will not even **store** them — `-c jar` writes a file
   containing nothing but comments, and every later `-b jar` sends no cookie at
   all. The result is a 401 that means "curl dropped the cookie", not "the app
   rejected you". Rewriting the jar's Secure column does not help either: there is
   no row in it to rewrite.

   **The cookies have to come off the response headers and go back as an explicit
   `Cookie:` header**, bypassing curl's cookie engine entirely. This is PR #4's
   flow, reproduced with the mechanism that works:

   ```bash
   # 1. CSRF + session cookies, read straight out of Set-Cookie. Expect 204.
   HDR=$(mktemp)
   curl -s -D "$HDR" -o /dev/null -H "$H" "$B/sanctum/csrf-cookie"
   COOKIE=$(awk 'tolower($1)=="set-cookie:"{split($2,a,";"); printf "%s%s", (n++?"; ":""), a[1]}' "$HDR")

   # The header value must be the URL-DECODED cookie: Laravel decrypts it, and
   # the base64 padding arrives as %3D.
   XSRF=$(printf '%s' "$COOKIE" | sed -n 's/.*XSRF-TOKEN=\([^;]*\).*/\1/p' \
          | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
   ```

   **Check the plumbing before spending a login attempt** — this proves the
   session and CSRF token are accepted, and it costs nothing because the request
   is refused at auth and can change no data:

   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' -X POST -H "$H" \
        -H "Cookie: $COOKIE" -H "X-XSRF-TOKEN: $XSRF" -H 'Accept: application/json' "$B/api/watchlist"
   # Good: 401  — CSRF passed, auth refused. (Drop the X-XSRF-TOKEN header and
   #              the same call is 419, which is how you know 401 meant something.)
   ```

   Then the real thing:

   ```bash
   # 2. Log in. Laravel REGENERATES the session id on login, so the response
   #    carries a new orbit-session — capture it or step 3 is still a guest.
   OUT=$(mktemp)
   curl -s -D "$OUT" -H "$H" -H "Cookie: $COOKIE" -H "X-XSRF-TOKEN: $XSRF" \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -d '{"email":"ghie.cotcheza@gmail.com","password":"…"}' \
        -w '\n%{http_code}\n' "$B/login"
   AUTHED=$(awk 'tolower($1)=="set-cookie:"{split($2,a,";"); printf "%s%s", (n++?"; ":""), a[1]}' "$OUT")

   # 3. The authenticated read.
   curl -s -H "$H" -H "Cookie: ${AUTHED:-$COOKIE}" -H 'Accept: application/json' \
        -w '\n%{http_code}\n' "$B/api/me"
   rm -f "$HDR" "$OUT"
   ```

   **Good:** `204`, then `200` with `{"data":{"id":1,"name":"Ghie",…}}`, then `200`
   with keys `email, id, name`.
   **⚠ `POST /login` is throttled 5/min on `email|ip`** and the throttle runs
   before validation, so a fumbled password costs a slot. It recovers in a minute.

   **Cheaper smoke, if the password is not to hand** — still proves the auth stack
   and the JSON error renderer are wired, and needs no cookies at all:
   ```bash
   curl -s -H "$H" -H 'Accept: application/json' -w '\n%{http_code}\n' "$B/api/me"
   ```
   **Good:** `401` and `{"message":"Unauthenticated."}` — **not** a redirect and
   **not** HTML. (An HTML body here would mean the JSON-rendering rule in
   `bootstrap/app.php` stopped applying under `/api/`.)

4. **The PWA surface — once PR #10 (`feat/pwa`) is merged.**
   ```bash
   curl -sI -H "$H" "$B/manifest.webmanifest" | grep -i content-type   # application/manifest+json
   curl -sI -H "$H" "$B/sw.js"                | grep -i content-type   # application/javascript; charset=utf-8
   ```
   **⚠ Status code is worthless here and this is the trap.** Both paths return
   **200 `text/html`** today, *before* the PWA is merged, because the SPA
   catch-all swallows them. `text/html` on either of these means the route is not
   registered — the shell is answering. Assert the `Content-Type`.
   ```bash
   curl -s -H "$H" "$B/sw.js" | grep -oE "PRECACHE|app-[A-Za-z0-9_-]+\.js" | sort -u
   ```
   **Good:** the service worker names the **current** `app-<hash>.js` — the same
   hash step 1 printed. A stale hash here means `build:retain` or the build ran in
   the wrong order.

5. **Static asset caching.**
   ```bash
   curl -sI -H "$H" "$B/build/assets/app-<hash>.js" | grep -i cache-control
   ```
   **Good:** `public, max-age=31536000, immutable` (hashed filenames).
   `/globe/` and `/icons/` are `public, max-age=604800` — a week, not immutable,
   because those filenames carry no content hash.

6. **The stack itself.**
   ```bash
   docker compose ps
   ```
   **Good:** `app`, `horizon`, `scheduler`, `web`, `postgres`, `redis` all `Up`;
   `horizon`, `postgres` and `redis` additionally `(healthy)`. **`assets` is
   absent and that is correct** — it is `profiles: ['build']`, a task. `web` is the
   only one with a published port, and it reads `127.0.0.1:3085->8080/tcp`. If it
   reads `0.0.0.0:3085`, stop: the stack is exposed to the internet.

7. **The queue is alive and empty of failures.**
   ```bash
   docker compose exec -T app php artisan horizon:status
   docker compose exec -T app php artisan queue:failed
   ```
   **Good:** `Horizon is running.` and `No failed jobs found.`
   `horizon:status` is the check that step 8's `horizon:terminate` was followed by
   a supervisor that actually came back — a terminate whose container did not
   restart leaves a silent queue, and nothing else in this battery would notice.

8. **Nothing threw during the deploy.**
   ```bash
   docker compose exec -T app tail -n 40 storage/logs/laravel.log
   ```
   **Good:** no `ERROR`/`CRITICAL` newer than the restart. Until Resend is
   configured this file is also where **every alert email** lands
   (`MAIL_MAILER=log`), so mail lines here are expected, not a fault.

## Rollback

The deploy is a merge commit, so the rollback is a revert of that merge — not a
`reset`, which would leave `main` behind its remote and the next deploy would
"pull" the bad code straight back.

```bash
git -C /var/www/orbit log --oneline -5                 # find the merge commit <sha>
git -C /var/www/orbit revert -m 1 --no-edit <sha>      # -m 1 = keep main's side
git -C /var/www/orbit push origin main
chown -R orbit:orbit /var/www/orbit
```

Then **redeploy from step 3** — the revert is only code on disk until the assets
are rebuilt and the containers are restarted. Reverting and not restarting leaves
the bad build serving.

**Migrations are not reverted, and mostly do not need to be.** Every migration in
this repo so far is **additive** — new tables (`deal_rules` arrives with PR #11)
and new columns, nothing dropped or retyped — so the reverted code simply ignores
them and the database is compatible with both sides. Do **not** reach for
`migrate:rollback` as a reflex: dropping a table the reverted code does not read
buys nothing and loses the rows. Check the migration before assuming, and if a
future one is destructive it needs its own written-down rollback rather than this
paragraph.

**Assets survive a rollback on purpose.** `build:retain` keeps the newest three
builds, so the previous build's chunks are still on disk and a phone holding a
reference to them still resolves while the revert is deploying.
