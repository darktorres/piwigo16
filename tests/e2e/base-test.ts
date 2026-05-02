/**
 * Base Test Configuration
 *
 * @remarks
 * Extended Playwright test base with automatic timeout configuration and
 * JavaScript coverage collection support. Tests are configured based on
 * tags in their titles:
 * - @upload: 120s timeout (file uploads are slow)
 * - @slow: 60s timeout (complex operations)
 * - @quick: 15s timeout (fast tests)
 * - default: 30s timeout (standard tests)
 *
 * FIXTURES:
 * - page: Standard page with debug capture and coverage
 * - adminPage: Pre-authenticated page with fresh session per test (isolated, parallel-safe)
 * - adminContext: Authenticated browser context for creating multiple pages
 *
 * @packageDocumentation
 */

import { test as base, expect, Page, BrowserContext } from '@playwright/test';
import { TEST_CONFIG } from './config/test-timeouts';
import {
    setupHttpLogging,
    setupConsoleLogging,
    captureAndSaveDebugContext,
    clearDebugData,
} from './helpers/debug-helpers';
import { TEST_DATA } from './helpers/test-data';

const baseUrl = process.env.PIWIGO_URL || 'http://localhost/piwigo16';

// Import addCoverageReport from monocart-reporter for proper coverage handling
let addCoverageReport: any;
try {
    addCoverageReport = require('monocart-reporter').addCoverageReport;
} catch (e) {
    // monocart-reporter not available, coverage won't be collected
}

/**
 * Get configured test timeout based on test title tags
 *
 * @remarks
 * Parses test title for tags and returns appropriate timeout configuration.
 * Tags are case-sensitive and checked in title string.
 *
 * @param testTitle - Test title to parse for timeout tags
 * @returns Timeout in milliseconds based on tags found
 *
 * @example
 * ```typescript
 * const timeout = getConfiguredTimeout('should upload file @upload');  // Returns 120000
 * ```
 */
function getConfiguredTimeout(testTitle: string): number {
    if (testTitle.includes('@upload')) {
        return TEST_CONFIG.upload;
    }
    if (testTitle.includes('@slow')) {
        return TEST_CONFIG.slow;
    }
    if (testTitle.includes('@quick')) {
        return TEST_CONFIG.quick;
    }
    return TEST_CONFIG.default;
}

/**
 * Authenticate a browser context via API login
 *
 * @remarks
 * Logs in via the Piwigo API and sets the session cookie on the context.
 * This is faster than UI login and provides test isolation.
 *
 * @param context - Browser context to authenticate
 * @returns Promise resolving when authentication is complete
 */
async function authenticateContext(context: BrowserContext): Promise<void> {
    // Create a temporary request context for API login
    const request = context.request;

    // Login via API
    const loginResp = await request.post(`${baseUrl}/ws.php?format=json`, {
        form: {
            method: 'pwg.session.login',
            username: TEST_DATA.admin.username,
            password: TEST_DATA.admin.password,
        },
    });

    const loginJson = await loginResp.json();
    if (loginJson.stat !== 'ok') {
        throw new Error(`API login failed: ${loginJson.message || 'unknown error'}`);
    }

    // The login response sets the pwg_id cookie automatically on the context
    // Verify by checking session status
    const statusResp = await request.post(`${baseUrl}/ws.php?format=json`, {
        form: { method: 'pwg.session.getStatus' },
    });

    const statusJson = await statusResp.json();
    if (statusJson.stat !== 'ok' || statusJson.result?.username !== TEST_DATA.admin.username) {
        throw new Error(`Session verification failed after login: ${JSON.stringify(statusJson)}`);
    }
}

/**
 * Test fixtures type definition
 */
type TestFixtures = {
    adminPage: Page;
    adminContext: BrowserContext;
};

/**
 * Extended test fixture with automatic timeout, coverage, and debug capture
 *
 * @remarks
 * Provides configured test base that:
 * 1. Automatically sets timeout based on test title tags using {@link getConfiguredTimeout}
 * 2. Collects JavaScript coverage via page.coverage when COLLECT_COVERAGE=true
 * 3. Filters coverage to include only Piwigo's own JS files
 * 4. Reports coverage to monocart-reporter for V8 coverage analysis
 * 5. Automatically captures HTTP requests/responses, console logs, and page errors on test failure
 * 6. Provides `adminPage` fixture for pre-authenticated pages (isolated per test)
 * 7. Provides `adminContext` fixture for creating multiple authenticated pages
 *
 * Debug information is automatically saved to test-debug/ directory when tests fail,
 * including:
 * - All HTTP requests and responses with headers and body content
 * - All console logs (errors, warnings, info)
 * - Page HTML content
 * - JavaScript errors thrown on the page
 *
 * Import and use like: `import { test } from '../base-test'`
 */
