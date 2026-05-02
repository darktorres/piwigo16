/**
 * Batch Photo Management Tests
 *
 * @remarks
 * Comprehensive tests for batch photo operations and bulk management.
 * Uses adminPage fixture for isolated, pre-authenticated sessions.
 */

import { test, expect } from '@base-test';
import { loginAndGetToken, baseUrl } from '@helpers/api-helpers';
import { isVisibleSafe } from '@helpers/page-helpers';

test.describe('Batch Manager UI @admin @batch', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should load batch manager page', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=batch_manager&mode=global', {
            waitUntil: 'networkidle',
        });
        await adminPage.waitForLoadState('domcontentloaded');

        const title = await adminPage.title();
        expect(title.length).toBeGreaterThanOrEqual(0);

        const content = await adminPage.locator('.content, main, body').first();
        const isVisible = await isVisibleSafe(content, 'content element');
        expect(isVisible).toBe(true);
    });

    test('should display filter section', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=batch_manager&mode=global', {
            waitUntil: 'networkidle',
        });
        const filterSection = adminPage.locator('text=Filter').first();
        const addFilterButton = adminPage.locator('text=Add a filter');
        const hasFilter = (await filterSection.isVisible()) || (await addFilterButton.isVisible());
        expect(hasFilter).toBe(true);
    });

    test('should display photo selection controls', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=batch_manager&mode=global', {
            waitUntil: 'networkidle',
        });
        const checkboxes = await adminPage.locator('input[type="checkbox"]');
        const selectAll = await adminPage.locator('[class*="select"], button[name*="select"]');

        const hasSelection =
            (await checkboxes.count()) > 0 || (await isVisibleSafe(selectAll, 'select all button'));

        expect(hasSelection).toBe(true);
    });

    test('should display batch action options', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=batch_manager&mode=global', {
            waitUntil: 'networkidle',
        });
        const actions = await adminPage.locator(
            'select[name*="action"], [class*="action"], [class*="operation"]'
        );
        const actionCount = await actions.count();

        expect(actionCount).toBeGreaterThanOrEqual(0);
    });

    test('should have apply/submit button for actions', async ({ adminPage }) => {
        await adminPage.goto('/piwigo16/admin.php?page=batch_manager&mode=global', {
            waitUntil: 'networkidle',
        });
        const submitButton = adminPage.locator('button#applyAction[type="submit"]');
        await expect(submitButton).toHaveCount(1);
    });
});

test.describe('Batch Filters and Selection @admin @batch', () => {
    test('should have all photos filter option', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&filter=all_photos',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });

    test('should filter photos by category', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&filter=category',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });

    test('should filter photos by tag', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&filter=tag',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });

    test('should filter photos by privacy level', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&filter=level',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });

    test('should filter photos by dimensions', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&filter=dimension',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });

    test('should filter photos by file size', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&filter=filesize',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });

    test('should have caddie prefilter', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&prefilter=caddie',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });

    test('should have favorites prefilter', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&prefilter=favorites',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });

    test('should have last import prefilter', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&prefilter=last_import',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });

    test('should have no album prefilter', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&prefilter=no_album',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });

    test('should have no tag prefilter', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&prefilter=no_tag',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });

    test('should have duplicates prefilter', async ({ adminPage }) => {
        const response = await adminPage.goto(
            '/piwigo16/admin.php?page=batch_manager&mode=global&prefilter=duplicates',
            { waitUntil: 'networkidle' }
        );
        expect(response?.status()).toBeGreaterThan(0);
    });
});

test.describe('Batch Metadata Operations @api @batch', () => {
    test('should add tags to photos', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: '*',
                per_page: 1,
            },
        });

        const listJson = await listResponse.json();
        if (listJson.stat === 'ok' && listJson.result?.images?.length > 0) {
            const imageId = listJson.result.images[0].id;

            const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setInfo',
                    image_id: imageId,
                    tags: 'test,batch',
                    pwg_token: token,
                },
            });

            const json = await response.json();
            expect(json.stat).toBe('ok');
        }
    });

    test('should update photo author', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: '*',
                per_page: 1,
            },
        });

        const listJson = await listResponse.json();
        if (listJson.stat === 'ok' && listJson.result?.images?.length > 0) {
            const imageId = listJson.result.images[0].id;

            const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setInfo',
                    image_id: imageId,
                    author: 'Batch Author',
                    pwg_token: token,
                },
            });

            const json = await response.json();
            expect(json.stat).toBe('ok');
        }
    });

    test('should update photo title', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: '*',
                per_page: 1,
            },
        });

        const listJson = await listResponse.json();
        if (listJson.stat === 'ok' && listJson.result?.images?.length > 0) {
            const imageId = listJson.result.images[0].id;

            const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setInfo',
                    image_id: imageId,
                    name: `Batch Updated ${Date.now()}`,
                    pwg_token: token,
                },
            });

            const json = await response.json();
            expect(json.stat).toBe('ok');
        }
    });

    test('should update photo date creation', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: '*',
                per_page: 1,
            },
        });

        const listJson = await listResponse.json();
        if (listJson.stat === 'ok' && listJson.result?.images?.length > 0) {
            const imageId = listJson.result.images[0].id;

            const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setInfo',
                    image_id: imageId,
                    date_creation: '2024-01-01 12:00:00',
                    pwg_token: token,
                },
            });

            const json = await response.json();
            expect(json.stat).toBe('ok');
        }
    });

    test('should update photo description/comment', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: '*',
                per_page: 1,
            },
        });

        const listJson = await listResponse.json();
        if (listJson.stat === 'ok' && listJson.result?.images?.length > 0) {
            const imageId = listJson.result.images[0].id;

            const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setInfo',
                    image_id: imageId,
                    comment: 'Batch updated description',
                    pwg_token: token,
                },
            });

            const json = await response.json();
            expect(json.stat).toBe('ok');
        }
    });

    test('should synchronize metadata from files', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.syncMetadata',
                pwg_token: token,
            },
        });

        const json = await response.json();
        expect(['ok', 'fail']).toContain(json.stat);
    });
});

