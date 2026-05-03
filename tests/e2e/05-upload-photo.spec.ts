import { test } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { pwgUrl } from './helpers/url';
import { gotoOk } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';

test('upload photo page loads via admin.php', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsAdmin(page);
    await gotoOk(
        page,
        pwgUrl('/admin.php?page=photos_add&tab=direct'),
        'photos_add tab=direct'
    );
    monitor.assertClean('photos_add tab=direct');
});

test('photos_add page loads without errors', async ({ page }) => {
    const monitor = attachMonitor(page);
    await loginAsAdmin(page);
    await gotoOk(page, pwgUrl('/admin.php?page=photos_add'), 'photos_add');
    monitor.assertClean('photos_add');
});
