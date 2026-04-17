import { test, expect } from "@playwright/test";
import {
    collectConsoleIssues,
    assertNoErrors,
    ensureTestPhotoExists,
    dbGetFirstPhotoId,
    dbGetAdminUserId,
    dbGetFirstGroupId,
} from "../helpers/index.js";

test.describe("Admin smoke — every page loads without errors", () => {
    test.beforeEach(async ({ page }) => {
        await ensureTestPhotoExists(page);
    });

    async function smokeCheck(page: Parameters<typeof collectConsoleIssues>[0], path: string) {
        const getIssues = collectConsoleIssues(page);
        await page.goto(path);
        await expect(page.locator("body")).not.toBeEmpty();
        await assertNoErrors(page, getIssues());
    }

    test("dashboard", async ({ page }) => {
        await smokeCheck(page, "admin.php");
    });

    test("batch manager — global", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=batch_manager&section=global");
    });

    test("batch manager — unit", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=batch_manager&section=unit");
    });

    test("tags", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=tags");
    });

    test("rating", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=rating");
    });

    test("rating — per user", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=rating_user");
    });

    test("cat_list", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=cat_list");
    });

    test("user list", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=user_list");
    });

    test("user permissions", async ({ page }) => {
        const adminId = await dbGetAdminUserId();
        const userId = adminId ?? 1;
        await smokeCheck(page, `admin.php?page=user_perm&user_id=${userId}`);
    });

    test("group list", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=group_list");
    });

    test("group permissions", async ({ page }) => {
        const groupId = await dbGetFirstGroupId();
        expect(groupId, "At least one group must exist for group permissions test").not.toBeNull();
        await smokeCheck(page, `admin.php?page=group_perm&group_id=${groupId!}`);
    });

    test("maintenance", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=maintenance");
    });

    test("history", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=history");
    });

    test("stats", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=stats");
    });

    test("comments", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=comments");
    });

    test("configuration — main", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=configuration&section=main");
    });

    test("configuration — display", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=configuration&section=display");
    });

    test("configuration — comments", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=configuration&section=comments");
    });

    test("menubar", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=menubar");
    });

    test("permalinks", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=permalinks");
    });

    test("plugins — installed", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=plugins");
    });

    test("themes — installed", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=themes");
    });

    test("languages — installed", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=languages");
    });

    test("updates", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=updates");
    });

    test("photo properties page", async ({ page }) => {
        const photoId = await dbGetFirstPhotoId();
        expect(photoId, "At least one photo must exist in the DB").not.toBeNull();
        await smokeCheck(page, `admin.php?page=photo-${photoId!}`);
    });

    test("AdminTools plugin config", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=plugin-AdminTools");
    });

    test("GDThumb plugin config", async ({ page }) => {
        await smokeCheck(page, "admin.php?page=plugin-GDThumb");
    });

    test("batch manager — unit (first photo)", async ({ page }) => {
        const photoId = await dbGetFirstPhotoId();
        expect(photoId, "At least one photo must exist in the DB").not.toBeNull();
        await smokeCheck(page, `admin.php?page=batch_manager&section=unit&image_id=${photoId!}`);
    });
});
