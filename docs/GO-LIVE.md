# Orbit — go-live checklist

The one-off list for taking Orbit from "running on the loopback" to "answering at
https://flights.ghiecode.io". Everything here happens **once**, in this order.
The repeatable part — how to deploy on any ordinary morning after this — is
[`.claude/commands/deploy.md`](../.claude/commands/deploy.md), and step (b) below
is just "run that".

**Where things stand as this is written:** `/var/www/orbit` is on `main` at
`9ff72ee` with the whole stack up and answering on `127.0.0.1:3085`. The vhost is
installed at `/etc/nginx/sites-available/flights-ghiecode` and is deliberately
**not** symlinked into `sites-enabled`, so nothing is reachable from the internet
yet. Nobody has looked at this app in a real browser. That last sentence is the
honest headline of this document.

---

## (a) Review and merge the open stack

Four PRs, and the order is a dependency order, not a preference.

| # | Branch | Base | What it is |
|---|--------|------|-----------|
| #9 | `chore/integration-fixes` | `main` | globe asset caching (`/globe/` location), plan wording |
| #10 | `feat/pwa` | **`chore/integration-fixes`** | manifest, service worker, offline page, `build:retain` |
| #11 | `feat/rules-engine` | `main` | NL rule parser, matching engine, create screen |
| — | `feat/alerts` | **`feat/rules-engine`** | alert evaluation, notifications, digest |

**#10 is stacked on #9, so #9 merges first** — merging #10 while #9 is open would
drag #9's commits in under #10's title. Likewise `feat/alerts` is stacked on #11
and merges after it. As this file is written the alerts PR has **not been opened
yet** (`git ls-remote --heads origin` shows only `main`,
`chore/integration-fixes`, `feat/pwa`, `feat/rules-engine`) — confirm its number
and base before merging rather than trusting this table.

**Recommended order: #9 → #10 → #11 → alerts.**

### The conflict, and it is one collision, not three

**#10 and #11 both land on six files** — `config/orbit.php`, `docs/API.md`,
`routes/console.php`, `tests/Feature/ScheduleTest.php`,
`tests/Feature/SeedersTest.php`, `vite.config.js` — but git resolves three of
them on its own. Merging the documented order (#9, #10, #11), **whichever of #10
/ #11 goes second conflicts in exactly three files, one hunk each**:

```
config/orbit.php
docs/API.md
routes/console.php
```

The set is the same in either order — putting #11 first and #10 second produces
the identical three. All three are the same shape: **both branches appended a new
section at the same place in the file**, so git cannot know which goes first and
asks. **The resolution in every case is to keep both sides and delete the three
marker lines.** Nothing needs to be chosen between; nothing needs rewriting.

| File | `HEAD` side (#10, PWA) | Incoming side (#11, rules) | Resolve |
|------|------------------------|----------------------------|---------|
| `config/orbit.php` | the `'pwa' => [...]` block (name, icons, theme colours) | the `'nlp' => [...]` block (parser, api_key, model, timeouts) | keep both blocks, both comment banners |
| `routes/console.php` | `Schedule::command('build:retain')->dailyAt('03:10')` | `Schedule::command('orbit:sweep-rules')->dailyAt('06:40')` | keep both schedule entries, both docblocks |
| `docs/API.md` | the `## The PWA routes` section | the `# Deal rules` section | keep both sections |

After resolving, `tests/Feature/ScheduleTest.php` asserts the schedule from both
sides, so it is the test that proves the `routes/console.php` resolve was done
right — a dropped entry fails it rather than going unnoticed until 03:10.

### The two files that look like conflicts and are not

Worth knowing so nobody "fixes" them:

- **`vite.config.js`** — #10 adds `build: { emptyOutDir: false }`, #11 adds
  `test: { include, exclude }` for Vitest. Different top-level keys, so git merges
  them cleanly and the merged file correctly carries **both**. Verify after
  merging: the file should contain `emptyOutDir: false` *and*
  `include: ['resources/js/**/*.test.js']`.
- **`tests/Feature/SeedersTest.php`** — both branches fixed the same date-rollover
  failure, in different places, and the two fixes are complementary rather than
  competing: #10 asserts the clock was left alone (`assertFalse(Date::hasTestNow())`
  in the test) and #11 makes the seeder leave it alone (a `finally` in
  `database/seeders/FakeHistorySeeder.php` that restores `Date::getTestNow()`
  rather than clearing it). The auto-merge keeps both, which is the wanted
  outcome. Verify after merging: `grep -n 'hasTestNow' tests/Feature/SeedersTest.php`
  finds the assertion, and `grep -n 'getTestNow' database/seeders/FakeHistorySeeder.php`
  finds the restore.

