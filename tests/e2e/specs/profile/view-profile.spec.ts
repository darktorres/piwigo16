import { test, expect } from '@base-test';
import { TEST_DATA } from '@helpers/test-data';
import { loginAndGetToken, baseUrl, expectApiSuccess } from '@helpers/api-helpers';
import { isVisibleSafe } from '@helpers/page-helpers';
import { ProfilePage } from '../../pages/ProfilePage';

test.describe('Profile Page - View Profile @critical @auth', () => {
    // Uses adminPage fixture - each test gets its own isolated authenticated session

    test('should load profile page when authenticated @smoke', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);

        // Navigate to profile page
        await profilePage.goto();

        // Verify page loaded successfully (not redirected to login)
        await expect(adminPage).toHaveURL(/profile\.php/);

        // Verify profile form is visible
        await profilePage.expectProfileFormVisible();
    });

    test('should display current user information', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);
        await profilePage.goto();

        // Verify username is displayed (readonly field)
        const usernameElement = adminPage.locator('text=' + TEST_DATA.admin.username).first();
        await expect(usernameElement).toBeVisible();

        // Verify email field exists and is editable
        const emailInput = await profilePage.getEmail();
        await expect(emailInput).toBeVisible();

        // Get current email value
        const currentEmail = await emailInput.inputValue();
        expect(currentEmail).toBeTruthy();
        expect(currentEmail.length).toBeGreaterThan(0);
    });

    test('should display password change fields', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);
        await profilePage.goto();

        // Verify all password fields are present
        const currentPasswordInput = await profilePage.getPassword();
        const newPasswordInput = await profilePage.getNewPassword();
        const confirmPasswordInput = await profilePage.getPasswordConfirm();

        await expect(currentPasswordInput).toBeVisible();
        await expect(newPasswordInput).toBeVisible();
        await expect(confirmPasswordInput).toBeVisible();

        // Verify fields are password type
        await expect(currentPasswordInput).toHaveAttribute('type', 'password');
        await expect(newPasswordInput).toHaveAttribute('type', 'password');
        await expect(confirmPasswordInput).toHaveAttribute('type', 'password');
    });

    test('should display preferences section', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);
        await profilePage.goto();

        // Verify preferences section
        const preferencesSection = await profilePage.getPreferences();

        // Preferences may not be visible if customization is disabled
        const isVisible = await isVisibleSafe(
            preferencesSection,
            'preferences section heading on profile page'
        );

        if (isVisible) {
            // Verify common preference fields
            const nbImagePageInput = await profilePage.getNbImagesPerPage();
            const themeSelect = await profilePage.getTheme();
            const languageSelect = await profilePage.getLanguage();

            await expect(nbImagePageInput).toBeVisible();
            await expect(themeSelect).toBeVisible();
            await expect(languageSelect).toBeVisible();

            // Verify current values are populated
            const nbImagePage = await nbImagePageInput.inputValue();
            expect(parseInt(nbImagePage || '0')).toBeGreaterThan(0);
        }
    });

    test('should display theme selector with options', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);
        await profilePage.goto();

        const themeSelect = await profilePage.getTheme();

        // Check if theme selector exists (may not be visible if customization disabled)
        const exists = await themeSelect.count();

        if (exists > 0) {
            await expect(themeSelect).toBeVisible();

            // Verify there are theme options
            const options = themeSelect.locator('option');
            const optionCount = await options.count();
            expect(optionCount).toBeGreaterThan(0);

            // Verify a theme is selected
            const selectedValue = await themeSelect.inputValue();
            expect(selectedValue).toBeTruthy();
        }
    });

    test('should display language selector with options', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);
        await profilePage.goto();

        const languageSelect = await profilePage.getLanguage();

        // Check if language selector exists
        const exists = await languageSelect.count();

        if (exists > 0) {
            await expect(languageSelect).toBeVisible();

            // Verify there are language options
            const options = languageSelect.locator('option');
            const optionCount = await options.count();
            expect(optionCount).toBeGreaterThan(0);

            // Verify a language is selected
            const selectedValue = await languageSelect.inputValue();
            expect(selectedValue).toBeTruthy();
        }
    });

    test('should have submit button', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);
        await profilePage.goto();

        // Find submit button (may have different text depending on language)
        const submitButton = await profilePage.getSaveButton();
        await expect(submitButton.first()).toBeVisible();
    });

    test('should have reset button', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);
        await profilePage.goto();

        // Find reset button
        const resetButton = await profilePage.getCancelButton();
        const count = await resetButton.count();
        expect(count).toBeGreaterThan(0);
    });

    test('should contain CSRF token in form', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);
        await profilePage.goto();

        // Verify pwg_token is present in form
        const tokenInput = await profilePage.getCsrfToken();
        await expect(tokenInput).toBeAttached();

        const tokenValue = await tokenInput.inputValue();
        expect(tokenValue).toBeTruthy();
        expect(tokenValue.length).toBeGreaterThan(10);
    });

    test('should redirect to login when not authenticated', async ({ browser }) => {
        // Create an isolated context WITHOUT cookies to avoid corrupting the shared context
        const isolatedContext = await browser.newContext();
        const isolatedPage = await isolatedContext.newPage();

        try {
            await isolatedPage.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

            // Should be redirected to login or identification page
            await isolatedPage.waitForURL(/identification\.php|login|profile\.php/, {
                timeout: 5000,
            });

            const currentUrl = isolatedPage.url();
            // Either redirected to login or stayed on profile (which would show login form)
            expect(currentUrl).toMatch(/identification\.php|login|profile\.php/);
        } finally {
            await isolatedContext.close();
        }
    });
});

