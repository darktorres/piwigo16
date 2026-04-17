import { test, expect } from "@playwright/test";
import {
    collectConsoleIssues,
    assertNoErrors,
    uniqueName,
    dbFindGroup,
    dbCleanupTestEntities,
} from "../helpers/index.js";

test.describe("Admin — groups", () => {
    test.afterAll(async () => {
        await dbCleanupTestEntities();
    });

    test("group list — toggle selection mode", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=group_list");
        await assertNoErrors(page, getIssues());

        const slider = page.locator("label.switch span.slider");
        await expect(slider).toBeVisible();

        const selectionBlock = page.locator("#selection-mode-block");
        await slider.click();
        await expect(selectionBlock).toBeVisible();

        await slider.click();
        await expect(selectionBlock).toBeHidden();
    });

    test("group list — add group form is visible", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=group_list");
        await assertNoErrors(page, getIssues());

        const addBlock = page.locator(".addGroupBlock");
        await expect(addBlock).toBeVisible();
        await addBlock.click();

        await expect(page.locator("#addGroupForm")).toBeVisible();
        await assertNoErrors(page, getIssues());
    });

    test("create group → verify DB → delete → verify gone", async ({ page }) => {
        const groupName = uniqueName("group");
        const getIssues = collectConsoleIssues(page);

        await page.goto("admin.php?page=group_list");
        await assertNoErrors(page, getIssues());

        await page.locator(".addGroupBlock").click();
        const addForm = page.locator("#addGroupForm");
        const nameInput = addForm.getByLabel("Group name");
        await expect(nameInput).toBeVisible();
        await nameInput.fill(groupName);

        const submitBtn = addForm.getByRole("button", { name: "Add" });
        await submitBtn.click();

        // Wait for the new group container to appear (AJAX-based)
        const groupContainer = page.locator(".GroupContainer").filter({ hasText: groupName });
        await expect(groupContainer).toBeVisible({ timeout: 10_000 });

        const dbGroup = await dbFindGroup(groupName);
        expect(dbGroup, `Group '${groupName}' should exist in DB`).not.toBeNull();

        // Delete via ellipsis dropdown
        const ellipsis = groupContainer.locator(".icon-ellipsis-vert");
        await ellipsis.click();
        const deleteBtn = groupContainer.locator("#GroupDelete");
        await expect(deleteBtn).toBeVisible();
        await deleteBtn.click();

        // Confirm the pwgConfirm dialog
        const confirmBtn = page.locator("#pwg-confirm-dialog .pwg-confirm-buttons button.btn-red");
        await expect(confirmBtn).toBeVisible({ timeout: 5_000 });

        await confirmBtn.click();

        // Wait for group element to be removed from DOM
        await expect(groupContainer).not.toBeAttached({ timeout: 10_000 });

        const dbGroupAfter = await dbFindGroup(groupName);
        expect(dbGroupAfter, `Group '${groupName}' should be deleted from DB`).toBeNull();
    });
});
