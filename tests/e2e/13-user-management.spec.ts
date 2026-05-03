import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { getCookieHeader, getPwgToken } from './helpers/upload-photo';
import { pwgUrl } from './helpers/url';
import { gotoOk } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';

test.describe('user management', () => {
    test('create and delete user lifecycle', async ({ page, request }) => {
        await loginAsAdmin(page);
        const cookie = await getCookieHeader(page);
        const pwgToken = await getPwgToken(request, cookie);

        // Unique-per-run username so reruns don't collide on stale rows.
        const username = `e2e_test_user_${Date.now()}`;
        const email = `e2e_${Date.now()}@example.com`;

        // Create user
        const createRes = await request.post(pwgUrl('/ws.php?format=json'), {
            headers: { Cookie: cookie },
            form: {
                method: 'pwg.users.add',
                username,
                password: 'SecurePass123!',
                password_confirm: 'SecurePass123!',
                email,
                pwg_token: pwgToken,
            },
        });
        expect(createRes.status(), 'pwg.users.add HTTP status').toBe(200);
        const createBody = await createRes.json();
        expect(
            createBody.stat,
            `pwg.users.add stat (body: ${JSON.stringify(createBody).slice(0, 400)})`
        ).toBe('ok');
        const userId: number = createBody.result.users?.[0]?.id ?? createBody.result.id ?? 0;
        expect(userId, 'pwg.users.add returned user id').toBeGreaterThan(0);

        // User appears in list
        const listRes = await request.get(pwgUrl('/ws.php?format=json&method=pwg.users.getList'), {
            headers: { Cookie: cookie },
        });
        const listBody = await listRes.json();
        expect(listBody.stat, 'pwg.users.getList stat').toBe('ok');
        const users = listBody.result.users._content ?? listBody.result.users ?? [];
        const usernames: string[] = users.map((u: { username: string }) => u.username);
        expect(usernames, 'created user appears in list').toContain(username);

        // Delete user
        const deleteRes = await request.post(pwgUrl('/ws.php?format=json'), {
            headers: { Cookie: cookie },
            form: { method: 'pwg.users.delete', user_id: String(userId), pwg_token: pwgToken },
        });
        expect((await deleteRes.json()).stat, 'pwg.users.delete stat').toBe('ok');

        // User no longer in list
        const afterDelete = await request.get(
            pwgUrl('/ws.php?format=json&method=pwg.users.getList'),
            {
                headers: { Cookie: cookie },
            }
        );
        const afterUsers = (await afterDelete.json()).result.users._content ?? [];
        const afterNames: string[] = afterUsers.map((u: { username: string }) => u.username);
        expect(afterNames, 'deleted user no longer in list').not.toContain(username);
    });

    test('admin user list page loads without JS errors', async ({ page }) => {
        const monitor = attachMonitor(page);
        await loginAsAdmin(page);
        monitor.reset();
        await gotoOk(page, pwgUrl('/admin.php?page=user_list'), 'admin user_list');
        monitor.assertClean('admin user_list');
    });

    test('admin group list page loads without JS errors', async ({ page }) => {
        const monitor = attachMonitor(page);
        await loginAsAdmin(page);
        monitor.reset();
        await gotoOk(page, pwgUrl('/admin.php?page=group_list'), 'admin group_list');
        monitor.assertClean('admin group_list');
    });
});
