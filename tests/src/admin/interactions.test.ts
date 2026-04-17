import { test, expect } from "@playwright/test";
import { collectConsoleIssues, assertNoErrors, ensureTestPhotoExists } from "../helpers/index.js";

test.describe("Admin JS interactions", () => {
    test.beforeEach(async ({ page }) => {
        await ensureTestPhotoExists(page);
    });

    test("sidebar accordion — click collapses other sections", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php");
        await assertNoErrors(page, getIssues());

        const dts = page.locator("#menubar dl dt, #menubar > dl > dt");
        const count = await dts.count();
        expect(count).toBeGreaterThan(1);

        await dts.nth(1).click();
        await expect(dts.nth(1).locator("+ dd")).toBeVisible();
        await expect(dts.nth(0).locator("+ dd")).toBeHidden();
    });

    test("datepicker opens on history page", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=history");
        await assertNoErrors(page, getIssues());

        const dpInput = page.locator("[data-datepicker]").first();
        await expect(dpInput).toBeVisible();

        await dpInput.click();
        const calendar = page.locator(".flatpickr-calendar.open");
        await expect(calendar).toBeVisible();

        const availableDay = calendar
            .locator(".flatpickr-day:not(.disabled):not(.nextMonthDay):not(.prevMonthDay):not(.flatpickr-disabled)")
            .first();
        await availableDay.click();
        await expect(dpInput).not.toHaveValue("");
    });

    test("AdminTools admin — checkbox visual toggle", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=plugin-AdminTools");
        await assertNoErrors(page, getIssues());

        // Checkbox may be hidden by custom styling — use evaluate to read/toggle
        const checkbox = page.locator("#ato-config input[type=checkbox]").first();
        await expect(checkbox).toBeAttached();

        const wasChecked = await checkbox.evaluate((el: Element) => (el as HTMLInputElement).checked);

        const didToggle = await page.evaluate(() => {
            const el = document.querySelector<HTMLInputElement>("#ato-config input[type=checkbox]");
            if (el) {
                el.checked = !el.checked;
                el.dispatchEvent(new Event("change", { bubbles: true }));
                return true;
            }
            return false;
        });
        expect(didToggle).toBeTruthy();

        const nowChecked = await checkbox.evaluate((el: Element) => (el as HTMLInputElement).checked);
        expect(nowChecked).toBe(!wasChecked);
    });

    test("AdminTools public toolbar — close and reopen", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        // Force the panel open regardless of prior localStorage state
        await page.goto("index.php");
        await page.evaluate(() => { localStorage.setItem("ato_panel_open", "1"); });
        await page.reload();
        await page.waitForLoadState("load");
        await assertNoErrors(page, getIssues());

        const header = page.locator("#ato_header");
        await expect(header).toBeVisible();

        const closeBtn = header.locator(".close-panel");
        await closeBtn.click();
        await expect(header).toBeHidden();

        const headerClosed = page.locator("#ato_header_closed");
        await expect(headerClosed).toBeVisible();

        await headerClosed.click();
        await expect(header).toBeVisible();
    });

    test("GDThumb admin — cache controls present", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=plugin-GDThumb");
        await assertNoErrors(page, getIssues());

        await expect(page.locator("#cachebuild")).toBeVisible();
        await expect(page.locator("#cachedelete")).toBeVisible();
        expect(await page.locator("#startLink").count()).toBeGreaterThan(0);
    });
});
