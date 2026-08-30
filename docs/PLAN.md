# Orbit — Build Plan

**Mission:** alert Ghie when a watched route gets insanely cheap.

## Locked decisions
- **Stack:** Laravel 13 + Vue 3 SPA (vue-router + pinia + Vite), PHP 8.5, Postgres 18, Redis (authed), Horizon + `schedule:work` containers. PWA (hand-rolled, health-tracker pattern). Single user, Sanctum cookie/session auth, no registration/reset routes.
- **Architecture:** hexagonal, pragmatic core. `app/Domain/` = pure PHP business logic (DealScorer, RuleMatcher, AlertPolicy — zero framework imports). `app/Application/` = use-case classes + `Ports/` interfaces (PriceProvider, PriceStatsProvider, RuleTextParser, DealNotifier). `app/Infrastructure/` = adapters (Travelpayouts prices, self-computed statistics, Fake providers; Anthropic parser + regex fallback; Resend mail; web push). Eloquent used directly for plain CRUD — no repository ceremony.
- **Data:** hybrid — Travelpayouts (daily calendar-shaped fares) + our own accruing price history, and the "usual price" statistics computed from both. **Until API keys exist, config-selected Fake providers serve realistic seeded data.** Booking = Skyscanner deep links (no API).
- **Deal score (0–100):** ~60% percentile vs route price statistics, ~25% trend vs our last 30 days, ~15% absolute vs route's own min/median. Tiers: ≥80 insane / ≥65 great / ≥50 good.
  - **The statistics are Orbit's own** (`ORBIT_STATS_PROVIDER=self`, `App\Infrastructure\Pricing\SelfStatsProvider`). Amadeus' price-analysis endpoint was the plan; their Self-Service API was decommissioned 2026-07-17 and nothing else sells the distribution of a route's fares, so the five-number summary is computed from the two tables the poller already fills. **No fabrication:** a route with neither calendar fares nor history gets `null`, and the scorer renormalises over what is left.
  - **Two horizons, blended by maturity.** *Cross-sectional* = the ~91 `calendar_fares` of the current poll window; available from the first poll, and its median is "what a typical departure date costs". *Longitudinal* = the accruing `route_price_history` (one row per morning, each that morning's cheapest); slow to arrive and the better comparison once it is, because the fare being scored **is** one of those rows. The blend is one line — `w = min(1, observations / 30)`, then `knot = round((1-w)·cross + w·long)` per knot — so a route reads as cross-sectional on day 1, half-and-half at day 15, and purely longitudinal from day 30. A convex combination of two sorted summaries stays sorted, which is what keeps `PriceStats`' ordering invariant safe.
  - **What "usual" honestly means, by phase:** days 1–29 it is *the going rate across the next three months*; from day 30 it is *what the cheapest fare on this route has actually been, morning after morning*.
- **Alerts:** fire on (a) watched-route score crossing the sensitivity tier — Relaxed = ≥80 only (default), Balanced = ≥65, Eager = ≥50; (b) a rule-matching fare at/below the rule's max price. 24h cooldown per route unless price drops a further ≥5%. Quiet hours defer delivery; weekly digest Sunday 09:00 Europe/Amsterdam. Email first (log transport until Resend's ghiecode.io domain is verified), web push after the PWA shell.
  - **A score may not interrupt anybody until there is a score to trust.** A watched route needs `orbit.alerts.min_tracking_days` (7) mornings of its own prices before it can fire; below that `AlertPolicy` answers `immature-data`, sends nothing and writes no ledger row (same as below-threshold — the ledger is what Orbit *said*). With self-computed statistics a route's first observation is its own min, median and max, so day one scores every route 100/insane/confident: eight meaningless "insane deal" mails the first morning after a watchlist is filled in. **Rule matches are deliberately not gated** — the owner's own maximum price is true on day one, and rules exist to find routes nobody was watching.
- **Rules vs watchlist:** separate concepts. Rules ("sunny weekend under €80") alert and list their current matches; one tap promotes a match to the watchlist. No auto-add.
- **Day-1 honesty:** while a route has <14 days of our own history, charts show a "tracking N days" note. **Below `orbit.alerts.min_tracking_days` (7) mornings there is no deal score at all** — the API answers `score: 0, tier: "none", confident: false, "Not enough data yet"`, the same state the screens already render for a route with no prices. Statistics exist from day 1 (the blend below), but a percentile against a distribution one observation wide is not a judgement; the *prices* are published throughout, only the verdict waits.
- **UI:** `design/README.md` is authoritative — tokens, choreography, all six screens (globe home, route detail, calendar, create rule, watchlist, settings). Globe stays mounted via KeepAlive across tab switches.
- **API:** `docs/API.md` is the contract between the back end and the screens — exact response shapes with an example each. It is written before the screens are, and a screen that needs a field it does not list needs the file changed first.

