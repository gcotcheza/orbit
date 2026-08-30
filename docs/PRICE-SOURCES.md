# Where live fares could come from — an audit of price sources

Desk research, 2026-08-21. No account was created and no vendor was contacted;
every claim below is either a repo fact or a cited public page, and anything that
could not be confirmed from a primary source is marked **UNVERIFIED**.

---

## 1. The question, and the answer

> *"We only depend on what was searched — Travelpayouts is Aviasales' cache of
> other people's searches. Is there any way to subscribe to an airline's prices?"*

**No.** Nothing in this market pushes a price to you. Every source examined —
cache, meta-search, live shopping API, airline NDC — answers a request and then
forgets you: no webhook, no feed, nothing that fires when a fare moves. The
products that *look* like subscriptions (Google Flights alerts, Skyscanner, Hopper,
Kayak) are e-mail features with no API behind them, and the one entry here with
"subscription" in its name — Lufthansa's *Fares Subscriptions* — is a Partner-Plan
bulk feed for distributors, not a per-route alert.

So Orbit stays a poller, and the question is not *push versus pull* but **what it
polls**: a cache of other people's searches (free, patchy, sometimes six times off
the market) or a live price (accurate, per-call, and 49,000–542,000 calls a month at
Orbit's calendar shape). The workable answer is neither — keep the cache for the
calendar, buy a live *second opinion* on the few numbers the app says out loud.

---

## 2. Orbit's request profile — the yardstick

Nine watched routes today (§27); every option below is priced against this.

### As billed today (one Travelpayouts request = one route, one calendar month)

| run | schedule | per run | per 30-day month |
| --- | --- | --- | --- |
| `orbit:poll-fares` (near, 181 d) | 06:10 daily | 9 × ≤7 = 63 | 1,890 |
| `orbit:poll-fares --far` (334 d) | Sat 04:10 | 9 × ≤12 = 108 | ~468 |
| `orbit:poll-returns` | 04:40 daily | 9 × 1 = 9 | 270 |
| `orbit:sweep-rules` | 06:40 daily | 30 capped × ≤4 = 120 | 3,600 |
| `orbit:discover` | 05:20 daily | 3 + 35 + 21 = 59 | 1,770 |
| `POST /api/routes/lookup` | on demand | ≤7 per miss | throttled, ≤10/h |

- **Watched-only** (near + far poll): **2,358 / month**
- **Watched + returns**: **2,628 / month**
- **Full** (+ rule sweep + discovery): **~8,000 / month**

The binding constraint is the hour, not the month: ~200 requests/hour per IP, and
the 06:00 hour already sits at 183 (7W + 120, breaching at W = 12) — §27.

### The same work in a live API's unit (one search = one origin-destination-date)

Travelpayouts sells a **month of departure dates per request**; every live shopping
API sells **one itinerary search**. That difference is the whole audit.

| run | date-cells / month |
| --- | --- |
| near poll (182 dates × 9 routes, daily) | ~49,100 |
| far poll (incremental 153 dates × 9, weekly) | ~6,000 |
| returns (335 dates × 4 duration bands × 9, daily) | ~362,000 |
| rule sweep (30 routes × ~90 dates, daily) | ~81,000 |
| discovery verification (8 routes × 182 dates, daily) | ~43,700 |
| **total** | **~542,000 / month** |

At the cheapest per-call price in this audit that is ~**$1,084/month**; at Duffel's
list rate ~$2,710. **Swapping the `PriceProvider` port for a live source is not on
the table at any price examined**, so nothing below is scored as though it were —
the useful volumes are *slices*, live-pricing only the numbers the app says aloud:

| scale | what it buys | searches / month |
| --- | --- | --- |
| **S1 watched-only** | one live check of each watched route's headline fare, daily | 270 |
| **S2 + returns** | plus one round-trip search per watched route, daily | 540 |
| **S3 full** | plus discovery's 5 verifications a night and ~50 user taps | 740 |

Today's SerpAPI free plan (250/month, 50 reserved) funds roughly **S0**: 150
discovery checks, ~50 taps, nothing scheduled per route.

---

## 3. What Travelpayouts gives us today, and where it is thin

**What it gives us, and it is a lot for €0:** a calendar month of one-way prices
per request (`/v2/prices/month-matrix`), a whole year of round-trips in one request
(`/v2/prices/latest`, §28), an all-destinations sweep off one origin for one
request (§30), ~200 requests/hour, EUR, no contract, and an affiliate link that
pays if anyone books. Nothing else here gives away any one of those, let alone all
five.

**Where it is thin — four failure modes, all already measured in this repo:**

1. **A cache of searches, so unsearched routes are empty.** The docs say it:
   *"data is transferred from the cache … recommended to use them to generate
   static pages."* §29 exists because of it — `fresh_for_hours` remembers *having
   asked*, because "a pair Travelpayouts has no fares for (an empty answer is a
   real answer) would be re-fetched on every view."
