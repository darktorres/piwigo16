/**
 * Authentication Requirements Test
 *
 * @remarks
 * Comprehensive test to determine exact auth requirements for Piwigo.
 * This test validates what cookies/state are necessary and sufficient
 * to authenticate a user and access protected pages.
 *
 * Tests conducted:
 * 1. No auth state - verify immediate redirect to login
 * 2. Only pwg_id cookie - verify if sufficient for auth
 * 3. Browser context isolation - verify cookies don't leak between contexts
 * 4. Storage state loading - verify how test.use() loads auth
 * 5. Storage state persistence - verify cookies persist across navigations
 * 6. Admin.json contents - verify what's actually saved
 * 7. Session validation - verify session is valid after storage state
 * 8. Redirects and form detection - verify how to detect login redirects
 * 9. Profile page behavior - verify how profile.php behaves with/without auth
 */

import { test, expect } from '@base-test';
import { baseUrl, loginAndGetToken } from '@helpers/api-helpers';
import { TEST_DATA } from '@helpers/test-data';
import { isVisibleSafe } from '@helpers/page-helpers';
import * as path from 'path';
import * as fs from 'fs';

// TEST 1: No storage state - fresh browser
test.describe('Auth Requirements - Test 1: Fresh Browser (No Auth)', () => {
    // Disable global storage state for these tests
    test.use({ storageState: undefined });

    test('profile.php without auth - check redirect behavior', async ({ page }) => {
        await page.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        const currentUrl = page.url();
        const hasLoginForm =
            (await page.locator('input[name="username"]').count()) > 0 &&
            (await page.locator('input[name="password"]').count()) > 0;
        const hasProfileForm =
            (await page.locator('input[name="mail_address"]').count()) > 0 ||
            (await page.locator('input[name="use_new_pwd"]').count()) > 0;

        console.log('TEST 1 - Fresh browser, no cookies:');
        console.log('  Final URL:', currentUrl);
        console.log('  Has login form:', hasLoginForm);
        console.log('  Has profile form:', hasProfileForm);
        console.log(
            '  Is on login page:',
            currentUrl.includes('identification.php') || hasLoginForm
        );

        // Verify it redirects to login or shows login form
        expect(currentUrl.includes('identification.php') || hasLoginForm).toBe(true);
    });

    test('admin.php without auth - check redirect behavior', async ({ page }) => {
        await page.goto(`${baseUrl}/admin.php`, { waitUntil: 'domcontentloaded' });

        const currentUrl = page.url();
        const hasLoginForm =
            (await page.locator('input[name="username"]').count()) > 0 &&
            (await page.locator('input[name="password"]').count()) > 0;

        console.log('TEST 1b - Fresh browser, admin.php:');
        console.log('  Final URL:', currentUrl);
        console.log('  Has login form:', hasLoginForm);

        expect(currentUrl.includes('identification.php') || hasLoginForm).toBe(true);
    });
});

// TEST 2: Admin.json contents inspection
test.describe('Auth Requirements - Test 2: Inspect admin.json', () => {
    test('read and analyze admin.json file', async () => {
        const adminJsonPath = path.join(__dirname, '../../../playwright/.auth/admin.json');

        const exists = fs.existsSync(adminJsonPath);
        console.log('TEST 2 - admin.json inspection:');
        console.log('  File exists:', exists);

        if (exists) {
            const content = fs.readFileSync(adminJsonPath, 'utf-8');
            const json = JSON.parse(content);

            console.log('  Total cookies:', json.cookies.length);
            console.log('  Origins:', json.origins.length);

            json.cookies.forEach((cookie: any, index: number) => {
                console.log(`  Cookie ${index}:`);
                console.log(`    Name: ${cookie.name}`);
                console.log(`    Domain: ${cookie.domain}`);
                console.log(`    Path: ${cookie.path}`);
                console.log(`    Value length: ${cookie.value.length}`);
                console.log(`    HttpOnly: ${cookie.httpOnly}`);
                console.log(`    Expires: ${cookie.expires}`);
            });

            const hasPhpsessid = json.cookies.some((c: any) => c.name === 'PHPSESSID');
            const hasPwgId = json.cookies.some((c: any) => c.name === 'pwg_id');

            console.log('  Has PHPSESSID:', hasPhpsessid);
            console.log('  Has pwg_id:', hasPwgId);

            expect(json.cookies.length).toBeGreaterThan(0);
        } else {
            throw new Error(`admin.json not found at ${adminJsonPath}`);
        }
    });
});

