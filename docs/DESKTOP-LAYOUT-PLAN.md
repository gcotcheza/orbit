# Desktop / iPad layout — implementation plan

**The plan is complete: phases 0–4 are shipped.** What is left over is the short "Still
open" list at the bottom, and none of it is layout.

Decision (2026-08-22): above 1024 px Orbit uses the **master–detail** layout from the
design canvas "Orbit Desktop Directions" (direction C); the landing page is the
globe + route detail pane. iPad in landscape, iPad Pro in portrait and every desktop
get this layout; iPad in portrait (768–1023 px) gets the collapsed variant. Phones are
untouched.

Sources of truth: the design canvas (artboards `MasterDetail`, `Calendar`, `Search`,
`Watch`, `Alerts`, `Create`, `Portrait`), its generator
(`scratchpad/design/canvas/build.py` — every recipe in CSS), and `docs/BUSINESS-LOGIC.md`.
The tablet audit that motivated this: the app is width-locked to `--app-width: 430px`
(`tokens.css`), the only media query is a phone-landscape rule in `GlobeStage.vue`, and
on an iPad 48–68 % of the width is empty.

## Layout contract

| Width | Frame |
| --- | --- |
| < 768 | Phone layout, unchanged (tab bar, 430 px column). |
| 768–1023 | **Collapsed C**: icon rail 76 px + one pane; the routes list becomes a chip strip under the screen head. |
| ≥ 1024 | **C**: icon rail 76 px · master pane 352 px · detail pane (rest). |

**Zero regression on the phone is a hard gate, not a goal.** Every desktop rule lives
behind `@media (min-width: 768px)` (or the tablet/desktop frame components), so the
phone CSS path is untouched by construction; Phase 0 first records phone screenshot
baselines for every screen (390×844, light + dark) and every later phase's gate fails
on any pixel difference against them; the full phone e2e suite runs unchanged on every
phase; the Opus reviewer of each PR must state "phone renders identical: yes" with the
diff numbers before the PR goes ready.

Rules that hold everywhere above 768: the globe takes whatever height the detail
below it does not need (bigger screen, bigger globe); calendar cells stay square; the
same live WebGL globe, only sized by its pane; no new copy, no new components beyond
the frame itself; every recipe value (type, radii, colours, shadows) is the existing
token.

## Phases — one PR each, draft → Opus review → ready → Ghie merges → watcher deploys

### Phase 0 — Foundations (no visible change on phone) — **SHIPPED**
What was actually built, which differs from the first sketch in three places:
- Tokens: `--shell-max` (defined as `var(--app-width)`, so it resolves to the same 430 px
  today), `--rail-width: 76px`, `--master-width: 352px`.
- **No `--bp-*` tokens.** A CSS custom property cannot be used in a `@media` query at all,
  so a `--bp-tablet` would look authoritative and be silently ignored by every media query
  that read it. The two breakpoints are written as literals (768 px, 1024 px) and explained
  in `docs/BUSINESS-LOGIC.md` §36 instead.
- The four `max-width: var(--app-width)` clamps (`.app-shell`, `.tab-bar`, `.sheet`,
  `.toast`) now read `--shell-max`, so a later breakpoint retargets one value, not four
  rules in four files.
- `GlobeStage`: the renderer follows its container (`ResizeObserver` on `.stage__globe` →
  globe.gl `width()/height()`, coalesced to one animation frame). **The `360px` stage height
  and the phone-landscape `40vh` rule are KEPT** — they are the phone guarantee, and the
  observer is what lets a later phase size the stage with flex without touching them.
- **Manifest `orientation` stayed `portrait` here.** Changing it is a visible change to the
  installed app, which Phase 0 promised not to make, so it became a Phase 4 decision for
  Ghie. She took it — it is `any` from phase 4 onwards; see that phase below.
- e2e: phone screenshot baselines FIRST (every screen, 390×844, light + dark, masked and
  compared at `maxDiffPixels: 0`), then `tablet` (820×1180) and `desktop` (1280×832)
  projects running a smoke spec (shell renders, nav present, no horizontal overflow) —
  assertions grow per phase. The sandbox clock is frozen on both sides
  (`E2E_FIXED_NOW`) so those baselines are valid on any day, not just the day they were
  recorded: `docs/E2E.md` "A frozen clock".
- Docs: this file + a §36 note. *Effort S. Risk: globe resize jank — verify on a real iPad.*