2. **Quoted dates silently stop being quoted.** §4's third deletion pass and
   `stale_after_days` / `far_stale_after_days` (3 / 17) exist because "an upsert
   only ever writes the dates the provider named this morning" and "nothing in the
   API marks a cell stale."
3. **The price can be badly out of date.** The three discovery finalists put to
   Google on 2026-08-16 (§31): DUS-AGP €29 cached vs €70; DUS-RAK €27 vs €168;
   EIN-VNO €18 vs €30. Marrakesh was **six times** off a real market.
4. **One shop's cache, not the market.** §32 changed the booking hand-off for
   exactly this: DUS→AGP at €29 where Skyscanner's cheapest that day was €68.

What is *not* on that list: "the numbers are wrong." Orbit already handles all four
honestly — `mayBeGone`, the printed age, the unverified "great find," the 10%
contradiction rule (§17). **A live source does not buy correctness, it buys
confidence** — ~50 user-initiated checks a month becoming a daily one per route.

---

## 4. The options

Cost columns are S1 / S2 / S3 from §2, in the vendor's own currency.

| option | type | AMS-market LCCs | access | S1 / S2 / S3 per month | verdict |
| --- | --- | --- | --- | --- | --- |
| **Travelpayouts Data API** *(today)* | cache | whatever Aviasales' users searched | open signup | €0 / €0 / €0 | **Keep.** The calendar cannot be bought elsewhere at this price |
| **Aviasales real-time Search API** | live | broad (meta-search) | **50,000 MAU required** | — | **Impossible.** Orbit has one user |
| **Kiwi.com Tequila** | live | broad, strong LCC | invitation-only since 2024 | — | **Closed.** No self-serve route |
| **Skyscanner Travel API** | live | broad | "established business, large audience", ~2-week review | free if approved | **Won't be approved.** Commercial partners only |
| **SerpAPI `google_flights`** | live (scraped) | everything Google shows: KLM, Transavia, easyJet, Vueling, TUI fly, Corendon, Eurowings, Wizz; Ryanair partially | open signup, card | **$25 / $25 / $25** (Starter, 1,000) | **Best fit.** Already integrated; one plan change |
| **Amadeus Self-Service** | live + cached stats | broad, weak LCC | **decommissioned 2026-07-17** | — | **Dead.** Confirms §21 |
| **Duffel** | live | easyJet, Transavia, Vueling, Eurowings, KLM, AF. **No** Ryanair, Wizz, TUI fly, Corendon | signup fast, live mode needs business verification | ~$1.35 / $2.70 / $3.70 | **Off-model.** Priced for bookers; Orbit never books |
| **FlightAPI.io** | live (scraped) | claims 700+ carriers; LCC mix unstated | open signup, card | **$49 / $49 / $49** (Lite floor) | **Fallback only.** Twice the price, none of the trust |
| **Ignav** | live | claims LCCs in 80+ markets | open signup | ~$0 / $0 / $0 (1,000 free) | **UNVERIFIED vendor.** Do not build on it untested |
| **Transavia Flight Offers API** | live, airline-direct | Transavia only | Distribution Solution Agreement | free (assumed) | **One carrier.** Real, but a contract for 1/9 of the market |
| **KLM–AF NDC** | live, airline-direct | KLM, AF, Transavia | content agreement / aggregator | — | **Trade channel.** Not for an individual |
| **Lufthansa Group NDC** | live, airline-direct | Eurowings, LH group | partner certification | — | **Trade channel.** Fares are Partner-Plan only |
| **Ryanair (unofficial endpoints)** | scrape | Ryanair | none — prohibited | €0 + legal exposure | **Do not.** Injuncted, twice |
| **Travelport / Sabre** | live, GDS | weak LCC by design | IATA-accredited agencies | — | **Not feasible** for an individual |
| **Booking.com Demand API** | live | broad | affiliate contract; **registrations closed** | — | **Shut.** Flights are reporting-only anyway |
| **Seats.aero** | cache (award) | miles, not cash | Pro $9.99/mo | — | **Out of scope.** Award seats, non-commercial API only |

---

## 5. Per-option notes

