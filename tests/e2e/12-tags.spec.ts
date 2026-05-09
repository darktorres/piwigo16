import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { getCookieHeader, createAlbum, uploadPhoto, getPwgToken } from './helpers/upload-photo';
import { wsUrl, adminUrl } from './helpers/url';
import { gotoOk } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';
import { TEST_PHOTOS } from './helpers/test-data.js';

const IMAGE = TEST_PHOTOS[5]!;

test.describe('tag management', () => {
    test('create tag, assign to photo, delete tag', async ({ page, request }) => {
        await loginAsAdmin(page);
        const cookie = await getCookieHeader(page);
        const pwgToken = await getPwgToken(request, cookie);

        const albumId = await createAlbum(request, cookie, `Tags Test Album ${Date.now()}`);
        const imageId = await uploadPhoto(request, cookie, IMAGE, albumId);

        // Unique-per-run tag name; pwg.tags.add fails if the tag exists.
        const tagName = `e2e-tag-${Date.now()}`;

        // Create tag
        const tagCreate = await request.post(wsUrl({ format: 'json' }), {
            headers: { Cookie: cookie },
            form: { method: 'pwg.tags.add', name: tagName },
        });
        expect(tagCreate.status(), 'pwg.tags.add HTTP status').toBe(200);
        const tagBody = (await tagCreate.json()) as {
            stat: string;
            result: { id?: number; info?: number };
        };
        expect(
            tagBody.stat,
            `pwg.tags.add stat (body: ${JSON.stringify(tagBody).slice(0, 400)})`
        ).toBe('ok');
        const tagId: number = tagBody.result.id ?? tagBody.result.info ?? 0;
        expect(tagId, 'pwg.tags.add returned tag id').toBeGreaterThan(0);

        // Assign tag to photo
        const setInfo = await request.post(wsUrl({ format: 'json' }), {
            headers: { Cookie: cookie },
            form: {
                method: 'pwg.images.setInfo',
                image_id: String(imageId),
                tag_ids: String(tagId),
            },
        });
        expect(setInfo.status(), 'pwg.images.setInfo HTTP status').toBe(200);
        const setInfoBody = (await setInfo.json()) as { stat: string };
        expect(
            setInfoBody.stat,
            `pwg.images.setInfo stat (body: ${JSON.stringify(setInfoBody).slice(0, 400)})`
        ).toBe('ok');

        // Tag images list returns the photo
        const tagImages = await request.get(
            wsUrl({ format: 'json', method: 'pwg.tags.getImages', tag_id: String(tagId) }),
            { headers: { Cookie: cookie } }
        );
        const tagImagesBody = (await tagImages.json()) as {
            stat: string;
            result: {
                images: { _content?: Array<{ id: number }> } | Array<{ id: number }> | undefined;
            };
        };
        expect(tagImagesBody.stat, 'pwg.tags.getImages stat').toBe('ok');
        const tagImagesField = tagImagesBody.result.images;
        const images: Array<{ id: number }> = Array.isArray(tagImagesField)
            ? tagImagesField
            : (tagImagesField?._content ?? []);
        expect(
            images.some((img) => img.id === imageId),
            `tag ${tagId} should contain photo ${imageId}`
        ).toBe(true);

        // Delete tag
        const deleteTag = await request.post(wsUrl({ format: 'json' }), {
            headers: { Cookie: cookie },
            form: { method: 'pwg.tags.delete', tag_id: String(tagId), pwg_token: pwgToken },
        });
        expect(((await deleteTag.json()) as { stat: string }).stat, 'pwg.tags.delete stat').toBe(
            'ok'
        );

        // Tag no longer in list
        const tagList = await request.get(wsUrl({ format: 'json', method: 'pwg.tags.getList' }), {
            headers: { Cookie: cookie },
        });
        const tagListBody = (await tagList.json()) as {
            result: { tags: { _content?: Array<{ id: number }> } };
        };
        const tags = tagListBody.result.tags._content ?? [];
        expect(
            tags.some((t) => t.id === tagId),
            `deleted tag ${tagId} should not be in list`
        ).toBe(false);
    });

    test('admin tags page loads without JS errors', async ({ page }) => {
        const monitor = attachMonitor(page);
        await loginAsAdmin(page);
        monitor.reset();
        await gotoOk(page, adminUrl('tags'), 'admin tags');
        monitor.assertClean('admin tags');
    });
});
