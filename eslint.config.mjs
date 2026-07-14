// @ts-check
import js from '@eslint/js';
import { defineConfig, globalIgnores } from 'eslint/config';
import tseslint from 'typescript-eslint';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import { reactRefresh } from 'eslint-plugin-react-refresh';
import eslintConfigPrettier from 'eslint-config-prettier';
import globals from 'globals';

// Enforces the mechanically checkable rules from docs/ui-conventions.md in
// app code: user-facing strings go through the i18n layer and colors come
// from packages/ui tokens. Consumers attach their own `files` patterns;
// apps/storefront and apps/admin import this into their own configs.
export const uiConventions = {
  plugins: {
    react,
  },
  rules: {
    'react/jsx-no-literals': [
      'error',
      {
        noStrings: true,
        ignoreProps: true,
        noAttributeStrings: false,
      },
    ],
    'no-restricted-syntax': [
      'error',
      {
        selector: 'Literal[value=/#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\\b/]',
        message:
          'Hardcoded hex color; use the design tokens from packages/ui (docs/ui-conventions.md).',
      },
      {
        selector:
          'TemplateElement[value.raw=/#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\\b/]',
        message:
          'Hardcoded hex color; use the design tokens from packages/ui (docs/ui-conventions.md).',
      },
      {
        selector: 'Literal[value=/\\b(?:rgba?|hsla?|oklch|oklab)\\(/]',
        message:
          'Hardcoded color value; use the design tokens from packages/ui (docs/ui-conventions.md).',
      },
      {
        selector: 'TemplateElement[value.raw=/\\b(?:rgba?|hsla?|oklch|oklab)\\(/]',
        message:
          'Hardcoded color value; use the design tokens from packages/ui (docs/ui-conventions.md).',
      },
    ],
  },
};

// Shared base for workspaces without their own eslint.config.mjs (ESLint's
// flat config lookup walks up from a workspace's cwd and stops at the first
// config file it finds). apps/storefront and apps/admin keep their own
// Next.js-generated eslint.config.mjs and never reach this file.
const eslintConfig = defineConfig([
  js.configs.recommended,
  ...tseslint.configs.recommended,
  reactHooks.configs.flat.recommended,
  reactRefresh.configs.vite(),
  {
    languageOptions: {
      globals: {
        ...globals.browser,
        ...globals.node,
      },
    },
  },
  {
    files: ['apps/checkin/src/**/*.{ts,tsx}'],
    ignores: ['**/*.test.{ts,tsx}'],
    ...uiConventions,
  },
  {
    // k6 load scenarios (infra/load) execute inside k6's own JavaScript runtime,
    // not Node: it injects these globals and resolves the `k6/*` imports itself.
    files: ['infra/load/**/*.js'],
    languageOptions: {
      globals: {
        __ENV: 'readonly',
        __VU: 'readonly',
        __ITER: 'readonly',
      },
    },
  },
  eslintConfigPrettier,
  globalIgnores([
    '**/dist/**',
    '**/build/**',
    '**/.next/**',
    '**/coverage/**',
    '**/*.tsbuildinfo',
    'infra/load/results/**',
  ]),
]);

export default eslintConfig;