### Travelpayouts Data API — what we run
Free with affiliate registration; ~200 req/h per IP; cache data with `expires_at`
per entry; RUB by default, EUR requested and the envelope checked (§22); no stated
cap on storing prices. Sits behind all three ports today. Detail in §3 and §22.

### The four closed doors
- **Aviasales real-time Flight Search API** — the same vendor's *live* product, and
  the one the question is really reaching for. Requires **50,000 monthly active
  users**, evidenced by screenshots and a site URL. Even if granted the terms
  exclude us: every search must be user-initiated and shown in full with a Book
  button, automated collection of result links is prohibited on pain of cut-off,
  ~200 queries/h per IP, and combining it with another meta-search's API is
  forbidden. A nightly unattended poll is exactly what those rules rule out.
- **Kiwi.com Tequila** — self-serve key registration closed May 2024; B2B partners
  only, invitation-based, applicants expected to have a live travel product.
  **UNVERIFIED** — secondary sources only; Kiwi publishes no current criteria.
- **Skyscanner Travel API** — "if you're an established business with a large
  audience, you can apply": case-by-case, ~2-week review, no self-service, no free
  tier, no per-call fee once approved. Not approvable at one user.
- **Amadeus for Developers Self-Service** — **decommissioned 2026-07-17**; keys
  disabled, portal closed, registrations paused beforehand. This confirms §21 and
  kills all four endpoints the plan once wanted: Flight Offers Search, Flight
  Cheapest Date Search, Flight Inspiration, and the Price Analysis quartiles
  `selfstats` was built to replace. Enterprise APIs continue via account managers;
  nothing self-service survives.

### SerpAPI `google_flights` — the incumbent second opinion
Free 250/mo (50/h), **Starter $25 → 1,000/mo (200/h)**, Developer $75 → 5,000,
Production $150 → 15,000, Big Data $275 → 30,000, then Cloud tiers. No
pay-as-you-go, no rollover; every plan caps throughput at 20% of monthly volume per
hour, and Google Flights is not priced separately. SerpAPI offers a legal shield of
up to $2M for scraping and parsing search-engine data — their indemnity, not a
licence; the activity is still against Google's terms. Live at the moment of call.
**Risk:** scraping a site that does not want to be scraped, so a layout change is
an outage — which Orbit already degrades to "unverified" (§31), and that posture is
what makes it tolerable. **Port:** none; it sits beside them in
`GoogleFlightsCheck`. **Effort: S.**

### Duffel
Pay-as-you-go: **$3.00 per confirmed order**, 1% managed content, $2.00 per paid
ancillary, and the line that matters — a **1500:1 search-to-book ratio** with
**$0.005 per excess search**. No monthly minimum; zero bookings means zero charges
*and zero free searches*, so at S3 all 740 are billable: ~$3.70/month. Trivially
affordable, and the wrong reason to be a customer. Live mode needs business
verification, and the ratio *is* the deal — search subsidised by booking. A tracker
that books nothing forever is not the product they price. Coverage is the second
problem: **Ryanair, Wizz Air, TUI fly and Corendon are absent** from the airline
directory, and those matter at EIN and DUS. **Port:** `PriceProvider`
(offer-request → offers, an async two-step, not a calendar). **Effort: M.**

### The two open-signup unknowns
Both take a card and a key today; neither says where its prices come from. **Port:**
`PriceProvider` for either. **Effort: M.**

- **FlightAPI.io** — free trial 20 credits; **Lite $49/mo = 30,000 credits**,
  Standard $99 = 100,000, Plus $199 = 500,000. One-way and round-trip cost 2
  credits each, so Lite funds 15,000 searches — 20× S3 at 2× SerpAPI's price,
  because $49 is the floor. Explicitly *"You cannot book flights using our API.
  This API only tracks prices."* Source undisclosed beyond "700+ airlines and
  travel providers": no carrier list, no caching or storage terms. **UNVERIFIED:**
  LCC coverage, rate limits, storage terms.
- **Ignav** — a self-serve landing spot for stranded Amadeus users: instant key,
  **1,000 free requests then $2.00 per 1,000**, no minimum, no stated rate limit,
  fare search and booking links but no ticketing. At S3 that is free, and it is the
  least verifiable entry here — pricing, coverage and positioning all from the
  vendor's own pages, no independent review or company record found. **UNVERIFIED
  in full**; it would need a two-week trial against Travelpayouts and Google on
  real routes before anyone built on it.

**Risk, both:** an undisclosed aggregation layer carrying SerpAPI's scraping
exposure and none of its transparency.

