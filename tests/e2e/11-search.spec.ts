import * as path from 'path';
import { fileURLToPath } from 'url';
import { test, expect } from '@playwright/test';
import { loginAsAdmin, attachErrorCollector } from './helpers/admin-login';
import { getCookieHeader, createAlbum, uploadPhoto } from './helpers/upload-photo';
import { pwgUrl } from './helpers/url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const IMAGE = path.join(__dirname, '../../galleries/Wallpapers/005.jpg');

test.describe('search functionality', () => {
    test('API search finds photo by name', async ({ page, request }) => {
        await loginAsAdmin(page);
        const cookie = await getCookieHeader(page);

        const albumId = await createAlbum(request, cookie, 'Search Test Album');
        await uploadPhoto(request, cookie, IMAGE, albumId, 'LighthouseDusk Panorama');

        const searchRes = await request.get(
            pwgUrl('/ws.php?format=json&method=pwg.images.search&query=LighthouseDusk'),
            { headers: { Cookie: cookie } }
        );
        const searchBody = await searchRes.json();
        expect(searchBody.stat).toBe('ok');
        const found = searchBody.result.images._content ?? searchBody.result.images ?? [];
        const names: string[] = found.map((img: { name: string }) => img.name);
        expect(names.some((n) => n.includes('LighthouseDusk'))).toBe(true);
    });

    test('gallery search page renders without JS errors', async ({ page }) => {
        const getErrors = attachErrorCollector(page);
        await page.goto(pwgUrl('/index.php?q=sunset'));
        expect(
            getErrors(),
            `pageerrors: ${getErrors()
                .map((e) => e.message)
                .join('; ')}`
        ).toHaveLength(0);
    });

    test('search with no results renders empty state without errors', async ({ page }) => {
        const getErrors = attachErrorCollector(page);
        const response = await page.goto(pwgUrl('/index.php?q=zzznomatch99xqz'));
        expect(
            getErrors(),
            `pageerrors: ${getErrors()
                .map((e) => e.message)
                .join('; ')}`
        ).toHaveLength(0);
        expect(response?.status()).toBe(200);
    });
});
