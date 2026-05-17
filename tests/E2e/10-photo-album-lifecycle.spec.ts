import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { getCookieHeader, createAlbum, uploadPhoto, getPwgToken } from './helpers/upload-photo';
import { wsUrl } from './helpers/url';
import { TEST_PHOTOS } from './helpers/test-data.js';

const IMAGE = TEST_PHOTOS[3]!;

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
    expect(listRes.status(), 'pwg.categories.getImages HTTP status').toBe(200);
    const listBody = (await listRes.json()) as {
        stat: string;
        result: { images: { _content?: Array<{ id: number }> } | Array<{ id: number }> };
    };
    expect(listBody.stat).toBe('ok');
    const imagesField = listBody.result.images;
    const images: Array<{ id: number }> = Array.isArray(imagesField)
        ? imagesField
        : (imagesField._content ?? []);
    expect(images.some((img) => img.id === imageId)).toBe(true);

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
    expect(updateRes.status(), 'pwg.images.setInfo HTTP status').toBe(200);
    expect(((await updateRes.json()) as { stat: string }).stat).toBe('ok');

    const infoRes = await request.get(
        wsUrl({ format: 'json', method: 'pwg.images.getInfo', image_id: String(imageId) }),
        { headers: { Cookie: cookie } }
    );
    expect(infoRes.status(), 'pwg.images.getInfo HTTP status').toBe(200);
    const infoBody = (await infoRes.json()) as { stat: string; result: { name: string } };
    expect(infoBody.stat).toBe('ok');
    expect(infoBody.result.name).toBe('Updated Name');

    // --- Delete photo ---
    const deletePhoto = await request.post(wsUrl({ format: 'json' }), {
        headers: { Cookie: cookie },
        form: { method: 'pwg.images.delete', image_id: String(imageId), pwg_token: pwgToken },
    });
    expect(deletePhoto.status(), 'pwg.images.delete HTTP status').toBe(200);
    expect(((await deletePhoto.json()) as { stat: string }).stat).toBe('ok');

    // Deleted photo getInfo must fail (stat=fail, but HTTP is still 200)
    const afterDelete = await request.get(
        wsUrl({ format: 'json', method: 'pwg.images.getInfo', image_id: String(imageId) }),
        { headers: { Cookie: cookie } }
    );
    expect(afterDelete.status(), 'deleted photo getInfo HTTP status').toBe(200);
    expect(((await afterDelete.json()) as { stat: string }).stat).toBe('fail');

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
    expect(deleteAlbum.status(), 'pwg.categories.delete HTTP status').toBe(200);
    expect(((await deleteAlbum.json()) as { stat: string }).stat).toBe('ok');

    // Deleted album must not appear in admin list
    const catList = await request.get(
        wsUrl({ format: 'json', method: 'pwg.categories.getAdminList' }),
        { headers: { Cookie: cookie } }
    );
    expect(catList.status(), 'pwg.categories.getAdminList HTTP status').toBe(200);
    const catBody = (await catList.json()) as {
        result: {
            categories: { _content?: Array<{ id: number }> } | Array<{ id: number }> | undefined;
        };
    };
    const catsField = catBody.result.categories;
    const cats: Array<{ id: number }> = Array.isArray(catsField)
        ? catsField
        : (catsField?._content ?? []);
    expect(cats.some((c) => c.id === albumId)).toBe(false);
});
