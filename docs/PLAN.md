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
- **Rules vs watchlist:** separate concepts. Rules ("sunny weekend under €80") alert and list their current matches; one tap promotes a match to the watchlist. No auto-add.
- **Day-1 honesty:** while a route has <14 days of our own history, charts show a "tracking N days" note. Deal scores work from day 1 via the stats provider.
- **UI:** `design/README.md` is authoritative — tokens, choreography, all six screens (globe home, route detail, calendar, create rule, watchlist, settings). Globe stays mounted via KeepAlive across tab switches.
- **API:** `docs/API.md` is the contract between the back end and the screens — exact response shapes with an example each. It is written before the screens are, and a screen that needs a field it does not list needs the file changed first.

## PR roadmap

**From PR3 onwards, `scripts/check.sh` must pass before a PR is merged.** It runs Pint, PHPStan (level 8, no baseline), ESLint, Vitest and PHPUnit inside the containers, in that order, and stops at the first failure. A finding is fixed or ignored with a reason next to it — this project has no baseline for new debt to hide in.

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

## Pending owner actions
- **Travelpayouts: adapter built, switch not thrown.** `ORBIT_PRICE_PROVIDER=travelpayouts` + `TRAVELPAYOUTS_TOKEN` in `.env` turns on real one-way fares (`/v2/prices/month-matrix`). Run `php artisan orbit:reset-history --confirm` in the same breath — the recorded history is all simulated, and mixing the two makes every trend and deal score a comparison between two different universes. Expect 41–87% day coverage and honest "tracking N days" charts afterwards.
- **Statistics: nothing to sign up for any more.** Amadeus Self-Service was decommissioned 2026-07-17; `ORBIT_STATS_PROVIDER=self` computes a route's usual price from Orbit's own fares and needs no key. Flip it in the same breath as `ORBIT_PRICE_PROVIDER`, restart, then `php artisan orbit:refresh-stats --now` — a summary of fake fares is a real statistic about a simulation.
- Dedicated Anthropic API key for the rule parser.
- Verify `ghiecode.io` as a sending domain in Resend; then switch `MAIL_MAILER` from `log`.