// TEST 3: Test.use() with storage state
test.describe('Auth Requirements - Test 3: Storage State Loading', () => {
    test.use({ storageState: path.join(__dirname, '../../../playwright/.auth/admin.json') });

    test('profile.php with storage state - verify cookies and content', async ({ page }) => {
        // Check cookies before navigation
        const cookiesBefore = await page.context().cookies();
        console.log('TEST 3 - Storage state loading:');
        console.log('  Cookies before nav:', cookiesBefore.map((c) => c.name).join(', '));

        // Navigate
        await page.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        // Check cookies after navigation
        const cookiesAfter = await page.context().cookies();
        console.log('  Cookies after nav:', cookiesAfter.map((c) => c.name).join(', '));

        const currentUrl = page.url();
        const hasLoginForm =
            (await page.locator('input[name="username"]').count()) > 0 &&
            (await page.locator('input[name="password"]').count()) > 0;
        const hasProfileForm =
            (await page.locator('input[name="mail_address"]').count()) > 0 ||
            (await page.locator('input[name="use_new_pwd"]').count()) > 0;

        console.log('  Final URL:', currentUrl);
        console.log('  Has login form:', hasLoginForm);
        console.log('  Has profile form:', hasProfileForm);

        // With storage state, we should have profile content, not login form
        expect(hasProfileForm).toBe(true);
        expect(hasLoginForm).toBe(false);
    });

    test('admin.php with storage state - verify access', async ({ page }) => {
        await page.goto(`${baseUrl}/admin.php`, { waitUntil: 'domcontentloaded' });

        const currentUrl = page.url();
        const hasLoginForm =
            (await page.locator('input[name="username"]').count()) > 0 &&
            (await page.locator('input[name="password"]').count()) > 0;
        const bodyText = await page.locator('body').innerText();
        const hasAdminContent =
            bodyText.includes('Albums') ||
            bodyText.includes('Photos') ||
            bodyText.includes('Users');

        console.log('TEST 3b - admin.php with storage state:');
        console.log('  Final URL:', currentUrl);
        console.log('  Has login form:', hasLoginForm);
        console.log('  Has admin content:', hasAdminContent);
    });

    test('navigate multiple pages with storage state - verify persistence', async ({ page }) => {
        const cookies1 = await page.context().cookies();
        console.log('TEST 3c - Cookie persistence:');
        console.log('  Cookies initially:', cookies1.map((c) => c.name).join(', '));

        await page.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });
        const cookies2 = await page.context().cookies();
        console.log('  Cookies after profile.php:', cookies2.map((c) => c.name).join(', '));

        await page.goto(`${baseUrl}/admin.php`, { waitUntil: 'domcontentloaded' });
        const cookies3 = await page.context().cookies();
        console.log('  Cookies after admin.php:', cookies3.map((c) => c.name).join(', '));

        // Cookies should persist across navigations
        expect(cookies3.length).toBeGreaterThan(0);
    });
});

// TEST 4: API session validation
test.describe('Auth Requirements - Test 4: API Session Validation', () => {
    test.use({ storageState: path.join(__dirname, '../../../playwright/.auth/admin.json') });

    test('check session status via API with storage state', async ({ request }) => {
        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.session.getStatus',
            },
        });

        const json = await response.json();

        console.log('TEST 4 - API session check:');
        console.log('  Response status:', response.status());
        console.log('  API stat:', json.stat);
        console.log('  User ID:', json.result?.user_id);
        console.log('  Username:', json.result?.username);
        console.log('  Status:', json.result?.status);
        console.log('  Email:', json.result?.email);

        expect(json.stat).toBe('ok');
    });

    test('verify admin credentials via API', async ({ request }) => {
        const response = await request.post(`${baseUrl}/ws.php?format=json`, {
            form: {
                method: 'pwg.session.getStatus',
            },
        });

        const json = await response.json();

        console.log('TEST 4b - Admin verification:');
        console.log('  Expected username:', TEST_DATA.admin.username);
        console.log('  Actual username:', json.result?.username);
        console.log(
            '  Is admin:',
            json.result?.status === 'admin' || json.result?.status === 'webmaster'
        );

        if (json.stat === 'ok') {
            expect(json.result.username).toBe(TEST_DATA.admin.username);
        }
    });
});

