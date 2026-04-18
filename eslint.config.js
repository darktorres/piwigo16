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
    ignores: ["**/dist/**"],
    languageOptions: {
      ecmaVersion: 2020,
      sourceType: "module",
      globals: {
        ...globals.browser,
        Piwigo: "readonly",
        pwg_token: "readonly",
        // Smarty template-injected config/state variables only (not utility functions)
        pagination: "readonly",
        has_group: "readonly",
        view_selector: "readonly",
        status_to_str: "readonly",
        registered_str: "readonly",
        last_visit_str: "readonly",
        dates_infos: "readonly",
        str_and_others_tags: "readonly",
        connected_user: "readonly",
        owner: "readonly",
        groupOptions: "readonly",
        guest_id: "readonly",
        // Add more as needed, but NOT utility functions - those must be imported
      },
    },
    rules: {
      ...js.configs.recommended.rules,
      "no-undef": "error",
      "no-unused-vars": ["warn", { argsIgnorePattern: "^_" }],
    },
  },
];
