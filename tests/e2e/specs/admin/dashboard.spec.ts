import { test, expect } from '@base-test';
import { DashboardPage } from '../../pages/admin/DashboardPage';
import { isElementVisible } from '@helpers/page-helpers';

const baseURL = process.env.PIWIGO_URL || 'http://localhost/piwigo16';

test.describe('Admin Dashboard @admin @smoke', () => {
    // Uses adminPage fixture - each test gets isolated authenticated session

    test('should load admin dashboard with complete navigation structure @critical', async ({
        adminPage,
    }) => {
        const dashboardPage = new DashboardPage(adminPage);
        await dashboardPage.goto();
        await dashboardPage.expectDashboard();
    });

    test('should display primary admin menu with all navigation options', async ({ adminPage }) => {
        const dashboardPage = new DashboardPage(adminPage);
        await dashboardPage.goto();
        await expect(dashboardPage.adminMenu).toBeVisible();
    });

    test('should include photos management link in admin menu', async ({ adminPage }) => {
        const dashboardPage = new DashboardPage(adminPage);
        await dashboardPage.goto();
        await expect(dashboardPage.photosLink.first()).toBeVisible();
    });

    test('should include albums management link in admin menu', async ({ adminPage }) => {
        const dashboardPage = new DashboardPage(adminPage);
        await dashboardPage.goto();
        await expect(dashboardPage.albumsLink.first()).toBeVisible();
    });

    test('should include user management link in admin menu', async ({ adminPage }) => {
        const dashboardPage = new DashboardPage(adminPage);
        await dashboardPage.goto();
        await expect(dashboardPage.usersLink.first()).toBeVisible();
    });

    test('should include plugins administration link in admin menu', async ({ adminPage }) => {
        const dashboardPage = new DashboardPage(adminPage);
        await dashboardPage.goto();
        await expect(dashboardPage.pluginsLink.first()).toBeVisible();
    });

    test('should include system configuration link in admin menu', async ({ adminPage }) => {
        const dashboardPage = new DashboardPage(adminPage);
        await dashboardPage.goto();
        await expect(dashboardPage.configLink.first()).toBeVisible();
    });

    test('should navigate to photos upload and batch management section', async ({ adminPage }) => {
        const dashboardPage = new DashboardPage(adminPage);
        await dashboardPage.goto();
        await dashboardPage.navigateToPhotos();
        await expect(adminPage).toHaveURL(/page=photos_add|page=batch_manager/);
    });

    test('should navigate to albums and categories management section', async ({ adminPage }) => {
        const dashboardPage = new DashboardPage(adminPage);
        await dashboardPage.goto();
        await dashboardPage.navigateToAlbums();
        await expect(adminPage).toHaveURL(/page=cat_list|page=album/);
    });

    test('should navigate to user management section with user list', async ({ adminPage }) => {
        const dashboardPage = new DashboardPage(adminPage);
        await dashboardPage.goto();
        await dashboardPage.navigateToUsers();
        await expect(adminPage).toHaveURL(/page=user_list/);
    });
});

test.describe('Admin Access Control @admin @auth', () => {
    test('should redirect to login when not authenticated', async ({ browser }) => {
        // Use a completely fresh browser context without any stored state
        const context = await browser.newContext({ storageState: undefined });
        const newPage = await context.newPage();
        await newPage.goto(`${baseURL}/admin.php`, { waitUntil: 'domcontentloaded' });

        const url = newPage.url();
        const hasLoginForm = await isElementVisible(newPage, '#username, input[name="username"]');
        const isLoginPage = url.includes('identification.php') || hasLoginForm;
        const hasAdminMenu = await isElementVisible(newPage, '#menubar #adminHome');

        expect(isLoginPage || !hasAdminMenu).toBeTruthy();

        await context.close();
    });

    test('admin should be able to logout from admin panel', async ({ adminPage }) => {
        const dashboardPage = new DashboardPage(adminPage);
        await dashboardPage.goto();
        await dashboardPage.expectDashboard();

        await dashboardPage.logout();

        await adminPage.goto(`${baseURL}/admin.php`, { waitUntil: 'domcontentloaded' });
        const hasLoginForm = await isElementVisible(adminPage, 'input[name="username"]');
        const isRedirected = adminPage.url().includes('identification.php');

        expect(hasLoginForm || isRedirected).toBeTruthy();
    });
});