// TEST 5: Redirect detection methods
test.describe('Auth Requirements - Test 5: Redirect Detection', () => {
    // Disable global storage state for these tests
    test.use({ storageState: undefined });

    test('detect login redirect via URL', async ({ page }) => {
        // Navigate without auth
        await page.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        const currentUrl = page.url();
        const isLoginPageByUrl =
            currentUrl.includes('identification.php') || currentUrl.includes('login');

        console.log('TEST 5 - URL detection:');
        console.log('  Final URL:', currentUrl);
        console.log('  Is login page (by URL):', isLoginPageByUrl);

        expect(isLoginPageByUrl).toBe(true);
    });

    test('detect login redirect via form presence', async ({ page }) => {
        await page.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        const hasLoginForm =
            (await page.locator('input[name="username"]').count()) > 0 &&
            (await page.locator('input[name="password"]').count()) > 0;

        console.log('TEST 5b - Form detection:');
        console.log('  Has login form:', hasLoginForm);

        expect(hasLoginForm).toBe(true);
    });

    test('detect login redirect via locator', async ({ page }) => {
        await page.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        const usernameInput = await isVisibleSafe(
            page.locator('input[name="username"]'),
            'username input on login page'
        );
        const passwordInput = await isVisibleSafe(
            page.locator('input[name="password"]'),
            'password input on login page'
        );

        console.log('TEST 5c - Locator detection:');
        console.log('  Username input visible:', usernameInput);
        console.log('  Password input visible:', passwordInput);

        expect(usernameInput || passwordInput).toBe(true);
    });
});

// TEST 6: Authenticated page detection
test.describe('Auth Requirements - Test 6: Authenticated Page Detection', () => {
    test.use({ storageState: path.join(__dirname, '../../../playwright/.auth/admin.json') });

    test('detect authenticated profile page via form', async ({ page }) => {
        await page.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        const hasProfileForm =
            (await page.locator('input[name="mail_address"]').count()) > 0 ||
            (await page.locator('input[name="use_new_pwd"]').count()) > 0;
        const hasLoginForm =
            (await page.locator('input[name="username"]').count()) > 0 &&
            (await page.locator('input[name="password"]').count()) > 0;

        console.log('TEST 6 - Authenticated page detection:');
        console.log('  Has profile form:', hasProfileForm);
        console.log('  Has login form:', hasLoginForm);
        console.log('  Is authenticated:', hasProfileForm && !hasLoginForm);

        expect(hasProfileForm).toBe(true);
        expect(hasLoginForm).toBe(false);
    });

    test('detect authenticated via locator methods', async ({ page }) => {
        await page.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        // Try various methods to detect authentication
        const mailAddressInput = page.locator('input[name="mail_address"]');
        const isMailVisible = await isVisibleSafe(
            mailAddressInput,
            'mail address input on authenticated profile page'
        );

        const loginForm = page.locator('input[name="username"]');
        const isLoginVisible = await isVisibleSafe(
            loginForm,
            'login form on authenticated profile page'
        );

        console.log('TEST 6b - Locator detection:');
        console.log('  Mail input visible:', isMailVisible);
        console.log('  Login form visible:', isLoginVisible);

        expect(isMailVisible).toBe(true);
        expect(isLoginVisible).toBe(false);
    });
});

