import { test, expect } from "@playwright/test";
import { collectConsoleIssues, assertNoErrors } from "../helpers/index.js";
import { dbGetFirstPhysicalAlbumId } from "../helpers/db.js";

test.describe("Admin — rating", () => {

    test("rating admin — category filter reveal/hide", async ({ page }) => {
        const catId = await dbGetFirstPhysicalAlbumId();
        expect(catId, "A physical album must exist").not.toBeNull();

        const getIssues = collectConsoleIssues(page);
        // Load with a pre-selected category so removeAlbumFilter appears
        await page.goto(`admin.php?page=rating&cat=${catId!}`);
        await assertNoErrors(page, getIssues());

        // After cache resolves, TomSelect adds the item and fires 'change',
        // which makes #removeAlbumFilter visible
        const removeBtn = page.locator("#removeAlbumFilter");
        await expect(removeBtn).toBeVisible({ timeout: 10_000 });
        await removeBtn.click();
        await expect(removeBtn).toBeHidden();
    });

    test("rating user — DataTables renders", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=rating_user");
        await assertNoErrors(page, getIssues());

        await expect(page.locator("#rateTable")).toBeVisible();
        await expect(page.locator(".dataTables_wrapper, #rateTable_wrapper").first()).toBeVisible();

        const headers = page.locator("#rateTable thead th");
        expect(await headers.count()).toBeGreaterThan(0);
    });
});
