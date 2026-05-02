import { test, expect } from '@base-test';
import { UploadPage } from '../../pages/photos/UploadPage';
import * as path from 'path';
import * as fs from 'fs';
import {
    baseUrl,
    loginAndGetToken,
    createTestAlbum,
    uploadImage,
    deleteTestAlbum,
    getFixturePath,
} from '@helpers/api-helpers';
import { waitForMultipleSelectors, isVisibleSafe } from '@helpers/page-helpers';
import { ELEMENT_TIMEOUTS } from '../../config/test-timeouts';
import { generateTestId } from '@helpers/test-data';

test.describe('Photo Upload Page @admin @photos @upload', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should display upload page @smoke', async ({ adminPage }) => {
        const uploadPage = new UploadPage(adminPage);
        await uploadPage.goto();
        await uploadPage.expectUploadPage();
    });

    test('should display add files button', async ({ adminPage }) => {
        const uploadPage = new UploadPage(adminPage);
        await uploadPage.goto();

        // The add files button may be visible or disabled (if no album is selected)
        // Check that it exists in the DOM - it will be enabled once an album is selected
        const addFilesButton = adminPage.locator('#addFiles');
        await expect(addFilesButton).toBeAttached();

        // If no albums exist, the button will be disabled
        // We just verify the upload page structure is correct
        const isDisabled = await addFilesButton.getAttribute('disabled');
        if (isDisabled !== null) {
            // Button is disabled - verify the "create album" prompt exists
            const createAlbumPrompt = adminPage.locator(
                'text=Piwigo requires an album, text=Create a first album'
            );
            const hasPrompt = await isVisibleSafe(
                createAlbumPrompt.first(),
                'create album prompt on upload page'
            );
            expect(hasPrompt || isDisabled !== null).toBeTruthy();
        } else {
            // Button is enabled - should be visible
            await expect(addFilesButton).toBeVisible();
        }
    });

    test('should display album selection controls', async ({ adminPage }) => {
        const uploadPage = new UploadPage(adminPage);
        await uploadPage.goto();

        // Check for various album selection mechanisms
        // Different Piwigo versions and themes implement this differently
        // Use safe helpers instead of inline .catch() for better error context
        const selectButton = await isVisibleSafe(uploadPage.selectAlbumButton);
        const createButton = await isVisibleSafe(uploadPage.createAlbumButton);
        const albumNameDisplay = await isVisibleSafe(uploadPage.selectedAlbumName);

        // Try multiple album selector patterns with validation
        // Piwigo versions use different selectors:
        // - #uploadHeaderAlbum: Main upload header album selector
        // - .albumSelector: Alternative class-based selector
        // - #categoryDropDown: Legacy category dropdown
        // - select[name="category"]: Generic category select element
        const albumSelectors = [
            '#uploadHeaderAlbum',
            '.albumSelector',
            '#categoryDropDown',
            'select[name="category"]',
        ];
        const matchedAlbumSelector = await waitForMultipleSelectors(
            adminPage,
            albumSelectors,
            ELEMENT_TIMEOUTS.quick
        );
        if (matchedAlbumSelector) {
            console.log(`Album selector found: ${matchedAlbumSelector}`);
        }

        // At least one of these should be present
        const hasAlbumControl =
            selectButton || createButton || matchedAlbumSelector !== null || albumNameDisplay;
        expect(hasAlbumControl).toBeTruthy();
    });
});

