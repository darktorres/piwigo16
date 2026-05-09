import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { getCookieHeader, createAlbum, uploadPhoto } from './helpers/upload-photo';
import { wsUrl } from './helpers/url';
import { TEST_PHOTOS } from './helpers/test-data.js';

const IMAGE_1 = TEST_PHOTOS[1]!;
const IMAGE_2 = TEST_PHOTOS[2]!;

test('upload photo via API returns image_id', async ({ page, request }) => {
    await loginAsAdmin(page);
    const cookie = await getCookieHeader(page);
    const albumId = await createAlbum(request, cookie, 'Upload Test Album');

    const imageId = await uploadPhoto(request, cookie, IMAGE_1, albumId);
    expect(imageId).toBeGreaterThan(0);
});

test('uploaded photo appears in getInfo', async ({ page, request }) => {
    await loginAsAdmin(page);
    const cookie = await getCookieHeader(page);
    const albumId = await createAlbum(request, cookie, 'GetInfo Test Album');

    const imageId = await uploadPhoto(request, cookie, IMAGE_1, albumId, 'My Wallpaper');

    const info = await request.get(
        wsUrl({ format: 'json', method: 'pwg.images.getInfo', image_id: String(imageId) }),
        {
            headers: { Cookie: cookie },
        }
    );
    const infoBody = (await info.json()) as {
        stat: string;
        result: { id: number; width: number; height: number };
    };
    expect(infoBody.stat).toBe('ok');
    expect(infoBody.result.id).toBe(imageId);
    expect(infoBody.result.width).toBeGreaterThan(0);
    expect(infoBody.result.height).toBeGreaterThan(0);
});

test('two uploaded photos both appear in album', async ({ page, request }) => {
    await loginAsAdmin(page);
    const cookie = await getCookieHeader(page);
    const albumId = await createAlbum(request, cookie, 'Multi Upload Album');

    await uploadPhoto(request, cookie, IMAGE_1, albumId);
    await uploadPhoto(request, cookie, IMAGE_2, albumId);

    const listRes = await request.get(
        wsUrl({ format: 'json', method: 'pwg.categories.getImages', cat_id: String(albumId) }),
        { headers: { Cookie: cookie } }
    );
    const listBody = (await listRes.json()) as {
        stat: string;
        result: { images: { _content?: unknown[] } | unknown[] };
    };
    expect(listBody.stat).toBe('ok');
    const imagesField = listBody.result.images;
    const images: unknown[] = Array.isArray(imagesField)
        ? imagesField
        : (imagesField._content ?? []);
    expect(Array.isArray(images)).toBe(true);
    expect(images.length).toBeGreaterThanOrEqual(2);
});
