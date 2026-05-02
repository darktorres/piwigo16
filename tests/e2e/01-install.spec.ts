import { test, expect } from '@playwright/test';
import { pwgUrl } from './helpers/url';

// All values come from .env.local (or shell env). No fallbacks — failing loud
// on missing config beats silently installing against the wrong DB.
const DB_HOST = process.env.PIWIGO_DB_HOST ?? 'localhost';
const DB_USER = process.env.PIWIGO_DB_USER ?? '';
const DB_PASS = process.env.PIWIGO_DB_PASSWORD ?? '';
const DB_NAME = process.env.PIWIGO_DB_BASE ?? '';

test('fresh install completes end-to-end', async ({ page }) => {
    await page.goto(pwgUrl('/install.php'));

    // Once installed, install.php redirects away. Skip rather than fail —
    // running this in a clean DB is opt-in (drop SKIP_GLOBAL_SETUP).
    const heading = page.getByRole('heading', { name: /Installation/ });
    if ((await heading.count()) === 0) {
        test.skip(true, 'Piwigo already installed (set SKIP_GLOBAL_SETUP=0 to wipe and re-run)');
        return;
    }
    await expect(heading).toBeVisible();

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
    await expect(page.getByRole('link', { name: 'Visit the gallery' })).toBeVisible();
});
