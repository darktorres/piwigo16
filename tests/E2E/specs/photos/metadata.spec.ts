/**
 * Photo Metadata Edit Tests
 *
 * @remarks
 * Tests for photo metadata editing functionality.
 * Verifies ability to edit photo title, description, and other properties.
 * Uses adminPage fixture for isolated, pre-authenticated sessions.
 *
 * Test Isolation Strategy:
 * Each test gets its own album and image via {@link createTestAlbumWithPhoto}
 * to prevent test interference and enable parallel execution.
 * Automatic cleanup in afterEach using {@link deleteTestAlbum}.
 *
 * @packageDocumentation
 */

import { test, expect } from '@base-test';
import { PhotoEditPage } from '../../pages/photos/PhotoEditPage';
import {
    createTestAlbumWithPhoto,
    deleteTestAlbum,
    createTestAlbum,
    baseUrl,
} from '@helpers/api-helpers';
import { safeCleanup } from '@helpers/cleanup-helpers';
import { TIMEOUTS } from '../../config/config';
import { ANIMATION_TIMEOUTS } from '../../config/test-timeouts';

test.describe('Photo Metadata Edit Page @admin @photos @metadata', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should display photo edit page @smoke', async ({ adminPage, request }) => {
        const result = await createTestAlbumWithPhoto(request, `Metadata_Smoke_${Date.now()}`);
        const { albumId, imageId, token } = result;

        try {
            const photoEditPage = new PhotoEditPage(adminPage);
            await photoEditPage.goto(imageId);
            await photoEditPage.expectEditPage();
        } finally {
            await safeCleanup(() => deleteTestAlbum(request, albumId, token), `album ${albumId}`);
        }
    });

    test('should display photo preview', async ({ adminPage, request }) => {
        const result = await createTestAlbumWithPhoto(request, `Preview_Test_${Date.now()}`);
        const { albumId, imageId, token } = result;

        try {
            const photoEditPage = new PhotoEditPage(adminPage);
            await photoEditPage.goto(imageId);
            await expect(photoEditPage.photoPreview).toBeVisible();
        } finally {
            await safeCleanup(() => deleteTestAlbum(request, albumId, token), `album ${albumId}`);
        }
    });

    test('should display title input field', async ({ adminPage, request }) => {
        const result = await createTestAlbumWithPhoto(request, `Title_Input_${Date.now()}`);
        const { albumId, imageId, token } = result;

        try {
            const photoEditPage = new PhotoEditPage(adminPage);
            await photoEditPage.goto(imageId);
            await expect(photoEditPage.titleInput).toBeVisible();
        } finally {
            await safeCleanup(() => deleteTestAlbum(request, albumId, token), `album ${albumId}`);
        }
    });

    test('should display author input field', async ({ adminPage, request }) => {
        const result = await createTestAlbumWithPhoto(request, `Author_Input_${Date.now()}`);
        const { albumId, imageId, token } = result;

        try {
            const photoEditPage = new PhotoEditPage(adminPage);
            await photoEditPage.goto(imageId);
            await expect(photoEditPage.authorInput).toBeVisible();
        } finally {
            await safeCleanup(() => deleteTestAlbum(request, albumId, token), `album ${albumId}`);
        }
    });

    test('should display description field', async ({ adminPage, request }) => {
        const result = await createTestAlbumWithPhoto(request, `Desc_Input_${Date.now()}`);
        const { albumId, imageId, token } = result;

        try {
            const photoEditPage = new PhotoEditPage(adminPage);
            await photoEditPage.goto(imageId);
            // Wait for the form to be visible
            await adminPage.waitForSelector('form#pictureModify, form[name="pictureModify"]', {
                timeout: TIMEOUTS.element.standard,
            });
            // Try multiple possible selectors for the description field
            const descField = adminPage.locator(
                'textarea[name="comment"], textarea.description, #photo-comment'
            );
            await expect(descField.first()).toBeVisible({ timeout: TIMEOUTS.element.standard });
        } finally {
            await safeCleanup(() => deleteTestAlbum(request, albumId, token), `album ${albumId}`);
        }
    });

    test('should display privacy level select', async ({ adminPage, request }) => {
        const result = await createTestAlbumWithPhoto(request, `Privacy_Input_${Date.now()}`);
        const { albumId, imageId, token } = result;

        try {
            const photoEditPage = new PhotoEditPage(adminPage);
            await photoEditPage.goto(imageId);
            // Wait for the form first
            await adminPage.waitForSelector('form#pictureModify, form[name="pictureModify"]', {
                timeout: TIMEOUTS.element.standard,
            });
            await expect(photoEditPage.privacyLevelSelect).toBeVisible({
                timeout: TIMEOUTS.element.standard,
            });
        } finally {
            await safeCleanup(() => deleteTestAlbum(request, albumId, token), `album ${albumId}`);
        }
    });

    test('should display submit button', async ({ adminPage, request }) => {
        const result = await createTestAlbumWithPhoto(request, `Submit_Btn_${Date.now()}`);
        const { albumId, imageId, token } = result;

        try {
            const photoEditPage = new PhotoEditPage(adminPage);
            await photoEditPage.goto(imageId);
            // Check for various submit button selectors
            const submitBtn = adminPage
                .locator('input[type="submit"], button[type="submit"], input[name="submit"]')
                .first();
            await expect(submitBtn).toBeVisible({ timeout: TIMEOUTS.element.standard });
        } finally {
            await safeCleanup(() => deleteTestAlbum(request, albumId, token), `album ${albumId}`);
        }
    });
});

