// =============================================================================
// Orbit — asset build
// =============================================================================
//   docker compose --profile build run --rm assets     # npm ci && npm run build
//
// Two entry points and no more: one stylesheet holding the design tokens, one
// script that mounts the SPA. Everything else the app draws is a Vue SFC pulled
// in by that script, so Vite's graph is the app's own import graph.
//
// THE BUILD RUNS IN A CONTAINER, never on the host — see the `assets` service
// in docker-compose.yml. It writes `public/build/` through the bind mount as
// uid 115, which is the same uid php-fpm and the nginx sidecar read it as.
// =============================================================================
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    build: {
        /*
         * DO NOT let the build empty public/build.
         *
         * Vite's default is to wipe the output directory, which means every
         * deploy DELETES the previous build's chunks. Orbit is a client-
         * rendered SPA behind a service worker, so that is not a stale-asset
         * problem, it is a dead-page problem:
         *
         *   A PAGE LEFT OPEN ACROSS A DEPLOY. Its entry chunk is already
         *   running and looks fine, right up to the first lazy import — and
         *   every screen is one, including the 1.9 MB globe. The chunk 404s,
         *   the dynamic import rejects, and the tab bar stops working with
         *   nothing in any server log to say so.
         *
         *   A DOCUMENT SERVED FROM ANY CACHE. Stale HTML naming a deleted entry
         *   chunk is a script tag pointing at a 404, which for this app is a
         *   completely blank screen behind a 200.
         *
         * Keeping the old files costs a few hundred kilobytes per build and
         * makes a briefly-stale reference resolve instead of dying. The
         * directory is not allowed to grow without limit: `php artisan
         * build:retain` keeps the newest three builds from a ledger it writes
         * and deletes the rest. It runs in the deploy, straight after this, and
         * again on the daily schedule (routes/console.php) so that a forgotten
         * deploy step is a day of extra chunks rather than a full disk.
         */
        emptyOutDir: false,
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    // Vue's compiler rewrites `src=""` on a handful of tags into
                    // an import, so the bundler fingerprints the file. Both flags
                    // stop it doing that to a ROOT-RELATIVE path: anything under
                    // `public/` is served by nginx as it stands and has no build
                    // entry to resolve to, and the rewrite would turn a working
                    // URL into a build-time "failed to resolve import".
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            // The ESM bundler build, named explicitly rather than left to the
            // package's `exports` map, so every import of `vue` — ours, pinia's,
            // vue-router's — resolves to ONE copy. Two copies of Vue in a bundle
            // is the classic "injection not found" fault, and it stays invisible
            // until a store is read from a component the other copy created.
            //
            // This is the with-compiler build (~14 KB more than
            // `vue.runtime.esm-bundler.js`) and matches ghie-writes. Every screen
            // here is an SFC compiled at build time, so nothing NEEDS it today —
            // it is the one that keeps a runtime `template:` string working.
            vue: 'vue/dist/vue.esm-bundler.js',
            '@': '/resources/js',
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    test: {
        // ONLY OUR OWN TESTS.
        //
        // Vitest's default `include` sweeps the whole project for anything
        // matching `*.test.*`, and `vendor/` is part of the project — a PHP
        // package that happens to ship a JavaScript library with its own test
        // suite (anthropic-ai/sdk pulls in standard-webhooks, which does)
        // therefore lands in `npm run test:js` and fails it on a dependency
        // *we* do not have installed. Composer's tree is not ours to run.
        //
        // `node_modules` is in vitest's defaults; naming it here replaces them,
        // so it has to be repeated.
        include: ['resources/js/**/*.test.js'],
        exclude: ['**/node_modules/**', 'vendor/**'],
    },
});
