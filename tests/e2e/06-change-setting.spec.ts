import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { pwgUrl } from './helpers/url';

test('gallery title setting round-trips through $conf write path', async ({ page }) => {
    await loginAsAdmin(page);

    await page.goto(pwgUrl('/admin.php?page=configuration&section=main'));
    await expect(page.locator('body')).not.toContainText('Fatal error');

    const titleInput = page.locator('input[name="gallery_title"]');
    await expect(titleInput).toBeVisible();

    const newTitle = 'E2E Gallery Title';
    await titleInput.fill(newTitle);

    await page.click('button[name="submit"]');

    await page.goto(pwgUrl('/admin.php?page=configuration&section=main'));
    await expect(page.locator('input[name="gallery_title"]')).toHaveValue(newTitle);

    await page.locator('input[name="gallery_title"]').fill('Piwigo');
    await page.click('button[name="submit"]');
});
