# Orbit

A mobile-first flight-deal tracker. It watches routes out of the Dutch airports
(AMS / EIN / DUS), keeps its own price history, scores every fare it sees, and
says something only when a route gets *insanely* cheap. The home screen is a 3D
globe that auto-tours the watched routes.

Laravel 13 + Vue 3 SPA, PHP 8.5, Postgres 18, Redis. Single user. Runs
Dockerized behind the host nginx at `https://flights.ghiecode.io`.

## Where things are written down

- **[`docs/PLAN.md`](docs/PLAN.md)** — the locked decisions (stack,
  architecture, deal score, alert rules) and the PR roadmap.
- **[`design/README.md`](design/README.md)** — the design handoff, and the
  authority on every screen: tokens, copy, globe choreography. The prototype
  next to it (`Flight Deal Tracker - Globe.dc.html`) is a reference to
  recreate, not code to copy.
