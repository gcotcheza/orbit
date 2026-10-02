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
scripts/worktree.sh add feat/thing          # list, and remove <branch>, are the others
```

It fetches `origin` and runs `git -C /srv/sessions/orbit/repo worktree add -b
feat/thing /srv/sessions/orbit/worktrees/feat-thing origin/main`, naming the
clone rather than reading it off its own location, and refuses a clone or a
target under `/var/www`.

The clone is root-owned and every container here runs as `115:119`, so the tree
they mount is one they can read and cannot write — and for the overlay runner
that needs nothing done about it by hand. It wants no `.env` either: `docker
compose` prints one *"variable is not set"* warning per unset `DB_*` and
`REDIS_PASSWORD` and carries on, and the suite reads the committed
`.env.testing` rather than this file. The gate below was proved in a worktree
that had no `.env` at all.

`vendor/`, `bootstrap/cache` and `node_modules/` are bind-overlaid outside the
tree by that runner, and `storage/` — the application's own writable directory,
which the suite logs into through Monolog — is chowned to `115:119` by its
overlay step, which already runs as root and already chowns its own overlay. On
the deployed checkout the same line is a no-op, because `storage/` is app-owned
there already. PHPUnit warns once per run that it cannot write
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

**It replaces the fleet hooks, so it runs them.** This box sets `core.hooksPath`
in *system* scope — `/etc/gitconfig`, pointing at `/usr/local/lib/fleet-githooks`
— so every commit in every repository, by every user, already runs the fleet
`pre-commit` (gitleaks over the staged *blobs* through `git cat-file`, this
repository's own `.env` values, and a personal-identifier layer) and the fleet
`commit-msg`. `core.hooksPath` names a directory, not a file, so installing this
one switches **both** off — which is why each hook here ends by handing over to
its namesake in whatever directory system scope names: `pre-commit` after its own
nine patterns pass, `commit-msg` at once. The fleet hook's refusal is the
commit's refusal. Where system scope names no such hook, the commit goes ahead
with one line on stderr saying nothing else ran (`docs/DECISIONS.md`,
`the-local-hooks-chain-to-the-fleet-hooks-they-replace`).

One cost, stated rather than discovered: a checkout with no `.env` cannot
commit until it has one, because the layer that catches *your* live keys cannot
run without it. There is no second one to weigh against it, because there is no
override: S1 leaves the guard none, and a commit it refuses is reported with the
key it named and then fixed, never stood up some other way.

**The gate.** `scripts/check.sh` runs sixteen checks, six of them on the
host, stopping at the first failure: ShellCheck (every shell script under
`scripts/`, at `-S warning`), Gitleaks, Pint, the guard-diff lint (on the host,
the fleet's `fleet-lint-guard-diff` over `scripts/`), the deploy script's own tests (on
the host), the worktree script's own tests (on the host), the standards-version
check (on the host, against the canonical clone's `VERSION` and
`ENGINEERING-STANDARDS.md`, and the only check here that can see the vendored
copy go stale), the image-tag check (on the host, over the compose files, from
the canonical clone at `/srv/engineering-standards`, so this step runs on this
box), the deploy mutants
(on the host, breaking one deploy guard at a time to prove each of those tests
can still go red), `composer audit`, deptrac (layers, no baseline), PHPStan
(level 8, no baseline), `npm audit`, ESLint, Vitest, PHPUnit. It must pass
before a PR is merged — this project has no baseline for new debt to hide in.

It takes the runner as its one argument, and will not guess: `dev` uses the
stack you already have up, `overlay` gives every step a throwaway container with
its own `vendor/`, `bootstrap/cache` and `node_modules/`, and is the one to use
in a tree with no stack up — including the worktree a merged head, or the merge
commit the deploy named instead, is gated in when the ledger holds no green for it. Same checks, same order, either way.
**A green run appends its own line to the gate ledger**, which is what
`scripts/deploy.sh` reads instead of re-running the gate on the box.

```bash
export COMPOSE_PROJECT_NAME=orbit-<name>
docker compose -f docker-compose.yml -f docker-compose.ci.yml up -d --build
./scripts/check.sh dev
```

**Both files, every time, and `--build`.** `docker-compose.ci.yml` overrides one
thing — `orbit/app:ci` in place of the `orbit/app:latest` that
`docker-compose.yml`'s three PHP services boot — and compose builds a missing
image under the name the file gives it, so a stack brought up without the
overlay is what rebuilds production's tag (`docs/DECISIONS.md`:
`the-ci-gate-builds-its-own-image-tag`). It never rebuilds a *stale* one, which
is why the `dev` recipe carries `--build` and the overlay runner builds `app`
itself on every run. `scripts/check.sh` sets both files for itself, whichever
runner it is given.

On the server, a worktree must use a sandbox project brought up from that
same directory and named on the same command line —
`COMPOSE_PROJECT_NAME=orbit-<name> docker compose -f docker-compose.yml -f docker-compose.ci.yml up -d --build postgres redis app`,
then `COMPOSE_PROJECT_NAME=orbit-<name> bash scripts/check.sh dev` (`web` is
left out because it publishes `127.0.0.1:3085`, which production owns); the gate
refuses to run against a stack started from another directory.

That stack is where an `.env` becomes necessary: `docker-compose.yml`
interpolates `DB_*` and `REDIS_PASSWORD` out of it, and postgres exits at boot
on an empty password (*"You must specify POSTGRES_PASSWORD to a non-empty value
for the superuser"*).

```bash
cd /srv/sessions/orbit/worktrees/feat-thing
cp .env.example .env
key="base64:$(openssl rand -base64 32)"
sed -i "s|^APP_KEY=.*|APP_KEY=${key}|; s|^DB_PASSWORD=.*|DB_PASSWORD=sandbox|; s|^APP_ENV=.*|APP_ENV=local|" .env
```

What this page proves from a root-owned worktree is the `overlay` runner and
the browser gate; `dev` there additionally needs `vendor/` and `node_modules/`
handed over the way the gate hands over `storage/`, and that is not proven
here.

A worktree made the way this page shows needs no seam: it is root-owned, root's
git owns it, and the gate's secrets step — the one check that reads the tree
with git rather than through a container — runs against it unaided. `CI_GIT`
exists for a run inside `/var/www/orbit`, where root's git is refused before it
can list anything; the deploy no longer makes one, and `scripts/check.sh` with
no argument prints what the variable is for and what its `-C` has to name.

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
checkout is root-owned, because every container runs as `115:119`. The browser
runs on the sandbox's own compose network, never the host's, and reaches the app
as `http://flights.ghiecode.io:8080` through an alias there; `127.0.0.1:3185` is
for a person looking at a `--keep` stack. `scripts/e2e-network-test.sh`, run by
the gate's PHPUnit step, keeps it that way.

