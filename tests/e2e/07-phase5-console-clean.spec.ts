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
import { pwgUrl, adminUrl, identificationUrl } from './helpers/url';
import { gotoOk } from './helpers/strict-assertions';
import { attachMonitor } from './helpers/page-monitor';

// ── Gallery (anonymous) routes ──────────────────────────────────────────────

const ANON_ROUTES: ReadonlyArray<{ name: string; url: () => string }> = [
    { name: 'gallery home', url: () => pwgUrl('/index.php') },
    { name: 'identification', url: () => identificationUrl() },
    { name: 'search', url: () => pwgUrl('/index.php?/search') },
    { name: 'tags', url: () => pwgUrl('/index.php?/tags') },
];

for (const route of ANON_ROUTES) {
    test(`${route.name} — clean (no JS errors, no failed XHRs)`, async ({ page }) => {
        const monitor = attachMonitor(page);
        await gotoOk(page, route.url(), route.name);
        monitor.assertClean(route.name);
    });
}

// ── Admin (authenticated) routes ────────────────────────────────────────────

const ADMIN_ROUTES: ReadonlyArray<{ name: string; url: () => string }> = [
    { name: 'admin dashboard', url: () => adminUrl() },
    { name: 'admin albums', url: () => adminUrl('albums') },
    {
        name: 'admin batch_manager (filter=all)',
        url: () => adminUrl('batch_manager') + '&filter=all',
    },
    { name: 'admin photos_add direct', url: () => adminUrl('photos_add') + '&section=direct' },
    { name: 'admin configuration', url: () => adminUrl('configuration') },
    { name: 'admin history', url: () => adminUrl('history') },
    { name: 'admin plugins', url: () => adminUrl('plugins') },
    { name: 'admin languages', url: () => adminUrl('languages') },
    { name: 'admin themes', url: () => adminUrl('themes') },
    { name: 'admin maintenance', url: () => adminUrl('maintenance') },
];

for (const route of ADMIN_ROUTES) {
    test(`${route.name} — clean (no JS errors, no failed XHRs)`, async ({ page }) => {
        const monitor = attachMonitor(page);
        await loginAsAdmin(page);
        // Reset because loginAsAdmin's navigations may have triggered routine
        // requests that we don't want to attribute to this route's check.
        monitor.reset();
        await gotoOk(page, route.url(), route.name);
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
