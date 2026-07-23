import js from "@eslint/js";
import tseslint from "typescript-eslint";
import globals from "globals";

// Prettier is deliberately NOT wired in here (no eslint-plugin-prettier) — same
// separation of concerns P0 kept between ECS (style) and PHPStan (correctness).
// `bun run format` runs Prettier standalone; this config is lint rules only.
export default tseslint.config(
  {
    ignores: [
      "dist/**",
      "node_modules/**",
      "_data/**",
      "vendor/**",
      // Bundled third-party JS (verified per-file — docs/PLAN-REPLAY.md P1).
      "themes/default/js/ui/**",
      "themes/default/js/plugins/**",
      "themes/default/js/jquery.js",
      "themes/default/js/jquery.min.js",
      "themes/default/js/jquery.cookie.js",
      "themes/default/js/pngfix.js",
      "themes/admin/default/js/jquery.geoip.js",
      // Dev-only WS API debug console, not shipped application code.
      "tools/ws/**",
    ],
  },
  js.configs.recommended,
  {
    files: ["**/*.{js,mjs,cjs}"],
    languageOptions: {
      ecmaVersion: "latest",
      sourceType: "script",
      globals: {
        ...globals.browser,
        ...globals.jquery,
        ...globals.node,
      },
    },
    rules: {
      "no-console": ["warn", { allow: ["warn", "error"] }],
      "no-unused-vars": [
        "warn",
        { argsIgnorePattern: "^_", varsIgnorePattern: "^_" },
      ],
      eqeqeq: "error",
      "no-implicit-coercion": "error",
      "no-param-reassign": ["warn", { props: false }],
    },
  },
  {
    files: ["**/*.ts"],
    extends: [...tseslint.configs.recommendedTypeChecked],
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
  },
);
