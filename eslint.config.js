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
        // Smarty template-injected config/state variables
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
        // Plugin/theme injected globals
        RVTS: "readonly",
        RVTS_CATS: "readonly",
        PS_params: "readonly",
        global_params: "readonly",
        GeoIp: "readonly",
        nb_plugin: "readonly",
        related_categories_ids: "readonly",
        newTag: "readonly",
        tagBoxes: "readonly",
        nbResult: "readonly",
        // NOTE: sprintf, data, current_param, etc. must be imported, not added here
      },
    },
    rules: {
      ...js.configs.recommended.rules,
      "no-undef": "error",
      "no-unused-vars": ["warn", { argsIgnorePattern: "^_" }],
    },
  },
];
