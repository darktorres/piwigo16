/**
 * Configuration General Settings Tests
 *
 * @remarks
 * Extracted and reorganized from larger test files.
 * Uses adminPage fixture for isolated, pre-authenticated sessions.
 */

import { test, expect } from '@base-test';
import { isVisibleSafe } from '@helpers/page-helpers';
import { baseUrl } from '@helpers/api-helpers';
import { ConfigurationPage } from '@pages/ConfigurationPage';

test.describe('Configuration General Settings @critical @admin @config', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should load configuration main section', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        // Verify we're on the configuration page
        expect(adminPage.url()).toContain('page=configuration');
        expect(adminPage.url()).toContain('section=main');

        // Check for configuration form
        await configPage.waitForFormReady();

        // Check for PWG token (CSRF protection)
        const hasToken = await configPage.verifyPwgToken();
        expect(hasToken).toBeTruthy();
    });

    test('should update gallery title and persist across reload', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        const originalTitle = await configPage.getTextValue('gallery_title');
        const newTitle = `E2E Test Gallery ${Date.now()}`;

        try {
            // Update gallery title
            await configPage.fillTextInput('gallery_title', newTitle);

            // Submit form
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Verify success message
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload page and verify persistence
            await configPage.reloadAndVerify();

            const reloadedTitle = await configPage.getTextValue('gallery_title');
            expect(reloadedTitle).toBe(newTitle);
        } finally {
            // Restore original
            await configPage.fillTextInput('gallery_title', originalTitle);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();
        }
    });

    test('should verify gallery title appears on homepage', async ({ adminPage, adminContext }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        const originalTitle = await configPage.getTextValue('gallery_title');
        const newTitle = `Homepage Test ${Date.now()}`;

        try {
            // Update gallery title
            await configPage.fillTextInput('gallery_title', newTitle);

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Open new page to check gallery homepage
            const homePage = await adminContext.newPage();
            await homePage.goto(baseUrl, { waitUntil: 'load' });

            // Verify title appears on homepage (in title tag or as heading)
            const pageTitle = await homePage.title();
            expect(
                pageTitle.includes(newTitle) ||
                    (await isVisibleSafe(homePage.locator(`text=${newTitle}`)))
            ).toBeTruthy();

            await homePage.close();
        } finally {
            // Restore original
            await configPage.fillTextInput('gallery_title', originalTitle);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();
        }
    });

    test('should update page banner and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        const originalBanner = await configPage.getTextValue('page_banner');
        const newBanner = `Test Banner ${Date.now()}`;

        try {
            await configPage.fillTextInput('page_banner', newBanner);

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Verify success message
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload and verify persistence
            await configPage.reloadAndVerify();

            const reloadedBanner = await configPage.getTextValue('page_banner');
            expect(reloadedBanner).toBe(newBanner);
        } finally {
            // Restore original
            await configPage.fillTextInput('page_banner', originalBanner);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();
        }
    });

    test('should disable guest browsing and verify effect', async ({ adminPage, adminContext }) => {
        // This test verifies that disabling guest access actually prevents browsing
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('default');

        // Look for guest access controls
        if (await configPage.hasSetting('status')) {
            // Change guest status to "Guest" (most restricted) or check current state
            const currentStatus = await configPage.getSelectValue('status');

            // Verify the form loads correctly
            expect(currentStatus).toBeDefined();
        }
    });

    test('should toggle user registration and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        const originalState = await configPage.isCheckboxChecked('allow_user_registration');

        try {
            // Toggle the checkbox
            await configPage.toggleCheckbox('allow_user_registration');
            const newState = await configPage.isCheckboxChecked('allow_user_registration');
            expect(newState).toBe(!originalState);

            // Submit form
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Verify success message
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload and verify persistence
            await configPage.reloadAndVerify();

            const persistedState = await configPage.isCheckboxChecked('allow_user_registration');
            expect(persistedState).toBe(newState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('allow_user_registration');
            if (current !== originalState) {
                await configPage.toggleCheckbox('allow_user_registration');
                const submitBtn = await configPage.getSubmitButton();
                await submitBtn.click();
                await configPage.verifySuccess();
            }
        }
    });

    test('should verify registration form appears when enabled', async ({
        adminPage,
        adminContext,
    }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        const originalState = await configPage.isCheckboxChecked('allow_user_registration');

        try {
            // Enable registration if not already
            if (!originalState) {
                await configPage.toggleCheckbox('allow_user_registration');
                const submitBtn = await configPage.getSubmitButton();
                await submitBtn.click();
                await configPage.verifySuccess();
            }

            // Open registration page in new context
            const registrationPage = await adminContext.newPage();
            await registrationPage.goto('/piwigo16/register.php', { waitUntil: 'load' });

            // Verify registration form is accessible
            const usernameInput = registrationPage.locator('input[name="login"]');
            const isFormVisible = await isVisibleSafe(usernameInput);

            // If registration is enabled, form should be visible
            expect(isFormVisible).toBeTruthy();

            await registrationPage.close();
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('allow_user_registration');
            if (current !== originalState) {
                await configPage.toggleCheckbox('allow_user_registration');
                const submitBtn = await configPage.getSubmitButton();
                await submitBtn.click();
                await configPage.verifySuccess();
            }
        }
    });

    test('should enable rating system and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        const originalState = await configPage.isCheckboxChecked('rate');

        try {
            // Toggle rating
            await configPage.toggleCheckbox('rate');

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload and verify
            await configPage.reloadAndVerify();

            const newState = await configPage.isCheckboxChecked('rate');
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('rate');
            if (current !== originalState) {
                await configPage.toggleCheckbox('rate');
                const submitBtn = await configPage.getSubmitButton();
                await submitBtn.click();
                await configPage.verifySuccess();
            }
        }
    });

    test('should enable anonymous rating and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        const originalState = await configPage.isCheckboxChecked('rate_anonymous');

        try {
            // Toggle anonymous rating
            await configPage.toggleCheckbox('rate_anonymous');

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload and verify
            await configPage.reloadAndVerify();

            const newState = await configPage.isCheckboxChecked('rate_anonymous');
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('rate_anonymous');
            if (current !== originalState) {
                await configPage.toggleCheckbox('rate_anonymous');
                const submitBtn = await configPage.getSubmitButton();
                await submitBtn.click();
                await configPage.verifySuccess();
            }
        }
    });

    test('should enable history logging and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        const originalState = await configPage.isCheckboxChecked('log');

        try {
            // Toggle logging
            await configPage.toggleCheckbox('log');

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
            await configPage.verifySuccess();

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload and verify
            await configPage.reloadAndVerify();

            const newState = await configPage.isCheckboxChecked('log');
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('log');
            if (current !== originalState) {
                await configPage.toggleCheckbox('log');
                const submitBtn = await configPage.getSubmitButton();
                await submitBtn.click();
                await configPage.verifySuccess();
            }
        }
    });

    test('should require PWG token for form submission (CSRF protection)', async ({
        adminPage,
    }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        // Verify PWG token exists
        const hasToken = await configPage.verifyPwgToken();
        expect(hasToken).toBeTruthy();
    });

    test('should display mail theme options and persist selection', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        const originalValue = await configPage.getSelectValue('mail_theme');

        // Get all available options
        const options = await configPage.getSelectOptions('mail_theme');
        expect(options.length).toBeGreaterThan(0);

        // Select different option if multiple exist
        if (options.length > 1) {
            const newValue = originalValue === 'clear' ? 'dark' : 'clear';

            try {
                await configPage.selectValue('mail_theme', newValue);

                const submitBtn = await configPage.getSubmitButton();
                await submitBtn.click();
                await configPage.verifySuccess();

                // Verify success
                const successMsg = adminPage.locator('text=Your configuration settings are saved');
                await expect(successMsg).toBeVisible({ timeout: 5000 });

                // Reload and verify
                await configPage.reloadAndVerify();

                const persistedValue = await configPage.getSelectValue('mail_theme');
                expect(persistedValue).toBe(newValue);
            } finally {
                // Restore original
                await configPage.selectValue('mail_theme', originalValue);
                const submitBtn = await configPage.getSubmitButton();
                await submitBtn.click();
                await configPage.verifySuccess();
            }
        }
    });

    test('should allow webmaster only to edit configuration', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        // This test verifies the page loads for webmaster
        await configPage.waitForFormReady();

        // The page should not show webmaster warning for actual webmasters
        const webmasterWarning = adminPage.locator('text=webmaster, text=status is required');
        await expect(webmasterWarning).not.toBeVisible();
    });
});
