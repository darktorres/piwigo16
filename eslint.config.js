import js from "@eslint/js";
import globals from "globals";

export default [
  {
    ignores: [
      "node_modules/**",
      "tests/node_modules/**",
      "vendor/**",
      "tests/**",
      "dist/**",
    ],
  },
  {
    files: ["admin/**/*.js", "plugins/**/*.js", "themes/**/*.js"],
    languageOptions: {
      ecmaVersion: 2020,
      sourceType: "script",
      globals: {
        ...globals.browser,
        Piwigo: "readonly",
        pwg_token: "readonly",
      },
    },
    rules: {
      ...js.configs.recommended.rules,
    },
  },
];