### After the merges

```bash
git -C /var/www/orbit pull origin main
cd /var/www/orbit && sudo -u orbit ./scripts/check.sh
```

**Run `scripts/check.sh` on `main` even though every PR was green.** A merge
commit — and especially a hand-resolved one — is code that no run has ever seen.
`config/orbit.php` and `routes/console.php` were resolved by hand; PHPStan and
`ScheduleTest` are what confirm the resolution is not just syntactically valid but
right. All seven steps must pass.

**#11 moves `composer.lock`** (it adds `anthropic-ai/sdk ^0.42`), so the deploy in
step (b) *does* need its conditional `composer install` — see the runbook's step 5.

---

## (b) Deploy

Follow [`.claude/commands/deploy.md`](../.claude/commands/deploy.md) end to end.

Two things about this particular run:

- **`composer install` is required, not skipped** — `composer.lock` moved (above).
- **This is `build:retain`'s first-ever run.** The command arrives with #10, and
  its first run prunes everything predating its ledger — see the ⚠ in the
  runbook's step 4. It must come *after* the first post-merge asset build.

Then run the runbook's full verification battery against the loopback, with the
`Host:` header, **before** enabling the vhost. Everything that can be caught
privately should be caught privately.

---

## (c) Go live

```bash
# 1. Baseline: is nginx already happy, before you change anything?
nginx -t

# 2. Enable the vhost.
ln -s /etc/nginx/sites-available/flights-ghiecode /etc/nginx/sites-enabled/flights-ghiecode

# 3. Test AGAIN — this is the one that matters.
nginx -t

# 4. Reload.
systemctl reload nginx
```

**`nginx -t` twice is deliberate.** The first run establishes that any failure
after the symlink is *yours*; without it you cannot tell a new fault from one
that was already there. The second is the gate: **if it fails, delete the symlink
and stop.** Never `reload` on a failed test — nginx keeps serving the old config,
so the four other sites on this box stay up, and that is a state worth preserving.

`reload` rather than `restart`: it re-execs workers gracefully and drops no
connection on the five vhosts that are already live.

### Smoke test — now for real, over the internet

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://flights.ghiecode.io/          # 200
curl -s -o /dev/null -w '%{http_code}\n' http://flights.ghiecode.io/           # 301 → https
curl -s https://flights.ghiecode.io/up | grep -c 'Application up'              # 1
curl -s -o /dev/null -w '%{http_code}\n' https://flights.ghiecode.io/horizon   # 404 (the vhost's, not the app's)

