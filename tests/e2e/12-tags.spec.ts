import * as path from 'path';
import { fileURLToPath } from 'url';
import { test, expect } from '@playwright/test';
import { loginAsAdmin, attachErrorCollector } from './helpers/admin-login';
import { getCookieHeader, createAlbum, uploadPhoto, getPwgToken } from './helpers/upload-photo';
import { pwgUrl } from './helpers/url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const IMAGE = path.join(__dirname, '../../galleries/Wallpapers/006.jpg');

test.describe('tag management', () => {
    test('create tag, assign to photo, delete tag', async ({ page, request }) => {
        await loginAsAdmin(page);
        const cookie = await getCookieHeader(page);
        const pwgToken = await getPwgToken(request, cookie);

        const albumId = await createAlbum(request, cookie, 'Tags Test Album');
        const imageId = await uploadPhoto(request, cookie, IMAGE, albumId);

        // Unique-per-run tag name; pwg.tags.add fails if the tag exists.
        const tagName = `e2e-tag-${Date.now()}`;

        // Create tag
        const tagCreate = await request.post(pwgUrl('/ws.php?format=json'), {
            headers: { Cookie: cookie },
            form: { method: 'pwg.tags.add', name: tagName },
        });
        const tagBody = await tagCreate.json();
        expect(tagBody.stat).toBe('ok');
        const tagId: number = tagBody.result.id ?? tagBody.result.info;
        expect(tagId).toBeGreaterThan(0);

        // Assign tag to photo
        const setInfo = await request.post(pwgUrl('/ws.php?format=json'), {
            headers: { Cookie: cookie },
            form: {
                method: 'pwg.images.setInfo',
                image_id: String(imageId),
                tag_ids: String(tagId),
            },
        });
        expect((await setInfo.json()).stat).toBe('ok');

        // Tag images list returns the photo
        const tagImages = await request.get(
            pwgUrl(`/ws.php?format=json&method=pwg.tags.getImages&tag_id=${tagId}`),
            { headers: { Cookie: cookie } }
        );
        const tagImagesBody = await tagImages.json();
        expect(tagImagesBody.stat).toBe('ok');
        const images = tagImagesBody.result.images._content ?? tagImagesBody.result.images ?? [];
        expect(images.some((img: { id: number }) => img.id === imageId)).toBe(true);

        // Delete tag
        const deleteTag = await request.post(pwgUrl('/ws.php?format=json'), {
            headers: { Cookie: cookie },
            form: { method: 'pwg.tags.delete', tag_id: String(tagId), pwg_token: pwgToken },
        });
        expect((await deleteTag.json()).stat).toBe('ok');

        // Tag no longer in list
        const tagList = await request.get(pwgUrl('/ws.php?format=json&method=pwg.tags.getList'), {
            headers: { Cookie: cookie },
        });
        const tags = (await tagList.json()).result.tags._content ?? [];
        expect(tags.some((t: { id: number }) => t.id === tagId)).toBe(false);
    });

    test('admin tags page loads without JS errors', async ({ page }) => {
        const getErrors = attachErrorCollector(page);
        await loginAsAdmin(page);
        await page.goto(pwgUrl('/admin.php?page=tags'));
        expect(
            getErrors(),
            `pageerrors: ${getErrors()
                .map((e) => e.message)
                .join('; ')}`
        ).toHaveLength(0);
    });
});
