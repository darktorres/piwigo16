import { test } from '@playwright/test';
import { pwgUrl, identificationUrl } from './helpers/url';
import { gotoOk, assertVisible } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';

test('gallery home page loads without errors', async ({ page }) => {
    const monitor = attachMonitor(page);
    await gotoOk(page, pwgUrl('/index.php'), 'gallery home');
    monitor.assertClean('gallery home');
});

test('identification page renders login form', async ({ page }) => {
    const monitor = attachMonitor(page);
    await gotoOk(page, identificationUrl(), 'identification');
    await assertVisible(
        page.locator('input.login[name="username"]'),
        'identification: username field'
    );
    await assertVisible(
        page.locator('form[name="login_form"] input[name="password"]'),
        'identification: password field'
    );
    await assertVisible(
        page.locator('form[name="login_form"] input[name="login"]'),
        'identification: submit button'
    );
    monitor.assertClean('identification');
});
