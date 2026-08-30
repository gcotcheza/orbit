# Developing Orbit

Everything a contributor or an operator needs; the [`README`](../README.md)
describes the product itself. Sections below moved here verbatim when the
repository went public.

## Development

**Work in a git worktree, one per branch** — `/var/www/orbit` is the deployed
checkout and must stay on `main`. The convention is
`/var/www/orbit-worktrees/<short-name>`:

```bash
git -C /var/www/orbit worktree add /var/www/orbit-worktrees/feat-thing -b feat/thing
```

**The commit guard.** Run it once, in the main checkout — it refuses to run
from a linked worktree, because `core.hooksPath` is shared configuration and
setting it from a worktree would arm the main checkout too:

```bash
scripts/install-hooks.sh
```

It points `core.hooksPath` at `scripts/hooks`, whose `pre-commit` scans the
staged diff with gitleaks, with nine patterns of its own, and against every
secret-shaped value in this checkout's `.env` — resolved through the shared git
dir, so it still works from a worktree. It names the key and the file, never the
value.

**It replaces a guard rather than adding one.** This box sets a *global*
`core.hooksPath` (`/root/.githooks`) that already runs gitleaks and the same
`.env` check on every commit in every repository. Git's precedence means a local
`core.hooksPath` switches that off completely, so `scripts/hooks/pre-commit` is
built to be a superset of it and is worth installing only for as long as it
stays one. A clone where nobody runs the installer is not unguarded: it keeps
the global hook.

Two costs, stated rather than discovered: a checkout with no `.env` cannot
commit until it has one, because the layer that catches *your* live keys cannot
run without it; and `git commit --no-verify` bypasses the guard, exactly as it
bypasses the global one — say so in the pull request if you use it.

**The gate.** `scripts/check.sh` runs seven checks in the containers, stopping
at the first failure: Pint, `composer audit`, PHPStan (level 8, no baseline),
`npm audit`, ESLint, Vitest, PHPUnit. It must pass before a PR is merged — this
project has no baseline for new debt to hide in.

```bash
docker compose up -d
./scripts/check.sh
```

On the server, a worktree must use a sandbox project brought up from that
same directory and named on the same command line —
`COMPOSE_PROJECT_NAME=orbit-<name> docker compose up -d postgres redis app`,
then `COMPOSE_PROJECT_NAME=orbit-<name> bash scripts/check.sh` (`web` is left
out because it publishes `127.0.0.1:3085`, which production owns); the gate
refuses to run against a stack started from another directory.

**The compose-project trap.** `docker-compose.yml` pins `name: orbit` and
publishes `127.0.0.1:3085`; the browser sandbox pins `orbit-e2e` on
`127.0.0.1:3185` with its own generated `.env.e2e`. A compose command is
resolved through the project name — containers, networks *and volumes* — so
running `-f docker-compose.e2e.yml` without `--env-file .env.e2e` would point
the sandbox at production's `.env`. It does not: `ORBIT_E2E` is a required
interpolation variable, and the command fails instead.

**The browser gate.** `scripts/e2e.sh` builds, seeds, drives a real Chromium
over SwiftShader and destroys the stack again — about 90 seconds after the first
run. Run it **as root** (it needs the docker socket); nothing it writes into the
checkout is root-owned, because every container runs as `115:119`.

```bash
scripts/e2e.sh                                # everything
scripts/e2e.sh -- specs/globe.spec.js         # one spec
scripts/e2e.sh --keep -- --grep "heat map"    # one test, stack left up
```

Seven green checks have never seen a screen — [`docs/E2E.md`](E2E.md)
explains what that costs and what this harness found.

## Deploy

The runbook is [`.claude/commands/deploy.md`](../.claude/commands/deploy.md), and
it is the authority: pull, gate, `composer install --no-dev`, build assets,
migrate, seed, **restart the long-lived containers** (they boot the code once —
a deploy that skips this looks entirely successful and serves the old app), then
the post-deploy checks. Going live from scratch, including the host nginx vhost
and the owner-key decisions, is [`docs/GO-LIVE.md`](GO-LIVE.md).

## Where the rest is written down

- **[`CLAUDE.md`](../CLAUDE.md)** — the house rules for this repository, and the
  written exceptions to the fleet standard.
- **[`docs/STANDARDS.md`](STANDARDS.md)** — the fleet engineering
  standard, vendored byte-identically from the `engineering-standards`
  repository. It applies here in full; `CLAUDE.md` says where Orbit does not
  meet it yet.
- **[`docs/DECISIONS.md`](DECISIONS.md)** — the engineering *why* that is
  too long for a comment and is not a domain rule.
- **[`docs/E2E.md`](E2E.md)** — the browser gate: the sandbox, the
  divergences from production, and how to add a spec.
- **[`docs/GO-LIVE.md`](GO-LIVE.md)** — first deploy, host nginx, owner
  keys, and an honest list of what is not done.
- **[`docs/PLAN.md`](PLAN.md)** — the locked decisions and the PR roadmap.
  Historical: where a number there and a number in `config/orbit.php` disagree,
  the config is right.
