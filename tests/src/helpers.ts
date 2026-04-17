// Backward-compatibility shim for install.test.ts — do not use in new tests.
export { loginAsAdmin, collectConsoleIssues, assertNoErrors, ensureTestPhotoExists, type ConsoleIssue } from "./helpers/index.js";

// ensureTestGroupExists is not in the new helpers — kept here for the old admin-interactions.test.ts
// while it still references this file during the transition.
import { type Page } from "@playwright/test";

export async function ensureTestGroupExists(page: Page): Promise<void> {
    await page.goto("admin.php?page=group_list");
    await page.waitForLoadState("load");

    const groupItems = page.locator(".GroupContainer, .group-item");
    if (await groupItems.count() > 0) return;

    const addForm = page.locator("#addGroupForm");
    if (await addForm.count() > 0 && await addForm.isVisible()) {
        const nameInput = addForm.locator("input[type=text]").first();
        if (await nameInput.count() > 0) {
            await nameInput.fill("Test Group");
            const submitBtn = addForm.locator("input[type=submit], button[type=submit]").first();
            if (await submitBtn.count() > 0) {
                await submitBtn.click();
                await page.waitForLoadState("load");
            }
        }
    }
}

export async function checkFeaturePresent(page: Page, selector: string): Promise<boolean> {
    const element = page.locator(selector).first();
    const count = await element.count();
    if (count === 0) return false;
    return element.isVisible();
}
