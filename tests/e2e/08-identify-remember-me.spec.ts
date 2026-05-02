/**
 * Phase 8 Step 2 exit signal: identify + remember-me flow works under SameSite=Strict.
 *
 * Verifies:
 * - Login with remember-me checked sets the pwg_remember cookie
 * - Login without remember-me does not set pwg_remember
 * - Logout clears the pwg_remember cookie (value becomes empty)
 */

import { test, expect } from '@playwright/test';
import { pwgUrl } from './helpers/url';

const REMEMBER_COOKIE = 'pwg_remember';

test('login with remember-me sets the remember cookie', async ({ page, context }) => {
    await page.goto(pwgUrl('/identification.php'));
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'p4ssword!');
    await page.check('input[name="remember_me"]');
    await page.click('input[name="login"]');
    await page.waitForURL((url) => !url.pathname.includes('identification.php'));

    const cookies = await context.cookies();
    const rememberCookie = cookies.find((c) => c.name === REMEMBER_COOKIE);
    expect(
        rememberCookie,
        'pwg_remember cookie must be set after login with remember-me'
    ).toBeDefined();
    expect(rememberCookie!.value, 'pwg_remember must have a non-empty value').not.toBe('');
});

test('login without remember-me does not set the remember cookie', async ({ page, context }) => {
    await page.goto(pwgUrl('/identification.php'));
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'p4ssword!');
    // leave remember_me unchecked
    await page.click('input[name="login"]');
    await page.waitForURL((url) => !url.pathname.includes('identification.php'));

    const cookies = await context.cookies();
    const rememberCookie = cookies.find((c) => c.name === REMEMBER_COOKIE && c.value !== '');
    expect(
        rememberCookie,
        'pwg_remember must not be set when remember-me is unchecked'
    ).toBeUndefined();
});

test('logout clears the remember cookie', async ({ page, context }) => {
    // Log in with remember-me so the cookie is set
    await page.goto(pwgUrl('/identification.php'));
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'p4ssword!');
    await page.check('input[name="remember_me"]');
    await page.click('input[name="login"]');
    await page.waitForURL((url) => !url.pathname.includes('identification.php'));

    const beforeLogout = await context.cookies();
    expect(
        beforeLogout.find((c) => c.name === REMEMBER_COOKIE && c.value !== ''),
        'pwg_remember must be set before logout'
    ).toBeDefined();

    // Logout — Piwigo reads ?act=logout via include/user.inc.php
    await page.goto(pwgUrl('/identification.php?act=logout'));
    await page.waitForURL((url) => !url.search.includes('act=logout'));

    const afterLogout = await context.cookies();
    const rememberCookie = afterLogout.find((c) => c.name === REMEMBER_COOKIE && c.value !== '');
    expect(rememberCookie, 'pwg_remember must be empty after logout').toBeUndefined();
});