test.describe.serial('Photo Metadata API Operations @admin @photos @metadata @api', () => {
    test('should update photo title via API', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `TitleUpdate_Test_${Date.now()}`
        );

        try {
            const newTitle = `Updated Title ${Date.now()}`;

            // Update title
            const updateResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setInfo',
                    image_id: imageId,
                    name: newTitle,
                    single_value_mode: 'replace',
                    pwg_token: token,
                },
            });

            if (!updateResp.ok()) {
                throw new Error(`API request failed with status ${updateResp.status()}`);
            }
            expect((await updateResp.json()).stat).toBe('ok');

            // Verify update
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            if (!infoResp.ok()) {
                throw new Error(`API request failed with status ${infoResp.status()}`);
            }
            const info = await infoResp.json();
            expect(info.result.name).toBe(newTitle);
        } finally {
            await deleteTestAlbum(request, albumId, token);
        }
    });

    test('should update photo author via API', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `AuthorUpdate_Test_${Date.now()}`
        );

        try {
            const newAuthor = `Test Author ${Date.now()}`;

            // Update author
            const updateResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setInfo',
                    image_id: imageId,
                    author: newAuthor,
                    single_value_mode: 'replace',
                    pwg_token: token,
                },
            });

            if (!updateResp.ok()) {
                throw new Error(`API request failed with status ${updateResp.status()}`);
            }
            expect((await updateResp.json()).stat).toBe('ok');

            // Verify update
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            if (!infoResp.ok()) {
                throw new Error(`API request failed with status ${infoResp.status()}`);
            }
            const info = await infoResp.json();
            expect(info.result.author).toBe(newAuthor);
        } finally {
            await deleteTestAlbum(request, albumId, token);
        }
    });

    test('should update photo description via API', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `DescUpdate_Test_${Date.now()}`
        );

        try {
            const newDesc = `Test description updated at ${Date.now()}`;

            // Update description
            const updateResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setInfo',
                    image_id: imageId,
                    comment: newDesc,
                    single_value_mode: 'replace',
                    pwg_token: token,
                },
            });

            if (!updateResp.ok()) {
                throw new Error(`API request failed with status ${updateResp.status()}`);
            }
            expect((await updateResp.json()).stat).toBe('ok');

            // Verify update
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            if (!infoResp.ok()) {
                throw new Error(`API request failed with status ${infoResp.status()}`);
            }
            const info = await infoResp.json();
            expect(info.result.comment).toBe(newDesc);
        } finally {
            await deleteTestAlbum(request, albumId, token);
        }
    });

    test('should update photo privacy level via API', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `PrivacyUpdate_Test_${Date.now()}`
        );

        try {
            // Update to highest privacy level
            const updateResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setInfo',
                    image_id: imageId,
                    level: '8',
                    pwg_token: token,
                },
            });

            if (!updateResp.ok()) {
                throw new Error(`API request failed with status ${updateResp.status()}`);
            }
            expect((await updateResp.json()).stat).toBe('ok');

            // Verify update
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            if (!infoResp.ok()) {
                throw new Error(`API request failed with status ${infoResp.status()}`);
            }
            const info = await infoResp.json();
            expect(info.result.level).toBe('8');
        } finally {
            await deleteTestAlbum(request, albumId, token);
        }
    });

    test('should update multiple metadata fields at once via API', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `MultiUpdate_Test_${Date.now()}`
        );

        try {
            const updates = {
                name: `Multi Update ${Date.now()}`,
                author: 'Multi Test Author',
                comment: 'Multi field update test',
                level: '4',
            };

            // Update multiple fields
            const updateResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setInfo',
                    image_id: imageId,
                    ...updates,
                    single_value_mode: 'replace',
                    pwg_token: token,
                },
            });

            if (!updateResp.ok()) {
                throw new Error(`API request failed with status ${updateResp.status()}`);
            }
            expect((await updateResp.json()).stat).toBe('ok');

            // Verify all updates
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            if (!infoResp.ok()) {
                throw new Error(`API request failed with status ${infoResp.status()}`);
            }
            const info = await infoResp.json();
            expect(info.result.name).toBe(updates.name);
            expect(info.result.author).toBe(updates.author);
            expect(info.result.comment).toBe(updates.comment);
            expect(info.result.level).toBe(updates.level);
        } finally {
            await deleteTestAlbum(request, albumId, token);
        }
    });

    test('should get photo info via API', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `GetInfo_Test_${Date.now()}`
        );

        try {
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            if (!infoResp.ok()) {
                throw new Error(`API request failed with status ${infoResp.status()}`);
            }
            const info = await infoResp.json();
            expect(info.stat).toBe('ok');
            expect(String(info.result.id)).toBe(String(imageId));
            expect(info.result.name).toBeDefined();
            expect(info.result.file).toBeDefined();
            expect(info.result.width).toBeDefined();
            expect(info.result.height).toBeDefined();
        } finally {
            await deleteTestAlbum(request, albumId, token);
        }
    });
});

