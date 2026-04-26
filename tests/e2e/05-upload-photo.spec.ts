import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';

test('upload photo page loads via admin.php', async ({ page }) => {
    await loginAsAdmin(page);

    const response = await page.goto('/admin.php?page=photos_add&tab=direct');
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toContainText('Fatal error');
    expect(page.url()).toContain('admin');
});

test('photos_add page loads without errors', async ({ page }) => {
    await loginAsAdmin(page);
    const response = await page.goto('/admin.php?page=photos_add');
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toContainText('Fatal error');
});
