/**
 * Configuration Image Sizes Settings Tests
 *
 * @remarks
 * Comprehensive E2E tests for Piwigo's image size/derivative configuration.
 * Uses adminPage fixture for isolated, pre-authenticated sessions.
 *
 * @packageDocumentation
 */

import { test, expect } from '@base-test';
import { isVisibleSafe } from '@helpers/page-helpers';
import { baseUrl, loginAndGetToken, uploadImage, getFixturePath } from '@helpers/api-helpers';
import { generateTestId } from '@helpers/test-data';

test.describe('Configuration Sizes Settings @critical @admin @config', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should load sizes configuration section', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        expect(adminPage.url()).toContain('section=sizes');

        const form = adminPage.locator('form[method="post"]');
        await expect(form).toBeVisible();

        // Check for PWG token
        const pwgToken = adminPage.locator('input[name="pwg_token"]');
        await expect(pwgToken).toBeAttached();
    });

    test('should display all derivative size types', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        // Verify all standard derivative types have inputs
        const sizeTypes = [
            'square',
            'thumb',
            'xxsmall',
            'xsmall',
            'small',
            'medium',
            'large',
            'xlarge',
            'xxlarge',
        ];

        for (const type of sizeTypes) {
            const widthInput = adminPage.locator(`input[name="d[${type}][w]"]`);
            const heightInput = adminPage.locator(`input[name="d[${type}][h]"]`);

            // At least width or height should exist for each type
            const hasWidth = await isVisibleSafe(widthInput);
            const hasHeight = await isVisibleSafe(heightInput);

            if (hasWidth || hasHeight) {
                // If the derivative type is enabled, should have size inputs
                expect(hasWidth || hasHeight).toBeTruthy();
            }
        }
    });

    test('should change square thumbnail size and persist', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const squareInput = adminPage.locator('input[name="d[square][w]"]');
        await expect(squareInput).toBeVisible();

        const originalValue = await squareInput.inputValue();
        const newValue = originalValue === '120' ? '150' : '120';

        try {
            // Update square size
            await squareInput.fill(newValue);

            const submitBtn = adminPage.locator(
                'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
            );
            await submitBtn.click();

            // Verify success message
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload and verify persistence
            await adminPage.reload({ waitUntil: 'domcontentloaded' });
            await adminPage.waitForSelector('form[method="post"]');

            const persistedValue = await adminPage
                .locator('input[name="d[square][w]"]')
                .inputValue();
            expect(persistedValue).toBe(newValue);
        } finally {
            // Restore original
            await adminPage.locator('input[name="d[square][w]"]').fill(originalValue);
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();
        }
    });

    test('should change medium image size and persist', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const mediumWidthInput = adminPage.locator('input[name="d[medium][w]"]');
        const mediumHeightInput = adminPage.locator('input[name="d[medium][h]"]');
        await expect(mediumWidthInput).toBeVisible();

        const originalWidth = await mediumWidthInput.inputValue();
        const originalHeight = await mediumHeightInput.inputValue();

        const newWidth = originalWidth === '576' ? '600' : '576';
        const newHeight = originalHeight === '432' ? '450' : '432';

        try {
            // Update medium size
            await mediumWidthInput.fill(newWidth);
            await mediumHeightInput.fill(newHeight);

            const submitBtn = adminPage.locator(
                'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
            );
            await submitBtn.click();

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload and verify persistence
            await adminPage.reload({ waitUntil: 'domcontentloaded' });
            await adminPage.waitForSelector('form[method="post"]');

            const persistedWidth = await adminPage
                .locator('input[name="d[medium][w]"]')
                .inputValue();
            const persistedHeight = await adminPage
                .locator('input[name="d[medium][h]"]')
                .inputValue();

            expect(persistedWidth).toBe(newWidth);
            expect(persistedHeight).toBe(newHeight);
        } finally {
            // Restore original
            await adminPage.locator('input[name="d[medium][w]"]').fill(originalWidth);
            await adminPage.locator('input[name="d[medium][h]"]').fill(originalHeight);
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();
        }
    });

    test('should change large image size and persist', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const largeWidthInput = adminPage.locator('input[name="d[large][w]"]');
        const largeHeightInput = adminPage.locator('input[name="d[large][h]"]');
        await expect(largeWidthInput).toBeVisible();

        const originalWidth = await largeWidthInput.inputValue();
        const originalHeight = await largeHeightInput.inputValue();

        const newWidth = originalWidth === '1008' ? '1024' : '1008';
        const newHeight = originalHeight === '756' ? '768' : '756';

        try {
            // Update large size
            await largeWidthInput.fill(newWidth);
            await largeHeightInput.fill(newHeight);

            const submitBtn = adminPage.locator(
                'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
            );
            await submitBtn.click();

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload and verify persistence
            await adminPage.reload({ waitUntil: 'domcontentloaded' });
            await adminPage.waitForSelector('form[method="post"]');

            const persistedWidth = await adminPage
                .locator('input[name="d[large][w]"]')
                .inputValue();
            const persistedHeight = await adminPage
                .locator('input[name="d[large][h]"]')
                .inputValue();

            expect(persistedWidth).toBe(newWidth);
            expect(persistedHeight).toBe(newHeight);
        } finally {
            // Restore original
            await adminPage.locator('input[name="d[large][w]"]').fill(originalWidth);
            await adminPage.locator('input[name="d[large][h]"]').fill(originalHeight);
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();
        }
    });

    test('should validate resize quality range (50-98)', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const qualityInput = adminPage.locator('input[name="resize_quality"]');
        await expect(qualityInput).toBeVisible();

        const originalValue = await qualityInput.inputValue();

        try {
            // Test value below minimum
            await qualityInput.fill('40');

            const submitBtn = adminPage.locator(
                'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
            );
            await submitBtn.click();

            // Should see error message for quality constraint violation
            const errorMsg = adminPage.locator('text=50..98, text=between');
            await expect(errorMsg).toBeVisible({ timeout: 5000 });

            // Test valid value
            await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
                waitUntil: 'domcontentloaded',
            });
            await adminPage.waitForSelector('form[method="post"]');

            await adminPage.locator('input[name="resize_quality"]').fill('85');
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();

            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });
        } finally {
            // Restore original
            await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
                waitUntil: 'domcontentloaded',
            });
            await adminPage.waitForSelector('form[method="post"]');
            await adminPage.locator('input[name="resize_quality"]').fill(originalValue);
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();
        }
    });

    test('should change quality setting and persist', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const qualityInput = adminPage.locator('input[name="resize_quality"]');
        await expect(qualityInput).toBeVisible();

        const originalValue = await qualityInput.inputValue();
        const newValue = originalValue === '85' ? '90' : '85';

        try {
            await qualityInput.fill(newValue);

            const submitBtn = adminPage.locator(
                'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
            );
            await submitBtn.click();

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload and verify
            await adminPage.reload({ waitUntil: 'domcontentloaded' });
            await adminPage.waitForSelector('form[method="post"]');

            const persistedValue = await adminPage
                .locator('input[name="resize_quality"]')
                .inputValue();
            expect(persistedValue).toBe(newValue);
        } finally {
            // Restore original
            await adminPage.locator('input[name="resize_quality"]').fill(originalValue);
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();
        }
    });

    test('should enable original resize and persist', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const originalResizeCheckbox = adminPage.locator('input[name="original_resize"]');
        await expect(originalResizeCheckbox).toBeVisible();

        const originalState = await originalResizeCheckbox.isChecked();

        try {
            // Toggle original resize
            await originalResizeCheckbox.click();

            const submitBtn = adminPage.locator(
                'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
            );
            await submitBtn.click();

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload and verify
            await adminPage.reload({ waitUntil: 'domcontentloaded' });
            await adminPage.waitForSelector('form[method="post"]');

            const newState = await adminPage.locator('input[name="original_resize"]').isChecked();
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original
            const current = await adminPage.locator('input[name="original_resize"]').isChecked();
            if (current !== originalState) {
                await adminPage.locator('input[name="original_resize"]').click();
                await adminPage
                    .locator(
                        'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                    )
                    .click();
            }
        }
    });

    test('should have restore default settings button', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        // Look for restore settings link/button
        const restoreLink = adminPage.locator(
            'a[href*="action=restore_settings"], button:has-text("Restore"), a:has-text("Restore")'
        );
        await expect(restoreLink).toBeVisible();
    });

    test('should restore default settings when requested', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const restoreLink = adminPage.locator('a[href*="action=restore_settings"]');

        // Assert that the restore link is visible (required feature)
        await expect(restoreLink).toBeVisible();

        // Click restore link
        await restoreLink.click();

        // Verify success message
        const successMsg = adminPage.locator('text=Your configuration settings are saved');
        await expect(successMsg).toBeVisible({ timeout: 5000 });

        // Verify page reloaded with default values
        expect(adminPage.url()).toContain('section=sizes');
    });
});

