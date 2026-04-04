import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import simpleImportSort from 'eslint-plugin-simple-import-sort';
import svelte from 'eslint-plugin-svelte';
import globals from 'globals';

import svelteConfig from './frontend/svelte.config.js';

export default [
  {
    ignores: [
      '.references/**',
      'backend/**',
      'content/**',
      'docs/**',
      'frontend/.svelte-kit/**',
      'frontend/build/**',
      'frontend/node_modules/**',
      'node_modules/**',
      'runtime/**',
      'site/**',
      'storage/**',
      'tests/**',
      'vendor/**',
    ],
  },
  js.configs.recommended,
  ...svelte.configs.recommended,
  {
    files: ['eslint.config.*', 'frontend/**/*.js', 'frontend/**/*.svelte'],
    languageOptions: {
      globals: {
        ...globals.browser,
        ...globals.node,
      },
      parserOptions: {
        svelteConfig,
      },
    },
    plugins: {
      'simple-import-sort': simpleImportSort,
    },
    rules: {
      'simple-import-sort/imports': 'warn',
      'simple-import-sort/exports': 'warn',
    },
  },
  prettier,
];
