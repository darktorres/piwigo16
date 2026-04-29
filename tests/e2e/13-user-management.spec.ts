import { test, expect } from '@playwright/test';
import { loginAsAdmin, attachErrorCollector } from './helpers/admin-login';
import { getCookieHeader } from './helpers/upload-photo';

test.describe('user management', () => {
    test('create and delete user lifecycle', async ({ page, request }) => {
        await loginAsAdmin(page);
        const cookie = await getCookieHeader(page);

        // Create user
        const createRes = await request.post('/ws.php?format=json', {
            headers: { Cookie: cookie },
            form: {
                method: 'pwg.users.add',
                username: 'e2e_test_user',
                password: 'SecurePass123!',
                password_confirm: 'SecurePass123!',
                email: 'e2e@example.com',
            },
        });
        const createBody = await createRes.json();
        expect(createBody.stat).toBe('ok');
        const userId: number =
            createBody.result.users?.[0]?.id ??
            createBody.result.id ??
            0;
        expect(userId).toBeGreaterThan(0);

        // User appears in list
        const listRes = await request.get('/ws.php?format=json&method=pwg.users.getList', {
            headers: { Cookie: cookie },
        });
        const listBody = await listRes.json();
        expect(listBody.stat).toBe('ok');
        const users = listBody.result.users._content ?? listBody.result.users ?? [];
        const usernames: string[] = users.map((u: { username: string }) => u.username);
        expect(usernames).toContain('e2e_test_user');

        // Delete user
        const deleteRes = await request.post('/ws.php?format=json', {
            headers: { Cookie: cookie },
            form: { method: 'pwg.users.delete', user_id: String(userId) },
        });
        expect((await deleteRes.json()).stat).toBe('ok');

        // User no longer in list
        const afterDelete = await request.get('/ws.php?format=json&method=pwg.users.getList', {
            headers: { Cookie: cookie },
        });
        const afterUsers = (await afterDelete.json()).result.users._content ?? [];
        const afterNames: string[] = afterUsers.map((u: { username: string }) => u.username);
        expect(afterNames).not.toContain('e2e_test_user');
    });

    test('admin user list page loads without JS errors', async ({ page }) => {
        const getErrors = attachErrorCollector(page);
        await loginAsAdmin(page);
        await page.goto('/admin.php?page=user_list');
        expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
    });

    test('admin group list page loads without JS errors', async ({ page }) => {
        const getErrors = attachErrorCollector(page);
        await loginAsAdmin(page);
        await page.goto('/admin.php?page=group_list');
        expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
    });
});
