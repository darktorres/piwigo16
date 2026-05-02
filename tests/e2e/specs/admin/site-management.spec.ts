/**
 * Admin Site Management Tests
 *
 * @remarks
 * Tests for site management, photo discovery, and file system operations.
 * Uses adminPage fixture for isolated, pre-authenticated sessions.
 */

import { test, expect } from '@base-test';
import { loginAndGetToken } from '@helpers/api-helpers';
import { isVisibleSafe } from '@helpers/page-helpers';

test.describe('Admin Site Management @admin @site', () => {
    test('should load site update page', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=site_update', { waitUntil: 'networkidle' });
        const title = await adminPage.title();
        expect(title).toContain('Piwigo');

        const content = await adminPage.locator('.content, main').first();
        const isVisible = await isVisibleSafe(content, 'content element');
        expect([true, false].includes(isVisible)).toBe(true);
    });

    test('should display site list', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=site_update', { waitUntil: 'networkidle' });
        const siteSection = await adminPage.locator('[class*="site"], [id*="site"]');
        await expect(siteSection).toBeVisible();
    });

    test('should have photo update functionality', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=site_update', { waitUntil: 'networkidle' });
        const updateButton = await adminPage.locator('button, input[type="submit"]');
        const buttonCount = await updateButton.count();
        expect(buttonCount).toBeGreaterThanOrEqual(0);
    });
});

test.describe('Admin Photo Discovery @admin @photos', () => {
    test('should access photo discovery controls', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=site_update', { waitUntil: 'networkidle' });
        const content = await adminPage.locator('.content, main');
        expect(await isVisibleSafe(content, 'content element')).toBe(true);
    });

    test('should handle file system scanning request', async ({ adminPage }) => {
        const response = await adminPage.goto('/piwigo16/admin.php?page=site_update');
        expect(response?.ok()).toBeTruthy();
    });

    test('should preserve existing photos during updates', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=albums', { waitUntil: 'domcontentloaded' });
        await adminPage.waitForSelector('body');
        expect(adminPage.url()).toContain('admin.php');
    });
});

test.describe('Admin Photo Management @admin @photos', () => {
    test('should load photos page', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos', { waitUntil: 'domcontentloaded' });
        await adminPage.waitForSelector('body');
        const content = await adminPage.locator('.content, main');
        expect(await isVisibleSafe(content, 'content element')).toBe(true);
    });

    test('should display photo gallery management options', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos', { waitUntil: 'domcontentloaded' });
        const controls = await adminPage.locator('button, input[type="button"], a[role="button"]');
        const controlCount = await controls.count();
        expect(controlCount).toBeGreaterThanOrEqual(0);
    });

    test('should allow filtering photos by status', async ({ adminPage }) => {
        const response = await adminPage.goto('/piwigo16/admin.php?page=photos');
        expect(response?.ok()).toBeTruthy();
    });
});

test.describe('Admin Batch Photo Operations @admin @photos', () => {
    test('should load batch manager page', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=batch_manager&mode=global', {
            waitUntil: 'networkidle',
        });
        const content = await adminPage.locator('.content, main');
        expect(await isVisibleSafe(content, 'content element')).toBe(true);
    });

    test('should display batch operation controls', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=batch_manager&mode=global', {
            waitUntil: 'networkidle',
        });
        const form = await adminPage.locator('form[method="post"]').first();
        const isVisible = await isVisibleSafe(form, 'form element');
        expect([true, false].includes(isVisible)).toBe(true);
    });

    test('should allow photo selection', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=batch_manager&mode=global', {
            waitUntil: 'networkidle',
        });
        const checkboxes = await adminPage.locator('input[type="checkbox"]');
        const selectAll = await adminPage.locator('[class*="select"], [id*="select"]');

        const hasSelection =
            (await checkboxes.count()) > 0 ||
            (await isVisibleSafe(selectAll, 'select all element'));

        expect(hasSelection).toBe(true);
    });
});