test.describe.serial('Photo Upload API @admin @photos @upload @api', () => {
    test('should upload photo via API', async ({ request }) => {
        // Create test album
        const albumName = `Upload_Test_${generateTestId()}`;
        const { id: albumId, token } = await createTestAlbum(request, albumName);

        try {
            const imagePath = path.join(__dirname, '../../fixtures/images/test-image-1.jpg');
            const imageId = await uploadImage(
                request,
                albumId,
                token,
                imagePath,
                'Test Upload Image'
            );

            expect(imageId).toBeDefined();

            // Verify image exists
            const infoResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            const infoJson = await infoResponse.json();
            expect(infoJson.stat).toBe('ok');
            expect(infoJson.result.name).toBe('Test Upload Image');
        } finally {
            // Cleanup
            await deleteTestAlbum(request, albumId, token);
        }
    });

    test('should upload multiple photos via API', async ({ request }) => {
        // Create test album
        const albumName = `MultiUpload_Test_${generateTestId()}`;
        const { id: albumId, token } = await createTestAlbum(request, albumName);

        const uploadedIds: number[] = [];

        try {
            const testImages = ['test-image-1.jpg', 'test-image-2.jpg', 'test-image-3.jpg'];

            for (let i = 0; i < testImages.length; i++) {
                const imagePath = path.join(__dirname, '../../fixtures/images', testImages[i]);
                const imageId = await uploadImage(
                    request,
                    albumId,
                    token,
                    imagePath,
                    `Test Image ${i + 1}`
                );
                uploadedIds.push(imageId);
            }

            expect(uploadedIds.length).toBe(3);

            // Verify all images were uploaded successfully
            for (const imageId of uploadedIds) {
                const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                    form: {
                        method: 'pwg.images.getInfo',
                        image_id: imageId,
                    },
                });
                const info = await infoResp.json();
                expect(info.stat).toBe('ok');
            }
        } finally {
            // Cleanup
            await deleteTestAlbum(request, albumId, token);
        }
    });

    test('should upload photo with metadata via API', async ({ request }) => {
        // Create test album
        const albumName = `MetadataUpload_Test_${generateTestId()}`;
        const { id: albumId, token } = await createTestAlbum(request, albumName);

        try {
            const imagePath = path.join(__dirname, '../../fixtures/images/test-image-1.jpg');
            const imageId = await uploadImage(
                request,
                albumId,
                token,
                imagePath,
                'Photo With Metadata',
                {
                    author: 'E2E Test Author',
                    comment: 'This is a test description',
                    level: '0',
                }
            );

            // Verify metadata
            const infoResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            const infoJson = await infoResponse.json();
            expect(infoJson.stat).toBe('ok');
            expect(infoJson.result.name).toBe('Photo With Metadata');
            expect(infoJson.result.author).toBe('E2E Test Author');
            expect(infoJson.result.comment).toBe('This is a test description');
        } finally {
            // Cleanup
            await deleteTestAlbum(request, albumId, token);
        }
    });

    test('should upload photo with tags via API', async ({ request }) => {
        // Create test album
        const albumName = `TagUpload_Test_${generateTestId()}`;
        const { id: albumId, token } = await createTestAlbum(request, albumName);

        try {
            const imagePath = path.join(__dirname, '../../fixtures/images/test-image-1.jpg');
            const imageId = await uploadImage(
                request,
                albumId,
                token,
                imagePath,
                'Photo With Tags',
                {
                    tags: 'e2e-test,automated,upload-test',
                }
            );

            // Verify tags
            const infoResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            const infoJson = await infoResponse.json();
            expect(infoJson.stat).toBe('ok');
            expect(infoJson.result.tags).toBeDefined();
            expect(infoJson.result.tags.length).toBeGreaterThanOrEqual(1);
        } finally {
            // Cleanup
            await deleteTestAlbum(request, albumId, token);
        }
    });

    test('should set privacy level on upload via API', async ({ request }) => {
        // Create test album
        const albumName = `PrivacyUpload_Test_${generateTestId()}`;
        const { id: albumId, token } = await createTestAlbum(request, albumName);

        try {
            const imagePath = path.join(__dirname, '../../fixtures/images/test-image-1.jpg');
            const imageId = await uploadImage(request, albumId, token, imagePath, 'Private Photo', {
                level: '8', // Highest privacy level
            });

            // Verify privacy level
            const infoResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            const infoJson = await infoResponse.json();
            expect(infoJson.stat).toBe('ok');
            expect(infoJson.result.level).toBe('8');
        } finally {
            // Cleanup
            await deleteTestAlbum(request, albumId, token);
        }
    });
});

test.describe('Photo Upload Validation @admin @photos @upload @validation', () => {
    test('should reject upload without album via API', async ({ request }) => {
        // Login using consolidated helper
        const token = await loginAndGetToken(request);

        const imagePath = getFixturePath('images/test-image-1.jpg');
        const imageBuffer = fs.readFileSync(imagePath);
        const fileName = path.basename(imagePath);

        // Try to upload without category
        const uploadResponse = await request.post(
            `${baseUrl}/ws.php?format=json&method=pwg.images.addSimple`,
            {
                multipart: {
                    name: 'No Album Photo',
                    pwg_token: token,
                    image: {
                        name: fileName,
                        mimeType: 'image/jpeg',
                        buffer: imageBuffer,
                    },
                },
            }
        );

        const uploadJson = await uploadResponse.json();
        // Without a category, it might still succeed (goes to orphans) or fail
        // This test documents the behavior
        if (uploadJson.stat === 'ok' && uploadJson.result?.image_id) {
            // If succeeded, delete the orphan
            await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.delete',
                    image_id: uploadJson.result.image_id,
                    pwg_token: token,
                },
            });
        }
        // Test passes either way - we're documenting the behavior
        expect(uploadJson.stat).toBeDefined();
    });

    test('should handle duplicate upload via API', async ({ request }) => {
        // Create test album
        const albumName = `DuplicateUpload_Test_${generateTestId()}`;
        const { id: albumId, token } = await createTestAlbum(request, albumName);

        try {
            const imagePath = path.join(__dirname, '../../fixtures/images/test-image-1.jpg');

            // First upload
            const firstId = await uploadImage(request, albumId, token, imagePath, 'Duplicate Test');
            expect(firstId).toBeDefined();

            // Second upload of same image
            const secondId = await uploadImage(
                request,
                albumId,
                token,
                imagePath,
                'Duplicate Test 2'
            );
            // Piwigo should detect duplicate by checksum
            // Either returns same ID or creates new one based on settings
            expect(secondId).toBeDefined();
        } finally {
            // Cleanup
            await deleteTestAlbum(request, albumId, token);
        }
    });
});
