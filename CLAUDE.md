# Orbit — house rules

Fleet engineering standards: `docs/STANDARDS.md` (also loaded via
`.claude/rules/standards.md`). They apply here in full; anything below
overrides them and says why.

- **Where work happens.** A git worktree, one per branch, cut from the
  root-owned clone at `/srv/sessions/orbit/repo` by `scripts/worktree.sh add
  <branch>`, which refuses any path under `/var/www` and runs:
  `git -C /srv/sessions/orbit/repo worktree add -b <branch> /srv/sessions/orbit/worktrees/<name> origin/main`.
  `/var/www/orbit` IS production and is bind-mounted into the running
  containers, so editing, branching or building there changes the live site
  immediately; root's git does not enter it at all, and
  `/var/www/orbit-worktrees/` is retired.
- **Merging to `main` does not deploy.** Nothing ships until
  `.claude/commands/deploy.md` is run, literally: the long-lived containers
  boot the code once, so an unrestarted deploy looks entirely successful and
  serves the old app.
- **The gate.** From a worktree, `scripts/check.sh overlay` under its own
  `COMPOSE_PROJECT_NAME`, then `scripts/e2e.sh`; `dev` execs into a stack already up there.
  `scripts/deploy.sh` runs no gate: it refuses a head that lacks green ci and e2e rows in the gate ledger.
- **The gate isolates its writes; it does not refuse the live checkout (S6).**
  In either runner it refuses a compose stack started from any other
  directory, which keeps a worktree off production's containers. Overlay mode
  runs every PHP and node step in a throwaway container with its own
  `vendor/`, `bootstrap/cache` and `node_modules/` laid over the tree, so dev
  dependencies never reach the live app; it does hand `storage/` to the app's
  user. Nothing stops the gate inside `/var/www/orbit`, so it is never run
  there.
- **Layers.** `app/Domain` is pure PHP and imports no framework;
  `app/Application` holds the use cases and their `Ports/`;
  `app/Infrastructure` implements a port and imports inward, never the
  reverse. Eloquent is used directly for plain CRUD — no repository ceremony.
- **Why-decisions.** `docs/DECISIONS.md`. Domain rules, numbered and with
  their config keys: `docs/BUSINESS-LOGIC.md`. Locked decisions and the
  roadmap: `docs/PLAN.md`.
- **`design/README.md` is the authority on every screen** — tokens, copy,
  globe choreography — and `docs/API.md` is the contract between the back end
  and those screens: a screen that needs a field it does not list needs that
  file changed first.
- **No PHPStan baseline, ever.** A wrong finding is ignored in `phpstan.neon`
  with the reason beside it; a right one is fixed. There is nowhere for new
  debt to hide.
- **Never a bare `docker compose` from a worktree.** `docker-compose.yml` pins
  `name: orbit`, so a bare command resolves to production's containers,
  network *and volumes*, and its three PHP services name `orbit/app:latest` —
  which compose rebuilds, under that name, the moment the image is missing.
  Name a sandbox *and* the gate's overlay on the same command line:
  `COMPOSE_PROJECT_NAME=orbit-<name> docker compose -f docker-compose.yml -f docker-compose.ci.yml up -d --build postgres redis app`.
  `docker-compose.ci.yml` overrides the tag and nothing else, and
  `scripts/check.sh` sets both files for itself.

## Exceptions

- **C12, validation happens on the server and nowhere else.** The three forms
  (`resources/js/Views/Login.vue:74`, `resources/js/Views/Search.vue:257`,
  `resources/js/Components/settings/ChangePassword.vue:115`) render the
  server's 422 sentences after a round trip; there is no rules module the
  browser reads and no test holding the two sides together. Drop this line
  when `resources/js/lib/` carries that module and a test compares its
  sentences with `app/Http/Requests/`; follow-up branch
  `feat/inline-validation`.
