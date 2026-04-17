import { test, expect } from "@playwright/test";
import {
    collectConsoleIssues,
    assertNoErrors,
    ensureTestPhotoExists,
    uniqueName,
    dbFindCommentByContent,
    dbCleanupTestEntities,
} from "../helpers/index.js";
import { dbGetFirstPhotoId, dbGetFirstPhysicalAlbumId } from "../helpers/db.js";

test.describe("Gallery — comments", () => {
    test.afterAll(async () => {
        await dbCleanupTestEntities();
    });

    function getPhotoUrl(photoId: number, catId: number): string {
        return `picture.php?/${photoId}/category/${catId}`;
    }

    test("comment form is visible and can be filled", async ({ page }) => {
        await ensureTestPhotoExists(page);

        const photoId = await dbGetFirstPhotoId();
        const catId = await dbGetFirstPhysicalAlbumId();
        expect(photoId).not.toBeNull();
        expect(catId).not.toBeNull();

        const getIssues = collectConsoleIssues(page);
        await page.goto(getPhotoUrl(photoId!, catId!));
        expect(page.url()).toContain("picture.php");
        await assertNoErrors(page, getIssues());

        const commentForm = page.locator("#commentAdd");
        await expect(commentForm).toBeVisible();

        const textarea = page.locator("#contentid");
        await expect(textarea).toBeVisible();
        await textarea.fill("Test comment fixture");
        await expect(textarea).toHaveValue("Test comment fixture");
    });

    test("submit comment → verify DB → (cleanup)", async ({ page }) => {
        await ensureTestPhotoExists(page);

        const photoId = await dbGetFirstPhotoId();
        const catId = await dbGetFirstPhysicalAlbumId();
        expect(photoId).not.toBeNull();
        expect(catId).not.toBeNull();

        const authorName = uniqueName("cmt");
        const commentText = `Automated test comment by ${authorName}`;

        const getIssues = collectConsoleIssues(page);
        await page.goto(getPhotoUrl(photoId!, catId!));
        expect(page.url()).toContain("picture.php");
        await assertNoErrors(page, getIssues());

        const commentForm = page.locator("#commentAdd");
        await expect(commentForm).toBeVisible();

        // Admin users don't see the #author field — their username is used automatically
        const authorInput = page.locator("#author");
        if (await authorInput.isVisible()) {
            await authorInput.fill(authorName);
        }

        const textarea = page.locator("#contentid");
        await expect(textarea).toBeVisible();
        await textarea.fill(commentText);

        // verify_ephemeral_key requires ≥3 s between page load and submit (anti-spam)
        await page.waitForTimeout(3500);

        const submitBtn = page.locator("#addComment input[type=submit], #addComment button[type=submit]").first();
        await submitBtn.click();
        await page.waitForLoadState("load");

        const dbComment = await dbFindCommentByContent(photoId!, commentText);
        expect(dbComment, "Comment should be stored in DB").not.toBeNull();
        expect(dbComment!.id).toBeDefined();
        expect(["true", "false"]).toContain(dbComment!.validated);
    });
});
