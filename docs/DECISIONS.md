# Decisions

The engineering *why*, when it is too long to live in a comment and is not a
domain rule. Domain rules — what a deal score means, what "usual" is, which
number gates an alert — stay in `docs/BUSINESS-LOGIC.md`, numbered, with their
config key beside them; this file is about how the repository is built and
checked. A short inline pointer (`See docs/DECISIONS.md: <title>`) marks the
places where "simplifying" the code would break something real.

---

### the-fleet-standard-is-vendored-and-drift-tested
`docs/STANDARDS.md` is a byte-identical copy of `ENGINEERING-STANDARDS.md` from the fleet's `engineering-standards` repository — not a link to it and not a summary of it. A copy is what a reviewer on GitHub, a laptop checkout and a worker in a scratch clone can all read; the canonical clone at `/srv/engineering-standards/` exists on one machine, and a rule nobody can see in the diff is a rule that gets argued about instead of followed. `.claude/rules/standards.md` is a SYMLINK to that same file rather than a second copy, so a session loads the standard at launch — no `paths:` frontmatter, which would make it load lazily, only once somebody happened to open a matching file — while there is still exactly one set of bytes in the repository to hash. Cross-repo `@path` imports would have avoided the copy altogether and were rejected: an import pointing outside the project raises a one-time approval dialog, and these sessions run unattended, so it would sit on a prompt nobody is there to answer.

The first line of the vendored file declares the version and the sha256 of every byte after it, and `tests/Unit/Standards/StandardsDriftTest` recomputes that hash. Editing the vendored text locally therefore turns the gate red, which is the whole point: the standard is amended upstream and re-vendored whole, never patched in one project. It extends `PHPUnit\Framework\TestCase` rather than `Tests\TestCase` because it only reads two files off disk and must keep working in a checkout where nothing boots — the same reason the domain tests do. A project that genuinely cannot meet a rule writes the exception into its own `CLAUDE.md` — project precedence means a local rule legitimately overrides the fleet floor, and saying so is what stops the copies quietly disagreeing. When the canonical file moves nothing here notices by itself, and nothing should: the always-on session compares each project's declared version against the canonical repository and opens the bump PR, which arrives as an ordinary reviewable diff of the standard's own text.

### the-gate-checks-dependency-advisories
`composer audit --locked --no-dev` and `npm audit --omit=dev --audit-level=high` are the gate's advisory checks, and they run in two different places on purpose. The composer half sits directly after Pint: `--locked` reads the lock file rather than the installed tree, so it really is seconds of parsing plus one advisory lookup and belongs among the cheap checks. The npm half sits immediately before ESLint instead, because it is the first step that needs `node_modules` — putting it beside the composer half would let a cold `npm ci` land minutes of install ahead of PHPStan, which is precisely the ordering the gate's own header exists to protect.

Both are scoped to what production actually installs. `--no-dev` and `--omit=dev` keep phpunit, pint, larastan, vitest and Playwright out of the result: an advisory against a package no user can be reached through would redden the gate for something unfixable-by-design and teach people to skip the step.

THE TWO SEVERITY DIALS ARE NOT THE SAME, and the difference is narrower than it looks. npm takes a floor: `--audit-level=high` passes anything below High. Composer has no floor — `composer audit --help` offers `--ignore-severity`, which is an ignore-LIST of named levels, not a minimum — so short of enumerating every severity we do not care about, it fails on any advisory it finds against a production package. We leave it there deliberately: it is stricter than the fleet bar rather than looser, and Orbit's production `require` block is short enough that the noise is affordable. If that stops being true, the honest change is an explicit `--ignore-severity` line with a reason beside it, never dropping the step.

These are also the only gate steps that need the network, so a Packagist or npm registry outage can hold up a merge. That is the accepted cost of not learning about a known vulnerability from somewhere other than the gate.

### the-gate-is-written-twice-and-that-is-a-debt
`scripts/check.sh` drives the *running* `app` container with `docker compose exec`, and on this box that container's `vendor/` was installed `--no-dev`: there is no Pint, no PHPStan and no phpunit in it, and installing them would install them into production, because the checkout is bind-mounted. So the deploy runbook does not call the gate script — it restates the same seven steps against a throwaway container with an overlaid `vendor/` and `bootstrap/cache`. The reason is real and the workaround is correct; the duplication is still a duplication, and this PR had to edit both to add two steps, which is exactly how the two copies drift.

Collapsing them means parameterising the runner — one script that either `exec`s into a live container or `run`s a throwaway one with the overlay — and it is a change to the gate itself, so it does not belong in the PR that vendors the standard. The follow-up branch is `chore/one-gate-two-runners`. Until it lands, a step added to one copy is added to the other in the same commit.
