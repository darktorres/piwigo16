import { test, expect } from '@playwright/test';
import { pwgUrl } from './helpers/url';

test('gallery home page loads without errors', async ({ page }) => {
    const response = await page.goto(pwgUrl('/index.php'));
    expect(response?.status()).toBe(200);
    await expect(page).toHaveTitle(/.+/);
    await expect(page.locator('body')).not.toContainText('Fatal error');
    await expect(page.locator('body')).not.toContainText('Warning:');
    await expect(page.locator('body')).not.toContainText('Parse error');
});

test('identification page renders login form', async ({ page }) => {
    const response = await page.goto(pwgUrl('/identification.php'));
    expect(response?.status()).toBe(200);
    await expect(page.locator('input[name="username"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
    await expect(page.locator('input[name="login"]')).toBeVisible();
});
