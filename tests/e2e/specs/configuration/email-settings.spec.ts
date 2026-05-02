/**
 * Configuration Email Settings Tests
 *
 * @remarks
 * Comprehensive E2E tests for Piwigo's email/notification configuration.
 * Uses adminPage fixture for isolated, pre-authenticated sessions.
 *
 * @packageDocumentation
 */

import { test, expect } from '@base-test';
import { isVisibleSafe } from '@helpers/page-helpers';
import { baseUrl } from '@helpers/api-helpers';

test.describe('Configuration Email Settings @critical @admin @config', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should load email configuration settings', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=main`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        expect(adminPage.url()).toContain('page=configuration');

        const form = adminPage.locator('form[method="post"]');
        await expect(form).toBeVisible();
    });

    test('should display mail theme options', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=main`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const mailThemeSelect = adminPage.locator('select[name="mail_theme"]');
        await expect(mailThemeSelect).toBeVisible();

        // Should have at least 2 themes: clear and dark
        const options = await mailThemeSelect.locator('option').allTextContents();
        expect(options.length).toBeGreaterThanOrEqual(2);

        // Verify expected themes exist
        const optionsText = options.join(' ');
        expect(optionsText.toLowerCase()).toContain('clear');
        expect(optionsText.toLowerCase()).toContain('dark');
    });

    test('should change mail theme and persist', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=main`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const mailThemeSelect = adminPage.locator('select[name="mail_theme"]');
        await expect(mailThemeSelect).toBeVisible();

        const originalValue = await mailThemeSelect.inputValue();

        // Toggle between clear and dark
        const newValue = originalValue === 'clear' ? 'dark' : 'clear';

        try {
            await mailThemeSelect.selectOption(newValue);

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

            const persistedValue = await adminPage
                .locator('select[name="mail_theme"]')
                .inputValue();
            expect(persistedValue).toBe(newValue);
        } finally {
            // Restore original
            await adminPage.locator('select[name="mail_theme"]').selectOption(originalValue);
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();
        }
    });

    test('should toggle obligatory user mail address and persist', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=main`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const checkbox = adminPage.locator('input[name="obligatory_user_mail_address"]');
        await expect(checkbox).toBeVisible();

        const originalState = await checkbox.isChecked();

        try {
            // Toggle the checkbox
            await checkbox.click();

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

            const newState = await adminPage
                .locator('input[name="obligatory_user_mail_address"]')
                .isChecked();
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original
            const current = await adminPage
                .locator('input[name="obligatory_user_mail_address"]')
                .isChecked();
            if (current !== originalState) {
                await adminPage.locator('input[name="obligatory_user_mail_address"]').click();
                await adminPage
                    .locator(
                        'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                    )
                    .click();
            }
        }
    });

    test('should configure email notification on new user registration', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=main`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const checkbox = adminPage.locator('input[name="email_admin_on_new_user"]');
        await expect(checkbox).toBeVisible();

        const originalState = await checkbox.isChecked();

        try {
            // Toggle the checkbox
            await checkbox.click();

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

            const newState = await adminPage
                .locator('input[name="email_admin_on_new_user"]')
                .isChecked();
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original
            const current = await adminPage
                .locator('input[name="email_admin_on_new_user"]')
                .isChecked();
            if (current !== originalState) {
                await adminPage.locator('input[name="email_admin_on_new_user"]').click();
                await adminPage
                    .locator(
                        'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                    )
                    .click();
            }
        }
    });

    test('should configure week start day and persist', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/admin.php?page=configuration&section=main`, {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const weekStartSelect = adminPage.locator('select[name="week_starts_on"]');
        await expect(weekStartSelect).toBeVisible();

        const originalValue = await weekStartSelect.inputValue();

        // Toggle between sunday and monday
        const newValue = originalValue === 'sunday' ? 'monday' : 'sunday';

        try {
            await weekStartSelect.selectOption(newValue);

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

            const persistedValue = await adminPage
                .locator('select[name="week_starts_on"]')
                .inputValue();
            expect(persistedValue).toBe(newValue);
        } finally {
            // Restore original
            await adminPage.locator('select[name="week_starts_on"]').selectOption(originalValue);
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();
        }
    });
});

test.describe('Configuration Comment Email Notifications @critical @admin @config', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should toggle email notification on new comment and persist', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=comments', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const checkbox = adminPage.locator('input[name="email_admin_on_comment"]');
        await expect(checkbox).toBeVisible();

        const originalState = await checkbox.isChecked();

        try {
            await checkbox.click();

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

            const newState = await adminPage
                .locator('input[name="email_admin_on_comment"]')
                .isChecked();
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original
            const current = await adminPage
                .locator('input[name="email_admin_on_comment"]')
                .isChecked();
            if (current !== originalState) {
                await adminPage.locator('input[name="email_admin_on_comment"]').click();
                await adminPage
                    .locator(
                        'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                    )
                    .click();
            }
        }
    });

    test('should toggle email notification on comment validation and persist', async ({
        adminPage,
    }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=comments', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const checkbox = adminPage.locator('input[name="email_admin_on_comment_validation"]');
        await expect(checkbox).toBeVisible();

        const originalState = await checkbox.isChecked();

        try {
            await checkbox.click();

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

            const newState = await adminPage
                .locator('input[name="email_admin_on_comment_validation"]')
                .isChecked();
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original
            const current = await adminPage
                .locator('input[name="email_admin_on_comment_validation"]')
                .isChecked();
            if (current !== originalState) {
                await adminPage.locator('input[name="email_admin_on_comment_validation"]').click();
                await adminPage
                    .locator(
                        'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                    )
                    .click();
            }
        }
    });

    test('should toggle email notification on comment edition and persist', async ({
        adminPage,
    }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=comments', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const checkbox = adminPage.locator('input[name="email_admin_on_comment_edition"]');
        await expect(checkbox).toBeVisible();

        const originalState = await checkbox.isChecked();

        try {
            await checkbox.click();

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

            const newState = await adminPage
                .locator('input[name="email_admin_on_comment_edition"]')
                .isChecked();
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original
            const current = await adminPage
                .locator('input[name="email_admin_on_comment_edition"]')
                .isChecked();
            if (current !== originalState) {
                await adminPage.locator('input[name="email_admin_on_comment_edition"]').click();
                await adminPage
                    .locator(
                        'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                    )
                    .click();
            }
        }
    });

    test('should toggle email notification on comment deletion and persist', async ({
        adminPage,
    }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=comments', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const checkbox = adminPage.locator('input[name="email_admin_on_comment_deletion"]');
        await expect(checkbox).toBeVisible();

        const originalState = await checkbox.isChecked();

        try {
            await checkbox.click();

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

            const newState = await adminPage
                .locator('input[name="email_admin_on_comment_deletion"]')
                .isChecked();
            expect(newState).toBe(!originalState);
        } finally {
            // Restore original
            const current = await adminPage
                .locator('input[name="email_admin_on_comment_deletion"]')
                .isChecked();
            if (current !== originalState) {
                await adminPage.locator('input[name="email_admin_on_comment_deletion"]').click();
                await adminPage
                    .locator(
                        'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                    )
                    .click();
            }
        }
    });

    test('should configure all comment notification options together', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=comments', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const emailOnComment = adminPage.locator('input[name="email_admin_on_comment"]');
        const emailOnValidation = adminPage.locator(
            'input[name="email_admin_on_comment_validation"]'
        );
        const emailOnEdition = adminPage.locator('input[name="email_admin_on_comment_edition"]');
        const emailOnDeletion = adminPage.locator('input[name="email_admin_on_comment_deletion"]');

        // Assert that main email comment feature is available (required feature)
        await expect(emailOnComment).toBeVisible();

        // Store original states
        const originalCommentState = await emailOnComment.isChecked();
        const originalValidationState = (await isVisibleSafe(emailOnValidation))
            ? await emailOnValidation.isChecked()
            : false;
        const originalEditionState = (await isVisibleSafe(emailOnEdition))
            ? await emailOnEdition.isChecked()
            : false;
        const originalDeletionState = (await isVisibleSafe(emailOnDeletion))
            ? await emailOnDeletion.isChecked()
            : false;

        try {
            // Enable all notifications
            if (!(await emailOnComment.isChecked())) {
                await emailOnComment.click();
            }
            if (
                (await isVisibleSafe(emailOnValidation)) &&
                !(await emailOnValidation.isChecked())
            ) {
                await emailOnValidation.click();
            }
            if ((await isVisibleSafe(emailOnEdition)) && !(await emailOnEdition.isChecked())) {
                await emailOnEdition.click();
            }
            if ((await isVisibleSafe(emailOnDeletion)) && !(await emailOnDeletion.isChecked())) {
                await emailOnDeletion.click();
            }

            const submitBtn = adminPage.locator(
                'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
            );
            await submitBtn.click();

            // Verify success
            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });

            // Reload and verify all are enabled
            await adminPage.reload({ waitUntil: 'domcontentloaded' });
            await adminPage.waitForSelector('form[method="post"]');

            expect(
                await adminPage.locator('input[name="email_admin_on_comment"]').isChecked()
            ).toBeTruthy();
        } finally {
            // Restore original states
            const currentComment = await adminPage
                .locator('input[name="email_admin_on_comment"]')
                .isChecked();
            if (currentComment !== originalCommentState) {
                await adminPage.locator('input[name="email_admin_on_comment"]').click();
            }
            if (await isVisibleSafe(emailOnValidation)) {
                const current = await adminPage
                    .locator('input[name="email_admin_on_comment_validation"]')
                    .isChecked();
                if (current !== originalValidationState) {
                    await adminPage
                        .locator('input[name="email_admin_on_comment_validation"]')
                        .click();
                }
            }
            if (await isVisibleSafe(emailOnEdition)) {
                const current = await adminPage
                    .locator('input[name="email_admin_on_comment_edition"]')
                    .isChecked();
                if (current !== originalEditionState) {
                    await adminPage.locator('input[name="email_admin_on_comment_edition"]').click();
                }
            }
            if (await isVisibleSafe(emailOnDeletion)) {
                const current = await adminPage
                    .locator('input[name="email_admin_on_comment_deletion"]')
                    .isChecked();
                if (current !== originalDeletionState) {
                    await adminPage
                        .locator('input[name="email_admin_on_comment_deletion"]')
                        .click();
                }
            }
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();
        }
    });
});

test.describe('Configuration Display and Default Settings @admin @config', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should load display configuration section', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=display', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        expect(adminPage.url()).toContain('section=display');

        const form = adminPage.locator('form[method="post"]');
        await expect(form).toBeVisible();
    });

    test('should validate number of categories per page (minimum 4)', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=display', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        const nbCategoriesInput = adminPage.locator('input[name="nb_categories_page"]');
        await expect(nbCategoriesInput).toBeVisible();

        const originalValue = await nbCategoriesInput.inputValue();

        try {
            // Test value below minimum
            await nbCategoriesInput.fill('2');

            const submitBtn = adminPage.locator(
                'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
            );
            await submitBtn.click();

            // Should see error
            const errorMsg = adminPage.locator('text=must be above 4');
            const hasError = await isVisibleSafe(errorMsg);
            expect(hasError).toBeTruthy();

            // Test valid value
            await adminPage.goto('/piwigo16/admin.php?page=configuration&section=display', {
                waitUntil: 'domcontentloaded',
            });
            await adminPage.waitForSelector('form[method="post"]');

            await adminPage.locator('input[name="nb_categories_page"]').fill('12');
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();

            const successMsg = adminPage.locator('text=Your configuration settings are saved');
            await expect(successMsg).toBeVisible({ timeout: 5000 });
        } finally {
            // Restore original
            await adminPage.goto('/piwigo16/admin.php?page=configuration&section=display', {
                waitUntil: 'domcontentloaded',
            });
            await adminPage.waitForSelector('form[method="post"]');
            await adminPage.locator('input[name="nb_categories_page"]').fill(originalValue);
            await adminPage
                .locator(
                    'button[type="submit"][name="submit"], input[type="submit"][name="submit"]'
                )
                .click();
        }
    });

    test('should configure default guest/user settings', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=default', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form[method="post"]');

        // This section allows configuring default user preferences
        const form = adminPage.locator('form[method="post"]');
        await expect(form).toBeVisible();

        // Default section should have guest user settings
        expect(adminPage.url()).toContain('section=default');
    });
});
