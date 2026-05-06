import * as path from 'path';
import { fileURLToPath } from 'url';
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { getCookieHeader, createAlbum, uploadPhoto, getPwgToken } from './helpers/upload-photo';
import { wsUrl } from './helpers/url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const IMAGE = path.join(__dirname, '../../galleries/Wallpapers/004.jpg');

test('photo and album full CRUD lifecycle', async ({ page, request }) => {
    await loginAsAdmin(page);
    const cookie = await getCookieHeader(page);
    const pwgToken = await getPwgToken(request, cookie);

    // --- Create album ---
    const albumId = await createAlbum(request, cookie, 'Lifecycle Album');
    expect(albumId).toBeGreaterThan(0);

    // --- Upload photo ---
    const imageId = await uploadPhoto(request, cookie, IMAGE, albumId, 'Original Name');

    // --- Photo appears in album ---
    const listRes = await request.get(
        wsUrl({ format: 'json', method: 'pwg.categories.getImages', cat_id: String(albumId) }),
        { headers: { Cookie: cookie } }
    );
    const listBody = await listRes.json();
    expect(listBody.stat).toBe('ok');
    const images = listBody.result.images._content ?? listBody.result.images;
    expect(images.some((img: { id: number }) => img.id === imageId)).toBe(true);

    // --- Update photo name ---
    const updateRes = await request.post(wsUrl({ format: 'json' }), {
        headers: { Cookie: cookie },
        // single_value_mode defaults to 'fill_if_empty' which would keep the
        // existing name; 'replace' is what callers usually want for an update.
        form: {
            method: 'pwg.images.setInfo',
            image_id: String(imageId),
            name: 'Updated Name',
            single_value_mode: 'replace',
        },
    });
    expect((await updateRes.json()).stat).toBe('ok');

    const infoRes = await request.get(
        wsUrl({ format: 'json', method: 'pwg.images.getInfo', image_id: String(imageId) }),
        { headers: { Cookie: cookie } }
    );
    const infoBody = await infoRes.json();
    expect(infoBody.stat).toBe('ok');
    expect(infoBody.result.name).toBe('Updated Name');

    // --- Delete photo ---
    const deletePhoto = await request.post(wsUrl({ format: 'json' }), {
        headers: { Cookie: cookie },
        form: { method: 'pwg.images.delete', image_id: String(imageId), pwg_token: pwgToken },
    });
    expect((await deletePhoto.json()).stat).toBe('ok');

    // Deleted photo getInfo must fail
    const afterDelete = await request.get(
        wsUrl({ format: 'json', method: 'pwg.images.getInfo', image_id: String(imageId) }),
        { headers: { Cookie: cookie } }
    );
    expect((await afterDelete.json()).stat).toBe('fail');

    // --- Delete album ---
    const deleteAlbum = await request.post(wsUrl({ format: 'json' }), {
        headers: { Cookie: cookie },
        form: {
            method: 'pwg.categories.delete',
            category_id: String(albumId),
            photo_deletion_mode: 'delete_orphans',
            pwg_token: pwgToken,
        },
    });
    expect((await deleteAlbum.json()).stat).toBe('ok');

    // Deleted album must not appear in admin list
    const catList = await request.get(
        wsUrl({ format: 'json', method: 'pwg.categories.getAdminList' }),
        { headers: { Cookie: cookie } }
    );
    const catBody = await catList.json();
    const cats = catBody.result.categories._content ?? catBody.result.categories ?? [];
    expect(cats.some((c: { id: number }) => c.id === albumId)).toBe(false);
});