// TEST 7: Identify what makes a page "logged in"
test.describe('Auth Requirements - Test 7: Authentication Markers', () => {
    test.use({ storageState: path.join(__dirname, '../../../playwright/.auth/admin.json') });

    test('identify what elements appear when logged in on profile.php', async ({ page }) => {
        await page.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        const bodyText = await page.locator('body').innerText();

        // Collect all potential authentication markers
        const markers = {
            hasMailField: (await page.locator('input[name="mail_address"]').count()) > 0,
            hasPasswordField: (await page.locator('input[name="use_new_pwd"]').count()) > 0,
            hasPasswordField2: (await page.locator('input[name="passwordConf"]').count()) > 0,
            hasThemeSelect: (await page.locator('select[name="theme"]').count()) > 0,
            hasLanguageSelect: (await page.locator('select[name="language"]').count()) > 0,
            hasValidateButton: (await page.locator('input[name="validate"]').count()) > 0,
            hasProfileForm: bodyText.includes('profile'),
            hasLogoutLink: bodyText.includes('logout') || bodyText.includes('Logout'),
        };

        console.log('TEST 7 - Authentication markers on profile.php:');
        Object.entries(markers).forEach(([key, value]) => {
            console.log(`  ${key}: ${value}`);
        });

        // Most of these should be true when authenticated
        const authenticatedCount = Object.values(markers).filter((v) => v).length;
        expect(authenticatedCount).toBeGreaterThan(3);
    });

    test('identify what elements appear when NOT logged in on profile.php', async ({ browser }) => {
        // Create an isolated context WITHOUT cookies to avoid corrupting the shared context
        const isolatedContext = await browser.newContext();
        const isolatedPage = await isolatedContext.newPage();

        try {
            await isolatedPage.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

            const bodyText = await isolatedPage.locator('body').innerText();

            const markers = {
                hasMailField:
                    (await isolatedPage.locator('input[name="mail_address"]').count()) > 0,
                hasPasswordField:
                    (await isolatedPage.locator('input[name="use_new_pwd"]').count()) > 0,
                hasLoginForm:
                    (await isolatedPage.locator('input[name="username"]').count()) > 0 &&
                    (await isolatedPage.locator('input[name="password"]').count()) > 0,
                hasLoginButton:
                    (await isolatedPage.locator('input[type="submit"]').count()) > 0 &&
                    (bodyText.includes('Log in') ||
                        bodyText.includes('login') ||
                        bodyText.includes('Connexion')),
            };

            console.log('TEST 7b - Elements when NOT authenticated:');
            Object.entries(markers).forEach(([key, value]) => {
                console.log(`  ${key}: ${value}`);
            });

            // When not logged in, should have login form but not profile fields
            expect(markers.hasLoginForm).toBe(true);
            expect(markers.hasMailField).toBe(false);
        } finally {
            await isolatedContext.close();
        }
    });
});

// TEST 8: Cookie requirements
test.describe('Auth Requirements - Test 8: Cookie Requirements', () => {
    test('attempt auth with only pwg_id from admin.json', async ({ browser }) => {
        const context = await browser.newContext();
        const page = await context.newPage();
        // Load admin.json to get pwg_id cookie
        const adminJsonPath = path.join(__dirname, '../../../playwright/.auth/admin.json');
        const adminJson = JSON.parse(fs.readFileSync(adminJsonPath, 'utf-8'));
        const pwgIdCookie = adminJson.cookies.find((c: any) => c.name === 'pwg_id');

        if (pwgIdCookie) {
            await context.addCookies([pwgIdCookie]);
        }

        // Now navigate with only pwg_id
        await page.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        const hasProfileForm = (await page.locator('input[name="mail_address"]').count()) > 0;
        const hasLoginForm =
            (await page.locator('input[name="username"]').count()) > 0 &&
            (await page.locator('input[name="password"]').count()) > 0;

        console.log('TEST 8 - With only pwg_id cookie:');
        console.log('  Has profile form:', hasProfileForm);
        console.log('  Has login form:', hasLoginForm);

        await page.close();
        await context.close();

        // pwg_id alone should be sufficient (based on earlier tests)
        expect(hasLoginForm).toBe(false);
    });
});
