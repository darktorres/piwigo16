import { defineConfig, devices } from "@playwright/test";
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

// Load .env from tests/ directory if present
const envFile = resolve(__dirname, ".env");
if (existsSync(envFile)) {
    for (const line of readFileSync(envFile, "utf8").split("\n")) {
        const m = line.match(/^\s*([^#=\s]+)\s*=\s*(.*)\s*$/);
        if (m) process.env[m[1]] ??= m[2].replace(/^['"]|['"]$/g, "");
    }
}

export default defineConfig({
    testDir: "./src",
    testMatch: "**/*.test.ts",
    globalSetup: "./src/helpers/global-setup.ts",
    globalTeardown: "./src/helpers/global-teardown.ts",
    timeout: 60_000,
    retries: 0,
    workers: 1,
    use: {
        baseURL: "http://localhost/piwigo-fork/",
        headless: true,
        viewport: { width: 1280, height: 1024 },
        ignoreHTTPSErrors: true,
        storageState: ".auth/admin.json",
    },
    projects: [
        {
            name: "chromium",
            use: {
                ...devices["Desktop Chrome"],
                launchOptions: {
                    args: ["--no-sandbox", "--disable-setuid-sandbox", "--disable-dev-shm-usage"],
                },
            },
        },
    ],
    reporter: [["list"], ["html", { open: "never", outputFolder: "playwright-report" }]],
});
