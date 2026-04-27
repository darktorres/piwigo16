/**
 * Phase 5 exit signal: no console TypeError/SyntaxError on any entry-point route.
 * Verifies the Vite build is wired correctly (hashed dist URLs served) and that
 * none of the TS-compiled bundles throw at parse/eval time.
 */

import { test, expect } from '@playwright/test';
import { adminLogin } from './helpers/admin-login';

// Collect page errors across the test; fail if any TypeErrors/SyntaxErrors are found.
function attachErrorCollector(page: import('@playwright/test').Page): () => Error[] {
    const errors: Error[] = [];
    page.on('pageerror', (err) => {
        errors.push(err);
    });
    return () => errors;
}

// ── Gallery routes ──────────────────────────────────────────────────────────

test('gallery home — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await page.goto('/index.php');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('identification page — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await page.goto('/identification.php');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('search page — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await page.goto('/search.php');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('tags page — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await page.goto('/tags.php');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

// ── Admin routes (authenticated) ────────────────────────────────────────────

test('admin dashboard — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await adminLogin(page);
    await page.goto('/admin.php');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('admin albums — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await adminLogin(page);
    await page.goto('/admin.php?page=albums');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('admin batch manager global — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await adminLogin(page);
    await page.goto('/admin.php?page=batch_manager&filter=all');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('admin photos_add_direct — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await adminLogin(page);
    await page.goto('/admin.php?page=photos_add&section=direct');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('admin configuration — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await adminLogin(page);
    await page.goto('/admin.php?page=configuration');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('admin history — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await adminLogin(page);
    await page.goto('/admin.php?page=history');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('admin plugins installed — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await adminLogin(page);
    await page.goto('/admin.php?page=plugins');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('admin languages — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await adminLogin(page);
    await page.goto('/admin.php?page=languages');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('admin themes — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await adminLogin(page);
    await page.goto('/admin.php?page=themes');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

test('admin maintenance — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await adminLogin(page);
    await page.goto('/admin.php?page=maintenance');
    expect(getErrors(), `pageerrors: ${getErrors().map((e) => e.message).join('; ')}`).toHaveLength(0);
});

// ── Verify hashed dist URLs are served when manifest exists ─────────────────

test('gallery home loads hashed dist script URL', async ({ page }) => {
    const scriptUrls: string[] = [];
    page.on('request', (req) => {
        if (req.resourceType() === 'script') scriptUrls.push(req.url());
    });
    await page.goto('/index.php');
    const distUrls = scriptUrls.filter((u) => u.includes('/dist/assets/'));
    expect(distUrls.length, `Expected at least one dist/assets/ script; got: ${scriptUrls.join(', ')}`).toBeGreaterThan(0);
    // Hashed URL must contain a hex/alphanumeric hash segment
    expect(distUrls[0]).toMatch(/dist\/assets\/.+-[A-Za-z0-9_-]+\.js/);
});
