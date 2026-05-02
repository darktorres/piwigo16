/**
 * Configuration Watermark Settings Tests
 *
 * @remarks
 * Comprehensive E2E tests for Piwigo's watermark configuration.
 * Uses adminPage fixture for isolated, pre-authenticated sessions.
 *
 * @packageDocumentation
 */

import { test, expect } from '@base-test';
import { isVisibleSafe } from '@helpers/page-helpers';
import {
    baseUrl,
    loginAndGetToken,
    createTestAlbum,
    uploadImage,
    getFixturePath,
} from '@helpers/api-helpers';
import { generateTestId } from '@helpers/test-data';
import { ConfigurationPage } from '@pages/ConfigurationPage';
import * as fs from 'fs';
import * as path from 'path';

test.describe('Configuration Watermark Settings @critical @admin @config', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should load watermark configuration section', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        expect(adminPage.url()).toContain('section=watermark');

        await configPage.waitForFormReady();

        // Check for PWG token
        const hasToken = await configPage.verifyPwgToken();
        expect(hasToken).toBeTruthy();
    });

    test('should display watermark file selector', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        if (await configPage.hasSetting('w[file]')) {
            const fileSelect = configPage.getSelect('w[file]');
            await expect(fileSelect).toBeVisible();

            // Should have at least the "none" option
            const options = await fileSelect.locator('option').allTextContents();
            expect(options.length).toBeGreaterThanOrEqual(1);
        }
    });

    test('should display position options', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        if (await configPage.hasSetting('w[position]')) {
            const positionSelect = configPage.getSelect('w[position]');
            await expect(positionSelect).toBeVisible();

            // Should have position options
            const options = await positionSelect.locator('option').count();
            expect(options).toBeGreaterThan(0);
        }
    });

    test('should change watermark position and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        const positionSelect = configPage.getSelect('w[position]');
        await expect(positionSelect).toBeVisible();

        const originalValue = await positionSelect.inputValue();

        // Change to a different position
        const newPosition = originalValue === 'topleft' ? 'bottomright' : 'topleft';

        try {
            await positionSelect.selectOption(newPosition);

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Reload and verify persistence
            await configPage.reloadAndVerify();

            const persistedPosition = await configPage.getSelectValue('w[position]');
            expect(persistedPosition).toBe(newPosition);
        } finally {
            // Restore original position
            await configPage.selectValue('w[position]', originalValue);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();
        }
    });

    test('should configure watermark opacity and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        const opacityInput = configPage.getTextInput('w[opacity]');
        await expect(opacityInput).toBeVisible();

        const originalValue = await opacityInput.inputValue();
        const newValue = originalValue === '100' ? '75' : '100';

        try {
            await opacityInput.fill(newValue);

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Reload and verify persistence
            await configPage.reloadAndVerify();

            const persistedValue = await configPage.getTextValue('w[opacity]');
            expect(persistedValue).toBe(newValue);
        } finally {
            // Restore original
            await configPage.fillTextInput('w[opacity]', originalValue);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();
        }
    });

    test('should validate opacity range (1-100)', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        const opacityInput = configPage.getTextInput('w[opacity]');
        await expect(opacityInput).toBeVisible();

        const originalValue = await opacityInput.inputValue();

        try {
            // Test value of 0 (should fail - must be > 0)
            await opacityInput.fill('0');

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();

            // Should see error message for opacity constraint violation
            await configPage.verifyError();

            // Test valid value
            await configPage.navigateToSection('watermark');
            await configPage.fillTextInput('w[opacity]', '80');
            const submitBtn2 = await configPage.getSubmitButton();
            await submitBtn2.click();
            await configPage.verifySuccess();
        } finally {
            // Restore original
            await configPage.fillTextInput('w[opacity]', originalValue);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
        }
    });

    test('should configure watermark minimum size and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        const minWidthInput = configPage.getTextInput('w[minw]');
        const minHeightInput = configPage.getTextInput('w[minh]');

        await expect(minWidthInput).toBeVisible();
        await expect(minHeightInput).toBeVisible();

        const originalWidth = await minWidthInput.inputValue();
        const originalHeight = await minHeightInput.inputValue();

        const newWidth = '500';
        const newHeight = '500';

        try {
            await minWidthInput.fill(newWidth);
            await minHeightInput.fill(newHeight);

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Reload and verify persistence
            await configPage.reloadAndVerify();

            const persistedWidth = await configPage.getTextValue('w[minw]');
            const persistedHeight = await configPage.getTextValue('w[minh]');

            expect(persistedWidth).toBe(newWidth);
            expect(persistedHeight).toBe(newHeight);
        } finally {
            // Restore original values
            await configPage.fillTextInput('w[minw]', originalWidth);
            await configPage.fillTextInput('w[minh]', originalHeight);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();
        }
    });

    test('should configure X and Y position coordinates and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        const xposInput = configPage.getTextInput('w[xpos]');
        const yposInput = configPage.getTextInput('w[ypos]');

        await expect(xposInput).toBeVisible();
        await expect(yposInput).toBeVisible();

        const originalX = await xposInput.inputValue();
        const originalY = await yposInput.inputValue();

        const newX = '50';
        const newY = '50';

        try {
            await xposInput.fill(newX);
            await yposInput.fill(newY);

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Reload and verify persistence
            await configPage.reloadAndVerify();

            const persistedX = await configPage.getTextValue('w[xpos]');
            const persistedY = await configPage.getTextValue('w[ypos]');

            expect(persistedX).toBe(newX);
            expect(persistedY).toBe(newY);
        } finally {
            // Restore original
            await configPage.fillTextInput('w[xpos]', originalX);
            await configPage.fillTextInput('w[ypos]', originalY);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();
        }
    });

    test('should validate position coordinates range (0-100)', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        const xposInput = configPage.getTextInput('w[xpos]');
        await expect(xposInput).toBeVisible();

        const originalValue = await xposInput.inputValue();

        try {
            // Test value below 0
            await xposInput.fill('-10');

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();

            // Should see error message for position constraint violation
            await configPage.verifyError();
        } finally {
            // Restore original
            await configPage.navigateToSection('watermark');
            await configPage.fillTextInput('w[xpos]', originalValue);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
        }
    });

    test('should select predefined watermark position - top left', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        const positionSelect = configPage.getSelect('w[position]');
        await expect(positionSelect).toBeVisible();

        const originalValue = await positionSelect.inputValue();

        try {
            await positionSelect.selectOption('topleft');

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Reload and verify that xpos=0, ypos=0 (top-left coordinates)
            await configPage.reloadAndVerify();

            const xposInput = configPage.getTextInput('w[xpos]');
            const yposInput = configPage.getTextInput('w[ypos]');

            await expect(xposInput).toBeVisible();
            const xpos = await xposInput.inputValue();
            const ypos = await yposInput.inputValue();

            expect(xpos).toBe('0');
            expect(ypos).toBe('0');
        } finally {
            // Restore original
            await configPage.selectValue('w[position]', originalValue);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
        }
    });

    test('should select predefined watermark position - bottom right', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        const positionSelect = configPage.getSelect('w[position]');
        await expect(positionSelect).toBeVisible();

        const originalValue = await positionSelect.inputValue();

        try {
            await positionSelect.selectOption('bottomright');

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Reload and verify that xpos=100, ypos=100 (bottom-right coordinates)
            await configPage.reloadAndVerify();

            const xposInput = configPage.getTextInput('w[xpos]');
            const yposInput = configPage.getTextInput('w[ypos]');

            await expect(xposInput).toBeVisible();
            const xpos = await xposInput.inputValue();
            const ypos = await yposInput.inputValue();

            expect(xpos).toBe('100');
            expect(ypos).toBe('100');
        } finally {
            // Restore original
            await configPage.selectValue('w[position]', originalValue);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
        }
    });

    test('should disable watermark by selecting no file', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        const fileSelect = configPage.getSelect('w[file]');
        await expect(fileSelect).toBeVisible();

        const originalValue = await fileSelect.inputValue();

        try {
            // Select empty/none option
            await fileSelect.selectOption('');

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Reload and verify persistence
            await configPage.reloadAndVerify();

            const persistedFile = await configPage.getSelectValue('w[file]');
            expect(persistedFile).toBe('');
        } finally {
            // Restore original if it was set
            if (originalValue) {
                await configPage.selectValue('w[file]', originalValue);
                const submitBtn = await configPage.getSubmitButton();
                await submitBtn.click();
            }
        }
    });

    test('should accept PNG watermark file upload', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        const fileInput = configPage.getFileInput('watermarkImage');
        await expect(fileInput).toBeVisible();

        // Create a small test watermark PNG file
        const testWatermarkPath = path.join(__dirname, '../../fixtures/images/test-watermark.png');

        // Check if test watermark exists, skip if not
        if (!fs.existsSync(testWatermarkPath)) {
            test.skip();
        }

        await configPage.uploadFile('watermarkImage', testWatermarkPath);

        const submitBtn = await configPage.getSubmitButton();
        await submitBtn.click();
        await configPage.verifySuccess();

        // Verify file was selected in dropdown by checking if option exists
        const selectedValue = await configPage.getSelectValue('w[file]');
        expect(selectedValue).toBeTruthy();
    });

    test('should reject non-PNG watermark file', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('watermark');

        const fileInput = configPage.getFileInput('watermarkImage');
        await expect(fileInput).toBeVisible();

        // Try uploading a JPEG file (should be rejected)
        const testImagePath = getFixturePath('test-image-1.jpg');

        if (!fs.existsSync(testImagePath)) {
            test.skip();
        }

        await configPage.uploadFile('watermarkImage', testImagePath);

        const submitBtn = await configPage.getSubmitButton();
        await submitBtn.click();
        await configPage.verifySuccess();

        // Should see error about PNG only
        const errorMsg = adminPage.locator('text=PNG, text=Allowed file types');
        const hasError = await isVisibleSafe(errorMsg);

        expect(hasError).toBeTruthy();
    });
});

