// =============================================================================
// Where the harness puts things, and what it points at
// =============================================================================
// One module with no side effects, imported by BOTH playwright.config.js and
// the fixtures. It exists because the config cannot import the fixtures — the
// fixtures read .env.e2e at import time and the config is loaded by tooling
// that has no business needing a running sandbox (`--list`, an editor's test
// explorer) — and two copies of these paths would be two copies to keep in
// step.
//
// URL RATHER THAN A RELATIVE STRING. Playwright resolves some paths against the
// config file and others against the process's working directory, and the
// working directory here is /work inside a container. Deriving everything from
// `import.meta.url` makes every path absolute and makes none of it depend on
// where the run was started from.
// =============================================================================

/** Everything the run writes. Gitignored — see .gitignore. */
export const ARTIFACTS = new URL('./artifacts/', import.meta.url)

/** Committed reference images, for the screens stable enough to have one. */
export const BASELINES = new URL('./baselines/', import.meta.url)

/**
 * The signed-in session, captured once by specs/auth.setup.js and reused.
 *
 * NOT A CONVENIENCE. `POST /login` is throttled 5/min on email+ip
 * (AppServiceProvider), and the throttle runs before validation — eight specs
 * each signing in for themselves would spend the budget and start failing on
 * 429 somewhere around the fifth, which reads as a broken app.
 */
export const STORAGE_STATE = new URL('./state/auth.json', ARTIFACTS).pathname

/** A full-page screenshot kept for a human to look at, not compared to anything. */
export function screenPath(name) {
    return new URL(`./screens/${name}.png`, ARTIFACTS).pathname
}

// -----------------------------------------------------------------------------
// The origin
// -----------------------------------------------------------------------------
// THE PRODUCTION HOSTNAME, ON PURPOSE, and the browser is what is bent rather
// than the app: Chromium is launched with
// `--host-resolver-rules=MAP flights.ghiecode.io 127.0.0.1` so the name
// resolves to the sandbox's loopback port. The alternative — teaching the app
// to trust `localhost` — would mean the trusted-host allowlist in
// bootstrap/app.php is never exercised by anything with a browser in it, which
// is the one place a mistake in it shows up. docs/E2E.md has the long version.
//
// The port matches docker-compose.e2e.yml, which hardcodes it for the same
// reason production hardcodes 3085.
export const HOST = 'flights.ghiecode.io'
export const PORT = 3185
export const BASE_URL = `http://${HOST}:${PORT}`
