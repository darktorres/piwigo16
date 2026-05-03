import * as path from 'path';
import { fileURLToPath } from 'url';
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { getCookieHeader, createAlbum, uploadPhoto } from './helpers/upload-photo';
import { pwgUrl } from './helpers/url';
import { gotoOk } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const IMAGE = path.join(__dirname, '../../galleries/Wallpapers/005.jpg');

test.describe('search functionality', () => {
    test('API search finds photo by name', async ({ page, request }) => {
        await loginAsAdmin(page);
        const cookie = await getCookieHeader(page);

        const albumId = await createAlbum(request, cookie, `Search Test Album ${Date.now()}`);
        const uniqueName = `LighthouseDusk_${Date.now()}`;
        await uploadPhoto(request, cookie, IMAGE, albumId, uniqueName);

        const searchRes = await request.get(
            pwgUrl(`/ws.php?format=json&method=pwg.images.search&query=${uniqueName}`),
            { headers: { Cookie: cookie } }
        );
        expect(searchRes.status(), 'pwg.images.search HTTP status').toBe(200);
        const searchBody = await searchRes.json();
        expect(
            searchBody.stat,
            `pwg.images.search stat (full body: ${JSON.stringify(searchBody).slice(0, 400)})`
        ).toBe('ok');
        const found = searchBody.result.images._content ?? searchBody.result.images ?? [];
        const names: string[] = found.map((img: { name: string }) => img.name);
        expect(names, 'searched names').toContain(uniqueName);
    });

    test('gallery search page renders without JS errors', async ({ page }) => {
        const monitor = attachMonitor(page);
        await gotoOk(page, pwgUrl('/index.php?q=sunset'), 'gallery search');
        monitor.assertClean('gallery search');
    });

    test('search with no results renders empty state without errors', async ({ page }) => {
        const monitor = attachMonitor(page);
        await gotoOk(page, pwgUrl('/index.php?q=zzznomatch99xqz'), 'gallery search (no match)');
        monitor.assertClean('gallery search (no match)');
    });
});
