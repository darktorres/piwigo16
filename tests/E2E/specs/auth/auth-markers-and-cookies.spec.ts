/**
 * Auth Requirements - Test 6: Authenticated Page Detection & Auth Requirements - Test 7: Authentication Markers & Auth Requirements - Test 8: Cookie Requirements Tests
 *
 * @remarks
 * Tests authentication detection and cookie requirements.
 * Uses the `adminPage` fixture for isolated, pre-authenticated sessions.
 *
 * Tests in this file:
 * - Auth Requirements - Test 6: Authenticated Page Detection
 * - Auth Requirements - Test 7: Authentication Markers
 * - Auth Requirements - Test 8: Cookie Requirements
 */

import { test, expect } from '@base-test';
import { baseUrl } from '@helpers/api-helpers';
import { TEST_DATA } from '@helpers/test-data';
import { isVisibleSafe } from '@helpers/page-helpers';
import * as path from 'path';
import * as fs from 'fs';

test.describe('Auth Requirements - Test 6: Authenticated Page Detection', () => {
    // Use adminPage fixture for isolated, pre-authenticated sessions
    // No storageState needed - each test gets its own fresh session

    test('detect authenticated profile page via form', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        // Check for profile form fields
        const mailField = adminPage.locator('input[name="mail_address"]');
        const newPwdField = adminPage.locator('input[name="use_new_pwd"]');
        const hasProfileForm = (await mailField.count()) > 0 || (await newPwdField.count()) > 0;

        // Check for login form
        const usernameField = adminPage.locator('input[name="username"]');
        const passwordField = adminPage.locator('input[name="password"]');
        const hasLoginForm = (await usernameField.count()) > 0 && (await passwordField.count()) > 0;

        console.log('TEST 6 - Authenticated page detection:');
        console.log('  Has profile form:', hasProfileForm);
        console.log('  Has login form:', hasLoginForm);
        console.log('  Is authenticated:', hasProfileForm && !hasLoginForm);

        expect(hasProfileForm).toBe(true);
        expect(hasLoginForm).toBe(false);
    });

    test('detect authenticated via locator methods', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        // Try various methods to detect authentication
        const mailAddressInput = adminPage.locator('input[name="mail_address"]');
        const isMailVisible = await isVisibleSafe(
            mailAddressInput,
            'mail address input on profile page'
        );

        const loginForm = adminPage.locator('input[name="username"]');
        const isLoginVisible = await isVisibleSafe(loginForm, 'login form on profile page');

        console.log('TEST 6b - Locator detection:');
        console.log('  Mail input visible:', isMailVisible);
        console.log('  Login form visible:', isLoginVisible);

        expect(isMailVisible).toBe(true);
        expect(isLoginVisible).toBe(false);
    });
});

test.describe('Auth Requirements - Test 7: Authentication Markers', () => {
    // Use adminPage fixture for isolated, pre-authenticated sessions

    test('identify what elements appear when logged in on profile.php', async ({ adminPage }) => {
        await adminPage.goto(`${baseUrl}/profile.php`, { waitUntil: 'domcontentloaded' });

        // Collect all potential authentication markers using element queries
        const markers = {
            hasMailField: (await adminPage.locator('input[name="mail_address"]').count()) > 0,
            hasPasswordField: (await adminPage.locator('input[name="use_new_pwd"]').count()) > 0,
            hasPasswordField2: (await adminPage.locator('input[name="passwordConf"]').count()) > 0,
            hasThemeSelect: (await adminPage.locator('select[name="theme"]').count()) > 0,
            hasLanguageSelect: (await adminPage.locator('select[name="language"]').count()) > 0,
            hasValidateButton:
                (await adminPage
                    .locator('button[name="validate"], input[name="validate"]')
                    .count()) > 0,
            hasProfileForm: (await adminPage.locator('text=profile').count()) > 0,
            hasLogoutLink:
                (await adminPage.locator('a:has-text("logout"), a:has-text("Logout")').count()) > 0,
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
