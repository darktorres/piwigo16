import tsParser from '@typescript-eslint/parser';
import tsPlugin from '@typescript-eslint/eslint-plugin';
import prettierPlugin from 'eslint-plugin-prettier';
import prettierConfig from 'eslint-config-prettier';

export default [
    {
        ignores: [
            'dist/**',
            'node_modules/**',
            '_data/**',
            'vendor/**',
            'language/**',
            'install/db/**',
            'include/smarty/**',
            'include/minify/**',
            'include/phpmailer/**',
            'plugins/**',
            'themes/bootstrap_darkroom/**',
            'themes/default/js/plugins/selectize.*',
            'themes/elegant/**',
            'themes/smartpocket/**',
            'themes/modus/**',
        ],
    },
    {
        files: ['**/*.ts'],
        languageOptions: {
            parser: tsParser,
            parserOptions: {
                projectService: true,
                tsconfigRootDir: import.meta.dirname,
            },
        },
        plugins: {
            '@typescript-eslint': tsPlugin,
            prettier: prettierPlugin,
        },
        rules: {
            ...tsPlugin.configs.recommended.rules,
            ...prettierConfig.rules,
            'prettier/prettier': 'error',
            '@typescript-eslint/no-explicit-any': 'warn',
            '@typescript-eslint/explicit-function-return-type': 'off',
            '@typescript-eslint/no-unused-vars': [
                'warn',
                { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
            ],
            'no-console': ['warn', { allow: ['warn', 'error'] }],
        },
    },
];
