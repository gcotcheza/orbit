# Orbit — house rules

Fleet engineering standards: `docs/STANDARDS.md` (also loaded via
`.claude/rules/standards.md`). They apply here in full; anything below
overrides them and says why.

- **Where work happens.** A git worktree, one per branch, at
  `/var/www/orbit-worktrees/<short-name>` — never in the checkout itself.
  `/var/www/orbit` IS production and is bind-mounted into the running
  containers, so editing, branching or building there changes the live site
  immediately.
- **Merging to `main` does not deploy.** Nothing ships until
  `.claude/commands/deploy.md` is run, literally: the long-lived containers
  boot the code once, so an unrestarted deploy looks entirely successful and
  serves the old app.
- **The gate.** `scripts/check.sh dev` against a stack you brought up; the
  deploy runs `scripts/check.sh overlay`. Browser gate: `scripts/e2e.sh`.
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
  network *and volumes*. Name a sandbox on the same command line:
  `COMPOSE_PROJECT_NAME=orbit-<name> docker compose up -d postgres redis app`.

## Exceptions

- **C7/T1, the layer rule is reviewed, not executed.** The three layers above
  are real (`docs/PLAN.md:7`) and nothing enforces them: `grep -n deptrac
  composer.json` finds nothing. Drop this line when `grep -n deptrac
  scripts/check.sh` finds a step; follow-up branch `chore/deptrac`.
- **S4, the policy is on the host vhost and is report-only.**
  `deploy/nginx/flights-ghiecode.conf:164` ships a full
  `Content-Security-Policy-Report-Only` that `resources/views/app.blade.php:11`
  already writes against, and `docs/GO-LIVE.md:364` holds the promotion item
  and its stop condition; but `docker/web/nginx.conf` — the app's own nginx,
  which is what S4 asks for — sets three `Cache-Control` headers and no CSP.
  Drop this line when `grep -n Content-Security-Policy docker/web/nginx.conf`
  finds an enforcing policy and a browser test proves a deliberate inline
  script is caught; follow-up branch `feat/csp`.
- **C12, validation happens on the server and nowhere else.** The three forms
  (`resources/js/Views/Login.vue:74`, `resources/js/Views/Search.vue:257`,
  `resources/js/Components/settings/ChangePassword.vue:115`) render the
  server's 422 sentences after a round trip; there is no rules module the
  browser reads and no test holding the two sides together. Drop this line
  when `resources/js/lib/` carries that module and a test compares its
  sentences with `app/Http/Requests/`; follow-up branch
  `feat/inline-validation`.
