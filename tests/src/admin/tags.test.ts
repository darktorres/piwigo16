import { test, expect } from "@playwright/test";
import {
    collectConsoleIssues,
    assertNoErrors,
    ensureTestPhotoExists,
    uniqueName,
    dbFindTag,
    dbCleanupTestEntities,
} from "../helpers/index.js";
import { dbGetFirstPhotoId } from "../helpers/db.js";

test.describe("Admin — tags", () => {
    test.beforeEach(async ({ page }) => {
        await ensureTestPhotoExists(page);
    });

    test.afterAll(async () => {
        await dbCleanupTestEntities();
    });

    test("TomSelect tag widget initialises on photo properties page", async ({ page }) => {
        const photoId = await dbGetFirstPhotoId();
        expect(photoId, "At least one photo must exist").not.toBeNull();

        const getIssues = collectConsoleIssues(page);
        await page.goto(`admin.php?page=photo-${photoId!}`);
        await page.waitForLoadState("load");

        await assertNoErrors(page, getIssues());

        const tsControl = page.locator(".ts-control").first();
        await expect(tsControl).toBeVisible();

        // Type a character to trigger the "create" option in the dropdown
        // (dropdown won't open on empty focus when there are no options yet)
        const tsInput = tsControl.locator("input");
        await tsInput.fill("x");

        const dropdown = page.locator(".ts-dropdown");
        await expect(dropdown.first()).toBeVisible({ timeout: 5_000 });
    });

    test("create tag → verify DB → delete → verify gone", async ({ page }) => {
        const tagName = uniqueName("tag");
        const getIssues = collectConsoleIssues(page);

        await page.goto("admin.php?page=tags");
        await assertNoErrors(page, getIssues());

        // Click the add-tag label to reveal the input (it starts hidden)
        await page.locator("label.add-tag-label").click();
        const nameInput = page.locator("#add-tag-input");
        await expect(nameInput).toBeVisible();
        await nameInput.fill(tagName);
        await nameInput.press("Enter");

        // Wait for the new tag box to appear
        const tagEl = page.locator(".tag-box").filter({ hasText: tagName });
        await expect(tagEl).toBeVisible({ timeout: 10_000 });

        const dbTag = await dbFindTag(tagName);
        expect(dbTag, `Tag '${tagName}' should exist in DB`).not.toBeNull();
        const tagId = dbTag!.id;

        // Delete via ellipsis dropdown on the specific tag box
        const tagBox = page.locator(`.tag-box[data-id="${tagId}"]`);
        await expect(tagBox).toBeVisible();
        await tagBox.locator(".showOptions").click();

        const deleteOption = tagBox.locator(".dropdown-option.delete");
        await expect(deleteOption).toBeVisible();
        await deleteOption.click();

        // Confirm the pwgConfirm dialog
        const confirmBtn = page.locator("#pwg-confirm-dialog .pwg-confirm-buttons button.btn-red");
        await expect(confirmBtn).toBeVisible({ timeout: 5_000 });

        const deleteResponse = page.waitForResponse(
            (r) => r.url().includes("pwg.tags.delete"),
        );
        await confirmBtn.click();
        const resp = await deleteResponse;
        expect(resp.status()).toBe(200);
        const body = await resp.json() as { stat: string };
        expect(body.stat, `pwg.tags.delete should return stat=ok, got: ${JSON.stringify(body)}`).toBe("ok");

        // Check for JS errors after delete
        await assertNoErrors(page, getIssues());

        // Wait for tag box to be removed from DOM
        await expect(tagBox).not.toBeAttached({ timeout: 10_000 });

        const dbTagAfter = await dbFindTag(tagName);
        expect(dbTagAfter, `Tag '${tagName}' should be deleted from DB`).toBeNull();
    });
});