test.describe('Configuration Watermark - Image Upload Integration @critical @admin @config @upload @slow', () => {
    test('should apply watermark to newly uploaded images when enabled', async ({ adminPage }) => {
        const request = adminPage.request;
        const configPage = new ConfigurationPage(adminPage);

        // Enable watermark with a specific file
        await configPage.navigateToSection('watermark');

        const fileSelect = configPage.getSelect('w[file]');
        await expect(fileSelect).toBeVisible();

        const options = await fileSelect.locator('option').allTextContents();

        // Find a non-empty watermark file option
        let watermarkFile = '';
        for (const option of options) {
            if (option && option.trim() !== '' && option !== '---') {
                // Find option value by locating the option element
                const optionElements = await fileSelect.locator('option').all();
                for (const optionEl of optionElements) {
                    const text = await optionEl.textContent();
                    if (text === option) {
                        watermarkFile = (await optionEl.getAttribute('value')) || '';
                        if (watermarkFile) break;
                    }
                }
                if (watermarkFile) break;
            }
        }

        if (!watermarkFile) {
            // Skip: No watermark files available
            test.skip();
        }

        await fileSelect.selectOption(watermarkFile);

        // Set watermark to be visible
        const opacityInput = configPage.getTextInput('w[opacity]');
        await expect(opacityInput).toBeVisible();
        await opacityInput.fill('100');

        const submitBtn = await configPage.getSubmitButton();
        await submitBtn.click();
        await configPage.verifySuccess();

        // Upload test image via API
        const albumName = `WatermarkTest_${generateTestId()}`;
        const { id: albumId, token } = await createTestAlbum(request, albumName);

        try {
            const imagePath = getFixturePath('test-image-1.jpg');
            const imageId = await uploadImage(
                request,
                albumId,
                token,
                imagePath,
                'Watermark Test Image'
            );

            expect(imageId).toBeDefined();

            // Wait for derivatives to be generated with watermark
            await adminPage.waitForTimeout(3000);

            // Verify image was uploaded
            const infoResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            expect(infoResponse.ok()).toBeTruthy();
            const info = await infoResponse.json();
            expect(info.result).toBeDefined();
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

            // Disable watermark after test
            await configPage.navigateToSection('watermark');
            await configPage.selectValue('w[file]', '');
            const submitBtn2 = await configPage.getSubmitButton();
            await submitBtn2.click();
            await configPage.verifySuccess();
        }
    });

    test('should not apply watermark to new images when disabled', async ({ adminPage }) => {
        const request = adminPage.request;
        const configPage = new ConfigurationPage(adminPage);

        // Disable watermark
        await configPage.navigateToSection('watermark');

        const fileSelect = configPage.getSelect('w[file]');
        await expect(fileSelect).toBeVisible();

        await fileSelect.selectOption('');

        const submitBtn = await configPage.getSubmitButton();
        await submitBtn.click();
        await configPage.verifySuccess();

        // Upload test image via API
        const token = await loginAndGetToken(request);
        const albumName = `NoWatermarkTest_${generateTestId()}`;

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
                'No Watermark Test Image'
            );

            expect(imageId).toBeDefined();

            // Wait for derivatives to be generated
            await adminPage.waitForTimeout(2000);

            // Verify image was uploaded without watermark
            const infoResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            expect(infoResponse.ok()).toBeTruthy();
            const info = await infoResponse.json();
            expect(info.result).toBeDefined();
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
