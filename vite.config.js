// Orbit — asset build. Runs in a container, never on the host — see the
// `assets` service in docker-compose.yml (docs/BUSINESS-LOGIC.md §36).
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    build: {
        /**
         * DO NOT let the build empty public/build — a page left open across
         * a deploy 404s on its next lazy import (docs/BUSINESS-LOGIC.md §36).
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
                    // Stops rewriting a ROOT-RELATIVE src="" — nginx serves
                    // it as-is, with no build entry to resolve to.
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            // Named explicitly so every import of `vue` resolves to ONE
            // copy — two copies is the classic "injection not found" fault.
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
        // vendor/ can ship its own JS test suites (docs/BUSINESS-LOGIC.md §36).
        include: ['resources/js/**/*.test.js'],
        exclude: ['**/node_modules/**', 'vendor/**'],
    },
});
