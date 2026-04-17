import { type Page } from "@playwright/test";

const ADMIN_USER = process.env.PIWIGO_USER ?? "admin";
const ADMIN_PASS = process.env.PIWIGO_PASS ?? "admin";

export async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto("identification.php");
    await page.fill("#username", ADMIN_USER);
    await page.fill("#password", ADMIN_PASS);
    await page.click('form[name="login_form"] input[type="submit"]');
    await page.waitForLoadState("load");
    if (!page.url().includes("admin.php")) {
        await page.goto("admin.php");
        await page.waitForLoadState("load");
    }
}