test.describe.serial('Photo Album Association @admin @photos @metadata @association', () => {
    test('should associate photo with multiple albums via API', async ({ request }) => {
        // Create source album with photo
        const {
            albumId: album1Id,
            imageId,
            token,
        } = await createTestAlbumWithPhoto(request, `MultiAlbum1_${Date.now()}`);

        // Create second album (uses same cached token/session)
        const { id: album2Id } = await createTestAlbum(request, `MultiAlbum2_${Date.now()}`);

        try {
            // Associate with second album using pwg.images.setCategory
            const assocResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setCategory',
                    'image_id[]': String(imageId),
                    category_id: String(album2Id),
                    action: 'associate',
                    pwg_token: token,
                },
            });

            if (!assocResp.ok()) {
                throw new Error(`API request failed with status ${assocResp.status()}`);
            }
            const assocJson = await assocResp.json();
            expect(assocJson.stat).toBe('ok');

            // Small delay to allow database to update
            await new Promise((resolve) => setTimeout(resolve, ANIMATION_TIMEOUTS.short));

            // Verify photo appears in both albums
            const album2ImagesResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.categories.getImages',
                    cat_id: album2Id,
                    per_page: 100,
                },
            });

            if (!album2ImagesResp.ok()) {
                throw new Error(`API request failed with status ${album2ImagesResp.status()}`);
            }
            const album2Images = await album2ImagesResp.json();
            expect(album2Images.stat).toBe('ok');
            expect(album2Images.result.images).toBeDefined();
            const imageInAlbum2 = album2Images.result.images.some(
                (img: any) => String(img.id) === String(imageId)
            );
            expect(imageInAlbum2).toBe(true);
        } finally {
            // Cleanup both albums
            await deleteTestAlbum(request, album1Id, token, true);
            await deleteTestAlbum(request, album2Id, token, false);
        }
    });
});
