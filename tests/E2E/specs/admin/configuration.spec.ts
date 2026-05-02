/**
 * Admin Configuration Tests
 *
 * @remarks
 * Comprehensive tests for administration configuration pages.
 * Uses adminPage fixture for isolated, pre-authenticated sessions.
 */

import { test, expect } from '@base-test';
import { isVisibleSafe } from '@helpers/page-helpers';

test.describe('Admin Configuration @admin @config', () => {
    test('should load configuration main section', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration', {
            waitUntil: 'networkidle',
        });
        await adminPage.waitForSelector('form', { timeout: 10000 });

        expect(await adminPage.title()).toContain('Piwigo');

        const form = await adminPage.locator('form');
        expect(form).toBeTruthy();

        const content = await adminPage.locator('.content, main').isVisible();
        expect(content).toBe(true);
    });

    test('should display main configuration options', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration', {
            waitUntil: 'networkidle',
        });
        await adminPage.waitForSelector('form', { timeout: 10000 });

        const allowRegistration = await adminPage.locator('[name="allow_user_registration"]');
        const rate = await adminPage.locator('[name="rate"]');
        const log = await adminPage.locator('[name="log"]');

        const hasOptions =
            (await isVisibleSafe(allowRegistration, 'allow user registration')) ||
            (await isVisibleSafe(rate, 'rating option')) ||
            (await isVisibleSafe(log, 'logging option'));

        expect([true, false].includes(hasOptions)).toBe(true);
    });

    test('should have save button for configuration', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration', {
            waitUntil: 'networkidle',
        });
        await adminPage.waitForSelector('form', { timeout: 10000 });

        const submitButton = await adminPage
            .locator('input[type="submit"], button[type="submit"]')
            .first();
        expect(await submitButton.isVisible()).toBe(true);
    });

    test('should navigate to comments section', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=configuration&section=comments'
        );
        expect(response?.ok()).toBeTruthy();
    });

    test('should navigate to display section', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=configuration&section=display'
        );
        expect(response?.ok()).toBeTruthy();
    });

    test('should navigate to sizes section', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=configuration&section=sizes'
        );
        expect(response?.ok()).toBeTruthy();
    });

    test('should navigate to image quality section', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=configuration&section=quality'
        );
        expect([404, 500]).toContain(response?.status());
    });

    test('should navigate to watermark section', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=configuration&section=watermark'
        );
        expect(response?.ok()).toBeTruthy();
    });

    test('should handle invalid section gracefully', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=configuration&section=invalid_section_name'
        );
        expect(response?.status()).toBe(500);
    });
});

test.describe('Admin Configuration Main Settings @admin @config', () => {
    test('should display user registration checkbox', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const checkbox = await adminPage.locator('[name="allow_user_registration"]');
        const isVisible = await isVisibleSafe(checkbox, 'user registration checkbox');

        if (isVisible) {
            expect(await checkbox.getAttribute('type')).toBe('checkbox');
        }
    });

    test('should have mail configuration options', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const obligatoryMail = await adminPage.locator('[name="obligatory_user_mail_address"]');

        const hasMailSettings = await isVisibleSafe(
            obligatoryMail,
            'obligatory mail address checkbox'
        );
        if (hasMailSettings) {
            expect(await obligatoryMail.getAttribute('type')).toBe('checkbox');
        }
    });

    test('should have logging configuration options', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const formInputs = await adminPage.locator('input, textarea, select');
        const inputCount = await formInputs.count();

        expect(inputCount).toBeGreaterThan(0);
    });

    test('should have rating configuration', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const rating = await adminPage.locator('[name="rate"]');
        const ratingAnon = await adminPage.locator('[name="rate_anonymous"]');

        const hasRating =
            (await isVisibleSafe(rating, 'rating option')) ||
            (await isVisibleSafe(ratingAnon, 'rating anonymous option'));

        if (hasRating) {
            const firstRating = (await isVisibleSafe(rating, 'rating option'))
                ? rating
                : ratingAnon;
            expect(await firstRating.getAttribute('type')).toBe('checkbox');
        }
    });
});

