// @ts-check
import js from "@eslint/js";
import { defineConfig, globalIgnores } from "eslint/config";
import tseslint from "typescript-eslint";
import reactHooks from "eslint-plugin-react-hooks";
import { reactRefresh } from "eslint-plugin-react-refresh";
import eslintConfigPrettier from "eslint-config-prettier";
import globals from "globals";

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
  eslintConfigPrettier,
  globalIgnores([
    "**/dist/**",
    "**/build/**",
    "**/.next/**",
    "**/coverage/**",
    "**/*.tsbuildinfo",
  ]),
]);

export default eslintConfig;
