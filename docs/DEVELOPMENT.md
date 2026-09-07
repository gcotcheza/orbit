# Developing Orbit

Everything a contributor or an operator needs; the [`README`](../README.md)
describes the product itself. Sections below moved here verbatim when the
repository went public.

## Development

**Work in a git worktree, one per branch**, cut from the root-owned clone at
`/srv/sessions/orbit/repo`. `/var/www/orbit` is the deployed checkout, is
bind-mounted into the running containers and is app-owned, so root's git does
not enter it and no worktree is made there; `/var/www/orbit-worktrees/` is
retired (`docs/DECISIONS.md`: `worktrees-live-outside-the-served-tree`):

```bash
git -C /srv/sessions/orbit/repo fetch origin
git -C /srv/sessions/orbit/repo worktree add \
    /srv/sessions/orbit/worktrees/feat-thing -b feat/thing origin/main
```

The clone is root-owned and every container here runs as `115:119`, so the tree
they mount is one they can read and cannot write. The worktree needs an `.env`:
`docker-compose.yml` interpolates `DB_*` and `REDIS_PASSWORD` out of it, and
postgres exits at boot on an empty password (*"You must specify
POSTGRES_PASSWORD to a non-empty value for the superuser"*).

```bash
cd /srv/sessions/orbit/worktrees/feat-thing
cp .env.example .env
key="base64:$(openssl rand -base64 32)"
sed -i "s|^APP_KEY=.*|APP_KEY=${key}|; s|^DB_PASSWORD=.*|DB_PASSWORD=sandbox|; s|^APP_ENV=.*|APP_ENV=local|" .env
```

Nothing else has to be handed over by hand. `vendor/`, `bootstrap/cache` and
`node_modules/` are bind-overlaid outside the tree by the overlay runner, and
`storage/` — the application's own writable directory, which the suite logs into
through Monolog — is chowned to `115:119` by that runner's overlay step, which
already runs as root and already chowns its own overlay. On the deployed
checkout the same line is a no-op, because `storage/` is app-owned there
already. PHPUnit warns once per run that it cannot write
`.phpunit.result.cache` at the tree root; it is a warning, the run is still
green, and opening the root would give away the thing a root-owned clone is for.

That runner brings no stack up — its PHP steps are `docker compose run --rm
--no-deps` and `assets` is a profile-gated task, so nothing reaches postgres or
redis. Name a sandbox project on the same command line anyway, because a bare
`docker compose` here resolves to production, and put the run through the box
serializer:

```bash
COMPOSE_PROJECT_NAME=orbit-thing heavy-work orbit-gate-thing -- bash scripts/check.sh overlay
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

**The gate.** `scripts/check.sh` runs nine checks in the containers, stopping
at the first failure: Gitleaks, Pint, `composer audit`, deptrac (layers, no
baseline), PHPStan (level 8, no baseline), `npm audit`, ESLint, Vitest,
PHPUnit. It must pass before a PR is
merged — this project has no baseline for new debt to hide in.

It takes the runner as its one argument, and will not guess: `dev` uses the
stack you already have up, `overlay` gives every step a throwaway container with
its own `vendor/`, `bootstrap/cache` and `node_modules/`, and is what the deploy
runbook runs against production, whose `vendor/` is `--no-dev`. Same nine
checks, same order, either way.

```bash
docker compose up -d
./scripts/check.sh dev
```

On the server, a worktree must use a sandbox project brought up from that
same directory and named on the same command line —
`COMPOSE_PROJECT_NAME=orbit-<name> docker compose up -d postgres redis app`,
then `COMPOSE_PROJECT_NAME=orbit-<name> bash scripts/check.sh dev` (`web` is
left out because it publishes `127.0.0.1:3085`, which production owns); the gate
refuses to run against a stack started from another directory. What this page
proves from a root-owned worktree is the `overlay` runner; `dev` there
additionally needs `vendor/` and `node_modules/` handed over the way the gate
hands over `storage/`, and that is not proven here.

A worktree made the way this page shows needs no seam: it is root-owned, root's
git owns it, and the gate's secrets step — the one check that reads the tree
with git rather than through a container — runs against it unaided. `CI_GIT`
exists for the deploy, which runs the same gate against `/var/www/orbit`, where
root's git is refused before it can list anything; `scripts/check.sh` with no
argument prints what the variable is for and what its `-C` has to name.

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

Eight green checks have never seen a screen — [`docs/E2E.md`](E2E.md)
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
