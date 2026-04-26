import { test, expect } from '@playwright/test';

// PIWIGO_INSTALL_DB_HOST is the hostname as seen from *inside* the web container.
// When running against Docker Compose, this is the service name 'db'.
// PIWIGO_DB_HOST is used by global-setup's mysql CLI on the host side.
const INSTALL_DB_HOST = process.env.PIWIGO_INSTALL_DB_HOST || 'db';
const DB_USER = process.env.PIWIGO_DB_USER || 'piwigo';
const DB_PASS = process.env.PIWIGO_DB_PASSWORD || 'piwigo';
const DB_NAME = process.env.PIWIGO_DB_BASE || 'piwigo';

test('fresh install completes end-to-end', async ({ page }) => {
    await page.goto('/install.php');

    await expect(page.getByRole('heading', { name: /Installation/ })).toBeVisible();

    await page.fill('input[name="dbhost"]', INSTALL_DB_HOST);
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
    await expect(page.getByRole('link', { name: 'Visit the gallery' })).toBeVisible();
});
