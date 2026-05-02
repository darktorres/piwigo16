/**
 * Extended admin page smoke tests — one test per page not already covered in 07.
 * Verifies pages load without JS errors. A photo is created in beforeAll so
 * photo-editor routes have a valid ID to load.
 */

import * as path from 'path';
import { fileURLToPath } from 'url';
import { test, expect } from '@playwright/test';
import { loginAsAdmin, attachErrorCollector } from './helpers/admin-login';
import { getCookieHeader, createAlbum, uploadPhoto } from './helpers/upload-photo';
import { pwgUrl } from './helpers/url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const IMAGE = path.join(__dirname, '../../galleries/Wallpapers/007.jpg');

let sharedPhotoId = 0;

test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await loginAsAdmin(page);
    const cookie = await getCookieHeader(page);
    const request = page.context().request;

    const albumId = await createAlbum(request, cookie, 'Smoke Test Album');
    sharedPhotoId = await uploadPhoto(request, cookie, IMAGE, albumId, 'Smoke Test Photo');
    await page.close();
});

test('admin photo editor page loads without JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl(`/admin.php?page=photo&image_id=${sharedPhotoId}`));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin comments page loads without JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=comments'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin batch manager page loads without JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=batch_manager'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin stats page loads without JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=stats'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin rating page loads without JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=rating'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin permalinks page loads without JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=permalinks'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});