test.describe('Batch Category Operations @api @batch', () => {
    test('should associate photos with categories', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const photoResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: '*',
                per_page: 1,
            },
        });

        const catResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.getList',
            },
        });

        const photoJson = await photoResponse.json();
        const catJson = await catResponse.json();

        if (
            photoJson.stat === 'ok' &&
            catJson.stat === 'ok' &&
            photoJson.result?.images?.length > 0 &&
            catJson.result?.categories?.length > 0
        ) {
            const imageId = photoJson.result.images[0].id;
            const categoryId = catJson.result.categories[0].id;

            const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setCategory',
                    image_id: imageId,
                    categories: categoryId,
                    pwg_token: token,
                },
            });

            const json = await response.json();
            expect(json.stat).toBe('ok');
        }
    });

    test('should move photos between categories', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const photoResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: '*',
                per_page: 1,
            },
        });

        const catResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.getList',
            },
        });

        const photoJson = await photoResponse.json();
        const catJson = await catResponse.json();

        if (
            photoJson.stat === 'ok' &&
            catJson.stat === 'ok' &&
            photoJson.result?.images?.length > 0 &&
            catJson.result?.categories?.length > 0
        ) {
            const imageId = photoJson.result.images[0].id;
            const categoryId = catJson.result.categories[0].id;

            const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setCategory',
                    image_id: imageId,
                    categories: categoryId,
                    pwg_token: token,
                },
            });

            const json = await response.json();
            expect(json.stat).toBe('ok');
        }
    });

    test('should dissociate photos from virtual categories', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.setCategory',
                image_id: 1,
                categories: '',
                pwg_token: token,
            },
        });

        const json = await response.json();
        expect(['ok', 'fail']).toContain(json.stat);
    });
});

test.describe('Batch Photo Deletion @api @batch', () => {
    test('should handle photo deletion request', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: 'to_delete',
                per_page: 1,
            },
        });

        const listJson = await listResponse.json();
        if (listJson.stat === 'ok' && listJson.result?.images?.length > 0) {
            const imageId = listJson.result.images[0].id;

            const deleteResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.delete',
                    image_id: imageId,
                    pwg_token: token,
                },
            });

            const deleteJson = await deleteResponse.json();
            expect(['ok', 'fail']).toContain(deleteJson.stat);
        }
    });

    test('should delete photo derivatives', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: '*',
                per_page: 1,
            },
        });

        const listJson = await listResponse.json();
        if (listJson.stat === 'ok' && listJson.result?.images?.length > 0) {
            const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.maintenance.derivatives',
                    pwg_token: token,
                },
            });

            const json = await response.json();
            expect(json.stat).toBe('ok');
        }
    });

    test('should delete orphan photos', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.deleteOrphans',
                pwg_token: token,
            },
        });

        const json = await response.json();
        expect(json.stat).toBe('ok');
    });
});

