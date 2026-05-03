/**
 * Install-flow regression test — tagged @install-flow so it is OPT-IN,
 * not part of the default e2e run.
 *
 * The default e2e suite uses a fixture-loaded install (see
 * tests/e2e/global-setup.ts and tests/e2e/helpers/test-data.ts), which
 * skips the install.php form entirely and gives a known-good state in
 * ~1s. Run THIS spec only when you specifically want to validate the
 * install.php flow end-to-end:
 *
 *   npx playwright test --grep @install-flow
 *
 * The test will only do work if the install sentinel is missing —
 * against an already-installed test environment it skips. To force a
 * full run, drop the test DB + remove `local/.installed.test` first
 * (or just run with global-setup enabled first to rebuild fixture state,
 * then delete the sentinel).
 */

import { test, expect } from '@playwright/test';
import { pwgUrl } from './helpers/url';
import { assertVisible, assertNoServerErrors, assertHttpOk } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';

// All values come from .env.test (or shell env). No fallbacks — failing loud
// on missing config beats silently installing against the wrong DB.
const DB_HOST = process.env.PIWIGO_DB_HOST ?? 'localhost';
const DB_USER = process.env.PIWIGO_DB_USER ?? '';
const DB_PASS = process.env.PIWIGO_DB_PASSWORD ?? '';
const DB_NAME = process.env.PIWIGO_DB_BASE ?? '';

// The install spec uses its own admin credentials (not the fixture's
// fixture_admin) — it's exercising the install.php form, which lets the
// user choose any username/password.
const INSTALL_ADMIN_USER = 'admin';
const INSTALL_ADMIN_PASS = 'p4ssword!';

test('fresh install completes end-to-end @install-flow', async ({ page }) => {
    const monitor = attachMonitor(page);

    const installResp = await page.goto(pwgUrl('/install.php'));
    assertHttpOk(installResp, 'install.php navigation');

    // Once installed, install.php redirects away. Skip rather than fail —
    // running this in a clean DB is opt-in (drop SKIP_GLOBAL_SETUP).
    const heading = page.getByRole('heading', { name: /Installation/ });
    if ((await heading.count()) === 0) {
        test.skip(true, 'Piwigo already installed (set SKIP_GLOBAL_SETUP=0 to wipe and re-run)');
        return;
    }
    await assertVisible(heading, 'install: page heading');
    await assertNoServerErrors(page, 'install page initial render');

    // DB connection block
    const dbHostInput = page.locator('input[name="dbhost"]');
    const dbUserInput = page.locator('input[name="dbuser"]');
    const dbPassInput = page.locator('input[name="dbpasswd"]');
    const dbNameInput = page.locator('input[name="dbname"]');
    await assertVisible(dbHostInput, 'install: dbhost field');
    await assertVisible(dbUserInput, 'install: dbuser field');
    await assertVisible(dbPassInput, 'install: dbpasswd field');
    await assertVisible(dbNameInput, 'install: dbname field');
    await dbHostInput.fill(DB_HOST);
    await dbUserInput.fill(DB_USER);
    await dbPassInput.fill(DB_PASS);
    await dbNameInput.fill(DB_NAME);

    // Admin credentials block
    const adminNameInput = page.locator('input[name="admin_name"]');
    const adminPass1Input = page.locator('input[name="admin_pass1"]');
    const adminPass2Input = page.locator('input[name="admin_pass2"]');
    const adminMailInput = page.locator('input[name="admin_mail"]');
    await assertVisible(adminNameInput, 'install: admin_name field');
    await assertVisible(adminPass1Input, 'install: admin_pass1 field');
    await assertVisible(adminPass2Input, 'install: admin_pass2 field');
    await assertVisible(adminMailInput, 'install: admin_mail field');
    await adminNameInput.fill(INSTALL_ADMIN_USER);
    await adminPass1Input.fill(INSTALL_ADMIN_PASS);
    await adminPass2Input.fill(INSTALL_ADMIN_PASS);
    await adminMailInput.fill('admin@example.test');

    const newsletter = page.locator('input[name="newsletter_subscribe"]');
    if (await newsletter.isVisible()) {
        await newsletter.uncheck();
    }

    const installButton = page.locator('input[name="install"]');
    await assertVisible(installButton, 'install: submit button');
    await installButton.click();

    await expect(
        page.getByText('Congratulations'),
        'install: should show "Congratulations" within 30s'
    ).toBeVisible({ timeout: 30_000 });
    await expect(
        page.getByRole('link', { name: 'Visit the gallery' }),
        'install: should show gallery link after success'
    ).toBeVisible();

    await assertNoServerErrors(page, 'install: success page');
    monitor.assertClean('install flow');
});
