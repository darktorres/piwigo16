import { chromium, type FullConfig } from "@playwright/test";
import { mkdirSync, existsSync, unlinkSync } from "node:fs";
import { join } from "node:path";
import { dbSetConfig } from "./db.js";

const ADMIN_USER = process.env.PIWIGO_USER ?? "darktorres";
const ADMIN_PASS = process.env.PIWIGO_PASS ?? "1234";
const DB_CONFIG_PATH = join(__dirname, "..", "..", "..", "local", "config", "database.php");

export default async function globalSetup(config: FullConfig): Promise<void> {
    const baseURL = config.projects[0].use.baseURL ?? "http://localhost/piwigo-fork/";
    mkdirSync(".auth", { recursive: true });

    // Trigger a fresh install by removing the database config
    if (existsSync(DB_CONFIG_PATH)) {
        unlinkSync(DB_CONFIG_PATH);
    }

    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();

    try {
        // Run installer — form is pre-filled with local dev credentials
        await page.goto("install.php");
        await page.click('input[type=submit][name=install]');
        await page.waitForLoadState("load");

        // Log in — installer may have auto-logged in, so only fill the form if needed
        await page.goto("identification.php");
        if (page.url().includes("identification.php")) {
            await page.fill("#username", ADMIN_USER);
            await page.fill("#password", ADMIN_PASS);
            await page.click('form[name="login_form"] input[type="submit"]');
            await page.waitForLoadState("load");
        }

        // Quick-sync photos from disk
        await page.goto("admin.php");
        const quickSyncLink = page.locator('a[href*="quick_sync=1"]');
        const href = await quickSyncLink.getAttribute("href");
        await page.goto(href!, { waitUntil: "load", timeout: 60_000 });

        // Enable features that default to off in a fresh install
        await dbSetConfig("activate_comments", "true");
        await dbSetConfig("allow_user_registration", "true");

        // Create a permanent group so smoke tests that need a group can find one
        await page.goto("admin.php?page=group_list");
        await page.locator(".addGroupBlock").click();
        const nameInput = page.locator("#addGroupForm").getByLabel("Group name");
        await nameInput.waitFor({ state: "visible" });
        await nameInput.fill("smoke_group");
        await page.locator("#addGroupForm").getByRole("button", { name: "Add" }).click();
        await page.locator(".GroupContainer").filter({ hasText: "smoke_group" }).waitFor({ state: "visible" });

        // Save session for all subsequent tests
        await context.storageState({ path: ".auth/admin.json" });
    } finally {
        await browser.close();
    }
}
