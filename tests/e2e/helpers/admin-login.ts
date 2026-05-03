import type { Page } from '@playwright/test';
import { expect } from '@playwright/test';
import { pwgUrl } from './url';
import { TEST_DATA } from './test-data';
import { assertVisible, assertNoServerErrors, assertHttpOk } from './strict-assertions';

/**
 * Logs in as admin via the UI flow.
 *
 * Fail-fast contract:
 *   1. The identification page itself must render with no server errors.
 *   2. Username/password/login fields must be visible BEFORE we type.
 *   3. After clicking submit, we race the URL change against detection of
 *      a visible error message ("Invalid username or password!", etc.).
 *      Whichever fires first wins — never hangs the full test timeout.
 *   4. Post-login, we assert the redirect landed somewhere admin-safe and
 *      that the session is actually authenticated (logout link present).
 *
 * Throws an Error with actionable diagnostics on any failure step. The
 * default Playwright timeout is NOT involved — login should resolve in
 * under 10 seconds or be considered broken.
 */
export async function loginAsAdmin(page: Page): Promise<void> {
    const loginUrl = pwgUrl('/identification.php');

    const navResp = await page.goto(loginUrl);
    assertHttpOk(navResp, 'login page navigation');
    await assertNoServerErrors(page, 'login page initial render');

    const usernameField = page.locator('input[name="username"]');
    const passwordField = page.locator('input[name="password"]');
    const loginButton = page.locator('input[name="login"]');

    await assertVisible(usernameField, 'login form: username field');
    await assertVisible(passwordField, 'login form: password field');
    await assertVisible(loginButton, 'login form: submit button');

    await usernameField.fill(TEST_DATA.admin.username);
    await passwordField.fill(TEST_DATA.admin.password);

    // Race the navigation against detection of the inline error block.
    // Playwright's identification.tpl renders failures into
    // <div class="errors"> with the localised text; we match either
    // the error block being visible, or a body-level "Invalid username
    // or password!" string.
    const errorBlock = page.locator('div.errors, .login-error, .form-errors');
    const invalidText = page.getByText(/Invalid username or password/i);

    await loginButton.click();

    const outcome = await Promise.race([
        page
            .waitForURL((url) => !url.pathname.includes('identification.php'), {
                timeout: 8000,
            })
            .then(() => 'redirected' as const)
            .catch(() => null),
        errorBlock
            .waitFor({ state: 'visible', timeout: 8000 })
            .then(() => 'error-block' as const)
            .catch(() => null),
        invalidText
            .waitFor({ state: 'visible', timeout: 8000 })
            .then(() => 'invalid-text' as const)
            .catch(() => null),
    ]);

    if (outcome === 'error-block' || outcome === 'invalid-text') {
        const errText = (await errorBlock.first().textContent().catch(() => null))
            ?? (await invalidText.first().textContent().catch(() => null))
            ?? '(no error text captured)';
        throw new Error(
            `loginAsAdmin: server rejected credentials. Form error: "${errText.trim()}". ` +
                `Username='${TEST_DATA.admin.username}'. Check tests/e2e/helpers/test-data.ts.`
        );
    }
    if (outcome !== 'redirected') {
        // Neither the redirect nor the error fired within 8s — usually means
        // the form POST hung or returned a 500 with no body. Surface the
        // current URL and any visible body text to help debugging.
        const here = page.url();
        const bodyExcerpt = (await page.locator('body').textContent().catch(() => null))
            ?.replace(/\s+/g, ' ')
            .trim()
            .slice(0, 300);
        throw new Error(
            `loginAsAdmin: no redirect and no error within 8s. ` +
                `Currently at ${here}. Body excerpt: "${bodyExcerpt ?? '(empty)'}"`
        );
    }

    // Redirect happened — verify the landing page itself is healthy and
    // that we're actually authenticated. Piwigo redirects to the gallery
    // home by default; we check for a logout link in the menu, which only
    // appears for authenticated users.
    await assertNoServerErrors(page, 'post-login landing page');

    const logoutLink = page.locator('a[href*="act=logout"]').first();
    await expect(
        logoutLink,
        'post-login: logout link should be visible (proves session is authenticated)'
    ).toBeVisible({ timeout: 5000 });
}

/**
 * Lightweight pageerror collector. Prefer `attachMonitor()` from
 * page-monitor.ts for new code — it captures console.error and failed
 * HTTP requests as well, not just JS exceptions.
 *
 * Kept for backwards-compatibility with the existing 07-/11-/12-/13-/14-
 * specs that already call it; will be removed once those are migrated.
 */
export function attachErrorCollector(page: Page): () => Error[] {
    const errors: Error[] = [];
    page.on('pageerror', (err) => errors.push(err));
    return () => errors;
}