### Airline-direct, and why none of it lands
- **Transavia** — really does publish Airports, Routes and **Flight Offers** APIs,
  and Flight Offers returns "the same stock as the Transavia website." But the
  partner page describes a **Distribution Solution Agreement**, sandbox, then
  production: a contract, not a signup, with one carrier at the end of it. The only
  airline-direct door an individual could plausibly walk through. **UNVERIFIED:**
  whether the affiliate tier is truly contract-free, its rate limits and cost.
- **KLM–Air France NDC** — the developer portal documents aggregators and IT
  providers; access runs through a local AF-KLM representative and a signed content
  agreement. Sandbox registration is open; production is not.
- **Lufthansa Group** — the free Open API carries schedules, status, reference
  data, seat maps and lounges. **Fares are not in it.** BestPrice/Lowest Fares,
  *Fares Subscriptions* and OND are Partner Plan, and technology providers must be
  certified by the group's airlines.
- **Ryanair** — no public fare API; the endpoints hobby projects use are the
  website's own. Ryanair keeps winning on terms-of-use grounds: the CJEU held that
  although its flight data enjoys neither database right nor copyright, a scraper
  cannot invoke the Database Directive to escape a contractual ban; the Irish High
  Court permanently enjoined Flightbox on 2023-12-07; Booking.com lost in Delaware.
  **Do not build on these endpoints.**
- **easyJet, Vueling, Wizz, TUI fly, Corendon** — no self-service fare API.
  Distribution is aggregators and direct-connect deals (Wizz via Atlas and Kyte,
  Vueling NDC 16.2 via TripStack, easyJet via Kiwi.com) — all trade channels.
- **Travelfusion** — the largest LCC aggregator, ~330 carriers, direct-connect XML,
  roughly USD 1–4 per booking, sold to OTAs, TMCs and meta-searches under contract.
  The right shape of product, entirely the wrong size of customer.

### Out of scope
**Seats.aero** — Pro $9.99/month, partner API up to 1,000 calls/day, non-commercial
personal use only. It answers *award availability across 20-odd loyalty
programmes*, not cash fares, and nothing in Orbit's model is denominated in miles.

---

## 6. Three architectures

Costs are monthly, at S3 (the full 740-search scale) unless stated.

### A — Cache + SerpAPI Starter for a nightly watched-route check
Keep Travelpayouts as the calendar. Move SerpAPI off the free plan to **Starter
($25/mo, 1,000 searches, 200/h)** and add one scheduled live check per watched
route per night against its current cheapest departure, reusing
`GoogleFlightsCheck` and `LivePriceChecks` as they stand.

- **Cost:** $25/mo (~€23). Budget: 270 scheduled + 150 discovery + ~50 taps = 470
  of 1,000, leaving a 530-search reserve — ten times today's 50-search floor.
- **Coverage gain:** a daily Google cross-check on every watched route instead of
  ~50 taps a month; the contradiction rule (§17) and `mayBeGone` fire by themselves
  rather than waiting for a button. Alerts could be verified before sending — the
  feature §31's `reserve` was set aside for.
- **Effort: S.** A scheduled command, a quota policy splitting the month between
  scheduled and interactive spend, one config key. No new port, no new adapter.
- **Risk:** the scraping risk Orbit already runs, unchanged in kind; a Google
  layout change degrades to "unverified," a supported state.

### B — Cache + a live per-search API behind `PriceProvider`
Keep the calendar; add a live adapter for the headline fare on watched routes.
Amadeus is gone, so the candidates are Duffel (~$3.70), FlightAPI.io ($49) or
Ignav (~$0, unverified).

- **Cost:** $3.70–$49/mo. **Coverage gain over A: none worth the name** — a live
  quote from one aggregator instead of one from Google, on the same nine numbers.
- **Effort: M.** A new adapter, a second freshness model (per-search, not
  per-month), and §21's reset-history problem for any fare landing in these tables.
- **Risk: high, and not financial.** Duffel's ratio makes a never-booking account
  off-model, FlightAPI.io and Ignav are undisclosed sources, and none of the three
  covers Ryanair. A second opinion from a source we cannot audit is worth less than
  one from the source that already disagreed with us sixfold.

### C — Cache + Travelpayouts real-time search
**Not available:** 50,000 MAU, and even granted, the terms forbid the unattended,
link-collecting poll Orbit is. Recorded so it is not re-proposed.

---

## 7. Recommendation, and what is open

