import js from "@eslint/js";
import globals from "globals";
import tseslint from "@typescript-eslint/eslint-plugin";
import tsparser from "@typescript-eslint/parser";

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
      },
    },
    rules: {
      ...js.configs.recommended.rules,
      "no-undef": "error",
      "no-unused-vars": ["warn", { argsIgnorePattern: "^_", varsIgnorePattern: "^_", caughtErrorsIgnorePattern: "^_" }],
    },
  },
  {
    files: ["admin/**/*.ts", "plugins/**/*.ts", "themes/**/*.ts"],
    ignores: ["**/dist/**"],
    languageOptions: {
      parser: tsparser,
      parserOptions: {
        ecmaVersion: 2020,
        sourceType: "module",
        project: "./tsconfig.app.json",
      },
      globals: {
        ...globals.browser,
      },
    },
    plugins: {
      "@typescript-eslint": tseslint,
    },
    rules: {
      ...tseslint.configs['strict-type-checked'].rules,
      "@typescript-eslint/no-unused-vars": ["warn", { argsIgnorePattern: "^_", varsIgnorePattern: "^_", caughtErrorsIgnorePattern: "^_" }],
      "@typescript-eslint/no-non-null-assertion": "off",
      "@typescript-eslint/no-confusing-void-expression": "off",
      "@typescript-eslint/restrict-plus-operands": "off",
      "@typescript-eslint/restrict-template-expressions": ["error", { allowNumber: true, allowBoolean: false, allowNullish: false }],
      "eqeqeq": ["error", "always", { null: "ignore" }],
      "prefer-const": "error",
      "@typescript-eslint/prefer-nullish-coalescing": ["error", { ignorePrimitives: { string: true } }],
      "@typescript-eslint/no-invalid-void-type": ["error", { allowAsThisParameter: true }],
    },
  },
];