export const test = base.extend<TestFixtures>({
    page: async ({ page }, use, testInfo) => {
        // Configure timeout based on test title
        const timeout = getConfiguredTimeout(testInfo.title);
        testInfo.setTimeout(timeout);

        const collectCoverage = process.env.COLLECT_COVERAGE === 'true';

        // Set up automatic debug capture for all pages
        await setupHttpLogging(page);
        await setupConsoleLogging(page);

        // Start JS coverage before test
        if (collectCoverage) {
            await page.coverage.startJSCoverage({
                resetOnNavigation: false,
            });
        }

        // Run the test with the page
        await use(page);

        // Stop JS coverage and report to monocart-reporter
        if (collectCoverage) {
            const coverage = await page.coverage.stopJSCoverage();

            // Filter to only include Piwigo's own JS files
            const filteredCoverage = coverage.filter((entry) => {
                const url = entry.url;

                // Exclude node_modules
                if (url.includes('node_modules')) return false;

                // Exclude 3rd-party files
                const thirdPartyFiles = [
                    'mcs.js', // Custom scrollbar library
                    'pngfix.js', // IE PNG fix
                    'fontawesome', // Font Awesome icons
                ];
                for (const file of thirdPartyFiles) {
                    if (url.includes(file)) return false;
                }

                return true;
            });

            // Add coverage to monocart-reporter
            if (filteredCoverage.length > 0 && addCoverageReport) {
                await addCoverageReport(filteredCoverage, testInfo);
            }
        }

        // Capture and save debug context if test failed
        if (testInfo.status === 'failed' || testInfo.status === 'timedOut') {
            try {
                const debugFile = await captureAndSaveDebugContext(page, testInfo.title);
                if (debugFile) {
                    console.log(`[DEBUG] Debug context saved on ${testInfo.status}: ${debugFile}`);
                }
            } catch (error) {
                console.error(`[DEBUG] Failed to capture debug context: ${error}`);
            }
        }

        // Clean up debug data after test
        clearDebugData(page);
    },

    /**
     * Pre-authenticated browser context fixture
     *
     * @remarks
     * Creates a fresh browser context with admin authentication for each test.
     * Each test gets its own isolated session - no shared state between tests.
     * This enables parallel test execution without session conflicts.
     *
     * @example
     * ```typescript
     * test('admin test', async ({ adminContext }) => {
     *   const page1 = await adminContext.newPage();
     *   const page2 = await adminContext.newPage();
     *   // Both pages share the same authenticated session
     * });
     * ```
     */
    adminContext: async ({ browser }, use) => {
        // Create a fresh context for this test
        const context = await browser.newContext();

        // Authenticate via API
        await authenticateContext(context);

        // Provide the authenticated context to the test
        await use(context);

        // Clean up
        await context.close();
    },

    /**
     * Pre-authenticated page fixture
     *
     * @remarks
     * Provides a page that is already logged in as admin.
     * Each test gets its own isolated session via a fresh browser context.
     * This is the recommended fixture for admin UI tests.
     *
     * Benefits:
     * - Isolated: Each test has its own session (no cross-test contamination)
     * - Fast: Uses API login instead of UI login
     * - Parallel-safe: Tests can run simultaneously without conflicts
     * - No file dependencies: Doesn't rely on storageState files
     *
     * @example
     * ```typescript
     * test('admin dashboard test', async ({ adminPage }) => {
     *   await adminPage.goto('/admin.php');
     *   // Page is already authenticated
     * });
     * ```
     */
    adminPage: async ({ adminContext }, use, testInfo) => {
        // Configure timeout based on test title
        const timeout = getConfiguredTimeout(testInfo.title);
        testInfo.setTimeout(timeout);

        // Create a page from the authenticated context
        const page = await adminContext.newPage();

        // Set up debug capture
        await setupHttpLogging(page);
        await setupConsoleLogging(page);

        const collectCoverage = process.env.COLLECT_COVERAGE === 'true';

        // Start JS coverage before test
        if (collectCoverage) {
            await page.coverage.startJSCoverage({
                resetOnNavigation: false,
            });
        }

        // Provide the authenticated page to the test
        await use(page);

        // Stop JS coverage and report
        if (collectCoverage) {
            const coverage = await page.coverage.stopJSCoverage();
            const filteredCoverage = coverage.filter((entry) => {
                const url = entry.url;
                if (url.includes('node_modules')) return false;
                const thirdPartyDirs = ['/themes/default/js/plugins/', '/themes/default/js/ui/'];
                for (const dir of thirdPartyDirs) {
                    if (url.includes(dir)) return false;
                }
                const thirdPartyFiles = [
                    'jquery.js',
                    'jquery.min.js',
                    'jquery.cookie.js',
                    'mcs.js',
                    'pngfix.js',
                    'fontawesome',
                ];
                for (const file of thirdPartyFiles) {
                    if (url.includes(file)) return false;
                }
                return true;
            });
            if (filteredCoverage.length > 0 && addCoverageReport) {
                await addCoverageReport(filteredCoverage, testInfo);
            }
        }

        // Capture debug context if test failed
        if (testInfo.status === 'failed' || testInfo.status === 'timedOut') {
            try {
                const debugFile = await captureAndSaveDebugContext(page, testInfo.title);
                if (debugFile) {
                    console.log(`[DEBUG] Debug context saved on ${testInfo.status}: ${debugFile}`);
                }
            } catch (error) {
                console.error(`[DEBUG] Failed to capture debug context: ${error}`);
            }
        }

        // Clean up
        clearDebugData(page);
    },
});

// Re-export expect for convenience
export { expect };
