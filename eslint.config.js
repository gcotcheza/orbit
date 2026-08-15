// =============================================================================
// Orbit — front-end lint (ESLint flat config)
// =============================================================================
//   npm run lint          # report
//   npm run lint:fix      # report and fix what is mechanically fixable
//   scripts/check.sh      # runs the lint and fails the gate on an error
//
// WHY THIS EXISTS BEFORE THERE IS ANY VUE CODE
// Most of Orbit runs in a browser, and the faults that half produces do not
// show up in `php artisan test` or in the nginx log — they show up as a blank
// white screen on a phone, which is the single worst failure mode this app has
// because it looks like nothing happened. The globe alone is three.js plus a
// camera choreography with a dozen timers; a variable renamed in one branch of
// a tour function is exactly the class of fault that ships silently.
//
// So the config lands with the gate rather than with the first component. It
// already matches `resources/js/**/*.{js,vue}`, so PR4's first file is linted
// the moment it is written, and nobody has to remember to switch this on.
//
// WHY NO PRETTIER
// Same reasoning the PHP side uses for Pint: one enforcer, not two negotiating.
// A formatter and a linter that both have opinions about where a line breaks
// will eventually disagree, and the resolution is always a config file nobody
// enjoys. ESLint's `--fix` plus eslint-plugin-vue's template rules cover the
// mechanical part, and `.editorconfig` covers indent width for every editor in
// the loop. Adding Prettier later is a deliberate decision, not a default.
// =============================================================================

import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

export default [
    {
        // Build output, dependencies, and the PHP side. `public/build` in
        // particular will hold minified bundles AND their source maps, and
        // linting a 300 KB single-line chunk is a good way to make the run look
        // broken.
        ignores: [
            'public/**',
            'node_modules/**',
            'vendor/**',
            'storage/**',
            'bootstrap/cache/**',

            // The design handoff. `design/support.js` is the prototype's own
            // runtime — somebody else's code, committed verbatim as a
            // REFERENCE and never loaded by this app. Linting it produced 37
            // errors about `var` and empty catch blocks in a file that is not
            // ours to reformat, and a gate whose first run is 37 findings
            // nobody intends to fix is a gate that gets skipped.
            'design/**',

            // What a `scripts/e2e.sh` run leaves behind: screenshots, traces
            // and Playwright's HTML report — which SHIPS ITS OWN TRACE VIEWER,
            // a few hundred kilobytes of somebody else's minified bundle.
            // Linting it is 8,414 findings about single-letter variables in
            // code we did not write and cannot fix, i.e. a gate that is red
            // whenever the browser gate has been run and green otherwise. The
            // directory is gitignored; e2e/specs, e2e/fixtures.js and
            // e2e/playwright.config.js are ours and are linted.
            'e2e/artifacts/**',
        ],
    },

    js.configs.recommended,

    // `flat/recommended` is eslint-plugin-vue's vue3-recommended: the base rules
    // plus the ones that catch real template faults — missing `:key`, duplicate
    // attributes, a component used before it is defined, mutating a prop. It
    // also carries the plugin's template FORMATTING rules; the few this project
    // disagrees with are turned off below, with the reason.
    ...pluginVue.configs['flat/recommended'],

    {
        files: ['**/*.js', '**/*.vue'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            // --- Correctness, tightened ------------------------------------
            // The default `no-unused-vars` also flags an unused caught error,
            // which a PWA uses on purpose: `catch (e) { /* ignore */ }` is how
            // a storage helper survives a browser that refuses IndexedDB.
            // Arguments are only flagged after the last used one, so a callback
            // that ignores its first parameter still passes.
            'no-unused-vars': ['error', {
                args: 'after-used',
                caughtErrors: 'none',
                ignoreRestSiblings: true,
            }],

            // A bare `console.log` left in a commit ships to a phone and stays
            // there. `warn` and `error` are how the app reports a fault it
            // decided not to throw on — a globe texture that failed to load
            // still has to leave a trace — and `info` is for the handful of
            // deliberate one-line diagnostics you read off a phone attached to
            // a laptop. All three are reporting levels, not leftovers.
            'no-console': ['error', { allow: ['warn', 'error', 'info'] }],

            eqeqeq: ['error', 'always', { null: 'ignore' }],
            'no-var': 'error',
            'prefer-const': 'error',
            'object-shorthand': ['error', 'properties'],

            // --- Vue formatting rules this project disagrees with -----------
            // Short attributes go on one line and only break when the line gets
            // long, which is what makes a component readable on a laptop
            // split-screened with the app.
            'vue/max-attributes-per-line': 'off',

            // Requires a newline between an element's tags and its content, so
            // `<span>{{ price }}</span>` becomes three lines. In a UI made
            // largely of small labelled numbers — every screen in
            // design/README.md is exactly that — it triples the vertical size
            // of every card for no gain in clarity.
            'vue/singleline-html-element-content-newline': 'off',

            // Templates are indented two spaces, not the four .editorconfig
            // sets for everything else — the Vue SFC convention, since a
            // template nests far deeper than the script beside it.
            //
            // THIS ONE HAS TO BE ON FOR THE AUTOFIXES AROUND IT TO BE SAFE.
            // `vue/first-attribute-linebreak` and
            // `vue/html-closing-bracket-newline` both fix by INSERTING a line
            // break, and they ask this rule where the new line starts. With it
            // off they still fire and still fix, and the fix lands at column
            // zero — a `class=` and a bare `>` hard against the left margin.
            'vue/html-indent': ['error', 2],

            // Wants a self-closing `<div/>` for void content. Vue's compiler
            // accepts both and the DOM parser does not, so this is a taste
            // question that only matters when it churns a diff.
            'vue/html-self-closing': 'off',

            // --- Vue correctness, kept loud --------------------------------
            'vue/no-unused-components': 'error',
            'vue/require-explicit-emits': 'error',
            'vue/no-v-html': 'error',
        },
    },

    {
        // Route-level views (`resources/js/Views/`) are mounted by vue-router
        // and by nothing else, so their props come from the route's params and
        // the store rather than from a parent with its own defaults. A
        // client-side default there is a second source of truth that can only
        // ever disagree with the first.
        //
        // The rule stays ON for `resources/js/Components`, where a component IS
        // mounted from several call sites and an omitted optional prop is a
        // genuine footgun.
        files: ['resources/js/Views/**/*.vue'],
        rules: {
            'vue/require-default-prop': 'off',

            // A view's file name IS its route (`Watchlist.vue` ↔ `/watchlist`),
            // and the six screens in design/README.md are named the way the tab
            // bar names them. Renaming them to `WatchlistView` to satisfy a
            // rule about custom-element collisions — which cannot happen for a
            // component that is never written as a tag — would break that
            // mapping for nothing. Still ON everywhere else.
            'vue/multi-word-component-names': 'off',
        },
    },

    {
        // Build tooling: runs in node, not in the browser.
        files: ['vite.config.js', 'eslint.config.js'],
        languageOptions: {
            globals: { ...globals.node },
        },
    },
]
