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
  disk changes nothing until they are told. This is why step 9 exists and is not
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
  `Content-Type`**, which is what post-deploy check 4 does.
- **⚠ `SESSION_SECURE_COOKIE=true` breaks naive curl cookie replay.** Both cookies
  are `Secure`, so over the loopback's plain HTTP curl will not even **store**
  them: `-c jar` writes a file of comments and no rows. `-c`/`-b` therefore
  authenticate nothing, silently, and the 401 you get means "curl dropped the
  cookie" rather than "the app is broken". The cookies have to be lifted off
  `Set-Cookie` and sent back as an explicit `Cookie:` header — post-deploy check 3
  does it.

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
4. **The eight checks must be green on the merge commit being deployed.** From
   PR3 onwards this is the merge gate (`docs/PLAN.md`), so normally it was green
   on the branch before merge — but a merge commit is code no run has seen. Run
   it once here, on `main`, after the pull (deploy step 1).

   **⚠ `./scripts/check.sh` DOES NOT RUN ON THIS BOX AND MUST NOT BE MADE TO.**
   Every step in it is `docker compose exec -T app …`, i.e. the *live* php-fpm
   container, whose `vendor/` was installed `--no-dev` (deploy step 3) —
   there is no `vendor/bin/pint`, no `vendor/bin/phpstan` and no phpunit in it.
   The script is the *pre-merge* gate, run against a checkout that has dev
   dependencies. Installing them here to make it work is the trap: `./` is
   bind-mounted into `app`, `horizon` and `scheduler`, so a dev `composer
   install` in this checkout is a dev `composer install` **in production**.

   **The gate runs in a throwaway container with its own vendor tree instead.**
   Same image, same uid, same PHP as the site — and nothing it writes is
   visible to the running containers:

   ```bash
   set -euo pipefail

   GATE=/var/tmp/orbit-gate
   mkdir -p "$GATE/vendor" "$GATE/bootstrap-cache" && chown -R 115:119 "$GATE"

   # 0. dev dependencies, into the overlay and nowhere near the live vendor/
   docker compose run --rm --no-deps \
       -v "$GATE/vendor:/var/www/html/vendor" \
       -v "$GATE/bootstrap-cache:/var/www/html/bootstrap/cache" \
       app composer install --no-interaction --no-progress

   # 1. Gitleaks — git's view of the tree, copied out. A gitignored .env is
   #    never in $work/scan, so the scanner cannot read one, here of all places.
   #    The subshell is the cleanup guarantee: its trap fires however it ends.
   (
       set -euo pipefail
       work=$(mktemp -d)
       trap 'rm -rf "$work"' EXIT
       mkdir "$work/scan"

       git -C /var/www/orbit ls-files -z --cached --others --exclude-standard >"$work/list"
       if [ ! -s "$work/list" ]; then
           echo 'gate: git listed no file to scan. The step would have scanned' >&2
           echo '  nothing and reported no leaks. That is a failure.' >&2
           exit 1
       fi
       if grep -qzE '(^|/)\.gitleaks(\.toml|ignore)$' "$work/list"; then
           echo 'gate: the tree carries a gitleaks allowlist file, which would let' >&2
           echo '  the scanned code decide what the scanner may find. Delete it.' >&2
           exit 1
       fi

       tar -C /var/www/orbit --null -T "$work/list" -cf - | tar -xf - -C "$work/scan"
       if [ -z "$(ls -A "$work/scan")" ]; then
           echo 'gate: the scan directory came out empty. Not calling that clean.' >&2
           exit 1
       fi

       docker run --rm --network none -v "$work/scan:/scan:ro" \
           zricethezav/gitleaks:v8.30.1 \
           dir /scan --no-banner --redact --verbose --ignore-gitleaks-allow
   )

   # 2. Pint
   docker compose run --rm --no-deps \
       -v "$GATE/vendor:/var/www/html/vendor" \
       -v "$GATE/bootstrap-cache:/var/www/html/bootstrap/cache" \
       app vendor/bin/pint --test

   # 3. Composer advisories — the lockfile, production packages only
   docker compose run --rm --no-deps \
       -v "$GATE/vendor:/var/www/html/vendor" \
       -v "$GATE/bootstrap-cache:/var/www/html/bootstrap/cache" \
       app composer audit --locked --no-dev --abandoned=report

   # 4. PHPStan
   docker compose run --rm --no-deps \
       -v "$GATE/vendor:/var/www/html/vendor" \
       -v "$GATE/bootstrap-cache:/var/www/html/bootstrap/cache" \
       app vendor/bin/phpstan analyse --no-progress --memory-limit=512M

   # 5 + 6 + 7. npm advisories, ESLint and Vitest — `assets` is a task and
   #            brings its own node_modules; nothing about it is --no-dev.
   docker compose --profile build run --rm --entrypoint sh assets -c \
       '[ -d node_modules ] || npm ci --no-audit --fund=false && npm audit --omit=dev --audit-level=high'
   docker compose --profile build run --rm --entrypoint sh assets -c \
       '[ -d node_modules ] || npm ci --no-audit --fund=false && npm run lint'
   docker compose --profile build run --rm --entrypoint sh assets -c 'npm run test:js'

   # 8. PHPUnit
   docker compose run --rm --no-deps \
       -v "$GATE/vendor:/var/www/html/vendor" \
       -v "$GATE/bootstrap-cache:/var/www/html/bootstrap/cache" \
       app php artisan test

   # and take the overlay away again
   rm -rf "$GATE"
   ```

   **Good:** Gitleaks `no leaks found`, Pint `PASS`, `composer audit` and
   `npm audit` reporting no advisories, PHPStan `[OK] No errors`, ESLint
   silent, Vitest all green, and PHPUnit ending in `OK`. A failure is a stop,
   not a note.

   - **⚠ `bootstrap/cache` IS OVERLAID FOR A DIFFERENT AND WORSE REASON THAN
     `vendor`.** `composer install` fires `@php artisan package:discover` on
     every run, which writes `bootstrap/cache/packages.php` and `services.php` —
     the list of service providers the framework loads at boot. Run with dev
     dependencies present, that list names dev-only providers (Pail, Collision,
     Larastan's, whatever a package adds next). Those files are in the same
     bind-mounted checkout, so the **live, `--no-dev`** app would read them on
     its next boot, try to load a class that is not in its vendor tree, and
     answer **every request with a 500** — an outage caused by a gate run,
     minutes after it reported all green. The overlay is what keeps
     `package:discover`'s output inside the throwaway container.
   - **⚠ `--no-deps`** so that `run` does not start or restart `postgres` and
     `redis` behind your back. The suite needs neither: `phpunit.xml` pins
     sqlite `:memory:` and the array cache/session drivers.
   - `--rm` and `run` (not `exec`): this is a container that exists for one
     command. The live `app` container is never entered and never changed.
   - **⚠ NOT `sudo -u orbit`.** Talking to `/var/run/docker.sock` is a **group
     membership** — `orbit` is not in the `docker` group and gets
     `permission denied while trying to connect to the Docker daemon socket`
     before the first check runs. Run it as root. Nothing it does lands
     root-owned files in the checkout: the containers it drives are already
     `user: '115:119'`, which *is* the `orbit` user (see "How this is wired"),
     so file ownership is handled inside them rather than by the invoking shell.
     `$GATE` is chowned to the same uid for the same reason.
   - **⚠ The PHPUnit step writes into `storage/logs/laravel.log`.** It is the
     real checkout, bind-mounted, so a test that exercises a logging path leaves
     `testing.INFO` / `testing.ERROR` lines in production's application log.
     They are the gate's own noise and not incidents — the environment name in
     front of the level is how you tell. See post-deploy check 8.

5. **The browser gate is NOT a pre-flight check. It is deploy step 6.**

   `scripts/e2e.sh` used to be listed here, and being listed here is what broke
   deploy `ab262c4`. It drives a real browser against **the checkout's own
   `public/build/`**, which at pre-flight time is still the *previous* deploy's
   bundle — the code has been pulled, the assets have not been rebuilt, and the
   suite fails on a UI that does not exist yet. A red gate that says nothing
   about the commit being deployed is worse than no gate: it is fifteen minutes
   spent looking for a bug in the app.

   It has to run **after the asset build**, so it now lives with the deploy steps
   as step 6. Nothing else moved.

## Deploy steps

If any step fails, **stop and report** — do not continue to the next one.

```bash
cd /var/www/orbit
```

**⚠ THE ORDER IS ABOUT ONE WINDOW: PULL → MIGRATE.** The checkout is
bind-mounted into `app`, `horizon` and `scheduler`, so `git pull` puts the new
code on disk *in the running containers* immediately. The long-lived processes
keep serving the old code from opcache (which is why step 9 exists) — but
anything that boots the framework fresh in that window runs **new code against
an unmigrated database**: every `artisan` the scheduler spawns, every Horizon
worker that recycles after its job limit, every `php artisan` a person types.
A missing-column exception on the fare poll is what that looks like.

So the window is kept to the length of one `migrate`, and everything slow — the
asset build especially, which is an `npm ci` and a Vite build and takes minutes
— happens **after** the schema and the code agree. That is the only reason the
build is not step 3 any more.

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

3. **Composer dependencies — ONLY if `composer.lock` moved**
   ```bash
   git -C /var/www/orbit diff --name-only HEAD@{1} HEAD -- composer.lock    # empty? skip this step
   docker compose exec -T app composer install --no-dev --optimize-autoloader --no-interaction
   ```
   **Good:** `Nothing to install, update or remove` (if you ran it anyway) or a
   package list ending in `Generating optimized autoload files`.
   `--no-dev` keeps phpunit, larastan, mockery and friends out of the production
   classmap. It also means **`php artisan test` and `vendor/bin/pint` do not work
   in the `app` container** — by design, and the reason pre-flight step 4 runs the
   gate in a throwaway container with its own vendor tree.
   - **⚠ BEFORE the migration, not after.** A migration is code too: if the
     commit being deployed adds one that reaches for a class from a package the
     lockfile just introduced, running it against the old vendor tree is a fatal
     halfway through a schema change.

4. **Run migrations**
   ```bash
   docker compose exec -T app php artisan migrate --force
   ```
   **Good:** either `Nothing to migrate` or a list of migrations each ending
   `DONE`. `--force` is required: `APP_ENV=production` makes migrate refuse to
   run interactively-unconfirmed.
   - **⚠ THIS CLOSES THE WINDOW OPENED BY THE PULL** — see the note above the
     steps. It is deliberately the first slow-ish thing after the code lands and
     is deliberately ahead of the asset build: a schema that is minutes behind
     the code on disk is minutes of a scheduler running new queries against an
     old database.

5. **Build the front-end assets**
   ```bash
   docker compose --profile build run --rm assets
   ```
   **Good:** `npm ci` then `vite build`, ending in a table of chunks written to
   `public/build/`, and the container exits 0.
   `--profile build` is required — `assets` is a task and is not running, so
   `exec` cannot reach it and `run --rm` is the verb.

6. **`scripts/e2e.sh` — the browser gate. Optional, strongly recommended.**
   ```bash
   cd /var/www/orbit && ./scripts/e2e.sh
   ```
   **Good:** the `orbit-e2e` stack comes up, migrates, seeds, runs the browser
   suite and tears itself down, ending in a green `==> browser gate passed`.
   About 90 seconds after the first run.

   - **⚠ HERE, AND NOT IN THE PRE-FLIGHT, WHICH IS WHERE IT USED TO BE.** It
     serves the app out of the checkout's own `public/build/`, and it only builds
     one **if there is none** — so run before step 5 it tests the code that was
     just pulled through the bundle that was already there. Deploy `ab262c4` is
     that mistake: a red suite, a green app, and the difference was a stale
     JavaScript bundle. After step 5 the directory holds the build for the commit
     being deployed, which is the only thing worth driving a browser at.
   - **⚠ And BEFORE step 9's restart**, which is the other half of the sandwich.
     The whole point of a gate is that there is still something to stop.

   **What it adds over pre-flight step 4, and why it is worth the time.** Not one
   of `check.sh`'s eight checks has ever seen a screen — Vitest runs the front end
   in jsdom, which has no layout engine and no rasteriser. All eight are green on
   an app whose globe renders as a black circle and whose calendar renders 31
   identical grey squares. This drives a real Chromium (WebGL on SwiftShader)
   through the eight journeys and fails on any uncaught exception. `docs/E2E.md`
   is the full description.

   **Why it is optional.** It pulls a ~2 GB Playwright image the first time it
   runs, and this box's disk is shared with six other apps. On a box that has it
   cached there is no reason to skip it.

   - **⚠ Also root, not `sudo -u orbit`** — same docker.sock reason as pre-flight
     step 4.
   - **It cannot touch the live stack.** Different compose project (`orbit-e2e`),
     different port (`127.0.0.1:3185`, never 3085), its own generated `.env.e2e`
     and its own volumes. It is safe to run **while the site is up**, which is
     how it is meant to be run.
   - It leaves nothing behind: `down -v` at the end, always. `--keep` if you want
     to look at the sandbox afterwards; `scripts/e2e.sh --down` then tears it down.
   - **⚠ It runs off the live checkout's `vendor/`, `node_modules/` and
     `public/build/`** and installs each only if it is missing — so on this box
     it uses the `--no-dev` vendor tree, which is all it needs (it drives the app
     through a browser and runs no PHP tooling). It does **not** need the gate
     overlay from pre-flight step 4.
   - **⚠ A run is good when every line has a tick.** It used to carry three
     `test.fail()` markers — rendering defects written down as tests that passed
     while the bug was there, printing a `✘` in a green run. All three are fixed
     (the follow-ups PR), the markers are gone, and **a `✘` now means a
     failure.** The last line is still the thing to read: `==> browser gate
     passed`.
   - **⚠ NO COUNT IS WRITTEN DOWN HERE ON PURPOSE.** This said "runs 32 browser
     tests" for several months during which the number was 32 exactly once. A
     figure in a runbook that nothing checks is a figure that rots, and the only
     honest reading of "it ran 29" is then a shrug. The last line is the check.

7. **Prune old builds**
   ```bash
   docker compose exec -T app php artisan build:retain
   ```
   **Good:** a `keeping …` line naming up to three build versions, and a count of
   deleted files.
   - **⚠ "Up to three" is literal.** Retention keeps the newest three
     *snapshots*, and there are only as many snapshots as there have been runs:
     the first deploy after this command exists reports one, the second two. A
     `keeping` line naming fewer than three builds on an early deploy is the
     command working, not a build that went missing.
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

8. **Clear compiled views**
   ```bash
   docker compose exec -T app php artisan view:clear
   ```
   **Good:** `INFO  Compiled views cleared.`
   Blade compiles to `storage/framework/views/` keyed by source path, not by
   content hash, so a changed `app.blade.php` can otherwise keep serving the old
   compiled file.

9. **Restart the long-lived processes** — the step that actually ships the code
   ```bash
   docker compose exec -T horizon php artisan horizon:terminate
   docker compose restart app horizon scheduler web
   ```
   **Good:** `INFO  Sending TERM signal to processes.` followed by a
   `Process: 1 … DONE` line; then `restart` prints four `Restarting`/`Started`
   lines.
   - **`horizon:terminate` FIRST, and it is not redundant with `restart`.** It
     asks the workers to finish the job in hand and then exit, so an in-flight
     fare poll or alert send completes instead of being killed mid-transaction.
     `restart` alone would SIGTERM them. `stop_grace_period: 60s` on the horizon
     service is what gives that drain time to happen.
   - **⚠ IN THE `horizon` CONTAINER, NOT IN `app` — and this runbook said `app`
     for its first several deploys.** Horizon's master supervisor registers
     itself in redis under `gethostname()`, and every container has a hostname
     of its own. Run from `app`, the command looks for a master named after the
     app container, finds none, and exits **0** with
     `INFO  No processes to terminate.` — a green line, a successful step, and
     no drain whatsoever. Measured on this box, both halves in one sitting:

     | where | output |
     | --- | --- |
     | `exec -T app php artisan horizon:terminate` | `INFO No processes to terminate.` (rc 0) |
     | `exec -T horizon php artisan horizon:terminate` | `INFO Sending TERM signal to processes.` `Process: 1 … DONE` |

     So until this line changed, **every deploy SIGTERM'd the workers mid-job**
     via `restart` and the graceful drain had never once been in effect. Reading
     the output is the check: `No processes to terminate.` means you are in the
     wrong container.
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
   **Good:** `200`, and an `app-<hash>.js` whose hash **changed** if step 5 rebuilt
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
        -d '{"email":"<SEED_USER_EMAIL from the box .env>","password":"…"}' \
        -w '\n%{http_code}\n' "$B/login"
   AUTHED=$(awk 'tolower($1)=="set-cookie:"{split($2,a,";"); printf "%s%s", (n++?"; ":""), a[1]}' "$OUT")

   # 3. The authenticated read.
   curl -s -H "$H" -H "Cookie: ${AUTHED:-$COOKIE}" -H 'Accept: application/json' \
        -w '\n%{http_code}\n' "$B/api/me"
   rm -f "$HDR" "$OUT"
   ```

   **⚠ AN AUTHENTICATED *POST* NEEDS THE CSRF TOKEN LIFTED AGAIN, FROM THE LOGIN
   RESPONSE.** Step 3 above is a GET and gets away with it. Anything that writes
   does not: `Illuminate\Auth\SessionGuard::login()` calls
   `$session->regenerate()`, which mints a **new session id and a new CSRF
   token**, and the login response carries both as fresh `Set-Cookie` headers.
   Re-using `$XSRF` from before the login sends a token that belongs to a session
   that no longer exists, and Laravel answers **419** — which reads exactly like
   "CSRF is broken on this deploy" and is not. Take it off `$OUT`, next to
   `$AUTHED`:

   ```bash
   AUTH_XSRF=$(printf '%s' "$AUTHED" | sed -n 's/.*XSRF-TOKEN=\([^;]*\).*/\1/p' \
               | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')

   # e.g. pausing a route, then putting it back — both need the NEW token.
   curl -s -o /dev/null -w '%{http_code}\n' -X PATCH -H "$H" \
        -H "Cookie: $AUTHED" -H "X-XSRF-TOKEN: $AUTH_XSRF" \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -d '{"active":false}' "$B/api/watchlist/AMS-LIS"
   ```
   **Good:** `200`. A `419` here means the token, not the app.

   **⚠ AND A BARE `PUT /api/profile/password` IS 419, NOT 401.** It is the
   obvious "is the password endpoint protected?" smoke test and it proves
   nothing: `ValidateCsrfToken` runs **before** `auth` in the `web` group, so a
   request with no cookies at all is refused for having no token and never
   reaches the guard. Only the full lift above — session cookie, then
   `X-XSRF-TOKEN` from the same session — gets far enough for a 401 to mean
   "unauthenticated". Same trap as the `-c`/`-b` one: a refusal that is real,
   for a reason that is not the one being tested.
   **Better still: don't.** `scripts/e2e.sh` (deploy step 6) drives every one
   of these writes through a real browser that handles the cookie dance itself,
   against a sandbox where a mistake costs nothing. Hand-rolled `curl` POSTs
   against **production** change production's data.

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
   `horizon:status` is the check that step 9's `horizon:terminate` was followed by
   a supervisor that actually came back — a terminate whose container did not
   restart leaves a silent queue, and nothing else in this battery would notice.

8. **Nothing threw during the deploy.**
   ```bash
   docker compose exec -T app tail -n 40 storage/logs/laravel.log
   ```
   **Good:** no `production.ERROR` / `production.CRITICAL` newer than the
   restart.
   - **⚠ `testing.*` LINES ARE THE GATE'S OWN NOISE.** Pre-flight step 4 runs
     PHPUnit against this checkout, bind-mounted, so any test that exercises a
     logging path writes into *production's* application log with `testing` as
     the environment. `testing.ERROR` next to a passing gate is a test asserting
     that something fails, not an incident. The environment name in front of the
     level is the only thing that separates them; grep for `production.` if the
     tail is busy.

9. **The alert mail nobody is receiving yet.**
   ```bash
   docker compose exec -T app tail -n 40 storage/logs/mail.log
   ```
   **Good:** either nothing (no alert fired since the last look) or whole MIME
   messages, each headed `production.DEBUG: Symfony\Component\Mime\Email`.

   Until ghiecode.io is verified as a sending domain in Resend, `MAIL_MAILER=log`
   and **this file is where every alert this app decides to send ends up**. That
   is the deliberate stage that lets the firing rules be judged against real
   fares before anybody's phone lights up — and it is worth reading after a
   deploy that touched alerting.
   - **⚠ IT NEEDS `MAIL_LOG_CHANNEL=mail` IN `.env`,** which `.env` on this box
     does not have until somebody adds it: the line is in `.env.example` (which
     is in git) and `.env` (which is not). Add it **before** step 9's restart, so
     the workers pick it up with everything else. Without it the log mailer falls
     back to the default channel, whose floor is `LOG_LEVEL=info`, and the
     transport writes at DEBUG — so every message is rendered and then dropped,
     silently, which is exactly what was happening before this file mentioned
     `mail.log` at all. `tests/Feature/MailLogChannelTest.php` holds both halves
     of that.
   - The file does not exist until the first mail is written to it; a `No such
     file` from `tail` on a box that has fired no alerts is not a fault.

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

Then **redeploy from step 5** — the revert is only code on disk until the assets
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
