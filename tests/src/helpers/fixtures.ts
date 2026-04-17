import { type Page, expect } from "@playwright/test";

export function uniqueName(prefix: string): string {
    const rand = Math.floor(Math.random() * 100000);
    return `test_${prefix}_${Date.now()}_${rand}`;
}

export async function ensureTestPhotoExists(page: Page): Promise<void> {
    try {
        await page.goto("admin.php?page=batch_manager&section=global", {
            waitUntil: "load",
            timeout: 15000,
        });

        const selectAll = page.locator("#selectAll");
        if ((await selectAll.count()) > 0) {
            return;
        }

        await page.goto("admin.php", { waitUntil: "load", timeout: 15000 });

        const quickSyncLink = page.locator('a[href*="quick_sync=1"]');
        expect(
            await quickSyncLink.count(),
            "Quick Local Synchronization link must be present on admin dashboard",
        ).toBeGreaterThan(0);

        const href = await quickSyncLink.getAttribute("href");
        await page.goto(href!, { waitUntil: "load", timeout: 30000 });

        const bodyText = await page.locator("body").innerText();
        const errorsMatch = /(\d+)\s+errors during synchronization/.exec(bodyText);
        const errors = errorsMatch ? parseInt(errorsMatch[1]) : 0;
        expect(errors, "Synchronization must complete with 0 errors").toBe(0);
    } catch (err) {
        throw err;
    }
}
