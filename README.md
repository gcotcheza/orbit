# Orbit

A mobile-first flight-deal tracker. It watches routes out of the Dutch airports
(AMS / EIN / DUS), keeps its own price history, scores every fare it sees, and
says something only when a route gets *insanely* cheap. The home screen is a 3D
globe that auto-tours the watched routes. One account — the owner's — and no
signup, no marketplace, no dashboard for anybody else: it exists to send one
person one useful mail a week and to shut up the rest of the time.

Laravel 13 + Vue 3 SPA, PHP 8.5, Postgres 18, Redis. Runs Dockerized behind the
host nginx at `https://flights.ghiecode.io`.

## Screens

`design/screenshots/` holds one PNG per screen — the design's intent, not a
capture of the build:

| | |
| --- | --- |
| `01-home-globe-dark.png` | globe home, dark (the default theme) |
| `02-route-detail.png` | price, deal-score gauge, history chart, advice callout |
| `03-price-calendar.png` | the month heatmap and its day sheet |
| `04-create-rule.png` | the natural-language rule textarea and its chips |
| `05-watchlist.png` | boarding-pass rows |
| `06-settings.png` | alert channels, quiet hours, sensitivity |
| `07-home-globe-light.png` | globe home, light |

What the *running app* looks like is `scripts/e2e.sh`: it drives a real browser
and writes full-page screenshots to `e2e/artifacts/` (gitignored, regenerated
every run). The handful of committed reference images live in `e2e/baselines/`.

## What it does

- **Globe home.** A photorealistic globe auto-tours the watchlist: fit, dive to
  the origin, fly the great-circle arc, land on a pulsing ring, dwell, next
  route. Stays mounted across tab switches, so the tour is not restarted by a
  visit to the calendar.
- **Route detail.** The current fare against what the route usually costs, a
  0–100 deal score with a colour-graded gauge, sixty days of price history, and
  a one-sentence verdict written from the same numbers as the score — so the
  prose and the gauge cannot disagree.
- **Price calendar.** A month of departure dates as a heatmap, each day already
  judged `cheap` / `mid` / `pricey` against that month's own range by the
  server. Tapping a day opens a sheet with the fare, the verdict and two
  actions: open the route, or book *that day*.
- **Watchlist.** Boarding-pass rows with a sparkline, a status pill and a
  pause toggle; the top half of a row opens the route. Adding one is an origin
  button plus a destination typeahead over the seventy-seven places Orbit knows
  how to fly to. Dropping a route keeps its price history.
- **Rules in English.** "cheap weekend somewhere sunny in spring, leaving Friday
  from any NL airport, under €80" is parsed into removable chips — From, Max
  price, Trip length, Depart, Date window, Vibe — and stored as what the chips
  add up to. Claude Haiku reads the sentence when a key is configured; a
  deterministic regex parser reads it otherwise, and is also the fallback
  whenever the model refuses, truncates or cannot be reached.
- **Alerts that stay quiet.** A watched route fires when its deal score crosses
  the sensitivity the owner picked; a rule fires on any fare under the price the
  owner wrote down. 24-hour cooldown per route unless the fare has fallen a
  further 5%, quiet hours that defer *delivery* and never the decision, one mail
  per rule however many routes it matched, and a Sunday digest that is not sent
  when there is nothing in it.
- **PWA.** Hand-rolled manifest, service worker and offline page. `/api/*` is
  never cached — a cached fare is a wrong number that looks like a right one.

## Architecture in brief

Hexagonal, pragmatic core:

- **`app/Domain/`** — pure PHP, zero framework imports. `DealScorer`,
  `AlertPolicy`, `RuleMatcher`, `QuietHours`, `PriceStats`, `MonthWindow`.
  Everything they need arrives as an argument, so every rule in this app can be
  checked on paper and unit-tested without a container, a database or a clock.
- **`app/Application/`** — use-case classes (`RouteSnapshots`,
  `AlertEvaluation`, `RuleMatches`, `DigestBuilder`) plus `Ports/`: the four
  interfaces `PriceProvider`, `PriceStatsProvider`, `RuleTextParser` and
  `DealNotifier`.
- **`app/Infrastructure/`** — the adapters behind those ports: Travelpayouts
  and a deterministic fake for prices; a self-computing provider and a fake for
  statistics; the Anthropic and regex rule parsers; the mail notifier.
- Eloquent is used directly for plain CRUD. There is no repository ceremony.

