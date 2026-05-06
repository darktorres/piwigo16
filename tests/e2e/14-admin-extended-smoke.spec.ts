/**
 * Extended admin page smoke tests — one test per page not already covered in 07.
 * Verifies pages load with no JS errors, no failed XHRs, no server-error markers
 * in the body. A photo is created in beforeAll so photo-editor routes have a
 * valid ID to load.
 */

import * as path from 'path';
import { fileURLToPath } from 'url';
import { test } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { getCookieHeader, createAlbum, uploadPhoto } from './helpers/upload-photo';
import { adminUrl } from './helpers/url';
import { gotoOk } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const IMAGE = path.join(__dirname, '../../galleries/Wallpapers/007.jpg');

let sharedPhotoId = 0;

test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAsAdmin(page);
    const cookie = await getCookieHeader(page);
    const request = page.context().request;

    const albumId = await createAlbum(request, cookie, `Smoke Test Album ${Date.now()}`);
    sharedPhotoId = await uploadPhoto(request, cookie, IMAGE, albumId, 'Smoke Test Photo');
    await page.close();
});

const ROUTES: ReadonlyArray<{ name: string; url: () => string }> = [
    { name: 'admin photo editor', url: () => adminUrl('photo') + `&image_id=${sharedPhotoId}` },
    { name: 'admin comments', url: () => adminUrl('comments') },
    { name: 'admin batch_manager', url: () => adminUrl('batch_manager') },
    { name: 'admin stats', url: () => adminUrl('stats') },
    { name: 'admin rating', url: () => adminUrl('rating') },
    { name: 'admin permalinks', url: () => adminUrl('permalinks') },
];

for (const route of ROUTES) {
    test(`${route.name} — clean (no JS errors, no failed XHRs)`, async ({ page }) => {
        const monitor = attachMonitor(page);
        await loginAsAdmin(page);
        monitor.reset();
        await gotoOk(page, route.url(), route.name);
        monitor.assertClean(route.name);
    });
}