### Phase 1 — The frame and the landing page (≥ 768) — **SHIPPED**
- `App.vue` grows a desktop branch driven by `useLayout()` (`lib/layout.js`), a
  composable reporting `phone | tablet | desktop` from `matchMedia`: `IconRail`
  replaces `TabBar` at the frame's sizes (by `v-if`, so exactly one is ever in the
  DOM), the shell becomes `rail | master 352 | detail` at ≥ 1024 and `rail | one pane`
  at 768–1023, and `--shell-max` stops clamping it.
- `Components/route/RouteDetailPanel.vue` is everything `/route/:id` showed below its
  back bar; the screen is now the bar plus that panel, and the desktop detail pane is
  the same panel. The selected route is `?route=AMS-LIS` on `/`, written with
  `router.replace`, so `KeepAlive` of Home and the globe are untouched.
- Landing page = master routes list | globe (flexible) + `RouteDetailPanel`.

What was actually built differs from the sketch above in five places, each of which
is a decision worth keeping:
- **The breakpoints carry a height: `(min-width: 768px) and (min-height: 600px)`.** A
  phone on its side is 844×390 — wider than 768 and still a phone — and the browser
  gate proved it, collapsing a detail pane to zero height in `globe.spec.js`'s
  landscape test. The number is written out in `lib/layout.js` and in every `@media`
  rule, and those must be edited together. **The cost, stated plainly:** a desktop window
  shorter than 600px — a laptop with the devtools open, a short split-screen — falls back
  to the phone column rather than the frame. That is the safe direction to fail in, and it
  is the same trade the `GlobeStage` landscape rule already makes.
- **`--shell-max: none` is set on `.app-shell--rail`, not on `:root`.** A screen with
  no rail — the route detail, the login — keeps the phone column at any width, which
  is what "the other screens are unchanged this phase" has to mean. The update toast
  follows the frame with `.app-shell--rail .toast`; the day sheet needs nothing, being
  teleported to `<body>` and so outside the frame's token.
- **The globe takes 45% of the pane (never under 280px)**, not "whatever the detail
  does not need". The detail here is the phone's single column, which always wants
  more height than the pane has, so there is no leftover to give — the panel scrolls
  under a globe of a fixed share instead. The two-column detail pane the artboard
  draws, which is what makes leftover height mean anything, is phases 2–3.
- **The wide branch ignores the globe's `advance`.** The pane shows the route that was
  chosen; a tour moving off it every eleven seconds would argue with the panel below,
  rewrite the URL and refetch a route while nobody was touching anything.
- **`MasterPane`/`DetailPane` were not built as components.** Only one screen has a
  master pane this phase, so the split is `Home.vue`'s own layout plus a `meta.wide`
  flag on the route; the other tabbed screens get `app-shell__main--column`, which
  centres their existing phone layout in what the rail leaves. Phases 2–3 are what
  would make a shared pane component earn itself.

*Effort L (the core). Phone: 19 baselines at 0 diff, phone suite unchanged.*

### Phase 2 — Calendar and Watch in C — **SHIPPED**
- `Components/HomeHeader.vue` is the eyebrow/greeting both branches now draw, with the account
  link behind a `profile` prop (on for the phone, off inside the frame, where the rail has one).
- `Components/RouteRows.vue` is the master pane's list, shared by all three wide screens. It is
  mounted only by the frame, so its rules need no media query — the component *is* the guard.
- Calendar: master = head + rows; pane = month nav, the grid card capped at 560px, legend and
  cheapest banner, and the day sheet's own body docked beside it.
- Watch: master = head + the search chip + rows; pane = the chosen pass at column width, the
  rest two abreast, deal rules as a right-hand column.
- Landing: `RouteDetailPanel` becomes two columns and the globe takes the leftover height.

What was actually built differs from the sketch above in four places, each worth keeping:
- **The wide branches are the same template, not a second one.** Four wrappers per screen
  (`__master`, `__pane`, and friends) are `display: contents` below 1024px and become the frame's
  boxes above it. A wrapper that generates no box changes nothing about how its children lay out —
  margins still collapse through it — so the phone renders the DOM it always did while one
  template serves both. The alternative, Phase 1's `v-if`/`v-else` pair, would have duplicated the
  calendar's four states and the whole rules section, which is what phase 2 was supposed to stop
  doing rather than do twice more.
