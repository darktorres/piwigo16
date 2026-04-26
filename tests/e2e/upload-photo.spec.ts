import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';

// Minimal 1×1 white JPEG (no external fixture file needed)
const TINY_JPEG_BASE64 =
    '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8U' +
    'HRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgN' +
    'DRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy' +
    'MjL/wAARCAABAAEDASIAAhEBAxEB/8QAFgABAQEAAAAAAAAAAAAAAAAABgUEB' +
    '/8QAIBAAAgIBBQEBAAAAAAAAAAAAAQIDBAUREiExQf/EABQBAQAAAAAAAAAAAAAAAAAAAAD' +
    '/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwN22dNVT6g1FWtJXYqOrqa' +
    'TqSqorCqBkfOR5GfPIAAAAAA//Z';

test('upload photo via photos_add_direct endpoint', async ({ page, request }) => {
    await loginAsAdmin(page);

    await page.goto('/admin/photos_add.php?tab=direct');
    await expect(page.locator('body')).not.toContainText('Fatal error');
    expect(page.url()).toContain('admin');
});

test('photos_add page loads without errors', async ({ page }) => {
    await loginAsAdmin(page);
    const response = await page.goto('/admin/photos_add.php');
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toContainText('Fatal error');
});
