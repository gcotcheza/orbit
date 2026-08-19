# Handoff: Flight Deal Tracker — "Orbit" (mobile app with 3D globe home)

## Overview
A mobile flight-deal tracker. The home screen is a photorealistic 3D globe that auto-tours the user's watched routes: for each route the camera shows the fitted Earth, dives to the origin airport, then flies the great-circle path like a plane (climb → cruise → descend) and lands on the destination, marked with a pulsing ring. Other screens: route detail with price history + deal score, a price calendar heatmap, a natural-language alert-rule creator, a watchlist, and alert settings. Dark (default) and light themes.

## About the Design Files
The files in this bundle are **design references created in HTML** — a working prototype showing intended look and behavior, NOT production code to copy directly. Your task is to **recreate this design in the target codebase's existing environment** (React Native, Swift/SwiftUI, Kotlin, Flutter, React web, …) using its established patterns and libraries. If no codebase exists yet, choose the framework that best fits a mobile app with a WebGL/3D globe (e.g. React Native + expo-gl/three, or web React + globe.gl).

- `Flight Deal Tracker - Globe.dc.html` — the full prototype (open in a browser; needs internet for the globe textures/library). The design markup sits inside `<x-dc>…</x-dc>`; the behavior in the `class Component` script at the bottom. Ignore `support.js` (prototype runtime only).
- `screenshots/` — one PNG per screen (dark theme, plus one light-theme home).

## Fidelity
**High-fidelity.** Colors, typography, spacing, radii, copy, and the globe choreography are final intent. Recreate pixel-perfectly using your codebase's component library; treat the exact values below as the design tokens.

## Screens / Views
The prototype renders inside a 372×760 phone frame; content area ≈ 352×740, `border-radius: 38px`, vertical layout = status bar (42px) → scrollable content → tab bar (78px, absolute bottom).

### 1. Home — "Orbit" globe (`01-home-globe-dark.png`, `07-home-globe-light.png`)
- **Header** (padding `4px 18px 6px`, flex space-between):
  - "TRACKING LIVE" eyebrow: 11px/700, letter-spacing .15em, muted color, preceded by a 7px pulsing green dot (2.2s pulse).
  - "Good morning": Space Grotesk 700, 23px, letter-spacing −.02em, ink color.
  - Right: 40px round profile button (card bg, 1px line border). *Known gap: in the prototype it navigates to an onboarding screen that was removed — wire it to profile/onboarding in the real app.*
- **Globe viewport**: full-width × 360px, transparent background over the app's radial bg gradient.
  - Built with **globe.gl** (Three.js). Config used:
    - texture: `three-globe/example/img/earth-blue-marble.jpg`, bump: `earth-topology.png`; optional night texture `earth-night.jpg` (user-facing "Night lights" variant)
    - atmosphere on, altitude 0.22, color `#6f96ff` (dark) / `#7fb2ff` (light)
    - **only the active route's arc** is drawn: gradient `#ffd166 → #3cc0f2`, **hairline stroke 0.35** (inactive would be 0.16) — intentionally thin, like satellite-route imagery; altitude auto-scale 0.45, animated dashes (dash 0.5 / gap 0.22 / 2400ms)
    - origin point `#3fdcbb`, destination point `#ffd166` (radius 0.42, altitude 0.015)
    - destination pulse ring: `rgba(255,209,102,α)` fading, max radius 3, repeat 1100ms
    - controls: rotate-drag only (no zoom/pan/auto-rotate)
  - **Camera choreography per route** (replays on route change):
    1. Fitted globe: pointOfView(route midpoint, altitude 2.4) — 900ms (instant on first load)
    2. after ~1.3s: dive to origin, altitude 0.42 — 1700ms
    3. after another 2.5s: fly the 72-point great-circle arc over 3600ms with ease-in-out-quad; altitude curve `0.42 − 0.22·e + 0.4·sin(π·e)` (climb, cruise, descend deeper into the destination)
    4. on landing, dwell (Motion setting: Calm 5.4s / Balanced 4.4s / Lively 3.3s), then auto-tour advances to the next route.
  - **Plane**: a 32px white airplane glyph overlaid at the **center of the globe viewport** (the camera follows the flight, so the plane stays centered), rotated to the geographic bearing of travel, glow: `drop-shadow(0 0 7px rgba(150,185,255,.95))`. Visible only during the arc flight.
  - Overlays: top-left chip "6 routes orbiting" (card bg, 20px radius, 6px accent dot); bottom-center caption "AMS → LIS · Lisbon" (Space Grotesk 600 12px, letter-spacing .12em, muted).