curl -sI https://flights.ghiecode.io/manifest.webmanifest | grep -i content-type  # application/manifest+json
curl -sI https://flights.ghiecode.io/sw.js                | grep -i content-type  # application/javascript
curl -sI https://flights.ghiecode.io/build/assets/app-<hash>.js | grep -i cache-control  # max-age=31536000, immutable
curl -sI https://flights.ghiecode.io/globe/earth-blue-marble.jpg | grep -i cache-control # max-age=604800
curl -sI https://flights.ghiecode.io/ | grep -iE 'strict-transport|x-frame|content-security'
```

`/horizon` must be **404, not 403**. 403 is the app's `HorizonServiceProvider`
answering, which means the request reached PHP and the vhost's `location ^~
/horizon` block is not in effect. On the loopback today it *is* 403, correctly —
that block only exists in the host vhost.

**Then the part curl cannot do.** None of it has ever been seen in a browser:

- [ ] **Log in on the phone.** Real Safari/Chrome, real session — the cookie is
      `Secure` and `SameSite=Lax`, and this is the first time either has been
      exercised over actual HTTPS rather than worked around with a hand-built jar.
- [ ] **Eyeball the globe — the first real WebGL render this app has ever done.**
      Every test of it so far has been arithmetic on the tour timetable and the
      flight-arc maths in `resources/js/lib/`, deliberately, because those are
      checkable. Whether the planet actually *draws* — textures loading, arcs
      landing on the right cities, the auto-tour moving at a speed a human enjoys
      — is unknown until this moment. Budget time for it to be wrong.
- [ ] **Watch the console through a full tour, a rule creation and an alert.**
      Note every CSP violation; that list is what step (g)'s CSP promotion needs.
- [ ] **Install the PWA** ("Add to Home Screen"), then launch from the icon:
      standalone, no browser chrome, correct name and icon, dark splash.
- [ ] **Kill the network and reopen it** — the offline page should appear rather
      than the browser's dinosaur.
- [ ] **Tab through every screen**, then back to the globe: it must not re-mount
      (that is what the `KeepAlive` is for) and must not have lost its rotation.

---

## (d) DNS — optional, and already working

**Nothing is required here.** `ghiecode.io` has a proxied wildcard record, and
`flights.ghiecode.io` already resolves through it to Cloudflare's edge
(`188.114.97.2` / `188.114.96.2`, the same pair `health.ghiecode.io` answers with)
— which is why step (c)'s curls work the moment the symlink is in.

Adding an **explicit, PROXIED** `flights` record is still worth doing, for one
reason: a wildcard is invisible in the dashboard, so the day someone tightens or
removes it, Orbit disappears with no record anywhere saying it depended on it.

> Cloudflare → ghiecode.io → DNS → Add record
> **Type** `CNAME` (or `A`, matching the wildcard's shape) · **Name** `flights`
> · **Proxy status** **Proxied (orange cloud)**

**The orange cloud is not cosmetic.** The origin firewall accepts 80/443 only
from Cloudflare ranges, and `/etc/nginx/conf.d/cloudflare-realip.conf` rewrites
`$remote_addr` from `CF-Connecting-IP`. A grey-clouded record means the box
refuses the connection outright — and if it somehow did not, every request would
arrive with the wrong client IP and the login throttle would bucket the entire
internet together.

---

## (e) Register the deploy command on the host

Two files **outside this repo**, so they are not in the PR that adds this
document and have to be created by hand. This is the same pattern kidsquest uses:
a three-line pointer at the canonical runbook that lives with the code.

**1. Create `/root/.claude/commands/deploy-projects/orbit.md`:**

```markdown
# Deploy Orbit

Read and execute `/var/www/orbit/.claude/commands/deploy.md` — that file is the canonical deploy runbook for this project, versioned alongside the code.
```

**2. Add the dispatcher line** to `/root/.claude/commands/deploy.md`, in the
numbered list under "Instructions" step 1, alongside the existing projects:

```markdown
   - `orbit` → read and execute `/root/.claude/commands/deploy-projects/orbit.md`