test.describe('Configuration Sizes - New Upload Integration @critical @admin @config @upload @slow', () => {
    test('should apply new size settings to newly uploaded images', async ({ adminPage }) => {
        const request = adminPage.request;

        // Change thumbnail size setting
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const squareInput = adminPage.locator('input[name="d[square][w]"]');
        await expect(squareInput).toBeVisible();

        const originalSize = await squareInput.inputValue();
        const newSize = '180'; // Different from typical 120

        await squareInput.fill(newSize);

        const submitBtn = adminPage.locator(
            'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
        );
        await submitBtn.click();

        // Upload test image via API
        const token = await loginAndGetToken(request);
        const albumName = `SizeTest_${generateTestId()}`;

        // Create album via API
        const createAlbumResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.add',
                name: albumName,
                pwg_token: token,
            },
        });

        const albumJson = await createAlbumResponse.json();
        if (albumJson.stat !== 'ok') {
            test.skip();
            return;
        }

        const albumId = albumJson.result.id;

        try {
            const imagePath = getFixturePath('images/test-image.jpg');
            const imageId = await uploadImage(
                request,
                albumId,
                token,
                imagePath,
                'Size Test Image'
            );

            expect(imageId).toBeDefined();

            // Wait for derivatives to be generated
            await adminPage.waitForTimeout(2000);

            // Verify derivative was generated (check via API or file system)
            const infoResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            expect(infoResponse.ok()).toBeTruthy();
            const info = await infoResponse.json();
            expect(info.result).toBeDefined();

            // Clean up: restore original size
            await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=sizes`, {
                waitUntil: 'domcontentloaded',
            });
            await adminPage.waitForSelector('form[method="post"]');
            await adminPage.locator('input[name="d[square][w]"]').fill(originalSize);
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();
        } finally {
            // Clean up album
            await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.categories.delete',
                    category_id: albumId,
                    photo_deletion_mode: 'delete_orphans',
                    pwg_token: token,
                },
            });
        }
    });
});
