import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';

test('admin login and dashboard load', async ({ page }) => {
    await loginAsAdmin(page);
    const response = await page.goto('/admin.php');
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Piwigo Administration' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('Fatal error');
});

test('admin albums page loads', async ({ page }) => {
    await loginAsAdmin(page);
    const response = await page.goto('/admin.php?page=albums');
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toContainText('Fatal error');
});
