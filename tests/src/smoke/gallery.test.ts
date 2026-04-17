import { test, expect } from "@playwright/test";
import { collectConsoleIssues, assertNoErrors } from "../helpers/index.js";
import { dbGetFirstPhotoId, dbGetFirstPhysicalAlbumId } from "../helpers/db.js";

test.describe("Gallery smoke — public pages load without errors", () => {
    async function smokeCheck(page: Parameters<typeof collectConsoleIssues>[0], path: string) {
        const getIssues = collectConsoleIssues(page);
        await page.goto(path);
        await expect(page.locator("body")).not.toBeEmpty();
        const title = await page.title();
        expect(title.trim().length).toBeGreaterThan(0);
        await assertNoErrors(page, getIssues());
    }

    test("home page", async ({ page }) => {
        await smokeCheck(page, "index.php");
    });

    test("first album", async ({ page }) => {
        const catId = await dbGetFirstPhysicalAlbumId();
        expect(catId, "At least one physical album must exist").not.toBeNull();
        await smokeCheck(page, `index.php?/category/${catId!}`);
    });

    test("first photo", async ({ page }) => {
        const photoId = await dbGetFirstPhotoId();
        const catId = await dbGetFirstPhysicalAlbumId();
        expect(photoId, "At least one photo must exist").not.toBeNull();
        expect(catId, "At least one album must exist").not.toBeNull();
        await smokeCheck(page, `picture.php?/${photoId!}/category/${catId!}`);
    });

    test("most visited", async ({ page }) => {
        await smokeCheck(page, "index.php?/most_visited");
    });

    test("best rated", async ({ page }) => {
        await smokeCheck(page, "index.php?/best_rated");
    });

    test("recent photos", async ({ page }) => {
        await smokeCheck(page, "index.php?/recent_pics");
    });

    test("recent albums", async ({ page }) => {
        await smokeCheck(page, "index.php?/recent_cats");
    });

    test("calendar", async ({ page }) => {
        await smokeCheck(page, "index.php?/calendar");
    });

    test("tags page", async ({ page }) => {
        await smokeCheck(page, "tags.php");
    });

    test("search page", async ({ page }) => {
        await smokeCheck(page, "search.php");
    });

    test("identification / login page", async ({ page }) => {
        await smokeCheck(page, "identification.php");
    });

    test("register page", async ({ page }) => {
        await smokeCheck(page, "register.php");
    });
});
