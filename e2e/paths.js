// Where the harness puts things. One module with no side effects, imported
// by BOTH playwright.config.js and the fixtures (docs/E2E.md).

/** Everything the run writes. Gitignored — see .gitignore. */
export const ARTIFACTS = new URL('./artifacts/', import.meta.url)

/** Committed reference images, for the screens stable enough to have one. */
export const BASELINES = new URL('./baselines/', import.meta.url)

/**
 * The signed-in session, captured once by specs/auth.setup.js and reused —
 * not a convenience, the login throttle requires it (docs/E2E.md "The specs").
 */
export const STORAGE_STATE = new URL('./state/auth.json', ARTIFACTS).pathname

/** The stylesheet the phone baselines are photographed through (docs/E2E.md). */
export const BASELINE_STYLE = new URL('./baseline.css', import.meta.url).pathname

/** A full-page screenshot kept for a human to look at, not compared to anything. */
export function screenPath(name) {
    return new URL(`./screens/${name}.png`, ARTIFACTS).pathname
}

// The production hostname, on purpose — the browser is bent, not the app
// (docs/E2E.md "The hostname trick").
export const HOST = 'flights.ghiecode.io'
export const PORT = 3185
export const BASE_URL = `http://${HOST}:${PORT}`