- **The detail's two columns are a 2x2 grid, not two column elements.** The phone reads
  head → price → chart → advice → booking; the artboard's left column is head/price/advice and its
  right is chart/booking. Those two orders cannot both come out of one DOM order with two
  wrappers, and reordering is a phone regression. So the panel has **four** `display: contents`
  groups — summary, chart, advice, booking — placed into columns 1, 2, 1, 2. The cost is stated
  plainly: the shorter of each row's two groups leaves a gap under itself, which is what the
  artboard's own `space-between` columns draw anyway.
- **`meta.wide` grew a second value, and it means an iPad in PORTRAIT sees no change.** `true`
  still means "owns the frame from 768px" (the landing); `'desktop'` means "owns it from 1024,
  phone column below" — which is what the calendar and the watch list need, since their panes are
  a 352px master plus two more columns and a 768px window has room for neither. So phase 2's two
  screens arrive on an iPad in **landscape** and on a desktop window; at 820px they keep the
  centred phone layout phase 1 gave them, deliberately.
- **The mock's `aspect-ratio: auto` cells were not built.** The plan says square and the plan
  wins: the grid card is capped at 560px (the artboard's own width) and the cells' existing
  `aspect-ratio: 1` does the rest. A pane too narrow for the card and the day panel side by side
  wraps the panel under the grid rather than squashing either — **so the panel docks beside the
  month from 1264px, and sits under it between 1024 and 1263.** Docking at 1024 was tried and
  costs a 37px cell, which is smaller than the phone's; a scroll is the cheaper price. The watch
  list's rules column wraps the same way, from 1260px, and for the same reason.

*Phone: 19 baselines at 0 diff, phone suite unchanged. Docked `DaySheet` is `role="region"` and
not a modal dialog — nothing is covered, so there is nothing to trap focus in front of.*

### Phase 3 — Search, Create, Alerts in C — **SHIPPED**
- Search: master = the search card (typeahead in flow); detail = "Deals from your
  airports" as a 2-column grid; a look-up result shows `RouteDetailPanel` in the pane.
- Create: master = deal rules list; detail = compose + chips + banner + CTA at pane width.
- Alerts: master = section list (CHANNELS … THIS APP); detail = settings cards in two
  columns.

All three are `meta.wide: 'desktop'`, so they arrive on an iPad in **landscape** and on a
desktop window, and an iPad in portrait keeps the centred phone column — the same trade
phase 2 made, and for the same reason: a 352px master plus a content column does not fit
in 768px. All three are the phone's own template widened by `display: contents` wrappers.

What was actually built differs from the sketch above in five places, each worth keeping:
- **The deal rules list was not a component, and now is.** The plan said "reuse it"; there
  was nothing to reuse — `Views/Watchlist.vue` carried the whole section inline. It is now
  `Components/rules/DealRules.vue`, which owns the list, its two writes (pause, promote a
  match) and its own `rules.load()`, so neither screen has to. The watch list's markup and
  class names are unchanged, which is what keeps its baselines at zero; what moved with it
  is the CSS for those class names, **including copies of `.screen__notice`, `.screen__state`
  and `.screen__retry`** — scoped styles do not cross a component boundary, and renaming
  them would have been the DOM change the extraction exists to avoid. The `.rules` root
  keeps its hairline from *`Watchlist.vue`'s* stylesheet, because a child's root element
  carries its parent's scope id; the create screen mounts the same component with no such
  rule and gets no hairline, which is exactly right for a master pane.
- **A parse failure and a rules-list failure were the same `error` in the same store, and
  are not any more.** `stores/rules.js` had one `error` ref for the reading of the sentence
  being typed *and* for the saved list — so on `/create`, where `DealRules` now issues its
  own `GET /api/rules`, a failed list load printed under the textarea, a failed pause printed
  under the textarea, and the two cleared each other's messages. The store now has `error`
  (parse and create — the compose screen's own) and `listError` (load, pause, remove,
  promote — the list's own). Each control answers for itself where it is: `DealRules` always
  draws `listError` in whichever pane it was mounted in, and the compose card draws only the
  parse error. A `notice` prop briefly existed to suppress the duplicate; splitting the refs
  removed the duplicate, so the prop went with it.
- **`DealRules` takes a `newRule` prop, because "+ New rule" is a dead control on `/create`.**
  On the watch list it is the only door to this screen; on this screen it would link to the
  screen it is already on. `/create` passes `:new-rule="false"`; the watch list is unchanged.
- **The quiet-hours time inputs wrap rather than shrink.** `input[type="time"]` has a UA
  minimum width it will not go under, and `.card` hides its own overflow — so between 1024
  and about 1084px, where the alert pane's column is under ~290px, the *Until* box was cut
  off in silence, with no sideways scroll for the guard to catch. `.window` is now
  `flex-wrap: wrap` with `flex: 1 1 120px` fields, so the pair stacks instead of clipping;
  on the phone the two boxes are the same size they always were (the baselines prove it).
  The 1024x600 test now sweeps `.card *` for `scrollWidth > clientWidth`, which is the same
  blind-spot sweep the boarding passes got in phase 2.
- **Alerts has no scroll-spy, and the two columns are the reason.** The plan asked for one.
  In a two-column pane CHANNELS and SENSITIVITY start on the same line, as do TIMING and
  ACCOUNT — so a scroll offset does not name a section, and a spy would have flickered
  between two answers at every position. The list is therefore click-driven (plus the
  `#account` hash, which lights ACCOUNT as well as scrolling to it). At 1280x832 the cards
  fit without scrolling at all, so the list is mostly a map rather than a lift; the artboard's
  own note predicted exactly this.
- **The alerts columns are an explicit `grid-column` per section, not two column elements.**
  Same mechanism, and same reason, as phase 2's landing detail: the phone reads channels →
  sensitivity → timing → account → this app, and the design's left column is the 1st and 3rd
  of those. Five `display: contents` wrappers are placed into columns 1, 2, 1, 2, 2. The
  gated three are inside the `isReady` branch, so a screen still loading its settings lists
  and draws only ACCOUNT and THIS APP.
- **A card in the pane still leaves the frame, and that is not fixed here.** `DiscoveryCard`
  and the "…is on your watch list · Open it" link both navigate to `/route/:id`, which is a
  `bare` screen with no rail — so clicking a find inside the detail pane drops out of the
  frame entirely. That is unchanged phase 0-2 behaviour rather than something phase 3 broke,
  and fixing it means giving those two the same in-pane treatment the look-up now has. It is
  on the phase 4 list below.
- **The look-up's way back is a control, not the clear flow.** The brief expected the field's
  own ✕ to serve; the destination box has no ✕ (only the origin does, deliberately). So the
  pane carries one back button, labelled `Deals from your airports` — the heading it returns
  to, so no new copy was invented. Editing either end of the pair also drops the panel, since
  a pane holding a pair the form has moved past is stale.

*Phone: 19 baselines at 0 diff, phone suite unchanged. `meta.wide` on `/search`, `/create`
and `/alerts`; four new section ids (`#channels`, `#sensitivity`, `#timing`, `#this-app`)
join the `#account` that already existed.*

### Phase 4 — Quality and polish — **SHIPPED**
- **A find opens in the pane.** `DiscoveryCard` and search's "Open it" link do what phase 3's
  look-up does instead of navigating to the bare `/route/:id`.
- **Focus and an announcement when the pane swaps**, plus a roving `tabindex` and arrow keys on
  the master pane's tab lists, and a `:focus-visible` ring proved rather than assumed.
- **A dark pass over every wide layout** at 1280x832 and 1024x600.
- **Desktop and tablet screenshot baselines**, light and dark, at `maxDiffPixels: 0`.
- **`orientation: any`** in the manifest — Ghie's call, 2026-08-24.

What was actually built differs from the sketch above in seven places, each of which is a
decision worth keeping:

- **The dark pass was measured, not eyeballed, and it found exactly two wide-only faults.** A
  sweep over every element with its own text — compositing the background stack, applying the
  cumulative opacity, and comparing against WCAG AA — ran on all six wide screens at both
  widths in both themes. The two it found are both in components only the frame mounts.
  `RouteRows` dims the city to `opacity: .66` and the fare to `.78`, which is right on a card
  and wrong on the **accent-filled selected row**: white on `--accent` is only 3.4:1 to start
  with, and 0.66 of it measured **2.32:1**. The dimming is therefore released on the active row
  only, taking both to the same 3.38:1 the row's own code prints at — the value every other
  accent-filled control in this app already carries. `--accent` and `--on-solid` are not
  touched: they are the palette, they are the phone's too, and a token change here would be a
  redesign smuggled in as an accessibility fix. And `.seclist__item--active` (the alerts master
  pane) marked the current section with `background: var(--card)` and `box-shadow: var(--shadow)`
  — which is the design canvas's own recipe, and in the dark theme is `--card` on `--panel` at
  **1.13:1** under a **black** shadow, so the current row had no shape at all. It gains the card's
  own `--line` edge as an *inset* ring, so nothing moves and the light theme keeps its shadow.
- **What the sweep found and this phase deliberately did not fix.** The calendar's day numbers
  (`.cell__day`, `opacity: .7` over the heat scale) measure 2.86–4.20:1 and the cheapest prices
  4.22–4.45:1; the discovery badge measures 4.19:1. Every one of those is drawn identically on
  the phone, is inside the 19 baselines, and is a palette decision rather than a frame one — so
  fixing them is a change to the phone, which this plan is gated against. They are written down
  in "Still open" instead. The two disabled search buttons measure under 3:1 at `opacity: .45`
  and are *correct*: WCAG 1.4.3 exempts an inactive control.
- **The rows are a tab list with MANUAL activation.** Arrow keys move the focus and the tab stop;
  Enter and Space — the button's own — choose. Automatic activation is the commoner reading of
  the pattern and it is wrong here: every selection refetches a route and moves the globe, so
  arrowing from the top of the list to the bottom would have fired six requests nobody asked
  for. Left/Right do what Up/Down do, the ends wrap, Home/End go to the ends, and the list
  reports `aria-orientation="vertical"` because a `tablist` is horizontal unless it says
  otherwise. `kind="group"` — the watch list — is untouched: those are ordinary buttons and Tab
  reaches every one of them, which is right for a group of toggles.
- **The panel owns the focus move, and the landing page does not ask for it.** `RouteDetailPanel`
  takes an `autofocus` prop and sends the focus to whichever of its four headings rendered,
  watching the element rather than the fetch — the loaded, the **checking**, the not-found and
  the failed states have four different headings and the one that exists is the one worth being
  sent to. The checking heading is the one a **discovery** needs: a found route has no `routes`
  row, so its read 404s into a look-up and the panel sits in that state for several seconds —
  and with no heading there the focus fell all the way to `<body>`, the card that was pressed
  having been unmounted behind it. It is also the one heading that goes quiet: that branch keeps
  its own `role="status"` only when nothing is coming to focus it, so the same sentence is never
  announced twice, once as a live region and once as a focus move. The focus call passes
  `preventScroll`, because a pane that scrolls itself while handing the focus over has moved for
  a reason the reader cannot see. Only **search** passes it. On the landing page the pane swaps
  from a row inside a tab list, and moving the focus out of that list would break the arrow keys
  that had just been used to reach it; the row stays focused, which is what a tab list promises.
- **The live region says what the pane is of, and it is mounted before it has anything to say.**
  A region added to the DOM with its text already in it announces nothing, so the `role="status"`
  paragraph exists for as long as the frame does and only its text changes: `Deals from your
  airports` (the heading it starts on, existing copy) and `Showing AMS → LIS`. That leading word
  is the **only new string in this phase**; everything else on screen is copy the app already had.
- **The boarding pass's flight line did not wrap, and the rule went in anyway.** Measured at the
  grid's real card width (263 px, which is the only width it has — `repeat(auto-fill,
  minmax(240px, 1fr))` in a 540 px column gives two columns at every window from 1024 up), the
  eyebrow's natural width plus its icon plus the widest verdict pill (`Falling`) leaves **5.0 px**
  of slack on four of the six passes. It fits, and it is 5 px from not fitting — a fallback
  display font, a different rasteriser or one longer verdict label is the whole margin. So
  `.pass__flight` is `nowrap` with an ellipsis behind a `min-width: 0` eyebrow, in a wide-only
  media query inside `WatchRow.vue`, and the measurement is written here rather than a wrap
  being claimed that this renderer does not produce.
- **The create screen's empty master says one sentence, and the watch list still says two.**
  `DealRules` takes a `compact` prop; on `/create` the list's empty state is the blurb's own
  first sentence, because the long version explains what the box beside it is visibly for. The
  watch list's paragraph is left **byte-identical** rather than refactored into a shared
  constant — the phone's watch baseline is one of the nineteen this plan is gated on, and six
  duplicated words are cheaper than a whitespace surprise. Same call phase 3 made when it copied
  `.screen__notice` verbatim rather than renaming it.
- **The wide baselines are 32 images and they compare in CSS pixels.** `wide-baselines.spec.js`
  runs in both wide projects and photographs eight screens in both themes: the six tabbed ones
  plus the route detail and the login, which have no wide layout at all and are in the set
  precisely because "`--shell-max` is retargeted on the frame and not on `:root`" is a promise
  worth a picture. The project name is in the file name or the two projects would overwrite each
  other's images. `toHaveScreenshot` normalises to CSS pixels, so the tablet's DPR 2 costs
  nothing in bytes or in diff surface. Masks are the phone spec's plus the master rows'
  `.route-row__price` and `.route-row__dot` — a fare and the tone of the verdict beside it, both
  seeded content the phone has no equivalent of — and the landing page's set gains the detail
  panel's because the frame draws one there. 3.8 MB, recorded once on the final tree and proved
  at zero on a second full run.
- **A spec that must not write.** The find-in-the-pane assertion blocks `POST /api/routes/lookup`,
  because a discovery is by definition a route this sandbox has never priced: letting the panel
  settle would create a `routes` row, and no endpoint can remove one again. The test is about
  where the answer is drawn, not about the answer, and the look-up test beside it already proves
  a real route renders in the pane (it uses `AMS-LIS`, which is seeded, for the same reason).

**Ghie's call, 2026-08-24: the installed app may rotate.** `orientation` in the manifest is `any`
rather than `portrait`. An installed iPad turns into the master–detail frame, which is what the
whole plan was for; an installed phone turned sideways is 844x390 and gets the phone layout,
which the `min-height: 600px` half of the breakpoint guarantees rather than hopes for.

*Phone: 19 baselines at 0 diff, phone suite unchanged.*

## Still open (not layout, and not gated on)
- **The phone's own contrast.** `.cell__day` and `.cell__price` on the calendar's heat scale, and
  the discovery strip's `Unverified` badge, measure between 2.86 and 4.45:1 in both themes. They
  are the phone's pixels, so moving them means re-recording the nineteen baselines deliberately
  and reading the diff — a change to the palette, on its own PR, not a side effect of a frame.
- **The accent family itself.** `--on-solid` on `--accent` measures **3.38:1**, and every control
  in this app that fills with the accent draws 11–13.5px text on it — the selected master row that
  phase 4's dark pass released the dimming on, and with it the primary buttons, the pills and the
  chips, on the phone as much as in the frame. WCAG AA wants 4.5:1 at that size (3:1 only from
  18.66px bold or 24px), so the family is short app-wide rather than in one place, and phase 4
  deliberately did not touch it: `--accent` and `--on-solid` are the palette, and darkening either
  is a redesign that moves the nineteen phone baselines. It belongs on a palette PR that re-records
  them on purpose.
- **The globe's cost at a pane's size.** Nobody has profiled the renderer at 852x440 and up on a
  real iPad; the gate measures correctness, never frame rate, by design.
- **A shared `MasterPane`/`DetailPane` component.** Five screens now write the same four
  `display: contents` wrappers. It was right not to build it in phase 1 with one screen; with
  five it is a tidy-up somebody could do in an afternoon, and it would move no pixels.

## Acceptance per phase (what Ghie checks on the iPad)
Every phase: phone screenshots pixel-identical to the baselines, phone e2e green.
0. Nothing changed on the phone; the globe still fills its box after rotating the iPad.
1. Landing page in landscape = list | globe + detail, no empty band; tapping a route
   swaps the detail without leaving; portrait shows rail + one pane with a chip strip.
2. Calendar and Watch use the pane (iPad in landscape, or a desktop window — 1024px and up);
   calendar cells square; day panel docked beside the month from 1264px, under it below that.
   **Done.**
3. Search, Create, Alerts use the pane (iPad in landscape, or a desktop window — 1024px
   and up); the search card lists finds two abreast, a look-up answers in the pane, the
   new-rule sentence sits beside the rules it joins, and the alert cards are two columns.
   **Done.**
4. Dark mode clean; the rail and the master rows answer to a keyboard and show a focus ring;
   a find opens in the pane rather than leaving the frame; the installed iPad app rotates;
   nothing regressed on the phone (full e2e + screenshot baselines). **Done.**

## Working agreement
One Opus builder per phase, extra-explicit brief pointing at the canvas artboard and
`build.py` recipe for that screen; gates under `heavy-work`; Opus review before ready;
plain-language PR bodies; squash-merge. Start with Phase 0 + 1 in the same week, the
rest as budget allows.
