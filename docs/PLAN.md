# Orbit — Build Plan

**Mission:** alert Ghie when a watched route gets insanely cheap.

## Locked decisions
- **Stack:** Laravel 13 + Vue 3 SPA (vue-router + pinia + Vite), PHP 8.5, Postgres 18, Redis (authed), Horizon + `schedule:work` containers. PWA (hand-rolled, health-tracker pattern). Single user, Sanctum cookie/session auth, no registration/reset routes.
- **Architecture:** hexagonal, pragmatic core. `app/Domain/` = pure PHP business logic (DealScorer, RuleMatcher, AlertPolicy — zero framework imports). `app/Application/` = use-case classes + `Ports/` interfaces (PriceProvider, PriceStatsProvider, RuleTextParser, DealNotifier). `app/Infrastructure/` = adapters (Travelpayouts, Amadeus, Fake providers; Anthropic parser + regex fallback; Resend mail; web push). Eloquent used directly for plain CRUD — no repository ceremony.
- **Data:** hybrid — Travelpayouts (daily calendar-shaped fares) + Amadeus price-analysis (route "usual price" statistics) + our own accruing price history. **Until API keys exist, config-selected Fake providers serve realistic seeded data.** Booking = Skyscanner deep links (no API).
- **Deal score (0–100):** ~60% percentile vs route price statistics, ~25% trend vs our last 30 days, ~15% absolute vs route's own min/median. Tiers: ≥80 insane / ≥65 great / ≥50 good.
- **Alerts:** fire on (a) watched-route score crossing the sensitivity tier — Relaxed = ≥80 only (default), Balanced = ≥65, Eager = ≥50; (b) a rule-matching fare at/below the rule's max price. 24h cooldown per route unless price drops a further ≥5%. Quiet hours defer delivery; weekly digest Sunday 09:00 Europe/Amsterdam. Email first (log transport until Resend's ghiecode.io domain is verified), web push after the PWA shell.
- **Rules vs watchlist:** separate concepts. Rules ("sunny weekend under €80") alert and list their current matches; one tap promotes a match to the watchlist. No auto-add.
- **Day-1 honesty:** while a route has <14 days of our own history, charts show a "tracking N days" note. Deal scores work from day 1 via the stats provider.
- **UI:** `design/README.md` is authoritative — tokens, choreography, all six screens (globe home, route detail, calendar, create rule, watchlist, settings). Globe stays mounted via KeepAlive across tab switches.

## PR roadmap
1. Skeleton + plan + design bundle
2. Hardened Docker stack (port 127.0.0.1:3085)
3. SPA shell + theme tokens + single-user auth
4. Domain core + migrations + fake providers + pollers
5. Globe home screen
6. Route detail screen
7. Price calendar screen
8. Watchlist + settings screens
9. NL rules engine (Claude parser + fallback) + create-rule screen
10. Alert evaluation + notifications + digest
11. PWA (manifest/SW/offline, build retention)
12. Deploy runbook + go-live at flights.ghiecode.io

## Pending owner actions
- Sign up: Travelpayouts (affiliate) + Amadeus Self-Service; add keys to `.env`.
- Dedicated Anthropic API key for the rule parser.
- Verify `ghiecode.io` as a sending domain in Resend; then switch `MAIL_MAILER` from `log`.