**Providers.** Fares are real one-way prices from Travelpayouts'
`/v2/prices/month-matrix`. The "usual price" statistics are computed by Orbit
from its own two fare tables — *there is no third-party statistics adapter and
there will not be one*: Amadeus' price-analysis endpoint was the plan and their
Self-Service API was decommissioned on 2026-07-17, and nothing else sells the
distribution of a route's fares. Booking is a Skyscanner deep link; Orbit sells
nothing and needs no booking API.

**Both providers default to `fake`,** which is a production adapter and not a
test double — the app ships and demos before any key exists, deterministically,
so the same route shows the same prices on every deploy.

## How the data moves

Every morning, in an order that is load-bearing: **06:10** `orbit:poll-fares`
fans out one job per watched route and writes both the ~91-day calendar window
and one price observation (today's cheapest); **06:40** `orbit:sweep-rules`
fetches fares for the routes each rule is about, skipping anything the poll
already priced; **06:55** `orbit:alerts` decides what is worth interrupting
somebody about and talks to no provider at all, because everything it reads was
written by the two runs before it. **Monday 05:40** refreshes the route
statistics, **Sunday 09:00** sends the digest, **03:10** prunes old asset
builds. Every time is Europe/Amsterdam, from `config('orbit.timezone')` —
storage stays UTC, but "06:10" is a statement about the owner's morning.

The rules behind all of that, with their numbers and their config keys, are
[`docs/BUSINESS-LOGIC.md`](docs/BUSINESS-LOGIC.md).

## Repo layout

| path | what |
| --- | --- |
| `app/Domain/` | pure business rules — scoring, alert policy, rule matching |
| `app/Application/` | use cases and the four ports |
| `app/Infrastructure/` | adapters: Travelpayouts, self stats, NLP parsers, mail |
| `app/Jobs/`, `app/Console/Commands/` | the queued work and the fan-out commands that schedule it |
| `app/Http/` | controllers, form requests, API resources |
| `config/orbit.php` | every tunable number in the app, with the reasoning next to it |
| `routes/console.php` | the schedule, and why each time is what it is |
| `routes/web.php` | auth, the read API, the writes, the SPA catch-all |
| `resources/js/` | the Vue SPA — `Views/`, `Components/`, `stores/`, `lib/` |
| `resources/css/tokens.css` | the design tokens both themes are built from |
| `database/migrations/`, `database/seeders/` | schema, and the checked-in destination data |
| `database/seeders/data/european_destinations.php` | 77 destinations with vibes and month-by-month warmth |
| `tests/` | PHPUnit — `Unit/Domain` is where the rules are pinned |
| `e2e/` | Playwright specs, baselines, config |
| `scripts/` | `check.sh` (the merge gate) and `e2e.sh` (the browser gate) |
| `docker/`, `docker-compose*.yml`, `deploy/` | the stack and the host nginx vhost |
| `design/` | the handoff, the prototype, the screenshots |

## Development

**Work in a git worktree, one per branch** — `/var/www/orbit` is the deployed
checkout and must stay on `main`. The convention is
`/var/www/orbit-worktrees/<short-name>`:

```bash
git -C /var/www/orbit worktree add /var/www/orbit-worktrees/feat-thing -b feat/thing
```

**The gate.** `scripts/check.sh` runs five checks in the containers, stopping at
the first failure: Pint, PHPStan (level 8, no baseline), ESLint, Vitest,
PHPUnit. It must pass before a PR is merged — this project has no baseline for
new debt to hide in.

```bash
docker compose up -d
./scripts/check.sh
```

**The compose-project trap.** `docker-compose.yml` pins `name: orbit` and
publishes `127.0.0.1:3085`; the browser sandbox pins `orbit-e2e` on
`127.0.0.1:3185` with its own generated `.env.e2e`. A compose command is
resolved through the project name — containers, networks *and volumes* — so
running `-f docker-compose.e2e.yml` without `--env-file .env.e2e` would point
the sandbox at production's `.env`. It does not: `ORBIT_E2E` is a required
interpolation variable, and the command fails instead.

**The browser gate.** `scripts/e2e.sh` builds, seeds, drives a real Chromium
over SwiftShader and destroys the stack again — about 90 seconds after the first
run. Run it **as root** (it needs the docker socket); nothing it writes into the
checkout is root-owned, because every container runs as `115:119`.

```bash
scripts/e2e.sh                                # everything
scripts/e2e.sh -- specs/globe.spec.js         # one spec
scripts/e2e.sh --keep -- --grep "heat map"    # one test, stack left up
```

Five green checks have never seen a screen — [`docs/E2E.md`](docs/E2E.md)
explains what that costs and what this harness found.

## Deploy

The runbook is [`.claude/commands/deploy.md`](.claude/commands/deploy.md), and
it is the authority: pull, gate, `composer install --no-dev`, build assets,
migrate, seed, **restart the long-lived containers** (they boot the code once —
a deploy that skips this looks entirely successful and serves the old app), then
the post-deploy checks. Going live from scratch, including the host nginx vhost
and the owner-key decisions, is [`docs/GO-LIVE.md`](docs/GO-LIVE.md).

## Configuration

`.env.example` is the annotated template; the notes there are worth reading
before flipping anything. The keys that change behaviour:

| key | default | what it flips |
| --- | --- | --- |
| `ORBIT_TIMEZONE` | `Europe/Amsterdam` | which calendar day a fare counts toward, what "leaving Friday" means, when everything on the schedule runs |
| `ORBIT_PRICE_PROVIDER` | `fake` | `fake` \| `travelpayouts`. Real fares need `TRAVELPAYOUTS_TOKEN`; without it the adapter refuses to resolve |
| `ORBIT_STATS_PROVIDER` | `fake` | `fake` \| `self`. `self` computes the usual price from Orbit's own tables and needs no key |
| `TRAVELPAYOUTS_TOKEN` | unset | required by, and only by, the real price adapter |
| `TRAVELPAYOUTS_MARKER` | unset | the affiliate marker, read into config and deliberately sent to nobody |
| `ANTHROPIC_API_KEY` | unset | its presence selects the Claude rule parser; absent, the regex parser runs |
| `ORBIT_NLP_PARSER` | derived | overrides that choice — `regex` pins the deterministic parser on a box that has a key |
| `ORBIT_NLP_MODEL` | `claude-haiku-4-5-20251001` | the model behind the rule parser |
| `MAIL_MAILER` | `log` | whether alerts leave the box. `log` writes them to `storage/logs/mail.log` (`MAIL_LOG_CHANNEL`) until Resend has verified the sending domain |
| `SEED_USER_EMAIL` / `_NAME` / `_PASSWORD` | owner's | the single account. An unset password means "generate one and print it once" |
| `REDIS_PASSWORD` | — | read twice: by Laravel and by the `redis` service's `--requirepass`. Rotate both together |
| `HORIZON_DASHBOARD_TOKEN` | unset | Horizon access outside `local`. **Empty means deny**, never "no secret required" |

⚠ **`ORBIT_PRICE_PROVIDER` and `ORBIT_STATS_PROVIDER` move together, and the
history moves with them.** No row records which adapter wrote it, so a real
price landing in a table full of simulated ones makes every trend, every "usually
€120" and the next alert quietly wrong. Flip both, then
`php artisan orbit:reset-history --confirm` — it clears the three observation
tables and re-runs the ordinary stats refresh and fare poll, leaving the
watchlist, rules, settings and alert ledger untouched. Charts then honestly say
"tracking 1 day" and fill in from there.

The test suite reads `.env.testing` — committed, hermetic, both providers pinned
to the fakes — and never the `.env` next to the checkout.

## Where else things are written down

- **[`docs/BUSINESS-LOGIC.md`](docs/BUSINESS-LOGIC.md)** — every domain rule,
  with its number, its config key and where the code lives. Start here.
- **[`docs/API.md`](docs/API.md)** — the contract between the back end and the
  screens: exact response shapes, with an example each. A screen that needs a
  field it does not list needs that file changed first.
- **[`docs/E2E.md`](docs/E2E.md)** — the browser gate: the sandbox, the
  divergences from production, and how to add a spec.
- **[`docs/GO-LIVE.md`](docs/GO-LIVE.md)** — first deploy, host nginx, owner
  keys, and an honest list of what is not done.
- **[`docs/PLAN.md`](docs/PLAN.md)** — the locked decisions and the PR roadmap.
  Historical: where a number there and a number in `config/orbit.php` disagree,
  the config is right.
- **[`design/README.md`](design/README.md)** — the design handoff, and the
  authority on every screen: tokens, copy, globe choreography. The prototype
  next to it (`Flight Deal Tracker - Globe.dc.html`) is a reference to
  recreate, not code to copy.
