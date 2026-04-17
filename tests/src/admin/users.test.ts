import { test, expect } from "@playwright/test";
import {
    collectConsoleIssues,
    assertNoErrors,
    uniqueName,
    dbFindUser,
    dbCreateTestPrivateCategory,
    dbGetUserAccess,
    dbCleanupTestEntities,
} from "../helpers/index.js";

async function createUserViaUI(
    page: Parameters<typeof collectConsoleIssues>[0],
    username: string,
    password: string,
): Promise<void> {
    await page.goto("admin.php?page=user_list");
    await page.waitForLoadState("load");

    const addUserBtn = page.locator(".add-user-button").first();
    await expect(addUserBtn).toBeVisible();
    await addUserBtn.click();

    const userForm = page.locator("#AddUser");
    await expect(userForm).toBeVisible({ timeout: 5_000 });

    await userForm.locator(".AddUserLabelUsername .user-property-input").fill(username);
    await userForm.locator("#AddUserPassword").fill(password);
    await userForm.locator(".AddUserLabelEmail .user-property-input").fill(`${username}@test.invalid`);

    await userForm.locator(".AddUserSubmit").click();
    await expect(page.locator("#AddUserSuccess")).toBeVisible({ timeout: 10_000 });
}

test.describe("Admin — users", () => {
    test.afterAll(async () => {
        await dbCleanupTestEntities();
    });

    test("create user → verify DB → delete → verify gone", async ({ page }) => {
        const username = uniqueName("user");
        const password = "TestPass123!";
        const getIssues = collectConsoleIssues(page);

        await createUserViaUI(page, username, password);
        await assertNoErrors(page, getIssues());

        const dbUser = await dbFindUser(username);
        expect(dbUser, `User '${username}' should exist in DB`).not.toBeNull();
        const userId = parseInt(dbUser!.id, 10);

        // Navigate to user list and find the user row to delete
        await page.goto("admin.php?page=user_list");
        await page.waitForLoadState("load");

        // Find the user container in the dynamically-rendered list
        const userContainer = page
            .locator(".user-container")
            .filter({ has: page.locator(`:text("${username}")`) });
        await expect(userContainer).toBeVisible({ timeout: 15_000 });

        // Click the edit icon column to open the UserList popup
        await userContainer.locator(".user-col.user-container-edit").click();
        await page.locator("#UserList").waitFor({ state: "visible", timeout: 5_000 });

        // Click delete button and confirm via jquery-confirm
        const deleteBtn = page.locator("#UserList .delete-user-button");
        await expect(deleteBtn).toBeVisible({ timeout: 5_000 });
        await deleteBtn.click();

        const confirmBtn = page.locator("#pwg-confirm-dialog .pwg-confirm-buttons button.btn-red");
        await expect(confirmBtn).toBeVisible({ timeout: 5_000 });
        await confirmBtn.click();
        // Wait for the user container to disappear after deletion
        await expect(userContainer).not.toBeVisible({ timeout: 10_000 });

        const dbUserAfter = await dbFindUser(username);
        expect(dbUserAfter, `User '${username}' should be deleted from DB`).toBeNull();

        void userId;
    });

    test("grant user access to private album → verify DB → revoke → verify gone", async ({ page }) => {
        const username = uniqueName("perm");
        const password = "TestPass123!";
        const catName = uniqueName("privcat");
        const getIssues = collectConsoleIssues(page);

        await createUserViaUI(page, username, password);
        await assertNoErrors(page, getIssues());

        const dbUser = await dbFindUser(username);
        expect(dbUser, `User '${username}' should exist in DB`).not.toBeNull();
        const userId = parseInt(dbUser!.id, 10);

        const catId = await dbCreateTestPrivateCategory(catName);

        await page.goto(`admin.php?page=user_perm&user_id=${userId}`);
        await page.waitForLoadState("load");

        const catFalseSelect = page.locator('select[name="cat_false[]"]');
        await expect(catFalseSelect).toBeVisible();
        await catFalseSelect.selectOption(String(catId));

        const truthifyBtn = page.locator('input[name="truthify"]');
        await expect(truthifyBtn).toBeVisible();
        await truthifyBtn.click();
        await page.waitForLoadState("load");

        const granted = await dbGetUserAccess(userId, catId);
        expect(granted, `user_access row should exist for user ${userId} cat ${catId}`).toBe(true);

        const catTrueSelect = page.locator('select[name="cat_true[]"]');
        await expect(catTrueSelect).toBeVisible();
        await catTrueSelect.selectOption(String(catId));

        const falsifyBtn = page.locator('input[name="falsify"]');
        await expect(falsifyBtn).toBeVisible();
        await falsifyBtn.click();
        await page.waitForLoadState("load");

        const revoked = await dbGetUserAccess(userId, catId);
        expect(revoked, `user_access row should be gone after revoke`).toBe(false);
    });
});
