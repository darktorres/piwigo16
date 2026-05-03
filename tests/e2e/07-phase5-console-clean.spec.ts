/**
 * Phase 5 exit signal: no console TypeError/SyntaxError on any entry-point route.
 * Verifies the Vite build is wired correctly (hashed dist URLs served) and that
 * none of the TS-compiled bundles throw at parse/eval time.
 *
 * Each route is asserted with the full error-monitor (pageerror + console.error
 * + 4xx/5xx XHRs), not just the JS-pageerror collector — bundle eval failures,
 * missing dist assets, and broken admin AJAX all need to fail this test.
 */

import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { pwgUrl } from './helpers/url';
import { gotoOk } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';

// ── Gallery (anonymous) routes ──────────────────────────────────────────────

const ANON_ROUTES = [
    { name: 'gallery home', path: '/index.php' },
    { name: 'identification', path: '/identification.php' },
    { name: 'search', path: '/search.php' },
    { name: 'tags', path: '/tags.php' },
] as const;

for (const route of ANON_ROUTES) {
    test(`${route.name} — clean (no JS errors, no failed XHRs)`, async ({ page }) => {
        const monitor = attachMonitor(page);
        await gotoOk(page, pwgUrl(route.path), route.name);
        monitor.assertClean(route.name);
    });
}

// ── Admin (authenticated) routes ────────────────────────────────────────────

const ADMIN_ROUTES = [
    { name: 'admin dashboard', path: '/admin.php' },
    { name: 'admin albums', path: '/admin.php?page=albums' },
    { name: 'admin batch_manager (filter=all)', path: '/admin.php?page=batch_manager&filter=all' },
    { name: 'admin photos_add direct', path: '/admin.php?page=photos_add&section=direct' },
    { name: 'admin configuration', path: '/admin.php?page=configuration' },
    { name: 'admin history', path: '/admin.php?page=history' },
    { name: 'admin plugins', path: '/admin.php?page=plugins' },
    { name: 'admin languages', path: '/admin.php?page=languages' },
    { name: 'admin themes', path: '/admin.php?page=themes' },
    { name: 'admin maintenance', path: '/admin.php?page=maintenance' },
] as const;

for (const route of ADMIN_ROUTES) {
    test(`${route.name} — clean (no JS errors, no failed XHRs)`, async ({ page }) => {
        const monitor = attachMonitor(page);
        await loginAsAdmin(page);
        // Reset because loginAsAdmin's navigations may have triggered routine
        // requests that we don't want to attribute to this route's check.
        monitor.reset();
        await gotoOk(page, pwgUrl(route.path), route.name);
        monitor.assertClean(route.name);
    });
}

// ── Verify hashed dist URLs are served when manifest exists ─────────────────

test('gallery home loads hashed dist script URL', async ({ page }) => {
    const scriptUrls: string[] = [];
    page.on('request', (req) => {
        if (req.resourceType() === 'script') scriptUrls.push(req.url());
    });
    // ?no_photo_yet=browse bypasses the "no photos yet" splash page and
    // loads the standard gallery template (which includes Vite-built scripts).
    await gotoOk(page, pwgUrl('/index.php?no_photo_yet=browse'), 'gallery home (browse)');
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
