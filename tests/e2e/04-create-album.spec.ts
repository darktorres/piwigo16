import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { pwgUrl } from './helpers/url';

test('create album via web service API', async ({ page, request }) => {
    await loginAsAdmin(page);

    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map((c) => `${c.name}=${c.value}`).join('; ');

    const response = await request.post(pwgUrl('/ws.php?format=json'), {
        headers: { Cookie: cookieHeader },
        form: {
            method: 'pwg.categories.add',
            name: `E2E Test Album ${Date.now()}`,
        },
    });

    expect(response.status(), 'pwg.categories.add HTTP status').toBe(200);
    const body = await response.json();
    expect(
        body.stat,
        `pwg.categories.add stat (full body: ${JSON.stringify(body)})`
    ).toBe('ok');
    expect(body.result.id, 'pwg.categories.add returned id').toBeGreaterThan(0);
});
