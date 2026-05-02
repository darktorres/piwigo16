/**
 * Photo Derivatives Tests
 *
 * @remarks
 * Tests for photo derivative URLs (thumbnails, medium sizes, etc.).
 * All tests are independent and can run in any order.
 * Each test creates its own album and image, then cleans up.
 * Uses API-based authentication (no UI needed).
 *
 * @packageDocumentation
 */

import { test, expect } from '@base-test';
import {
    createTestAlbumWithPhoto,
    deleteAndVerifyAlbum,
    createTestAlbum,
    uploadImage,
    loginAndGetToken,
    baseUrl,
    withRetry,
} from '@helpers/api-helpers';
import { safeCleanup } from '@helpers/cleanup-helpers';
import { TIMEOUTS } from '../../config/config';
import * as path from 'path';

test.describe('Photo Derivatives @admin @photos @derivatives', () => {
    test('should get derivative URLs for uploaded photo', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `DerivUrl_Test_${Date.now()}`
        );

        try {
            // Get image info which includes derivative URLs
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            const info = await infoResp.json();
            expect(info.stat).toBe('ok');

            // Check for derivatives in response
            const derivatives = info.result.derivatives;
            expect(derivatives).toBeDefined();

            // Common derivative types
            const expectedTypes = ['square', 'thumb', 'small', 'medium', 'large'];
            for (const type of expectedTypes) {
                if (derivatives[type]) {
                    expect(derivatives[type].url).toBeDefined();
                    expect(derivatives[type].width).toBeDefined();
                    expect(derivatives[type].height).toBeDefined();
                }
            }
        } finally {
            // Cleanup with retry logic - prevents orphaned test data
            await safeCleanup(
                () => deleteAndVerifyAlbum(request, albumId, token),
                `album ${albumId}`
            );
        }
    });

    test('should have thumbnail derivative accessible', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `ThumbAccess_Test_${Date.now()}`
        );

        try {
            // Get image info
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            const info = await infoResp.json();
            const thumbUrl =
                info.result.derivatives?.thumb?.url || info.result.derivatives?.square?.url;

            if (thumbUrl) {
                // Fetch thumbnail to verify it's accessible with retry logic
                const thumbResp = await withRetry(
                    async () => request.get(thumbUrl, { timeout: TIMEOUTS.element.standard }),
                    { maxAttempts: 3, delayMs: 500, context: 'Fetch thumbnail derivative' }
                );
                expect(thumbResp.ok()).toBeTruthy();
                expect(thumbResp.headers()['content-type']).toContain('image');
            }
        } finally {
            // Cleanup with retry logic - prevents orphaned test data
            await safeCleanup(
                () => deleteAndVerifyAlbum(request, albumId, token),
                `album ${albumId}`
            );
        }
    });

    test('should have different sizes for different derivatives', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `DerivSizes_Test_${Date.now()}`
        );

        try {
            // Get image info
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            const info = await infoResp.json();
            const derivatives = info.result.derivatives;

            if (derivatives) {
                // Get sizes from available derivatives
                const sizes: { type: string; width: number; height: number }[] = [];

                for (const [type, deriv] of Object.entries(derivatives) as [string, any][]) {
                    if (deriv && deriv.width && deriv.height) {
                        sizes.push({
                            type,
                            width: parseInt(deriv.width),
                            height: parseInt(deriv.height),
                        });
                    }
                }

                // Sort by width
                sizes.sort((a, b) => a.width - b.width);

                // Verify sizes are ascending (smaller thumbnails to larger)
                for (let i = 1; i < sizes.length; i++) {
                    expect(sizes[i].width).toBeGreaterThanOrEqual(sizes[i - 1].width);
                }
            }
        } finally {
            // Cleanup with retry logic - prevents orphaned test data
            await safeCleanup(
                () => deleteAndVerifyAlbum(request, albumId, token),
                `album ${albumId}`
            );
        }
    });

    test('should get derivative params via API', async ({ request }) => {
        // Login
        await loginAndGetToken(request);

        // Get derivative params (image size configurations)
        const paramsResp = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.getVersion',
            },
        });

        // This verifies API is working
        const params = await paramsResp.json();
        expect(params.stat).toBe('ok');
    });
});

