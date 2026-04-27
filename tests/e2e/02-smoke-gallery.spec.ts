import { test, expect } from '@playwright/test';

test('gallery home page loads without errors', async ({ page }) => {
    const response = await page.goto('/index.php');
    expect(response?.status()).toBe(200);
    // Capture page content for debugging if title check fails
    const title = await page.title();
    if (!title) {
        const html = await page.content();
        console.log('DEBUG: page title is empty. First 2000 chars of HTML:\n' + html.slice(0, 2000));
    }
    await expect(page).toHaveTitle(/.+/);
    await expect(page.locator('body')).not.toContainText('Fatal error');
    await expect(page.locator('body')).not.toContainText('Warning:');
    await expect(page.locator('body')).not.toContainText('Parse error');
});

test('identification page renders login form', async ({ page }) => {
    const response = await page.goto('/identification.php');
    expect(response?.status()).toBe(200);
    await expect(page.locator('input[name="username"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
    await expect(page.locator('input[name="login"]')).toBeVisible();
});
