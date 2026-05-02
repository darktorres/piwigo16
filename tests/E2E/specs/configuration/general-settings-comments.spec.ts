/**
 * Configuration Comments Settings Tests
 *
 * @remarks
 * Extracted and reorganized from larger test files.
 * Uses adminPage fixture for isolated, pre-authenticated sessions.
 */

import { test, expect } from '@base-test';
import { isVisibleSafe } from '@helpers/page-helpers';
import { ConfigurationPage } from '@pages/ConfigurationPage';

test.describe('Configuration Comments Settings @critical @admin @config', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should load comments configuration section', async ({ adminPage }) => {
        const configPage = new ConfigurationPage(adminPage);
        await configPage.navigateToSection('comments');

        expect(adminPage.url()).toContain('section=comments');

        const form = configPage.getConfigForm();
        await expect(form).toBeDefined();
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

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

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

        const nbInput = configPage.getTextInput('nb_comment_page');
        await expect(nbInput).toBeVisible();

        const originalValue = await nbInput.inputValue();

        try {
            // Test value below minimum (should show error)
            await nbInput.fill('3');

            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();

            // Should see error message
            const errorMsg = adminPage.locator('text=between 5 and 50');
            const hasError = await isVisibleSafe(errorMsg);
            expect(hasError).toBeTruthy();

            // Test valid value
            await nbInput.fill('10');
            const submitBtn2 = await configPage.getSubmitButton();
            await submitBtn2.click();

            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });
        } finally {
            // Restore original
            await nbInput.fill(originalValue);
            const submitBtn = await configPage.getSubmitButton();
            await submitBtn.click();
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

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

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

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

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

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

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