```bash
scripts/e2e.sh                                # everything
scripts/e2e.sh -- specs/globe.spec.js         # one spec
scripts/e2e.sh --keep -- --grep "heat map"    # one test, stack left up
```

**From a root-owned worktree it needs five paths handed over first.** The
overlay gate above leaves none of them behind, and this script installs
`vendor/` and `node_modules/` into the checkout rather than over it, as one-off
containers running `115:119`.
It cannot do that in a root-owned tree until those directories exist and are
theirs — a `115:119` container cannot create one (`mkdir: Permission denied`) —
and the two refusals it carries (`scripts/e2e.sh:383-390`, `:417-423`) are about a checkout
that is being *served*, not about a worktree. This is the whole of it, run as it stands:

```bash
wt=/srv/sessions/orbit/worktrees/feat-thing
cd "$wt"
mkdir -p vendor node_modules public/build bootstrap/cache storage
chown -R 115:119 vendor node_modules public/build bootstrap/cache storage
heavy-work orbit-e2e-thing -- bash scripts/e2e.sh
chmod -R go-w vendor node_modules
```

The `chmod` is last on purpose: until the script has run there is nothing in
those two directories to tighten. No `.env` is needed for any of it — the
script writes its own `.env.e2e`. The gate reruns `vite build` whenever
`public/build/manifest.json` is missing or older than `resources/`,
`package-lock.json` or `vite.config.js` (`scripts/e2e.sh:403-414`), so emptying
`public/build/` by hand after a front-end edit is no longer needed — in a
served checkout it refuses the rebuild instead. Everything after `--` reaches
`playwright test` unchanged
(`scripts/e2e.sh:117`, `:502`), which is how one spec, `--project=tablet`, or a
re-recording `--update-snapshots=changed` gets through.

Fourteen green checks have never seen a screen — [`docs/E2E.md`](E2E.md)
explains what that costs and what this harness found.

## Deploy

`scripts/deploy.sh <PR#>` **is** the deploy: it resolves the merged pull request,
reads the gate ledger, and runs the whole moving half in one `heavy-work` job —
fast-forward, `composer install --no-dev` and the asset build only when their
inputs moved, migrate, `build:retain`, `view:clear`, the drain and **the four
restarts** (the containers boot the code once, so a deploy that stops before
them looks entirely successful and serves the old app). `scripts/verify.sh` is
the post-deploy battery. [`.claude/commands/deploy.md`](../.claude/commands/deploy.md)
is what is left for a person to decide; going live from scratch, including the
host nginx vhost and the owner-key decisions, is [`docs/GO-LIVE.md`](GO-LIVE.md).

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