## PR roadmap

**From PR3 onwards, `scripts/check.sh` must pass before a PR is merged.** It runs Gitleaks, Pint, `composer audit`, PHPStan (level 8, no baseline), `npm audit`, ESLint, Vitest and PHPUnit inside the containers, in that order, and stops at the first failure. A finding is fixed or ignored with a reason next to it — this project has no baseline for new debt to hide in.

1. Skeleton + plan + design bundle
2. Hardened Docker stack (port 127.0.0.1:3085)
3. Quality gates (Pint, Larastan, ESLint, unit tests) — `scripts/check.sh` must pass for every PR from here on
4. SPA shell + theme tokens + single-user auth
5. Domain core + migrations + fake providers + pollers
6. Globe home screen
7. Route detail screen
8. Price calendar screen
9. Watchlist + settings screens
10. NL rules engine (Claude parser + fallback) + create-rule screen
11. Alert evaluation + notifications + digest
12. PWA (manifest/SW/offline, build retention)
13. Deploy runbook + go-live at flights.ghiecode.io

### Return trips — a milestone after go-live

Orbit prices **one-way** legs, which is correct for the EU budget carriers it
was built around and wrong for anything long-haul: measured 2026-08-16, the
cheapest one-way is 58–69% of the cheapest return (AMS–JFK: €334 against €484).
So "from €334" was never wrong about the arithmetic and always wrong about the
trip. Split into PRs so the table starts filling with real fares before anything
has to draw them.

- **returns-1 — the data foundation.** `ReturnTripProvider` port, real
  (`/v2/prices/latest`, `one_way=false`) and fake adapters, the `return_fares`
  table, `PollReturnFares` + `orbit:poll-returns`. No UI and no endpoints; it
  shipped unscheduled and was **put on the schedule at 04:40** in a follow-up,
  ahead of any reader, so the history accumulates in the deployed stack rather
  than from a cron outside it. The measured data reality — sparse coverage, no
  duration grid,
  and the parameters that are load-bearing — is `docs/BUSINESS-LOGIC.md` §15.
- **returns-2 onwards** — statistics and a score for round trips (a "current
  price" for a return has to be *defined* before it can be computed); the
  screens, built for 8–34% coverage rather than against it; `tripLengthNights`
  finally *matching* rather than only being parsed and shown; and alerts, which
  have to reckon with a cache that is seven days deep.

## What is switched on
- **Travelpayouts: real one-way fares.** `ORBIT_PRICE_PROVIDER=travelpayouts` with `TRAVELPAYOUTS_TOKEN` in `.env` (`/v2/prices/month-matrix`). `php artisan orbit:reset-history --confirm` was run in the same breath, because the recorded history was all simulated and mixing the two would make every trend and deal score a comparison between two different universes. Day coverage runs 41-87%, and the "tracking N days" charts are honest about it.
- **Statistics: self-computed, nothing to sign up for.** Amadeus Self-Service was decommissioned 2026-07-17; `ORBIT_STATS_PROVIDER=self` computes a route's usual price from Orbit's own fares and needs no key. It was flipped in the same breath as `ORBIT_PRICE_PROVIDER` - a summary of fake fares is a real statistic about a simulation - and `php artisan orbit:refresh-stats --now` is how it is refilled by hand.
- **Round trips: real fares, polled daily.** `ORBIT_RETURNS_PROVIDER=travelpayouts` (same `TRAVELPAYOUTS_TOKEN`); `orbit:poll-returns` runs **daily at 04:40**, one request per watched route, and `php artisan orbit:poll-returns --now` fills `return_fares` by hand. The entry went in ahead of any reader - `docs/BUSINESS-LOGIC.md` §15 has the budget and why. Nothing was reset: the table was new, so no simulated rows were mixed in.
- **The rule parser has its own Anthropic key.**
- **Mail is real.** `ghiecode.io` is verified as a sending domain in Resend, and `MAIL_MAILER=resend`.
