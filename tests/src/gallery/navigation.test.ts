import { test, expect } from "@playwright/test";
import { collectConsoleIssues, assertNoErrors, ensureTestPhotoExists } from "../helpers/index.js";
import { dbGetFirstPhotoId, dbGetFirstPhysicalAlbumId } from "../helpers/db.js";

test.describe("Gallery — navigation", () => {
    async function getPhotoUrl(): Promise<string> {
        const photoId = await dbGetFirstPhotoId();
        const catId = await dbGetFirstPhysicalAlbumId();
        return `picture.php?/${photoId!}/category/${catId!}`;
    }

    test("photo page — clicking next link navigates to next photo", async ({ page }) => {
        await ensureTestPhotoExists(page);

        const getIssues = collectConsoleIssues(page);
        await page.goto(await getPhotoUrl());
        expect(page.url()).toContain("picture.php");
        await assertNoErrors(page, getIssues());

        const nextLink = page.locator("#linkNext");
        await expect(nextLink).toBeVisible();

        const currentUrl = page.url();
        await nextLink.click();
        await page.waitForLoadState("load");

        const newUrl = page.url();
        expect(newUrl).not.toBe(currentUrl);
        expect(newUrl).toContain("picture.php");
    });

    test("photo page — image map navigation areas exist", async ({ page }) => {
        await ensureTestPhotoExists(page);

        const getIssues = collectConsoleIssues(page);
        await page.goto(await getPhotoUrl());
        expect(page.url()).toContain("picture.php");
        await assertNoErrors(page, getIssues());

        const mainImg = page.locator("#theMainImage");
        await expect(mainImg).toBeVisible();

        await expect(mainImg).toHaveAttribute("usemap", /^#/);
        const mapName = await mainImg.evaluate((el) => (el as HTMLImageElement).getAttribute("usemap"));

        const mapElem = page.locator(`map[name="${mapName!.substring(1)}"]`);
        await expect(mapElem).toBeAttached();

        const areas = mapElem.locator("area");
        expect(await areas.count()).toBeGreaterThanOrEqual(0);
    });
});
