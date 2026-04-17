import { test, expect } from "@playwright/test";
import {
    collectConsoleIssues,
    assertNoErrors,
    ensureTestPhotoExists,
    dbGetFirstPhotoId,
    dbGetFirstPhysicalAlbumId,
    dbGetAdminUserId,
    dbGetRating,
    dbDeleteRating,
} from "../helpers/index.js";
import type { Locator } from "@playwright/test";

test.describe("Gallery — rating", () => {
    async function getPhotoUrl(): Promise<string> {
        const photoId = await dbGetFirstPhotoId();
        const catId = await dbGetFirstPhysicalAlbumId();
        return `picture.php?/${photoId!}/category/${catId!}`;
    }

    // After rating.js runs, buttons get class rateButtonStarFull or rateButtonStarEmpty
    const RATE_BTN_SELECTOR = ".rateButtonStarFull, .rateButtonStarEmpty";

    test("rating widget — clicking a star updates the displayed score", async ({ page }) => {
        await ensureTestPhotoExists(page);

        const getIssues = collectConsoleIssues(page);
        await page.goto(await getPhotoUrl());
        expect(page.url()).toContain("picture.php");
        await assertNoErrors(page, getIssues());

        const rateButtons = page.locator(RATE_BTN_SELECTOR);
        await expect(rateButtons.first()).toBeVisible();

        const scoreEl = page.locator("#ratingScore");
        const countEl = page.locator("#ratingCount");
        const initialScore = await scoreEl.textContent();

        const middleBtn = rateButtons.nth(Math.floor((await rateButtons.count()) / 2));

        const responsePromise = page.waitForResponse((r) => r.url().includes("ws.php"));
        await middleBtn.click();
        await responsePromise;

        const newScore = await scoreEl.textContent();
        expect(newScore?.trim()).not.toBe("");
        expect(newScore?.trim()).not.toBe(initialScore?.trim());

        const countText = await countEl.textContent();
        expect(countText).toMatch(/\d/);
    });

    test("photo page — rating star hover changes class", async ({ page }) => {
        await ensureTestPhotoExists(page);

        const photoId = await dbGetFirstPhotoId();
        const adminId = await dbGetAdminUserId();
        // Remove any existing rating so all buttons start as rateButtonStarEmpty
        if (photoId !== null && adminId !== null) {
            await dbDeleteRating(photoId, adminId);
        }

        const getIssues = collectConsoleIssues(page);
        await page.goto(await getPhotoUrl());
        expect(page.url()).toContain("picture.php");
        await assertNoErrors(page, getIssues());

        const rateButtons = page.locator(RATE_BTN_SELECTOR);
        await expect(rateButtons.first()).toBeVisible();

        // Use last button: with no user rating it's rateButtonStarEmpty; hovering makes it Full
        const count = await rateButtons.count();
        const lastBtn: Locator = rateButtons.nth(count - 1);

        await expect(lastBtn).toHaveClass("rateButtonStarEmpty");

        await lastBtn.hover();
        await expect(lastBtn).toHaveClass("rateButtonStarFull");

        await page.mouse.move(0, 0);
        await expect(lastBtn).toHaveClass("rateButtonStarEmpty");
    });

    test("click star as admin → verify DB → cleanup", async ({ page }) => {
        await ensureTestPhotoExists(page);

        const photoId = await dbGetFirstPhotoId();
        const adminId = await dbGetAdminUserId();
        expect(photoId, "Photo must exist").not.toBeNull();
        expect(adminId, "Admin user must exist").not.toBeNull();

        await dbDeleteRating(photoId!, adminId!);

        const getIssues = collectConsoleIssues(page);
        await page.goto(await getPhotoUrl());
        await assertNoErrors(page, getIssues());

        const rateButtons = page.locator(RATE_BTN_SELECTOR);
        await expect(rateButtons.first()).toBeVisible();

        const btnCount = await rateButtons.count();
        const targetBtn = rateButtons.nth(Math.floor(btnCount / 2));
        // title attr holds the mark value (JS doesn't clear it)
        const rateValue = await targetBtn.getAttribute("title");
        expect(rateValue).toMatch(/^\d+$/);

        const responsePromise = page.waitForResponse((r) => r.url().includes("ws.php"));
        await targetBtn.click();
        await responsePromise;

        const dbRate = await dbGetRating(photoId!, adminId!);
        expect(dbRate, `Rate row should exist in DB for photo ${photoId!} user ${adminId!}`).not.toBeNull();
        expect(dbRate).toBe(parseInt(rateValue!, 10));

        await dbDeleteRating(photoId!, adminId!);
    });
});