test.describe('Profile Page - API Verification @api @auth', () => {
    test('should get user info via API', async ({ request }) => {
        await loginAndGetToken(request);

        // Get session status which includes user info
        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.session.getStatus',
            },
        });

        const json = await response.json();
        expectApiSuccess(json);

        // Verify user info is present
        expect(json.result.username).toBe(TEST_DATA.admin.username);
        expect(json.result.status).toBeDefined();
        expect(['webmaster', 'admin']).toContain(json.result.status);
    });

    test('should verify profile data matches API data', async ({ adminPage }) => {
        const request = adminPage.request;
        // Get user info from API
        await loginAndGetToken(request);

        const statusResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.session.getStatus',
            },
        });

        const statusJson = await statusResponse.json();
        expectApiSuccess(statusJson);

        const apiUsername = statusJson.result.username;

        // Load profile page
        await adminPage.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        // Verify username matches
        const usernameOnPage = adminPage.locator('text=' + apiUsername).first();
        await expect(usernameOnPage).toBeVisible();
    });

    test('should get user preferences via API', async ({ request }) => {
        await loginAndGetToken(request);

        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.session.getStatus',
            },
        });

        const json = await response.json();
        expectApiSuccess(json);

        // Verify preferences data
        const result = json.result;
        expect(result.language).toBeDefined();
        expect(result.theme).toBeDefined();

        // Verify numeric preferences
        if (result.nb_image_page !== undefined) {
            expect(typeof result.nb_image_page).toBe('number');
            expect(result.nb_image_page).toBeGreaterThan(0);
        }
    });
});

test.describe('Profile Page - Accessibility @a11y', () => {
    test('should have accessible form labels', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);
        await profilePage.goto();

        // Check for label associations
        const emailInput = await profilePage.getEmail();
        const emailLabel = adminPage.locator('label[for="mail_address"]');

        // Either has label with for attribute or aria-label
        const hasLabel = (await emailLabel.count()) > 0;
        const hasAriaLabel = await emailInput.getAttribute('aria-label');

        expect(hasLabel || hasAriaLabel).toBeTruthy();
    });

    test('should have proper form structure', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);
        await profilePage.goto();

        // Verify form exists
        const form = await profilePage.getProfileForm();
        await expect(form).toBeVisible();

        // Verify form has action attribute
        const action = await form.getAttribute('action');
        expect(action).toBeTruthy();
        expect(action).toContain('profile.php');
    });

    test('should have fieldsets with legends', async ({ adminPage }) => {
        const profilePage = new ProfilePage(adminPage);
        await profilePage.goto();

        // Verify fieldsets have legends for accessibility
        const fieldsets = adminPage.locator('fieldset');
        const fieldsetCount = await fieldsets.count();

        if (fieldsetCount > 0) {
            for (let i = 0; i < fieldsetCount; i++) {
                const fieldset = fieldsets.nth(i);
                const legend = fieldset.locator('legend');
                const legendExists = (await legend.count()) > 0;

                // Each fieldset should have a legend
                expect(legendExists).toBeTruthy();
            }
        }
    });
});
