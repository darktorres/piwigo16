import { test, expect } from "@playwright/test";
import {
    collectConsoleIssues,
    assertNoErrors,
    uniqueName,
    dbFindAlbum,
    dbCleanupTestEntities,
} from "../helpers/index.js";

test.describe("Admin — albums", () => {
    test.afterAll(async () => {
        await dbCleanupTestEntities();
    });

    test("create virtual album → verify DB → delete → verify gone", async ({ page }) => {
        const albumName = uniqueName("album");
        const getIssues = collectConsoleIssues(page);

        await page.goto("admin.php?page=cat_list");
        await assertNoErrors(page, getIssues());

        // The .addAlbum tile intercepts pointer events until clicked to expand
        await page.locator(".addAlbum").click();

        const virtualNameInput = page.locator('input[name="virtual_name"]');
        await expect(virtualNameInput).toBeVisible();
        await virtualNameInput.fill(albumName);

        const submitBtn = page.locator('.addAlbum button[name="submitAdd"]');
        await submitBtn.click();
        await page.waitForLoadState("load");

        const albumEl = page
            .locator(`a:text("${albumName}"), span:text("${albumName}"), div.albumTitle:text("${albumName}")`)
            .first();
        await expect(albumEl).toBeVisible();

        const dbAlbum = await dbFindAlbum(albumName);
        expect(dbAlbum, `Album '${albumName}' should exist in DB with dir IS NULL`).not.toBeNull();
        const albumId = dbAlbum!.id;

        const pwgToken = await page.locator('form input[name="pwg_token"]').first().inputValue();

        // Navigate directly to the delete URL (PHP handles delete on GET with correct params)
        await page.goto(`admin.php?page=cat_list&delete=${albumId}&pwg_token=${pwgToken}`);
        await page.waitForLoadState("load");

        const dbAlbumAfter = await dbFindAlbum(albumName);
        expect(dbAlbumAfter, `Album '${albumName}' should be deleted from DB`).toBeNull();
    });
});
