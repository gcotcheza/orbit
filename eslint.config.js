// Orbit — front-end lint (ESLint flat config). No Prettier, deliberately
// (docs/BUSINESS-LOGIC.md §36, "Build tooling").

import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

export default [
    {
        // Build output, dependencies, the PHP side, and two vendored
        // exceptions (docs/BUSINESS-LOGIC.md §36, "Build tooling").
        ignores: [
            'public/**',
            'node_modules/**',
            'vendor/**',
            'storage/**',
            'bootstrap/cache/**',
            'design/**',
            'e2e/artifacts/**',
        ],
    },

    js.configs.recommended,

    // vue3-recommended; the few formatting rules this project disagrees
    // with are turned off below.
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
            // `caughtErrors: 'none'`: a PWA's storage helper ignores a caught
            // error on purpose (docs/BUSINESS-LOGIC.md §36).
            'no-unused-vars': ['error', {
                args: 'after-used',
                caughtErrors: 'none',
                ignoreRestSiblings: true,
            }],

            // warn/error/info are reporting levels, not leftovers.
            'no-console': ['error', { allow: ['warn', 'error', 'info'] }],

            eqeqeq: ['error', 'always', { null: 'ignore' }],
            'no-var': 'error',
            'prefer-const': 'error',
            'object-shorthand': ['error', 'properties'],

            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',

            /**
             * DO NOT turn off — vue/first-attribute-linebreak and
             * vue/html-closing-bracket-newline ask this where to fix (docs/BUSINESS-LOGIC.md §36).
             */
            'vue/html-indent': ['error', 2],

            'vue/html-self-closing': 'off',

            'vue/no-unused-components': 'error',
            'vue/require-explicit-emits': 'error',
            'vue/no-v-html': 'error',
        },
    },

    {
        // Views only — off, not for Components (docs/BUSINESS-LOGIC.md §36).
        files: ['resources/js/Views/**/*.vue'],
        rules: {
            'vue/require-default-prop': 'off',
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
