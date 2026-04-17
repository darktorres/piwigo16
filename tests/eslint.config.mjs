import tseslint from "typescript-eslint";
import playwright from "eslint-plugin-playwright";

export default tseslint.config(
    {
        ignores: ["node_modules/**", "playwright-report/**", "test-results/**"],
    },
    ...tseslint.configs.strictTypeChecked,
    ...tseslint.configs.stylisticTypeChecked,
    {
        ...playwright.configs["flat/recommended"],
        files: ["src/**/*.test.ts"],
        rules: {
            ...playwright.configs["flat/recommended"].rules,
            "playwright/expect-expect": ["warn", { assertFunctionNames: ["assertNoErrors", "smokeCheck"] }],
            "playwright/prefer-to-be": "error",
            "playwright/prefer-strict-equal": "error",
            "playwright/prefer-comparison-matcher": "error",
            "playwright/no-commented-out-tests": "error",
            "playwright/require-top-level-describe": "error",
        },
    },
    {
        languageOptions: {
            parserOptions: {
                projectService: true,
                tsconfigRootDir: import.meta.dirname,
            },
        },
        rules: {
            "@typescript-eslint/no-explicit-any": "error",
            "@typescript-eslint/restrict-template-expressions": ["error", { allowNumber: true }],
            "@typescript-eslint/no-unused-vars": [
                "error",
                { argsIgnorePattern: "^_", varsIgnorePattern: "^_" },
            ],
            "@typescript-eslint/consistent-type-imports": ["error", { prefer: "type-imports", fixStyle: "inline-type-imports" }],
            "@typescript-eslint/no-non-null-assertion": "off",
            "@typescript-eslint/no-require-imports": "off",
            "no-console": "warn",
        },
    },
);
