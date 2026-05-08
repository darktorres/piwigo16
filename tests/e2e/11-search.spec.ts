import * as path from 'path';
import { fileURLToPath } from 'url';
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { getCookieHeader, createAlbum, uploadPhoto } from './helpers/upload-photo';
import { pwgUrl, wsUrl } from './helpers/url';
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
            wsUrl({ format: 'json', method: 'pwg.images.search', query: uniqueName }),
            { headers: { Cookie: cookie } }
        );
        expect(searchRes.status(), 'pwg.images.search HTTP status').toBe(200);
        const searchBody = (await searchRes.json()) as {
            stat: string;
            result: {
                images:
                    | { _content?: Array<{ name: string }> }
                    | Array<{ name: string }>
                    | undefined;
            };
        };
        expect(
            searchBody.stat,
            `pwg.images.search stat (full body: ${JSON.stringify(searchBody).slice(0, 400)})`
        ).toBe('ok');
        const imagesField = searchBody.result.images;
        const found: Array<{ name: string }> = Array.isArray(imagesField)
            ? imagesField
            : (imagesField?._content ?? []);
        const names: string[] = found.map((img) => img.name);
        expect(names, 'searched names').toContain(uniqueName);
    });

    test('gallery search page renders without JS errors', async ({ page }) => {
        const monitor = attachMonitor(page);
        // Use ?/search&q=... so PathExtractor routes to SearchController, not gallery home.
        await gotoOk(page, pwgUrl('/index.php?/search&q=sunset'), 'gallery search');
        monitor.assertClean('gallery search');
    });

    test('search with no results renders empty state without errors', async ({ page }) => {
        const monitor = attachMonitor(page);
        await gotoOk(
            page,
            pwgUrl('/index.php?/search&q=zzznomatch99xqz'),
            'gallery search (no match)'
        );
        monitor.assertClean('gallery search (no match)');
    });
});
