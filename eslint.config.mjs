import globals from "globals";
import pluginJs from "@eslint/js";

export default [
    pluginJs.configs.recommended,

    {
        ignores: ["_data/*", "galleries/*", "**/node_modules/*", "vendor/*"],
    },

    {
        languageOptions: {
            globals: {
                ...globals.browser,
                ...globals.jquery,
            },
        },
    },

    {
        plugins: {},
    },

    {
        rules: {},
    },
];
