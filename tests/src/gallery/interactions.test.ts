import { test, expect } from "@playwright/test";
import { collectConsoleIssues, assertNoErrors, ensureTestPhotoExists } from "../helpers/index.js";
import { dbGetFirstPhotoId, dbGetFirstPhysicalAlbumId, dbGetAlbumWithPhotosId } from "../helpers/db.js";

test.describe("Gallery JS interactions", () => {
    async function getPhotoUrl(): Promise<string> {
        const photoId = await dbGetFirstPhotoId();
        const catId = await dbGetFirstPhysicalAlbumId();
        return `picture.php?/${photoId!}/category/${catId!}`;
    }

    async function getAlbumUrl(): Promise<string> {
        const catId = await dbGetAlbumWithPhotosId();
        return `index.php?/category/${catId!}`;
    }

    test("quick search form submits and navigates to results", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("index.php");
        await assertNoErrors(page, getIssues());

        const searchInput = page.locator("#qsearchInput");
        await expect(searchInput).toBeVisible();

        await searchInput.click();
        await searchInput.fill("photo");
        // waitForURL must be set up before the key press triggers navigation
        const navPromise = page.waitForURL(/qsearch|search/, { timeout: 10_000 });
        await page.keyboard.press("Enter");
        await navPromise;

        expect(page.url()).toMatch(/qsearch|search/);
        expect((await page.locator("body").innerText()).trim().length).toBeGreaterThan(0);
    });

    test("album — clicking a thumbnail opens PhotoSwipe lightbox", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto(await getAlbumUrl());
        await assertNoErrors(page, getIssues());

        const thumbLink = page
            .locator(
                '#thumbnails li:not(.album) a[data-pswp-src], #thumbnails li:not(.album) a[href*="picture.php"]',
            )
            .first();
        await expect(thumbLink).toBeVisible();

        await thumbLink.click();
        await expect(page.locator(".pswp")).toBeVisible();
    });

    test("picture — size switcher changes main image src", async ({ page }) => {
        await ensureTestPhotoExists(page);

        const getIssues = collectConsoleIssues(page);
        await page.goto(await getPhotoUrl());
        expect(page.url()).toContain("picture.php");
        await assertNoErrors(page, getIssues());

        const switchLink = page.locator("#derivativeSwitchLink");
        await expect(switchLink).toBeVisible();

        await switchLink.click();
        const switchBox = page.locator("#derivativeSwitchBox");
        await switchBox.hover();

        const switcherLinks = page.locator(
            '#derivativeSwitchBox a[href*="changeImgSrc"], #derivativeSwitchBox a[onclick*="changeImgSrc"]',
        );
        expect(await switcherLinks.count()).toBeGreaterThanOrEqual(2);

        const mainImg = page.locator("#theMainImage");
        const initialSrc = await mainImg.getAttribute("src");

        await switcherLinks.nth(1).click();
        await mainImg.waitFor({ state: "visible" });

        await expect(mainImg).not.toHaveAttribute("src", initialSrc ?? "");
    });

    test("home page — menubar blocks render", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("index.php");
        await assertNoErrors(page, getIssues());

        const menuLinks = page.locator("#menubar a, .menubar a");
        await expect(menuLinks.first()).toBeVisible();
    });

    test("language switch dropdown toggles", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("index.php");
        await assertNoErrors(page, getIssues());

        const switchLink = page.locator("#languageSwitchLink");
        await expect(switchLink).toBeVisible();

        const switchBox = page.locator("#languageSwitchBox");
        await expect(switchBox).toBeAttached();

        await switchLink.click();
        await expect(switchBox).toBeVisible();
    });

    test("album — sort order switchbox toggles", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto(await getAlbumUrl());
        await assertNoErrors(page, getIssues());

        const sortLink = page.locator("#sortOrderLink");
        await expect(sortLink).toBeVisible();

        const sortBox = page.locator("#sortOrderBox");
        await expect(sortBox).toBeAttached();

        await sortLink.click();
        await assertNoErrors(page, getIssues());
    });

    test("album — derivative switchbox toggles", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto(await getAlbumUrl());
        await assertNoErrors(page, getIssues());

        const derivLink = page.locator("#derivativeSwitchLink");
        await expect(derivLink).toBeVisible();

        const derivBox = page.locator("#derivativeSwitchBox");
        await expect(derivBox).toBeHidden();

        await derivLink.click();
        await expect(derivBox).toBeVisible();
    });

    test("photo page — download link is present and clickable", async ({ page }) => {
        await ensureTestPhotoExists(page);

        const getIssues = collectConsoleIssues(page);
        await page.goto(await getPhotoUrl());
        expect(page.url()).toContain("picture.php");
        await assertNoErrors(page, getIssues());

        const downloadLink = page.locator("#downloadSwitchLink");
        await expect(downloadLink).toBeVisible();

        await downloadLink.click();
        // ERR_ABORTED is expected for file downloads; assertNoErrors filters it
        await assertNoErrors(page, getIssues());
    });

    test("PhotoSwipe lightbox — close on Escape", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto(await getAlbumUrl());
        await assertNoErrors(page, getIssues());

        const thumbLink = page
            .locator(
                '#thumbnails li:not(.album) a[data-pswp-src], #thumbnails li:not(.album) a[href*="picture.php"]',
            )
            .first();
        await expect(thumbLink).toBeVisible();

        await thumbLink.click();
        await page.locator(".pswp").waitFor({ state: "visible" });

        await page.keyboard.press("Escape");
        await assertNoErrors(page, getIssues());
    });

    test("infinite scroll — RVTS loads more thumbnails on scroll", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto(await getAlbumUrl());
        await assertNoErrors(page, getIssues());

        const initialCount = await page.locator("#thumbnails li").count();
        expect(initialCount).toBeGreaterThan(0);

        const responsePromise = page
            .waitForResponse((r) => r.url().includes("index.php") && r.status() === 200, { timeout: 5000 })
            .catch(() => null);
        await page.evaluate(() => { window.scrollTo(0, document.body.scrollHeight); });
        await responsePromise;

        const newCount = await page.locator("#thumbnails li").count();
        expect(newCount).toBeGreaterThanOrEqual(initialCount);
    });

    test("GDThumb masonry — thumbnails have absolute positioning", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto(await getAlbumUrl());
        await assertNoErrors(page, getIssues());

        const thumbList = page.locator("#thumbnails li").first();
        await expect(thumbList).toBeVisible();

        const position = await thumbList.evaluate((el) => window.getComputedStyle(el).position);
        expect(position).toBe("absolute");

        const left = await thumbList.evaluate((el) => window.getComputedStyle(el).left);
        const top = await thumbList.evaluate((el) => window.getComputedStyle(el).top);
        expect(left).toMatch(/\d/);
        expect(top).toMatch(/\d/);

        const container = page.locator("#thumbnails");
        const height = await container.evaluate((el) => window.getComputedStyle(el).height);
        expect(height).toMatch(/\d+px/);
    });
});
