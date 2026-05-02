/**
 * Album Management Tests
 *
 * @remarks
 * Comprehensive tests for album management via UI and API.
 * Uses adminPage fixture for isolated, pre-authenticated sessions.
 */

import { test, expect } from '@base-test';
import { AlbumsPage } from '../../pages/admin/AlbumsPage';
import { TEST_DATA } from '@helpers/test-data';
import { loginAndGetToken } from '@helpers/api-helpers';

test.describe('Album Management @admin @albums', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should display albums page @smoke', async ({ adminPage }) => {
        const albumsPage = new AlbumsPage(adminPage);
        await albumsPage.goto();
        await albumsPage.expectAlbumsPage();
        await expect(albumsPage.albumTree).toBeVisible();
    });

    test('should display add album button', async ({ adminPage }) => {
        const albumsPage = new AlbumsPage(adminPage);
        await albumsPage.goto();
        await expect(albumsPage.addAlbumButton).toBeVisible();
    });

    test('should open add album modal when clicking add button', async ({ adminPage }) => {
        const albumsPage = new AlbumsPage(adminPage);
        await albumsPage.goto();
        // Wait for button to be visible and clickable before clicking
        await expect(albumsPage.addAlbumButton).toBeVisible({ timeout: 15000 });
        await albumsPage.addAlbumButton.click();
        await expect(albumsPage.addAlbumModal).toBeVisible();
        await expect(albumsPage.addAlbumNameInput).toBeVisible();
    });

    test('should close add album modal when clicking cancel', async ({ adminPage }) => {
        const albumsPage = new AlbumsPage(adminPage);
        await albumsPage.goto();
        // Wait for button to be visible and clickable before clicking
        await expect(albumsPage.addAlbumButton).toBeVisible({ timeout: 15000 });
        await albumsPage.addAlbumButton.click();
        await expect(albumsPage.addAlbumModal).toBeVisible();
        await albumsPage.addAlbumCancel.click();
        await expect(albumsPage.addAlbumModal).not.toBeVisible();
    });
});

test.describe('Album UI Creation @admin @albums @critical', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session
    const baseUrl = TEST_DATA.baseUrl;

    test('should create a new album via UI @critical', async ({ adminPage }) => {
        const albumsPage = new AlbumsPage(adminPage);
        await albumsPage.goto();

        const albumName = `E2E_Test_Album_${Date.now()}`;

        await albumsPage.createAlbum(albumName);

        // Verify album appears in tree
        const albumExists = await albumsPage.albumExists(albumName);
        expect(albumExists).toBe(true);

        // Cleanup via API
        const request = adminPage.request;
        const loginResp = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.session.login',
                username: TEST_DATA.admin.username,
                password: TEST_DATA.admin.password,
            },
        });
        expect(loginResp.ok()).toBeTruthy();

        const statusResp = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: { method: 'pwg.session.getStatus' },
        });
        expect(statusResp.ok()).toBeTruthy();
        const statusJson = await statusResp.json();
        expect(statusJson.stat).toBe('ok');
        const token = statusJson.result.pwg_token;
        expect(token).toBeDefined();

        const listResp = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: { method: 'pwg.categories.getList' },
        });
        expect(listResp.ok()).toBeTruthy();
        const listJson = await listResp.json();
        const categories = (await listJson).result.categories || [];
        expect(Array.isArray(categories)).toBe(true);

        const testAlbum = categories.find((c: any) => c.name === albumName);
        expect(testAlbum).toBeDefined(); // Created album should be found in category list;

        if (testAlbum) {
            const deleteResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.categories.delete',
                    category_id: testAlbum.id,
                    photo_deletion_mode: 'no_delete',
                    pwg_token: token,
                },
            });
            expect(deleteResp.ok()).toBeTruthy();
        }
    });
});

test.describe('Album API Operations @admin @albums @api', () => {
    const baseUrl = TEST_DATA.baseUrl;

    test('should create album via API', async ({ request }) => {
        // Login and get token
        const pwgToken = await loginAndGetToken(request);

        // Create album
        const albumName = `API_Test_Album_${Date.now()}`;
        const createResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.add',
                name: albumName,
                pwg_token: pwgToken,
            },
        });

        expect(createResponse.ok()).toBeTruthy();
        const createJson = await createResponse.json();
        expect(createJson.stat).toBe('ok');
        expect(createJson.result).toBeDefined();
        expect(createJson.result.id).toBeDefined();
        expect(typeof createJson.result.id).toBe('number');

        const albumId = createJson.result.id;

        // Cleanup: Delete the album
        const deleteResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.delete',
                category_id: albumId,
                photo_deletion_mode: 'no_delete',
                pwg_token: pwgToken,
            },
        });
        expect(deleteResponse.ok()).toBeTruthy();
    });

    test('should get album list via API', async ({ request }) => {
        // Login and verify session
        await loginAndGetToken(request);

        // Get album list
        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.getList',
            },
        });

        expect(response.ok()).toBeTruthy();
        const json = await response.json();
        expect(json.stat).toBe('ok');
        expect(Array.isArray(json.result.categories)).toBe(true);
    });

    test('should update album via API', async ({ request }) => {
        // Login and get token
        const pwgToken = await loginAndGetToken(request);

        // Create album
        const originalName = `API_Update_Test_${Date.now()}`;
        const createResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.add',
                name: originalName,
                pwg_token: pwgToken,
            },
        });
        expect(createResponse.ok()).toBeTruthy();
        const albumId = (await createResponse.json()).result.id;

        // Update album
        const newName = `API_Updated_${Date.now()}`;
        const updateResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.setInfo',
                category_id: albumId,
                name: newName,
                pwg_token: pwgToken,
            },
        });

        expect(updateResponse.ok()).toBeTruthy();

        const updateJson = await updateResponse.json();
        expect(updateJson.stat).toBe('ok');

        // Cleanup
        await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.delete',
                category_id: albumId,
                photo_deletion_mode: 'no_delete',
                pwg_token: pwgToken,
            },
        });
    });

    test('should delete album via API', async ({ request }) => {
        // Login and get token
        const pwgToken = await loginAndGetToken(request);

        // Create album to delete
        const albumName = `API_Delete_Test_${Date.now()}`;
        const createResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.add',
                name: albumName,
                pwg_token: pwgToken,
            },
        });
        expect(createResponse.ok()).toBeTruthy();
        const albumId = (await createResponse.json()).result.id;

        // Delete album
        const deleteResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.delete',
                category_id: albumId,
                photo_deletion_mode: 'no_delete',
                pwg_token: pwgToken,
            },
        });

        expect(deleteResponse.ok()).toBeTruthy();

        // Verify deletion (should return ok or empty response)
        expect(deleteResponse.ok()).toBeTruthy();

        // Verify album no longer exists
        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.getList',
                cat_id: albumId,
            },
        });
        expect(listResponse.ok()).toBeTruthy();
        const listJson = await listResponse.json();
        // Album should not be in the list or list should be empty
        const categories = listJson.result?.categories || [];
        const albumStillExists = categories.some((cat: any) => cat.id === albumId);
        expect(albumStillExists).toBe(false);
    });
});
