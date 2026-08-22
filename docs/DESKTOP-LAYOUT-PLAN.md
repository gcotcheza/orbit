# Desktop / iPad layout — implementation plan

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

### Phase 0 — Foundations (no visible change on phone)
- Tokens: `--bp-tablet: 768px`, `--bp-desktop: 1024px`; `--rail-width: 76px`,
  `--master-width: 352px`.
- Make the four `max-width: var(--app-width)` clamps (`.app-shell`, `.tab-bar`, `.sheet`,
  `.toast`) layout-aware instead of absolute.
- `GlobeStage`: stage height driven by its container (`ResizeObserver` → globe.gl
  `width()/height()`), replacing the fixed 360 px and the phone-landscape `40vh` rule.
- Manifest `orientation` → `any`.
- e2e: FIRST record phone screenshot baselines for every screen (390×844, light + dark)
  and wire a pixel-diff check into the gate; then add `tablet` (820×1180) and `desktop`
  (1280×832) Playwright projects that run a smoke spec (shell renders, nav present, no
  horizontal overflow) — assertions grow per phase.
- Docs: this file + a §36 note. *Effort S. Risk: globe resize jank — verify on a real iPad.*

### Phase 1 — The frame and the landing page (≥ 768)
- `AppShell` grows a desktop branch: `IconRail` (the tab bar's five destinations,
  centre Search button kept) replaces `TabBar` at ≥ 768; `MasterPane` + `DetailPane`
  at ≥ 1024; collapsed single pane + route chip strip at 768–1023.
- Route detail is extracted into a `RouteDetailPanel` component used by the phone
  screen `/route/:id` and by the desktop detail pane; selecting a route in the master
  pane updates the URL (`/?route=AMS-LIS`-style query or a child route) without leaving
  the screen; `KeepAlive` of Home stays intact.
- Landing page = master routes list | globe banner (flexible) + `RouteDetailPanel`.
- e2e desktop: landing layout assertions; the existing phone suite untouched.
  *Effort L (the core). Risks: router state for the selected route, Home KeepAlive,
  scoped-style specificity, globe resize.*

### Phase 2 — Calendar and Watch in C
- Calendar: master = routes list; detail = month grid scaled to the pane (square cells,
  gap absorbs height) + the day sheet's content docked as a side panel at ≥ 1024
  (bottom sheet below).
- Watch: master = routes list; detail = selected boarding pass (full recipe) + the
  others as a 2-column grid, deal rules in a side column.
  *Effort M. Risk: DaySheet teleport/focus handling when docked.*

### Phase 3 — Search, Create, Alerts in C
- Search: master = the search card (typeahead in flow); detail = "Deals from your
  airports" as a 2-column grid; a look-up result shows `RouteDetailPanel` in the pane.
- Create: master = deal rules list; detail = compose + chips + banner + CTA at pane width.
- Alerts: master = section list (CHANNELS … THIS APP, scroll-spy); detail = settings
  cards in two columns.
  *Effort M. Risk: none structural; mostly layout.*

### Phase 4 — Quality and polish
- Dark theme pass on every desktop screen; focus order and keyboard use of the rail;
  reduced-motion; hover states on the rail/rows; desktop screenshot baselines
  (light + dark) in e2e; perf check of the globe at 852×440+.
- Docs: `docs/BUSINESS-LOGIC.md` §36 frontend notes updated; `docs/E2E.md` gains the
  desktop/tablet projects. *Effort S–M.*

## Acceptance per phase (what Ghie checks on the iPad)
Every phase: phone screenshots pixel-identical to the baselines, phone e2e green.
0. Nothing changed on the phone; the globe still fills its box after rotating the iPad.
1. Landing page in landscape = list | globe + detail, no empty band; tapping a route
   swaps the detail without leaving; portrait shows rail + one pane with a chip strip.
2. Calendar and Watch use the pane; calendar cells square; day panel docked.
3. Search, Create, Alerts use the pane.
4. Dark mode clean; nothing regressed on the phone (full e2e + screenshot baselines).

## Working agreement
One Opus builder per phase, extra-explicit brief pointing at the canvas artboard and
`build.py` recipe for that screen; gates under `heavy-work`; Opus review before ready;
plain-language PR bodies; squash-merge. Start with Phase 0 + 1 in the same week, the
rest as budget allows.
