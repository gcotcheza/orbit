// Driven by scripts/e2e.sh. `webServer` is deliberately absent: the thing
// under test is a compose stack, a shell script's job (docs/E2E.md).
import { defineConfig } from '@playwright/test'
import { BASE_URL, HOST, STORAGE_STATE } from './paths.js'

export default defineConfig({
    testDir: './specs',

    // Under artifacts/ with everything else the run produces, so .gitignore
    // has one line rather than four.
    outputDir: './artifacts/test-results',

    // Platform-stamped: a baseline is a promise about a specific renderer
    // (docs/E2E.md "Baselines vs artifacts").
    snapshotPathTemplate: '{testDir}/../baselines/{arg}-{platform}{ext}',

    // One worker, no parallelism — a correctness concession, not a
    // performance one (docs/E2E.md "The specs").
    fullyParallel: false,
    workers: 1,

    // No retries — the whole point of a gate is to be told when a screen
    // has become unreliable (docs/E2E.md "The specs").
    retries: 0,

    // Generous: SwiftShader rasterises a 2048x1024 earth texture on the CPU
    // (docs/E2E.md "SwiftShader, and what may therefore be asserted").
    timeout: 90_000,

    expect: {
        timeout: 15_000,
        toHaveScreenshot: {
            animations: 'disabled',
            // 0.5%: below "a control moved," above renderer-build
            // font/anti-aliasing noise.
            maxDiffPixelRatio: 0.005,
        },
    },

    reporter: [
        ['list'],
        ['html', { outputFolder: './artifacts/report', open: 'never' }],
    ],

    use: {
        baseURL: BASE_URL,

        // A phone: 390x844 matches the layout's breakpoints. deviceScaleFactor
        // stays at 1 — 2x/3x only costs SwiftShader more pixels.
        viewport: { width: 390, height: 844 },
        hasTouch: true,

        // Retained only when something failed — a passing run should not leave
        // a hundred megabytes of traces behind.
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',

        launchOptions: {
            args: [
                // The hostname trick — no application code change needed
                // (docs/E2E.md "The hostname trick").
                `--host-resolver-rules=MAP ${HOST} 127.0.0.1`,

                // WebGL without a GPU: required from Chrome 137 onwards
                // (docs/E2E.md "SwiftShader, and what may therefore be asserted").
                '--use-angle=swiftshader',
                '--enable-unsafe-swiftshader',

                // OOM prevention: shared memory goes to /tmp so a renderer
                // that outgrows /dev/shm spills to disk, not OOM-killed.
                '--disable-dev-shm-usage',
            ],
        },
    },

    projects: [
        // Signs in once and writes the session to disk — see paths.js for
        // why (the login throttle, not speed).
        {
            name: 'setup',
            testMatch: /auth\.setup\.js/,
        },
        {
            name: 'chromium',
            testIgnore: [/auth\.setup\.js/, /layout-(smoke|screens)\.spec\.js/],
            dependencies: ['setup'],
            use: { storageState: STORAGE_STATE },
        },

        // Two small projects, two specs: the wide layouts are asserted, not
        // photographed, until phase 4 (docs/DESKTOP-LAYOUT-PLAN.md).
        {
            name: 'tablet',
            testMatch: /layout-(smoke|screens)\.spec\.js/,
            dependencies: ['setup'],
            use: {
                storageState: STORAGE_STATE,
                // An iPad in portrait, at its own pixel ratio.
                viewport: { width: 820, height: 1180 },
                deviceScaleFactor: 2,
            },
        },
        {
            name: 'desktop',
            testMatch: /layout-(smoke|screens)\.spec\.js/,
            dependencies: ['setup'],
            use: {
                storageState: STORAGE_STATE,
                viewport: { width: 1280, height: 832 },
                deviceScaleFactor: 1,
                hasTouch: false,
            },
        },
    ],
})
