import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { pwgUrl } from './helpers/url';
import { gotoOk } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';

test('admin login and dashboard load', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsAdmin(page);
    await gotoOk(page, pwgUrl('/admin.php'), 'admin dashboard');
    await expect(
        page.getByRole('heading', { name: 'Piwigo Administration' }),
        'admin: page heading should be visible'
    ).toBeVisible({ timeout: 5000 });
    monitor.assertClean('admin dashboard');
});

test('admin albums page loads', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsAdmin(page);
    await gotoOk(page, pwgUrl('/admin.php?page=albums'), 'admin albums');
    monitor.assertClean('admin albums');
});
