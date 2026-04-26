import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';

test('admin login and dashboard load', async ({ page }) => {
    await loginAsAdmin(page);
    const response = await page.goto('/admin/index.php');
    expect(response?.status()).toBe(200);
    await expect(page.getByText('Piwigo Administration')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('Fatal error');
});

test('admin albums page loads', async ({ page }) => {
    await loginAsAdmin(page);
    const response = await page.goto('/admin/albums.php');
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toContainText('Fatal error');
});
