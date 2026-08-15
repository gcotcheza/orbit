// =============================================================================
// Playwright — the browser gate's configuration
// =============================================================================
// Driven by scripts/e2e.sh, which brings the sandbox up first. Nothing here
// starts a server (`webServer` is deliberately absent): the thing under test is
// a four-container compose stack with a migrated database and sixty days of
// seeded fares, which is a shell script's job and not a config key's.
// =============================================================================
import { defineConfig } from '@playwright/test'
import { BASE_URL, HOST, STORAGE_STATE } from './paths.js'

export default defineConfig({
    testDir: './specs',

    // Traces, videos and the failure screenshots Playwright takes for itself.
    // Under artifacts/ with everything else the run produces, so .gitignore has
    // one line rather than four.
    outputDir: './artifacts/test-results',

    /*
     * BASELINES ARE COMMITTED AND ARE PLATFORM-STAMPED.
     *
     * A `toHaveScreenshot` baseline is a promise about a specific renderer: the
     * pixels below come out of SwiftShader on linux, and the same page on a
     * developer's macOS Chromium is a different image for reasons that have
     * nothing to do with the app. Naming the platform in the file means a run
     * somewhere else writes its own baseline instead of failing against one it
     * was never going to match.
     *
     * Only three screens have one — see docs/E2E.md for why the globe, the
     * calendar and the watchlist are artifacts-only.
     */
    snapshotPathTemplate: '{testDir}/../baselines/{arg}-{platform}{ext}',

    /*
     * ONE WORKER, AND NO PARALLELISM.
     *
     * Not a performance concession — a correctness one. Every spec drives the
     * SAME database: watchlist.spec pauses a route and puts it back, rules.spec
     * creates one. Two of those at once and each is testing a world the other
     * is editing. The suite is under two minutes serially.
     */
    fullyParallel: false,
    workers: 1,

    /*
     * NO RETRIES. A retry turns "this is flaky" into "this passed", and the
     * whole point of a browser gate is to be told when a screen has become
     * unreliable. Software rendering is slow, not random; the timeouts below
     * are sized for it.
     */
    retries: 0,

    // Generous, because SwiftShader rasterises a 2048x1024 earth texture on the
    // CPU. The assertions are all about correctness — never about how long
    // anything took — so a slow box is a slow run rather than a red one.
    timeout: 90_000,

    expect: {
        timeout: 15_000,
        toHaveScreenshot: {
            // CSS animations and transitions are finished rather than sampled
            // mid-flight: the live dot pulses on a 2.2s loop and every card
            // rises in on mount.
            animations: 'disabled',
            // Font hinting and the anti-aliasing of a 1px hairline differ by a
            // pixel or two between renderer builds. 0.5% of the page is far
            // below "a control moved" and far above "the text rasterised
            // slightly differently".
            maxDiffPixelRatio: 0.005,
        },
    },

    reporter: [
        ['list'],
        ['html', { outputFolder: './artifacts/report', open: 'never' }],
    ],

    use: {
        baseURL: BASE_URL,

        /*
         * A PHONE, because every screen in design/README.md is one — the
         * prototype is a 372x760 frame and the tab bar is fixed to the bottom
         * of it. 390x844 is the common small-modern-phone viewport and is what
         * the layout's breakpoints were written against.
         *
         * `deviceScaleFactor` is left at 1 rather than a phone's 2 or 3: it
         * doubles or triples the number of pixels SwiftShader has to rasterise
         * for the globe, and a baseline at 1x catches a moved control exactly
         * as well as one at 3x.
         */
        viewport: { width: 390, height: 844 },
        hasTouch: true,

        // Retained only when something failed — a passing run should not leave
        // a hundred megabytes of traces behind.
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',

        launchOptions: {
            args: [
                /*
                 * THE HOSTNAME TRICK, and the reason this harness needed no
                 * application code change at all.
                 *
                 * bootstrap/app.php trusts exactly one host —
                 * `^flights\.ghiecode\.io$` — and answers 400 to anything else,
                 * which is the correct production behaviour and would otherwise
                 * make a browser pointed at 127.0.0.1 untestable. Chromium's
                 * own resolver is told the name lives on the loopback, so the
                 * request that arrives at nginx carries
                 * `Host: flights.ghiecode.io:3185` and Symfony's trusted-host
                 * check — which compares getHost(), i.e. without the port —
                 * passes.
                 *
                 * What that buys: the allowlist, the Sanctum stateful-domain
                 * config and every absolute URL the app generates are exercised
                 * as configured, not as a test-only variant of themselves.
                 */
                `--host-resolver-rules=MAP ${HOST} 127.0.0.1`,

                /*
                 * WEBGL WITHOUT A GPU. This box has no display and no graphics
                 * driver; ANGLE's SwiftShader backend rasterises the globe on
                 * the CPU instead. `--enable-unsafe-swiftshader` is required
                 * from Chrome 137 onwards — without it a page asking for a
                 * WebGL context on a software renderer is refused, and Orbit's
                 * own `hasWebgl()` probe (globeScene.js) would correctly decide
                 * the browser cannot draw a globe and render the flat fallback.
                 * The suite would pass, having tested the fallback.
                 *
                 * THE COST: everything is slow and nothing is a benchmark.
                 * globe.spec.js asserts the canvas has an earth on it. It
                 * asserts nothing about frames.
                 */
                '--use-angle=swiftshader',
                '--enable-unsafe-swiftshader',
            ],
        },
    },

    projects: [
        /*
         * Signs in once and writes the session to disk. Everything else depends
         * on it and starts already authenticated — see paths.js for why that is
         * about the login throttle rather than about speed.
         */
        {
            name: 'setup',
            testMatch: /auth\.setup\.js/,
        },
        {
            name: 'chromium',
            testIgnore: /auth\.setup\.js/,
            dependencies: ['setup'],
            use: { storageState: STORAGE_STATE },
        },
    ],
})