**Take A.** €23 a month buys the one thing the cache cannot give — a daily
independent price on every number the app says out loud — for a scheduled command
and a quota split, with no new port, adapter or vendor relationship. It also
unlocks what §31 held its 50-search reserve for: verifying an alert before it wakes
somebody at 06:55.

**Do not take B.** More effort for the same nine numbers from a less transparent
source, and every live option covering Ryanair either does not exist or is
enjoined.

**Do not chase airline-direct.** Transavia is the only door an individual could
plausibly walk through, and it is a contract for one carrier out of the AMS/EIN/DUS
mix. Everything else is a trade channel. And keep the honesty about fare age — the
printed age, the unverified "great find," `mayBeGone` — because none of these
sources removes the need for it.

**Open questions for Ghie**

1. €23/month for a daily live check on nine routes — worth it, or is the current
   tap-to-check enough?
2. If yes: should a scheduled check also gate **alerts** before sending, or only
   refresh what the route screen shows?
3. Should a live Google price ever be **written into `route_price_history`**, or
   stay strictly a second opinion? (§21's reset-history problem says: stay out.)
4. Is a Transavia-only airline-direct feed worth a signed agreement for one carrier?
5. Points/award tracking (Seats.aero) — ever wanted, or permanently out of scope?

---

## 8. Sources

All accessed **2026-08-21**. Fetched and read directly: 1, 4, 5, 6, 7, 9, 10, 12,
15, 16, 17. The rest rely on search-engine summaries — 2, 3 and 8 returned HTTP 403
to a direct fetch and 14 returned an empty page shell.

1. Travelpayouts API reference — https://travelpayouts.github.io/slate/
2. Requirements for Aviasales Flight Search API access — https://support.travelpayouts.com/hc/en-us/articles/210995808-Requirements-for-Aviasales-Flight-Search-API-access
3. Requirements for Aviasales data API access — https://support.travelpayouts.com/hc/en-us/articles/203956083-Requirements-for-Aviasales-data-API-access
4. SerpApi pricing — https://serpapi.com/pricing
5. Duffel pricing — https://duffel.com/pricing
6. Duffel airline coverage — https://duffel.com/flights/airlines
7. Duffel getting started — https://duffel.com/docs/guides/getting-started-with-flights
8. Amadeus self-service shutdown, PhocusWire — https://www.phocuswire.com/amadeus-shut-down-self-service-apis-portal-developers
9. Amadeus self-service shutdown migration guide (vendor page) — https://ignav.com/docs/amadeus-self-service-shutdown
10. Skyscanner Travel API — https://www.partners.skyscanner.net/product/travel-api
11. Kiwi.com Tequila partner portal status *(secondary, UNVERIFIED)* — https://media.kiwi.com/articles-and-interviews/better-for-business-kiwi-com-takes-a-new-approach-to-partnerships/
12. Lufthansa Open API documentation — https://developer.lufthansa.com/docs
13. Lufthansa Group NDC Direct API — https://lhgroupairlines.com/ndc/en/ndc-solutions/ndc-direct-api
14. Air France-KLM NDC developer portal — https://developer.airfranceklm.com/ndc
15. Transavia partner API — https://partner.transavia.com/en-EU/products-and-services/our-api/
16. FlightAPI.io flight price API — https://www.flightapi.io/flight-price-api/
17. Ryanair v Flightbox, Irish High Court, 2023-12-07 — https://corporate.ryanair.com/news/ryanair-welcomes-high-court-ruling-confirming-screenscraping-is-unlawful/
18. CJEU, Ryanair v PR Aviation, on terms-of-use bans — https://www.pinsentmasons.com/out-law/news/website-operators-can-prohibit-screen-scraping-of-unprotected-data-via-terms-and-conditions-says-eu-court-in-ryanair-case
19. Booking.com Demand API overview — https://developers.booking.com/demand/docs/getting-started/overview
20. Travelfusion tfFlight API — https://corporate.travelfusion.com/products-services/tf-flight-api
21. Seats.aero Pro API access, limits and usage — https://docs.seats.aero/article/68-seatsaero-pro-api-access-limits-and-usage
22. Google QPX Express shutdown and successors (vendor blog) — https://duffel.com/blog/google-flights-api

**UNVERIFIED claims, collected:** Kiwi Tequila's current partner criteria;
Ignav's pricing, coverage and corporate existence; FlightAPI.io's data sources,
LCC coverage, per-plan rate limits and storage terms; Transavia's affiliate-tier
cost and rate limits; Travelfusion's per-booking fee range; whether Duffel would
tolerate a zero-booking account; Skyscanner's numeric acceptance threshold.
