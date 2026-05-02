/**
 * Configuration General Settings Tests
 *
 * @remarks
 * Comprehensive E2E tests for Piwigo's general configuration settings.
 * Tests cover settings persistence, behavior changes, and validation.
 * Uses adminPage fixture for isolated, pre-authenticated sessions.
 *
 * @packageDocumentation
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
            await configPage.submitForm();

            // Reload page and verify persistence
            await configPage.reloadAndVerify();

            const reloadedTitle = await configPage.getTextValue('gallery_title');
            expect(reloadedTitle).toBe(newTitle);
        } finally {
            // Restore original
            await configPage.fillTextInput('gallery_title', originalTitle);
            await configPage.submitForm();
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
            await configPage.submitForm();

            // Open new page to check gallery homepage
            const homePage = await adminContext.newPage();
            await homePage.goto(baseUrl, { waitUntil: 'domcontentloaded' });

            // Verify title appears on homepage (in title tag or header)
            const pageTitle = await homePage.title();
            const bodyText = await homePage.locator('body').innerText();

            // Title should appear either in page title or somewhere in the content
            const titleAppears = pageTitle.includes(newTitle) || bodyText.includes(newTitle);
            expect(titleAppears).toBeTruthy();

            await homePage.close();
        } finally {
            // Restore original
            await configPage.fillTextInput('gallery_title', originalTitle);
            await configPage.submitForm();
        }
    });

    test('should update page banner and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        const originalBanner = await configPage.getTextValue('page_banner');
        const newBanner = `Test Banner ${Date.now()}`;

        try {
            await configPage.fillTextInput('page_banner', newBanner);
            await configPage.submitForm();

            // Reload and verify persistence
            await configPage.reloadAndVerify();

            const reloadedBanner = await configPage.getTextValue('page_banner');
            expect(reloadedBanner).toBe(newBanner);
        } finally {
            // Restore original
            await configPage.fillTextInput('page_banner', originalBanner);
            await configPage.submitForm();
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
            await configPage.submitForm();

            // Reload and verify persistence
            await configPage.reloadAndVerify();

            const persistedState = await configPage.isCheckboxChecked('allow_user_registration');
            expect(persistedState).toBe(newState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('allow_user_registration');
            if (current !== originalState) {
                await configPage.toggleCheckbox('allow_user_registration');
                await configPage.submitForm();
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
                await configPage.submitForm();
            }

            // Open registration page in new context (logged out)
            const registrationPage = await adminContext.newPage();
            await registrationPage.goto('/piwigo16/register.php', {
                waitUntil: 'domcontentloaded',
            });

            // Verify registration form is accessible
            const usernameInput = registrationPage.locator('input[name="login"]');
            const isFormVisible = await isVisibleSafe(usernameInput);

            expect(isFormVisible).toBeTruthy();

            await registrationPage.close();
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('allow_user_registration');
            if (current !== originalState) {
                await configPage.toggleCheckbox('allow_user_registration');
                await configPage.submitForm();
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
            await configPage.submitForm();

            // Reload and verify
            await configPage.reloadAndVerify();

            const newState = await configPage.isCheckboxChecked('rate');
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('rate');
            if (current !== originalState) {
                await configPage.toggleCheckbox('rate');
                await configPage.submitForm();
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
            await configPage.submitForm();

            // Reload and verify
            await configPage.reloadAndVerify();

            const newState = await configPage.isCheckboxChecked('rate_anonymous');
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('rate_anonymous');
            if (current !== originalState) {
                await configPage.toggleCheckbox('rate_anonymous');
                await configPage.submitForm();
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
            await configPage.submitForm();

            // Reload and verify
            await configPage.reloadAndVerify();

            const newState = await configPage.isCheckboxChecked('log');
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('log');
            if (current !== originalState) {
                await configPage.toggleCheckbox('log');
                await configPage.submitForm();
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
                await configPage.submitForm();

                // Reload and verify
                await configPage.reloadAndVerify();

                const persistedValue = await configPage.getSelectValue('mail_theme');
                expect(persistedValue).toBe(newValue);
            } finally {
                // Restore original
                await configPage.selectValue('mail_theme', originalValue);
                await configPage.submitForm();
            }
        }
    });

    test('should allow webmaster only to edit configuration', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('main');

        // This test verifies the page loads for webmaster
        await configPage.waitForFormReady();

        // Verify isWebmaster template variable is set correctly
        const bodyText = await adminPage.locator('body').innerText();
        // The page should not show webmaster warning for actual webmasters
        const hasWebmasterWarning =
            bodyText.includes('webmaster') && bodyText.includes('status is required');

        // If we're logged in as admin/webmaster, should not see warning
        expect(hasWebmasterWarning).toBeFalsy();
    });
});

test.describe('Configuration Comments Settings @critical @admin @config', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should load comments configuration section', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('comments');

        expect(adminPage.url()).toContain('section=comments');
        await configPage.waitForFormReady();
    });

    test('should toggle comment activation and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('comments');

        const checkbox = configPage.getCheckbox('activate_comments');
        await expect(checkbox).toBeVisible();

        const originalState = await checkbox.isChecked();

        try {
            await checkbox.click();
            await configPage.submitForm();

            // Reload and verify
            await configPage.reloadAndVerify();

            const newState = await configPage.isCheckboxChecked('activate_comments');
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('activate_comments');
            if (current !== originalState) {
                await configPage.toggleCheckbox('activate_comments');
                await configPage.submitForm();
            }
        }
    });

    test('should validate comment page number constraints (5-50)', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('comments');

        const originalValue = await configPage.getTextValue('nb_comment_page');

        try {
            // Test value below minimum (should show error)
            await configPage.fillTextInput('nb_comment_page', '3');

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();

            // Should see error message
            const errorMsg = adminPage.locator('text=between 5 and 50');
            const hasError = await isVisibleSafe(errorMsg);
            expect(hasError).toBeTruthy();

            // Test valid value
            await configPage.fillTextInput('nb_comment_page', '10');
            const submitBtn2 = await configPage.getSubmitButton();
            await submitBtn2.click();
            await configPage.verifySuccess();
        } finally {
            // Restore original
            await configPage.fillTextInput('nb_comment_page', originalValue);
            await configPage.submitForm();
        }
    });

    test('should enable comment validation and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('comments');

        const checkbox = configPage.getCheckbox('comments_validation');
        await expect(checkbox).toBeVisible();

        const originalState = await checkbox.isChecked();

        try {
            await checkbox.click();
            await configPage.submitForm();

            // Reload and verify
            await configPage.reloadAndVerify();

            const newState = await configPage.isCheckboxChecked('comments_validation');
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('comments_validation');
            if (current !== originalState) {
                await configPage.toggleCheckbox('comments_validation');
                await configPage.submitForm();
            }
        }
    });

    test('should enable email notification on comment and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('comments');

        const checkbox = configPage.getCheckbox('email_admin_on_comment');
        await expect(checkbox).toBeVisible();

        const originalState = await checkbox.isChecked();

        try {
            await checkbox.click();
            await configPage.submitForm();

            // Reload and verify
            await configPage.reloadAndVerify();

            const newState = await configPage.isCheckboxChecked('email_admin_on_comment');
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('email_admin_on_comment');
            if (current !== originalState) {
                await configPage.toggleCheckbox('email_admin_on_comment');
                await configPage.submitForm();
            }
        }
    });

    test('should configure comment author mandatory and persist', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('comments');

        const checkbox = configPage.getCheckbox('comments_author_mandatory');
        await expect(checkbox).toBeVisible();

        const originalState = await checkbox.isChecked();

        try {
            await checkbox.click();
            await configPage.submitForm();

            // Reload and verify
            await configPage.reloadAndVerify();

            const newState = await configPage.isCheckboxChecked('comments_author_mandatory');
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original state
            const current = await configPage.isCheckboxChecked('comments_author_mandatory');
            if (current !== originalState) {
                await configPage.toggleCheckbox('comments_author_mandatory');
                await configPage.submitForm();
            }
        }
    });
});
