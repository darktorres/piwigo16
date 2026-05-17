import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { adminUrl } from './helpers/url';
import { gotoOk, assertVisible } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';

test('gallery title setting round-trips through $conf write path', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsAdmin(page);

    await gotoOk(page, adminUrl('configuration') + '&section=main', 'configuration page');

    const titleInput = page.locator('input[name="gallery_title"]');
    await assertVisible(titleInput, 'configuration: gallery_title input');

    const originalTitle = (await titleInput.inputValue()) || 'Piwigo';
    const newTitle = `E2E Gallery Title ${Date.now()}`;

    await titleInput.fill(newTitle);

    const submitButton = page.locator('button[name="submit"]');
    await assertVisible(submitButton, 'configuration: submit button');
    await submitButton.click();

    // Reload and verify the new value persisted.
    await gotoOk(
        page,
        adminUrl('configuration') + '&section=main',
        'configuration page (after save)'
    );
    await expect(
        page.locator('input[name="gallery_title"]'),
        `gallery_title should round-trip to '${newTitle}'`
    ).toHaveValue(newTitle, { timeout: 5000 });

    // Restore original
    await page.locator('input[name="gallery_title"]').fill(originalTitle);
    await page.locator('button[name="submit"]').click();
    monitor.assertClean('configuration round-trip');
});
