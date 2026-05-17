/**
 * Base Test Configuration
 *
 * @remarks
 * Extended Playwright test base with automatic timeout configuration. Tests
 * are configured based on tags in their titles:
 * - @upload: 120s timeout (file uploads are slow)
 * - @slow: 60s timeout (complex operations)
 * - @quick: 15s timeout (fast tests)
 * - default: 30s timeout (standard tests)
 *
 * FIXTURES:
 * - page: Standard page with debug capture
 * - adminPage: Pre-authenticated page with fresh session per test (isolated, parallel-safe)
 * - adminContext: Authenticated browser context for creating multiple pages
 *
 * @packageDocumentation
 */

import type { Page, BrowserContext } from '@playwright/test';
import { test as base, expect } from '@playwright/test';
import { TEST_CONFIG } from './config/test-timeouts';
import {
    setupHttpLogging,
    setupConsoleLogging,
    captureAndSaveDebugContext,
    clearDebugData,
} from './helpers/debug-helpers';
import { TEST_DATA } from './helpers/test-data';
import { wsUrl } from './helpers/url';

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
    const loginResp = await request.post(wsUrl({ format: 'json' }), {
        form: {
            method: 'pwg.session.login',
            username: TEST_DATA.admin.username,
            password: TEST_DATA.admin.password,
        },
    });

    const loginJson = (await loginResp.json()) as { stat: string; message?: string };
    if (loginJson.stat !== 'ok') {
        throw new Error(`API login failed: ${loginJson.message ?? 'unknown error'}`);
    }

    // The login response sets the pwg_id cookie automatically on the context
    // Verify by checking session status
    const statusResp = await request.post(wsUrl({ format: 'json' }), {
        form: { method: 'pwg.session.getStatus' },
    });

    const statusJson = (await statusResp.json()) as {
        stat: string;
        result?: { username?: string };
    };
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
 * Extended test fixture with automatic timeout and debug capture
 *
 * @remarks
 * Provides configured test base that:
 * 1. Automatically sets timeout based on test title tags using {@link getConfiguredTimeout}
 * 2. Automatically captures HTTP requests/responses, console logs, and page errors on test failure
 * 3. Provides `adminPage` fixture for pre-authenticated pages (isolated per test)
 * 4. Provides `adminContext` fixture for creating multiple authenticated pages
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

        // Set up automatic debug capture for all pages
        await setupHttpLogging(page);
        await setupConsoleLogging(page);

        // Run the test with the page
        await use(page);

        // Capture and save debug context if test failed
        if (testInfo.status === 'failed' || testInfo.status === 'timedOut') {
            try {
                const debugFile = await captureAndSaveDebugContext(page, testInfo.title);
                if (debugFile !== undefined && debugFile !== '') {
                    console.warn(`[DEBUG] Debug context saved on ${testInfo.status}: ${debugFile}`);
                }
            } catch (error) {
                console.error(`[DEBUG] Failed to capture debug context: ${String(error)}`);
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
     *   await adminPage.goto(adminUrl());
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

        // Provide the authenticated page to the test
        await use(page);

        // Capture debug context if test failed
        if (testInfo.status === 'failed' || testInfo.status === 'timedOut') {
            try {
                const debugFile = await captureAndSaveDebugContext(page, testInfo.title);
                if (debugFile !== undefined && debugFile !== '') {
                    console.warn(`[DEBUG] Debug context saved on ${testInfo.status}: ${debugFile}`);
                }
            } catch (error) {
                const msg = error instanceof Error ? error.message : String(error);
                console.error(`[DEBUG] Failed to capture debug context: ${msg}`);
            }
        }

        // Clean up
        clearDebugData(page);
    },
});

// Re-export expect for convenience
export { expect };
