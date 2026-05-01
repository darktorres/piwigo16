import { test, expect } from '@playwright/test';
import { pwgUrl } from './helpers/url';

// PIWIGO_INSTALL_DB_HOST is the hostname as seen by PHP doing the install.
// Default: 'localhost' (local Apache + MySQL). Override to 'db' for Docker Compose
// where the MySQL container is reached by service name from the web container.
const INSTALL_DB_HOST = process.env.PIWIGO_INSTALL_DB_HOST || 'localhost';
const DB_USER = process.env.PIWIGO_DB_USER || 'piwigo';
const DB_PASS = process.env.PIWIGO_DB_PASSWORD || 'piwigo';
const DB_NAME = process.env.PIWIGO_DB_BASE || 'piwigo';

test('fresh install completes end-to-end', async ({ page }) => {
    await page.goto(pwgUrl('/install.php'));

    // Once installed, install.php redirects away. Skip rather than fail —
    // running this in a clean DB is opt-in (drop SKIP_GLOBAL_SETUP).
    const heading = page.getByRole('heading', { name: /Installation/ });
    if (await heading.count() === 0) {
        test.skip(true, 'Piwigo already installed (set SKIP_GLOBAL_SETUP=0 to wipe and re-run)');
        return;
    }
    await expect(heading).toBeVisible();

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