```

Then `/deploy orbit` works the way `/deploy kidsquest` does.

*(Noticed while writing this: the dispatcher's list is already missing `kidsquest`
and `scribly`, both of which have files in `deploy-projects/`. Worth fixing in the
same sitting, but it is not Orbit's bug and not this PR's business.)*

---

## (f) Owner keys, whenever they are ready

**None of these block go-live.** Orbit is built to run without every one of them:
the fake providers serve realistic seeded fares, the regex parser reads rules, and
mail goes to a log file. That is a deliberate stage, not a stub — it lets the
alert thresholds be tuned against fares nobody is paid for and emails nobody
receives.

Everything below is `.env` in `/var/www/orbit`. **There is no `config:cache` step
anywhere in this project**, so `.env` is read at container boot — after editing
it, `docker compose restart app horizon scheduler` and nothing more.

### Fare providers — one key, and no key at all

```dotenv
TRAVELPAYOUTS_TOKEN=…          # daily calendar-shaped fares per route
```

**That is the only fare key there is.** The route price *statistics* — what a
route usually costs, which the deal score is mostly a percentile against — were
going to be Amadeus'. **Their Self-Service API was decommissioned on 2026-07-17**
(registrations closed, the remaining offering is enterprise), and nothing else
sells the distribution of a route's fares rather than the fares themselves. Orbit
computes its own instead, from the calendar window and the daily history it
already collects: `App\Infrastructure\Pricing\SelfStatsProvider`, no key, no
network. There is nothing left to sign up for here.

Adding the token **changes nothing on its own.** Each adapter is chosen by name,
and the names are still `fake`:

```dotenv
ORBIT_PRICE_PROVIDER=fake      # → travelpayouts
ORBIT_STATS_PROVIDER=fake      # → self
```

Both lines have to move, and an unknown name **throws at resolution rather than
falling back** — deliberately, because a box quietly serving invented prices would
send real alerts about fares that do not exist.

> ⚠ **Move them together, and reset the history in the same breath.** `self`
> summarises whatever fares are in the database, so `ORBIT_STATS_PROVIDER=self`
> against a table the fake provider filled is a real statistic about a
> simulation. The order is: both lines, then
> `php artisan orbit:reset-history --confirm`, then the restart, then
> `php artisan orbit:refresh-stats --now`.
>
> ⚠ **The statistics are thin before they are deep, and the app says so.** For
> the first month `self` answers from the current 90-day window — "the going rate
> across the next three months" — and blends toward the accruing daily history as
> that matures (30 observations). `docs/PLAN.md`'s score section and `docs/API.md`
> both spell the phases out.

### Anthropic — the rule parser

```dotenv
ANTHROPIC_API_KEY=…
```

**This one key is the whole switch.** `config/orbit.php` reads:

```php
'parser' => env('ORBIT_NLP_PARSER') ?: (env('ANTHROPIC_API_KEY') ? 'anthropic' : 'regex'),
```

so the moment a key exists the Anthropic adapter takes over, with no second line
to remember. It *composes* the regex parser as its fallback, so a refusal, a
timeout or a truncated JSON answer degrades to a slightly dumber parse rather
than a 500 on the create screen.

Two optional overrides, both with working defaults:

```dotenv
ORBIT_NLP_PARSER=regex                          # pin the deterministic parser despite a key (demos, bisects)
ORBIT_NLP_MODEL=claude-haiku-4-5-20251001       # the default; one short sentence in, small JSON out, inside a 500 ms debounce
```

**Wants its own key, not one borrowed from another app on this box** — per
`docs/PLAN.md`'s pending-owner-actions, and the same lesson health-tracker
learned.

### Resend — actually delivering the mail

```dotenv
RESEND_API_KEY=…               # note: RESEND_API_KEY, per config/services.php — not RESEND_KEY
MAIL_MAILER=log                # → resend, and only after the two steps below
```

**Verify `ghiecode.io` as a sending domain in Resend first** (DKIM/SPF records in
Cloudflare, then Resend's verify button). Until that is green, sending fails and
`MAIL_FROM_ADDRESS="flights@ghiecode.io"` is refused by the API.

> ⚠ **`--no-dev` does not install the Resend transport.** `resend/resend-php`
> appears in `composer.lock` only as `laravel/framework`'s **`require-dev`**
> suggestion, and the production deploy runs `composer install --no-dev`. So
> `MAIL_MAILER=resend` on a production box today throws on the missing transport
> class. It has to become a real dependency first:
> ```bash
> docker compose exec -T app composer require resend/resend-php
> ```
> then commit the moved `composer.json`/`composer.lock` and deploy normally.
> `config/mail.php` already has the `resend` mailer entry — that is Laravel's
> stock config and is not evidence the package is installed.

**Until all of that: mail lands in `storage/logs/laravel.log`**, inside the
container, and that is where to read the alerts and the Sunday digest:

```bash
docker compose exec -T app grep -A20 'flights@ghiecode.io' storage/logs/laravel.log | tail -60
```

---

## (g) Day 2 — what is honestly not done

Go-live is not "finished". This list is the real state of the app the morning
after, written down so none of it becomes a surprise.

**Owed immediately**

- [ ] **A browser has never seen this app.** Step (c)'s checklist is the first
      time. Until it is done, every visual claim in this repo is a claim about
      code, not about pixels — the globe especially.
- [ ] **CSP is `Report-Only` and must stay that way until there is evidence.**
      Promote it to `Content-Security-Policy` in
      `/etc/nginx/sites-available/flights-ghiecode` only after a full globe tour,
      a rule creation and an alert have each been done with the console open and
      produced no violation. Enforcing a wrong policy here does not degrade this
      app, it **blanks** it: a blocked entry chunk is a white page behind a 200
      with nothing in any server log. Two clauses are expected to argue —
      `img-src 'self'` (the globe textures are vendored now, so this should be
      clean) and `'unsafe-inline'` in `style-src` (Vue writes inline styles; a
      nonce is a bigger change than that file).

**Real work still to build**

- [x] **The real provider adapters.** Both are written:
      `TravelpayoutsPriceProvider` (#20) for the fares and `SelfStatsProvider`
      (#22) for the statistics — the latter because Amadeus' Self-Service API was
      decommissioned before a key was ever bought, so that half is computed from
      Orbit's own data rather than purchased. What remains is a `.env` decision
      rather than code: until `ORBIT_PRICE_PROVIDER`/`ORBIT_STATS_PROVIDER` move,
      every fare on every screen is still simulated. See (f).
- [ ] **The web-push adapter.** The `DealNotifier` port and its email
      implementation arrive with the alerts PR (nothing named `DealNotifier`
      exists on `main` as this is written). Push is the *second* implementation
      and no part of it exists: it needs a VAPID keypair, a subscription
      endpoint, and a `push` handler in the service worker. The PWA shell that
      makes it possible arrives with #10; the adapter does not.

**Polish — verified against the tree, not assumed**

- [ ] **One `euro()`, not four.** Defined separately in
      `resources/js/Components/route/format.js`, `calendar/format.js`,
      `globe/format.js`, and inline in `watch/WatchRow.vue`. Still four on
      `feat/rules-engine`. This is the clearest of the reuse items.
- [ ] **A watchlist store.** `resources/js/stores/` has `auth.js`, `rules.js`,
      `settings.js` and `theme.js` — but no `watchlist.js`, while watchlist state
      is fetched and held independently by `Views/Home.vue`, `Views/Watchlist.vue`,
      `Views/RouteDetail.vue` and `Views/Calendar.vue`. The rules screen got a
      store; the older watchlist screens predate the pattern.
- [ ] **An `--on-solid` token — which does not exist yet.** Ten components write
      a bare `#fff` for a glyph on a saturated fill. `AdviceCallout.vue` explains
      the literal and proposes the token in the same breath ("`A --on-solid token`
      would be the tidy answer and belongs to tokens.css, which this branch does
      not own"). Adding it to `tokens.css` is the small change nobody's branch
      was allowed to make.
- [ ] **`VerdictPill` reuse — check, do not assume.** It is already a single
      component with four importers, and `ToggleSwitch` is likewise already shared
      — neither is duplicated on `main` or on `feat/rules-engine`. The open
      question is narrower: `Components/rules/MatchBanner.vue` (#11) renders its
      own verdict-toned banner from the same `tokens.css` tone pair rather than
      reusing the pill. That may be right — a banner is not a pill — but it is the
      one place worth looking during the pass.

**Infrastructure gap — not Orbit's alone**

- [ ] **This box has no backups, and that now includes Orbit's Postgres.** The
      `orbit-pgdata` volume holds the accruing price history, which is the one
      thing here that cannot be regenerated: rules can be retyped and the
      watchlist rebuilt in a minute, but "what this route cost every day since we
      started looking" only exists because we recorded it, and it is what the deal
      score's trend component is computed against. A `pg_dump` on a schedule,
      landing somewhere off this box, is the minimum. Box-wide, not per-app —
      health-tracker and the others have the same gap.