- **Spotlight card** (overlaps globe by −30px margin): card bg, radius 22, padding 16/17. Route code (Space Grotesk 700 10.5px, .13em, accent-ink), city 23px, country 12px muted; right-aligned price (Space Grotesk 700 27px, tabular) + "% below usual" 11.5px. Bottom row: verdict pill (tone bg/ink + 6px dot) and a 72×24 sparkline (2px stroke in tone color) + chevron. Tapping opens Route detail.
- **Route rail**: horizontal scroll of chips ("AMS→LIS €58"): active = accent bg, white text, glow shadow `0 6px 16px rgba(94,132,255,.3)`; inactive = card bg + 1px line border. Tapping selects the route and replays the flight.

### 2. Route detail (`02-route-detail.png`) — no tab bar
- "‹ Back" header, route "AMS → OPO" (Space Grotesk 700 ~28px), "Porto, Portugal" muted.
- Price block: €52 huge (Space Grotesk 700), "38% below its usual €84" caption; right: circular **deal score** gauge (84) — ring stroke in score color (≥80 `--good`, ≥60 `--info`, ≥45 `--warn`, else `--bad`), 157 circumference dash.
- **A cheap fare that may already be gone is demoted**: over 48 h old *and* ≥20% under usual (server's judgement, `cheapest.mayBeGone`) drops the price to 32px in `--muted` with a "Seen 3 days ago — may be gone" pill in `--warn-ink`/`--warn-bg`, and the booking hand-off goes to its outline variant. Fresh fares and fares near usual are untouched.
- **"Check live price"** under the price block: a full-width outline control (card bg, `--line` hairline, `--accent-ink` label) that asks Google via SerpAPI for that exact departure. Its answer takes the headline with a `--good-ink` "Live on Google · checked just now" line, Google's typical band under it, and Orbit's own fare relegated to a muted "Orbit's cached fare €36, seen 3 days ago". Refusals leave the price alone and explain in a quiet card-coloured note — "held in reserve" (nothing will be spent) and "could not reach Google" (nothing *was* spent, so the button stays) are different sentences.
- Price-history line chart (300×140): area fill + 2px line in tone color, dashed horizontal "usual price" reference at the normal-price y, end dot.
- Advice callout: tone-tinted bg, 34px icon square in tone color, title Space Grotesk 700 15px.

### 3. Price calendar — "When is it cheap?" (`03-price-calendar.png`)
- Title Space Grotesk 700, subtitle "Cheapest fare per day · June 2026" muted.
- Route selector chips (active = ink bg, bg-colored text).
- 7-column grid (MO–SU), each day cell: `aspect-ratio:1`, radius 9, day number + price, background = 5-stop heat scale green→red: `rgb(121,184,148) → (176,202,150) → (236,217,168) → (228,166,116) → (214,112,76)` interpolated across the month's min–max.
- Legend gradient bar €38 ↔ €116; banner "★ Cheapest this month: June 11 · €38" on good-bg.
- Tapping a day opens a bottom sheet: date, price, verdict pill (cheap ≤ lo+28% of range / pricey ≥ 66%), color swatch.

### 4. Create alert rule (`04-create-rule.png`)
- Free-text textarea seeded with "cheap weekend somewhere sunny in spring, leaving Friday from any NL airport, under €80".
- Text is parsed live into removable chips: From (AMS/EIN/DUS), Max price (€80), Trip length (2–3 nights), Depart (Fridays), Date window (Mar – May), Vibe (☀ Sunny). Chips have category label + value; removing a chip excludes it; reset restores.
- Match count ("6 routes match") + CTA.

### 5. Watchlist (`05-watchlist.png`)
- Boarding-pass-style rows: flag swatch (CSS-gradient flags), "AMS → LIS" + city names, flight-no "FW###", price vs usual, status pill (Good/Falling/Normal/Wait in tone colors), iOS-style toggle (46×27, knob 21px, on = `--good`), remove action.
- "Add route" expander: origin picker buttons (AMS/EIN/DUS, active = accent bg), 3-letter destination input, add button.

### 6. Settings / Alerts (`06-settings.png`)
- Toggle rows (email, push, weekly digest, quiet hours) with the same switch spec.
- Alert-sensitivity segmented control (3 options) with explanatory blurb per level.

## Interactions & Behavior
- **Auto-tour**: cycles `activeIndex` through routes; paused while a flight is in progress (tour timer is held until landing, then dwells per Motion setting). User tapping a rail chip resets the tour timer and flies that route immediately.
- **Theme**: dark/light toggle swaps the full CSS-variable palette (below); globe atmosphere color follows.
- Tab bar: 5 items — Orbit (home), Calendar, center + button (Create), Watch, Alerts; active tint `--accent`, inactive `--muted`; center button 48×42, radius 14, accent bg.
- Animations used throughout: rise-in `translateY(16px)→0` 0.5s on cards; sheet slide-up; 2.2s pulse on live dot.
- Screens with no tab bar: route detail (and onboarding, not present in this variant).

## State Management
- `screen` (home | detail | calendar | create | watch | settings), `activeIndex` (toured route), `selId` (detail route), `theme`, `calId`/`calDay` (calendar route + tapped day sheet), `ruleText` + `removedChips` (create), `watch[]` (id, active) + add-form state, `settings` (email/push/weekly/quiet booleans, sens 0–2).
- Globe needs: route list with origin/destination lat-lng, precomputed great-circle arc (~72 points), camera sequence state (seq token to cancel on route change), plane overlay visibility/bearing.
- Data in the prototype is hardcoded (6 routes, sparkline seeds, calendar prices); production should fetch prices + history per route.

## Design Tokens
Fonts: **Space Grotesk** (display/numbers/headings), **Hanken Grotesk** (body). Weights 400–700.

Dark theme (default): bg `#0a0f1e`, panel `#111829`, card `#161f33`, card2 `#1f2940`, ink `#eef2fc`, ink2 `#bac6df`, muted `#7e8aa6`, line `#28324a`, good `#3fdcbb` (bg rgba(63,220,187,.15), ink `#6ff0d4`), info `#3cc0f2`, warn/bad `#fb7185`, accent `#5e84ff`, accent-ink `#9fb6ff`, track `#26314a`, shadow `0 1px 2px rgba(0,0,0,.38), 0 14px 34px rgba(0,0,0,.45)`.

Light theme: bg `#edeefb`, panel `#f6f6fd`, card `#ffffff`, card2 `#e9eafb`, ink `#0d0630`, ink2 `#473d72`, muted `#8079a6`, line `#e0e0f4`, good `#0fae93` (bg `#d6f6ef`, ink `#097a66`), info `#00a5e0`, warn/bad `#e71d36`, accent `#3454d1`, accent-ink `#2440a8`, shadow `0 1px 2px rgba(40,30,20,.05), 0 10px 28px rgba(40,30,20,.08)`.

Globe accents: arc gradient `#ffd166 → #3cc0f2`, origin `#3fdcbb`, destination/ring `#ffd166`, plane `#f2f7ff`.

Radii: cards 22, chips/rail 13, calendar cells 9, pills 20, phone screen 38. Spacing: screen gutter 18–20px; type scale 10.5 / 11 / 12 / 13.5 / 15 / 23 / 27px (+ larger detail price).

App background (behind content): `radial-gradient(125% 58% at 50% -14%, accent 22% → transparent 58%) + radial-gradient(90% 55% at 108% 112%, info 14% → transparent 60%) + bg`.

## Assets
- Earth textures + globe engine (all loaded from CDN in the prototype):
  - Library: `globe.gl` v2 (bundles Three.js) — `https://unpkg.com/globe.gl@2/dist/globe.gl.min.js`
  - Day: `https://unpkg.com/three-globe/example/img/earth-blue-marble.jpg`
  - Bump: `https://unpkg.com/three-globe/example/img/earth-topology.png`
  - Night: `https://unpkg.com/three-globe/example/img/earth-night.jpg`
  - For production, bundle these locally / use higher-res NASA Blue Marble imagery if deeper zoom is needed (CDN texture gets blurry below camera altitude ≈ 0.2).
- Airplane glyph: 24×24 SVG path `M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z`, fill `#f2f7ff`.
- Icons: simple 22px stroked SVGs (tab bar, back, profile) — replace with your icon set.
- Fonts from Google Fonts.

## Known gaps / notes
- The profile button on Home points at an onboarding flow that doesn't exist in this variant (blank screen) — implement or repoint.
- Prototype "Tweaks": Earth texture (Day/Night), Motion (Calm/Balanced/Lively), Auto-tour on/off — good candidates for user settings or remote config.

## Files
- `Flight Deal Tracker - Globe.dc.html` — full design + behavior reference
- `support.js` — prototype runtime (needed only to open the HTML; not part of the design)
- `screenshots/01-home-globe-dark.png` … `07-home-globe-light.png`
