import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { pwgUrl } from './helpers/url';

test('admin login and dashboard load', async ({ page }) => {
    await loginAsAdmin(page);
    const response = await page.goto(pwgUrl('/admin.php'));
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Piwigo Administration' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('Fatal error');
});

test('admin albums page loads', async ({ page }) => {
    await loginAsAdmin(page);
    const response = await page.goto(pwgUrl('/admin.php?page=albums'));
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toContainText('Fatal error');
});
