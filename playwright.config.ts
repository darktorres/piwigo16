import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: 'list',
    // @install-flow specs are opt-in — they exercise install.php against
    // a fresh DB, separately from the default fixture-loaded e2e flow.
    // Run them explicitly with `npx playwright test --grep @install-flow`.
    grepInvert: /@install-flow/,
    use: {
        // Default targets local Apache at /piwigo16/. CI/Docker overrides via BASE_URL.
        baseURL: process.env.BASE_URL || 'http://localhost/piwigo16',
        trace: 'on-first-retry',
        // Mark every request as a test-mode request so the runtime reads
        // .env.test (test DB) instead of .env (prod DB). The runtime only
        // honours this header on loopback origins — see TestMode.
        extraHTTPHeaders: {
            'X-Piwigo-Env': 'test',
        },
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
