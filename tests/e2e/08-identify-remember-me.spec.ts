/**
 * Phase 8 Step 2 exit signal: identify + remember-me flow works under SameSite=Strict.
 *
 * Verifies:
 * - Login with remember-me checked sets the pwg_remember cookie
 * - Login without remember-me does not set pwg_remember
 * - Logout clears the pwg_remember cookie (value becomes empty)
 *
 * Each step asserts the form is visible BEFORE typing, and races the
 * post-submit redirect against detection of inline error text — failures
 * surface in seconds, not at the test timeout.
 */

import { test, expect } from '@playwright/test';
import { identificationUrl } from './helpers/url';
import { TEST_DATA } from './helpers/test-data';
import { gotoOk, assertVisible } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';

const REMEMBER_COOKIE = 'pwg_remember';

async function fillLoginForm(
    page: import('@playwright/test').Page,
    rememberMe: boolean
): Promise<void> {
    await gotoOk(page, identificationUrl(), 'identification');
    const usernameField = page.locator('input[name="username"]');
    const passwordField = page.locator('input[name="password"]');
    const loginButton = page.locator('input[name="login"]');
    await assertVisible(usernameField, 'login form: username');
    await assertVisible(passwordField, 'login form: password');
    await assertVisible(loginButton, 'login form: submit');

    await usernameField.fill(TEST_DATA.admin.username);
    await passwordField.fill(TEST_DATA.admin.password);
    if (rememberMe) {
        const rememberCheckbox = page.locator('input[name="remember_me"]');
        await assertVisible(rememberCheckbox, 'login form: remember_me checkbox');
        await rememberCheckbox.check();
    }

    // Race redirect against error text so a credentials mismatch fails fast.
    const invalidText = page.getByText(/Invalid username or password/i);
    await loginButton.click();
    const outcome = await Promise.race([
        page
            .waitForURL((url) => !url.search.includes('/identification'), {
                timeout: 8000,
            })
            .then(() => 'redirected' as const)
            .catch(() => null),
        invalidText
            .waitFor({ state: 'visible', timeout: 8000 })
            .then(() => 'invalid-text' as const)
            .catch(() => null),
    ]);
    if (outcome !== 'redirected') {
        throw new Error(
            `login submission did not redirect within 8s (outcome=${outcome ?? 'timeout'}). ` +
                `Currently at ${page.url()}.`
        );
    }
}

test('login with remember-me sets the remember cookie', async ({ page, context }) => {
    const monitor = attachMonitor(page);
    await fillLoginForm(page, true);
    monitor.assertClean('login with remember-me');

    const cookies = await context.cookies();
    const rememberCookie = cookies.find((c) => c.name === REMEMBER_COOKIE);
    expect(
        rememberCookie,
        'pwg_remember cookie must be set after login with remember-me'
    ).toBeDefined();
    expect(rememberCookie!.value, 'pwg_remember must have a non-empty value').not.toBe('');
});

test('login without remember-me does not set the remember cookie', async ({ page, context }) => {
    const monitor = attachMonitor(page);
    await fillLoginForm(page, false);
    monitor.assertClean('login without remember-me');

    const cookies = await context.cookies();
    const rememberCookie = cookies.find((c) => c.name === REMEMBER_COOKIE && c.value !== '');
    expect(
        rememberCookie,
        'pwg_remember must not be set when remember-me is unchecked'
    ).toBeUndefined();
});

test('logout clears the remember cookie', async ({ page, context }) => {
    const monitor = attachMonitor(page);
    await fillLoginForm(page, true);

    const beforeLogout = await context.cookies();
    expect(
        beforeLogout.find((c) => c.name === REMEMBER_COOKIE && c.value !== ''),
        'pwg_remember must be set before logout'
    ).toBeDefined();

    // Logout — Piwigo reads ?act=logout via include/user.inc.php
    const logoutResp = await page.goto(identificationUrl() + '&act=logout');
    expect(
        logoutResp?.status(),
        'logout navigation should return a successful HTTP status'
    ).toBeLessThan(400);
    await page.waitForURL((url) => !url.search.includes('act=logout'), { timeout: 8000 });

    const afterLogout = await context.cookies();
    const rememberCookie = afterLogout.find((c) => c.name === REMEMBER_COOKIE && c.value !== '');
    expect(rememberCookie, 'pwg_remember must be empty after logout').toBeUndefined();
    monitor.assertClean('logout flow');
});
