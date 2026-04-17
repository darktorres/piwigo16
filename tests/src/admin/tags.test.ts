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

    test("selectize tag widget initialises on photo properties page", async ({ page }) => {
        const photoId = await dbGetFirstPhotoId();
        expect(photoId, "At least one photo must exist").not.toBeNull();

        const getIssues = collectConsoleIssues(page);
        await page.goto(`admin.php?page=photo-${photoId!}`);
        await page.waitForLoadState("load");

        await assertNoErrors(page, getIssues());

        const selectizeControl = page.locator(".selectize-control, .selectize-input");
        await expect(selectizeControl.first()).toBeVisible();

        await selectizeControl.first().click();

        const dropdown = page.locator(".selectize-dropdown");
        await expect(dropdown.first()).toBeVisible();
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

        // Confirm the jquery-confirm dialog
        const confirmBtn = page.locator(".jconfirm-box button.btn-red");
        await expect(confirmBtn).toBeVisible({ timeout: 5_000 });
        await confirmBtn.click();

        // Wait for tag box to be removed from DOM
        await expect(tagBox).not.toBeAttached({ timeout: 10_000 });

        const dbTagAfter = await dbFindTag(tagName);
        expect(dbTagAfter, `Tag '${tagName}' should be deleted from DB`).toBeNull();
    });
});