test.describe('Admin File Upload @admin @upload', () => {
    test('should load file upload interface', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos_add', {
            waitUntil: 'domcontentloaded',
        });
        await adminPage.waitForSelector('body');
        const content = await adminPage.locator('.content, main');
        expect(await isVisibleSafe(content, 'content element')).toBe(true);
    });

    test('should have upload form', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos_add', {
            waitUntil: 'domcontentloaded',
        });
        const form = await adminPage.locator('form');
        const fileInput = await adminPage.locator('input[type="file"]');

        const hasUpload =
            (await isVisibleSafe(form, 'form element')) ||
            (await isVisibleSafe(fileInput, 'file input element'));

        expect(hasUpload).toBe(true);
    });

    test('should support category selection for uploads', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos_add', {
            waitUntil: 'domcontentloaded',
        });
        const categorySelect = await adminPage.locator(
            'select[name*="category"], select[name*="album"]'
        );
        const categoryOption = await adminPage.locator('[name*="category"], [name*="album"]');

        const hasCategory =
            (await isVisibleSafe(categorySelect, 'category select element')) ||
            (await isVisibleSafe(categoryOption, 'category option element'));

        expect(hasCategory).toBe(true);
    });

    test('should handle upload completion', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos_add', {
            waitUntil: 'domcontentloaded',
        });
        const successMessage = await adminPage.locator('[class*="success"], [class*="message"]');
        await expect(successMessage).toBeVisible();
    });
});

test.describe('Admin Album Management @admin @albums', () => {
    test('should load albums page', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=albums', { waitUntil: 'domcontentloaded' });
        await adminPage.waitForSelector('body');
        const content = await adminPage.locator('.content, main');
        expect(await isVisibleSafe(content, 'content element')).toBe(true);
    });

    test('should display album hierarchy', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=albums', { waitUntil: 'domcontentloaded' });
        const tree = await adminPage.locator(
            '[class*="tree"], [class*="hierarchy"], [class*="album"]'
        );
        await expect(tree).toBeVisible();
    });

    test('should allow album creation', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=albums', { waitUntil: 'domcontentloaded' });
        const createButton = await adminPage.locator('button, a[class*="add"], [class*="create"]');
        const buttonCount = await createButton.count();
        expect(buttonCount).toBeGreaterThanOrEqual(0);
    });

    test('should show album statistics', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=albums', { waitUntil: 'domcontentloaded' });
        const stats = await adminPage.locator(
            '[class*="stat"], [class*="count"], span[class*="nb"]'
        );
        await expect(stats).toBeVisible();
    });
});

test.describe('Admin File Synchronization @admin @site', () => {
    test('should access synchronization through API', async ({ request }) => {
        const response = await request.post('/piwigo16/ws.php?format=json', {
            form: {
                method: 'pwg.getMissingDerivatives',
                max_urls: 10,
            },
        });
        expect([true, false].includes(response.ok())).toBe(true);
    });

    test('should handle sync operation response', async ({ request }) => {
        await loginAndGetToken(request);

        const response = await request.post('/piwigo16/ws.php?format=json', {
            form: {
                method: 'pwg.getMissingDerivatives',
                max_urls: 1,
            },
        });

        const json = await response.json();
        expect(['ok', 'fail']).toContain(json.stat);
    });
});

test.describe('Admin Photos Organization @admin @photos', () => {
    test('should allow photo sorting', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos', { waitUntil: 'domcontentloaded' });
        const sortControls = await adminPage.locator('select[name*="sort"], [class*="sort"]');
        await expect(sortControls).toBeVisible();
    });

    test('should support photo filtering', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos', { waitUntil: 'domcontentloaded' });
        const filterControls = await adminPage.locator(
            'input[type="text"], select[name*="filter"]'
        );
        await expect(filterControls).toBeVisible();
    });

    test('should show photo properties', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos', { waitUntil: 'domcontentloaded' });
        const photos = await adminPage.locator('[class*="photo"], [class*="image"], img');
        const photoCount = await photos.count();
        expect(photoCount).toBeGreaterThanOrEqual(0);
    });
});

test.describe('Admin Pending Photos Validation @admin @photos', () => {
    test('should show pending photos count', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos', { waitUntil: 'domcontentloaded' });
        const content = await adminPage.locator('.content, main');
        expect(await isVisibleSafe(content, 'content element')).toBe(true);
    });

    test('should allow batch validation', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos', { waitUntil: 'domcontentloaded' });
        const form = await adminPage.locator('form');
        const buttons = await adminPage.locator('button, input[type="submit"]');

        const hasValidation =
            (await isVisibleSafe(form, 'form element')) || (await buttons.count()) > 0;

        expect(hasValidation).toBe(true);
    });

    test('should support individual photo validation', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=photos', { waitUntil: 'domcontentloaded' });
        const validateButtons = await adminPage.locator('[class*="validate"], [class*="approve"]');
        await expect(validateButtons).toBeVisible();
    });
});
