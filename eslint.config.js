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
    files: ["**/postcss.config.js"],
    languageOptions: {
      globals: { ...globals.node },
    },
  },
  {
    files: ["admin/**/*.js", "plugins/**/*.js", "themes/**/*.js"],
    ignores: ["**/dist/**"],
    languageOptions: {
      ecmaVersion: 2020,
      sourceType: "module",
      globals: {
        ...globals.browser,
        // Gallery search filter Smarty-injected globals (search_filters.inc.tpl)
        search_id: "readonly",
        fullname_of_cat: "readonly",
        prefix_icon: "readonly",
        str_word_widget_label: "readonly",
        str_tags_widget_label: "readonly",
        str_album_widget_label: "readonly",
        str_author_widget_label: "readonly",
        str_added_by_widget_label: "readonly",
        str_filetypes_widget_label: "readonly",
        str_empty_search_top_alt: "readonly",
        str_empty_search_bot_alt: "readonly",
        // Plugin/theme injected globals (search_filters.inc.tpl)
        global_params: "readonly",
        // NOTE: sprintf, data, current_param, etc. must be imported, not added here
      },
    },
    rules: {
      ...js.configs.recommended.rules,
      "no-undef": "error",
      "no-unused-vars": ["warn", { argsIgnorePattern: "^_", caughtErrorsIgnorePattern: "^_" }],
    },
  },
];