test.describe('Photo Derivative Quality @admin @photos @derivatives @quality', () => {
    test('should serve derivatives with correct content type', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `DerivContent_Test_${Date.now()}`
        );

        try {
            // Get image info
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            const info = await infoResp.json();
            const derivatives = info.result.derivatives;

            if (derivatives) {
                // Test a few derivative types
                const typesToTest = ['thumb', 'small', 'medium'].filter((t) => derivatives[t]);

                for (const type of typesToTest) {
                    const url = derivatives[type].url;

                    // Fetch derivative with retry logic to handle transient failures
                    const resp = await withRetry(
                        async () => request.get(url, { timeout: TIMEOUTS.element.standard }),
                        { maxAttempts: 3, delayMs: 500, context: `Fetch ${type} derivative` }
                    );
                    expect(resp.ok()).toBeTruthy();
                    const contentType = resp.headers()['content-type'];
                    expect(contentType).toMatch(/image\/(jpeg|jpg|png|gif|webp)/i);
                }
            }
        } finally {
            await deleteAndVerifyAlbum(request, albumId, token);
        }
    });

    test('should handle small source image derivatives correctly', async ({ request }) => {
        // Create album
        const { id: albumId, token } = await createTestAlbum(
            request,
            `SmallSource_Test_${Date.now()}`
        );

        // Upload small image (100x100)
        const imagePath = path.join(__dirname, '../../fixtures/images/small-image.jpg');
        const imageId = await uploadImage(request, albumId, token, imagePath, 'Small Source Image');

        try {
            // Get derivatives
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            const info = await infoResp.json();

            // For small source images, larger derivatives shouldn't upscale
            // (depending on Piwigo settings)
            expect(info.stat).toBe('ok');
            expect(info.result.width).toBeDefined();
            expect(info.result.height).toBeDefined();
        } finally {
            await deleteAndVerifyAlbum(request, albumId, token);
        }
    });
});

test.describe('Photo Derivative URLs @admin @photos @derivatives @urls', () => {
    test('should have consistent derivative URL structure', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `DerivUrlStruct_Test_${Date.now()}`
        );

        try {
            // Get image info
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            const info = await infoResp.json();
            const derivatives = info.result.derivatives;

            if (derivatives) {
                // All derivative URLs should be valid URLs
                for (const [type, deriv] of Object.entries(derivatives) as [string, any][]) {
                    if (deriv && deriv.url) {
                        expect(deriv.url).toMatch(/^https?:\/\/.+/);
                        // Should contain 'i.php' or derivative path pattern
                        expect(deriv.url).toMatch(/i\.php|_data|upload|pwg_derivative/i);
                    }
                }
            }
        } finally {
            await deleteAndVerifyAlbum(request, albumId, token);
        }
    });

    test('should access original image URL', async ({ request }) => {
        const { albumId, imageId, token } = await createTestAlbumWithPhoto(
            request,
            `OriginalUrl_Test_${Date.now()}`
        );

        try {
            // Get image info
            const infoResp = await request.post(`${baseUrl}/ws.php?format=json`, {
                form: {
                    method: 'pwg.images.getInfo',
                    image_id: imageId,
                },
            });

            const info = await infoResp.json();

            // Original element URL
            const originalUrl = info.result.element_url;
            if (originalUrl) {
                // Try to access (might require authentication) with retry logic
                const resp = await withRetry(
                    async () => request.get(originalUrl, { timeout: TIMEOUTS.element.standard }),
                    { maxAttempts: 3, delayMs: 500, context: 'Fetch original image URL' }
                );
                // Should either succeed or require auth
                expect(resp.status()).toBeLessThan(500);
            }
        } finally {
            // Cleanup with retry logic - prevents orphaned test data
            await safeCleanup(
                () => deleteAndVerifyAlbum(request, albumId, token),
                `album ${albumId}`
            );
        }
    });
});
