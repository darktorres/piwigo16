/**
 * Phase 5 exit signal: no console TypeError/SyntaxError on any entry-point route.
 * Verifies the Vite build is wired correctly (hashed dist URLs served) and that
 * none of the TS-compiled bundles throw at parse/eval time.
 */

import { test, expect } from '@playwright/test';
import { loginAsAdmin, attachErrorCollector } from './helpers/admin-login';
import { pwgUrl } from './helpers/url';

// ── Gallery routes ──────────────────────────────────────────────────────────

test('gallery home — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await page.goto(pwgUrl('/index.php'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('identification page — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await page.goto(pwgUrl('/identification.php'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('search page — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await page.goto(pwgUrl('/search.php'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('tags page — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await page.goto(pwgUrl('/tags.php'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

// ── Admin routes (authenticated) ────────────────────────────────────────────

test('admin dashboard — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin albums — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=albums'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin batch manager global — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=batch_manager&filter=all'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin photos_add_direct — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=photos_add&section=direct'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin configuration — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=configuration'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin history — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=history'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin plugins installed — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=plugins'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin languages — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=languages'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin themes — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=themes'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

test('admin maintenance — no JS errors', async ({ page }) => {
    const getErrors = attachErrorCollector(page);
    await loginAsAdmin(page);
    await page.goto(pwgUrl('/admin.php?page=maintenance'));
    expect(
        getErrors(),
        `pageerrors: ${getErrors()
            .map((e) => e.message)
            .join('; ')}`
    ).toHaveLength(0);
});

// ── Verify hashed dist URLs are served when manifest exists ─────────────────

test('gallery home loads hashed dist script URL', async ({ page }) => {
    const scriptUrls: string[] = [];
    page.on('request', (req) => {
        if (req.resourceType() === 'script') scriptUrls.push(req.url());
    });
    // ?no_photo_yet=browse bypasses the "no photos yet" splash page and
    // loads the standard gallery template (which includes Vite-built scripts).
    await page.goto(pwgUrl('/index.php?no_photo_yet=browse'));
    // Scripts may be served directly from dist/assets/ or combined by FileCombiner
    // into _data/combined/. Either way the Vite manifest is being used.
    const viteUrls = scriptUrls.filter(
        (u) => u.includes('/dist/assets/') || u.includes('/_data/combined/')
    );
    expect(
        viteUrls.length,
        `Expected at least one Vite or combined script; got: ${scriptUrls.join(', ')}`
    ).toBeGreaterThan(0);
});