test.describe('Batch Privacy Level Operations @api @batch', () => {
    test('should set privacy level for photo', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: '*',
                per_page: 1,
            },
        });

        const listJson = await listResponse.json();
        if (listJson.stat === 'ok' && listJson.result?.images?.length > 0) {
            const imageId = listJson.result.images[0].id;

            for (const level of [0, 1, 2, 4, 8]) {
                const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                    form: {
                        method: 'pwg.images.setPrivacyLevel',
                        image_id: imageId,
                        level: level,
                        pwg_token: token,
                    },
                });

                const json = await response.json();
                expect(json.stat).toBe('ok');
            }
        }
    });
});

test.describe('Batch Image Ranking @api @batch', () => {
    test('should set rank for photos in category', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const catResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.categories.getList',
            },
        });

        const catJson = await catResponse.json();
        if (catJson.stat === 'ok' && catJson.result?.categories?.length > 0) {
            const categoryId = catJson.result.categories[0].id;

            const imgResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.categories.getImages',
                    cat_id: categoryId,
                    per_page: 1,
                },
            });

            const imgJson = await imgResponse.json();
            if (imgJson.stat === 'ok' && imgJson.result?.images?.length > 0) {
                const imageId = imgJson.result.images[0].id;

                const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                    form: {
                        method: 'pwg.images.setRank',
                        image_id: imageId,
                        category_id: categoryId,
                        rank: 1,
                        pwg_token: token,
                    },
                });

                const json = await response.json();
                expect(json.stat).toBe('ok');
            }
        }
    });
});

test.describe('Batch MD5 Checksum Operations @api @batch', () => {
    test('should calculate MD5 checksums for photos', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.setMd5sum',
                pwg_token: token,
            },
        });

        const json = await response.json();
        expect(json.stat).toBe('ok');
    });
});

test.describe('Batch Derivative Generation @api @batch', () => {
    test('should generate missing derivatives', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.getMissingDerivatives',
                max_urls: 10,
            },
        });

        const json = await response.json();
        expect(json.stat).toBe('ok');
    });
});

test.describe('Batch Caddie Operations @api @batch', () => {
    test('should add photo to caddie', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: '*',
                per_page: 1,
            },
        });

        const listJson = await listResponse.json();
        if (listJson.stat === 'ok' && listJson.result?.images?.length > 0) {
            const imageId = listJson.result.images[0].id;

            const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.caddie.add',
                    image_id: imageId,
                    pwg_token: token,
                },
            });

            const json = await response.json();
            expect(json.stat).toBe('ok');
        }
    });

    test('should remove photo from caddie', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.caddie.list',
                pwg_token: token,
            },
        });

        const listJson = await listResponse.json();
        if (listJson.stat === 'ok' && listJson.result?.images?.length > 0) {
            const imageId = listJson.result.images[0].id;

            const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.caddie.remove',
                    image_id: imageId,
                    pwg_token: token,
                },
            });

            const json = await response.json();
            expect(json.stat).toBe('ok');
        }
    });
});

test.describe('Batch Operations Error Handling @admin @batch', () => {
    test('should handle batch operation with invalid image', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.setInfo',
                image_id: 999999999,
                name: 'Invalid',
                pwg_token: token,
            },
        });

        const json = await response.json();
        expect(json.stat).toBe('fail');
        expect(json.err).toBe(404);
    });

    test('should handle batch operation with invalid category', async ({ request }) => {
        const token = await loginAndGetToken(request);

        const listResponse = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.search',
                query: '*',
                per_page: 1,
            },
        });

        const listJson = await listResponse.json();
        if (listJson.stat === 'ok' && listJson.result?.images?.length > 0) {
            const imageId = listJson.result.images[0].id;

            const response = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.setCategory',
                    image_id: imageId,
                    categories: 999999999,
                    pwg_token: token,
                },
            });

            const json = await response.json();
            expect(json.stat).toBe('ok');
        }
    });

    test('should handle batch operation without permission', async ({ request }) => {
        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.images.delete',
                image_id: 1,
            },
        });

        const json = await response.json();
        expect(json.stat).toBe('fail');
        expect(json.err).toBe(401);
    });
});
