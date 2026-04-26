import { test, expect } from '@playwright/test';

const DB_HOST = process.env.PIWIGO_DB_HOST ?? 'db';
const DB_USER = process.env.PIWIGO_DB_USER ?? 'piwigo';
const DB_PASS = process.env.PIWIGO_DB_PASSWORD ?? 'piwigo';
const DB_NAME = process.env.PIWIGO_DB_BASE ?? 'piwigo';

test('fresh install completes end-to-end', async ({ page }) => {
    await page.goto('/install.php');

    await expect(page.getByText('Installation')).toBeVisible();

    await page.fill('input[name="dbhost"]', DB_HOST);
    await page.fill('input[name="dbuser"]', DB_USER);
    await page.fill('input[name="dbpasswd"]', DB_PASS);
    await page.fill('input[name="dbname"]', DB_NAME);

    await page.fill('input[name="admin_name"]', 'admin');
    await page.fill('input[name="admin_pass1"]', 'p4ssword!');
    await page.fill('input[name="admin_pass2"]', 'p4ssword!');
    await page.fill('input[name="admin_mail"]', 'admin@example.test');

    await page.uncheck('input[name="newsletter_subscribe"]');

    await page.click('input[name="install"]');

    await expect(page.getByText('Congratulations')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('link', { name: 'Visit Gallery' })).toBeVisible();
});
