import { chromium, type FullConfig } from "@playwright/test";
import { mkdirSync, existsSync, unlinkSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { dbSetConfig } from "./db.js";

const __dirname = fileURLToPath(new URL(".", import.meta.url));
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
        await page.goto("install.php", { waitUntil: "networkidle" });
        console.log("Install page loaded, current URL:", page.url());

        const installBtn = page.locator('input[type=submit][name=install]');
        await installBtn.waitFor({ state: "visible", timeout: 15000 });
        console.log("Install button found, clicking...");

        await installBtn.click();
        await page.waitForTimeout(2000);  // Give it time to process
        await page.waitForLoadState("networkidle");

        // Log in — installer may have auto-logged in, so only fill the form if needed
        await page.goto("identification.php");
        if (page.url().includes("identification.php")) {
            await page.fill("#username", ADMIN_USER);
            await page.fill("#password", ADMIN_PASS);
            await page.click('form[name="login_form"] input[type="submit"]');
            await page.waitForLoadState("load");
        }

        // Quick-sync photos from disk
        await page.goto("admin.php", { waitUntil: "networkidle" });
        const quickSyncLink = page.locator('a[href*="quick_sync=1"]');
        await quickSyncLink.waitFor({ state: "visible", timeout: 10000 });
        const href = await quickSyncLink.getAttribute("href");
        if (!href) throw new Error("Quick sync link not found");
        await page.goto(href, { waitUntil: "networkidle", timeout: 120_000 });

        // Enable features that default to off in a fresh install
        await dbSetConfig("activate_comments", "true");
        await dbSetConfig("allow_user_registration", "true");

        // Create a permanent group so smoke tests that need a group can find one
        await page.goto("admin.php?page=group_list");

        // Fill the hidden group form and submit it
        await page.evaluate(() => {
            const form = document.querySelectorAll('form')[0];
            const nameInput = form.querySelector('#addGroupNameInput');
            const submitBtn = form.querySelector('button[type="submit"]');
            nameInput.value = 'smoke_group';
            nameInput.dispatchEvent(new Event('input', { bubbles: true }));
            nameInput.dispatchEvent(new Event('change', { bubbles: true }));
            submitBtn.click();
        });

        // Wait for the API response, then reload the page to see the new group
        await page.waitForTimeout(1000);
        await page.reload({ waitUntil: 'networkidle' });

        // Verify the group was created by checking it appears in the DOM
        await page.getByText("smoke_group").first().waitFor({ timeout: 5000 });

        // Save session for all subsequent tests
        await context.storageState({ path: ".auth/admin.json" });
    } finally {
        await browser.close();
    }
}
