import { test, expect } from "@playwright/test";
import { collectConsoleIssues, assertNoErrors } from "../helpers/index.js";

test.describe("Admin — rating", () => {

    test("rating admin — category filter reveal/hide", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=rating");
        await assertNoErrors(page, getIssues());

        // Selectize hides the native <select>; interact via its UI
        const selectizeInput = page.locator('[data-selectize=categories] + .selectize-control .selectize-input');
        await expect(selectizeInput).toBeVisible();
        await selectizeInput.click();

        // Wait for dropdown and pick the first option
        const firstOption = page.locator('.selectize-dropdown .option').first();
        await expect(firstOption).toBeVisible({ timeout: 5_000 });
        await firstOption.click();

        await expect(page.locator("#removeAlbumFilter")).toBeVisible();
        await page.locator("#removeAlbumFilter").click();
        await expect(page.locator("#removeAlbumFilter")).toBeHidden();
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