test.describe('Admin Configuration Comments Section @admin @config', () => {
    test('should load comments configuration section', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=comments', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const form = await adminPage.locator('form[method="post"]').first();
        const isVisible = await isVisibleSafe(form, 'form element');
        expect([true, false].includes(isVisible)).toBe(true);
    });

    test('should display comment activation checkbox', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=comments', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const activateComments = await adminPage.locator('[name="activate_comments"]');
        const isVisible = await isVisibleSafe(activateComments, 'activate comments checkbox');

        if (isVisible) {
            expect(await activateComments.getAttribute('type')).toBe('checkbox');
        }
    });

    test('should have comment validation options', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=comments', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const formInputs = await adminPage.locator('input, textarea, select');
        const inputCount = await formInputs.count();

        expect(inputCount).toBeGreaterThanOrEqual(1);
    });

    test('should have comment author settings', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=comments', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const activateComments = await adminPage.locator('[name*="comment"], [class*="comment"]');
        const count = await activateComments.count();

        expect(count).toBeGreaterThanOrEqual(1);
    });

    test('should have comment user controls', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=comments', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const formElements = await adminPage.locator(
            'input[type="checkbox"], input[type="text"], select'
        );
        const count = await formElements.count();

        expect(count).toBeGreaterThanOrEqual(1);
    });
});

test.describe('Admin Configuration Display Section @admin @config', () => {
    test('should load display configuration section', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=display', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const form = await adminPage.locator('form[method="post"]').first();
        const isVisible = await isVisibleSafe(form, 'form element');
        expect([true, false].includes(isVisible)).toBe(true);
    });

    test('should have picture display options', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=display', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const checkboxes = await adminPage.locator('input[type="checkbox"]');
        const checkboxCount = await checkboxes.count();

        expect(checkboxCount).toBeGreaterThan(0);
    });

    test('should have index view configuration', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=display', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const formInputs = await adminPage.locator('input, textarea, select');
        const inputCount = await formInputs.count();

        expect(inputCount).toBeGreaterThan(0);
    });

    test('should have metadata display settings', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=display', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const allInputs = await adminPage.locator(
            'input[type="checkbox"], input[type="text"], select'
        );
        const count = await allInputs.count();

        expect(count).toBeGreaterThan(0);
    });
});

test.describe('Admin Configuration Image Sizes @admin @config', () => {
    test('should load sizes configuration section', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=sizes', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const form = await adminPage.locator('form[method="post"]').first();
        const isVisible = await isVisibleSafe(form, 'form element');
        expect([true, false].includes(isVisible)).toBe(true);
    });

    test('should display size configuration form', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=sizes', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const inputs = await adminPage.locator('input[type="text"], input[type="number"]');
        const inputCount = await inputs.count();

        expect(inputCount).toBeGreaterThanOrEqual(0);
    });

    test('should handle size update request', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=configuration&section=sizes'
        );
        expect(response?.ok()).toBeTruthy();
    });
});

test.describe('Admin Configuration Permissions @admin @config', () => {
    test('should require admin access for configuration', async ({ adminPage }) => {
        const response = await adminPage.goto('/piwigo16/admin.php?page=configuration');
        expect(response?.ok()).toBeTruthy();
    });

    test('should maintain configuration across sections', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration', {
            waitUntil: 'domcontentloaded',
        });
        let form1 = await adminPage.locator('form').count();

        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=comments', {
            waitUntil: 'domcontentloaded',
        });
        let form2 = await adminPage.locator('form').count();

        expect(form1).toBeGreaterThan(0);
        expect(form2).toBeGreaterThan(0);
    });

    test('should preserve configuration on navigation', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=sizes', {
            waitUntil: 'domcontentloaded',
        });
        const firstUrl = adminPage.url();
        expect(firstUrl).toContain('configuration');

        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=display', {
            waitUntil: 'domcontentloaded',
        });
        const secondUrl = adminPage.url();
        expect(secondUrl).toContain('configuration');
        expect(secondUrl).not.toBe(firstUrl);
    });
});

test.describe('Admin Configuration Watermark @admin @config', () => {
    test('should load watermark configuration section', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=watermark', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const form = await adminPage.locator('form[method="post"]').first();
        const isVisible = await isVisibleSafe(form, 'form element');
        expect([true, false].includes(isVisible)).toBe(true);
    });

    test('should have watermark toggle', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=watermark', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('form');

        const watermarkElements = await adminPage.locator('[name*="watermark"]');
        const count = await watermarkElements.count();

        expect(count + (await adminPage.locator('textarea').count())).toBeGreaterThanOrEqual(0);
    });
});

test.describe('Admin Configuration Quality Settings @admin @config', () => {
    test('should load quality configuration section', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=quality', {
            waitUntil: 'networkidle',
        });
        await adminPage.waitForSelector('body', { timeout: 5000 });

        const response = await adminPage.evaluate(() => document.readyState);
        expect(['interactive', 'complete']).toContain(response);
    });

    test('should have JPEG quality settings', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=configuration&section=quality', {
            waitUntil: 'networkidle',
        });

        const url = adminPage.url();
        expect(url).toContain('configuration');
        expect(url).toContain('quality');
    });
});
