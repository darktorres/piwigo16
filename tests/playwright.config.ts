import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';

dotenv.config();

const collectCoverage = process.env.COLLECT_COVERAGE === 'true';

// Reporter configuration
const reporters: any[] = [['list']];

if (collectCoverage) {
    // Use monocart-reporter for coverage and test reporting
    // Coverage data is collected via the automatic coverage fixture and passed to monocart
    reporters.push([
        'monocart-reporter',
        {
            name: 'Piwigo E2E Test Report',
            outputFile: './test-results/monocart-report.html',
            coverage: {
                // Filter to include only Piwigo's JS files, exclude 3rd-party libraries
                entryFilter: (entry: any) => {
                    if (!entry || !entry.url) return false;

                    const url = entry.url;

                    // Exclude node_modules
                    if (url.includes('node_modules')) return false;

                    // Exclude 3rd-party files
                    const thirdPartyFiles = [
                        'mcs.js', // Custom scrollbar library
                        'pngfix.js', // IE PNG fix
                    ];
                    for (const file of thirdPartyFiles) {
                        if (url.includes(file)) return false;
                    }

                    return true;
                },
            },
        },
    ]);
} else {
    // Standard reporters for non-coverage runs
    reporters.push(['html', { outputFolder: 'playwright-report' }]);
    reporters.push(['json', { outputFile: 'test-results/results.json' }]);
}

export default defineConfig({
    testDir: './E2E/specs',
    // Enable parallel execution for tests using adminPage fixture (each gets isolated session)
    // Tests with database modifications should use test.describe.serial() to run sequentially
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    // Use multiple workers for parallel execution (adjust based on machine capacity)
    // Note: Install project still runs first as a dependency
    workers: process.env.CI ? 2 : 4,
    reporter: reporters,

    // Visual regression snapshot settings
    snapshotDir: './E2E/snapshots',
    snapshotPathTemplate:
        '{snapshotDir}/{testFileDir}/{testFileName}-snapshots/{arg}{-projectName}{ext}',

    expect: {
        // Visual comparison settings
        toHaveScreenshot: {
            // Allow minor pixel differences (anti-aliasing, font rendering)
            maxDiffPixelRatio: 0.01,
            // Threshold for color difference (0-1)
            threshold: 0.2,
            // Animation settling time
            animations: 'disabled',
        },
        toMatchSnapshot: {
            maxDiffPixelRatio: 0.01,
            threshold: 0.2,
        },
    },

    use: {
        baseURL: process.env.PIWIGO_URL || 'http://localhost/piwigo16',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        actionTimeout: 10000,
        navigationTimeout: 30000,

        // Enable coverage header for PHP
        extraHTTPHeaders: collectCoverage
            ? {
                  'X-Coverage': 'true',
              }
            : {},
    },

    globalSetup: './E2E/global-setup.ts',
    globalTeardown: './E2E/global-teardown.ts',

    projects: [
        // Installation project - runs first, creates fresh database and auth state
        {
            name: 'install',
            testMatch: /00-install\.spec\.ts/,
            use: {
                ...devices['Desktop Chrome'],
                // No storageState - installation test creates it
            },
        },

        // Desktop Chrome (primary) - depends on installation
        {
            name: 'chromium',
            testIgnore: /00-install\.spec\.ts/, // Don't run install test again
            use: {
                ...devices['Desktop Chrome'],
                // No storageState - tests will use ensureAdminLoggedIn() to login manually when needed
                // This avoids session persistence issues across test contexts
            },
            dependencies: ['install'],
        },

        // Firefox (optional - uncomment when needed)
        // {
        //   name: 'firefox',
        //   testIgnore: /00-install\.spec\.ts/,
        //   use: { ...devices['Desktop Firefox'] },
        //   dependencies: ['install'],
        // },

        // WebKit (optional - uncomment when needed)
        // {
        //   name: 'webkit',
        //   testIgnore: /00-install\.spec\.ts/,
        //   use: { ...devices['Desktop Safari'] },
        //   dependencies: ['install'],
        // },
    ],
});
