import { test, expect } from "@playwright/test";
import { collectConsoleIssues, assertNoErrors, ensureTestPhotoExists } from "../helpers/index.js";
import { dbGetFirstPhotoId, dbGetPhotoAuthor } from "../helpers/db.js";

test.describe("Admin — batch manager", () => {
    test.beforeEach(async ({ page }) => {
        await ensureTestPhotoExists(page);
    });

    test("action select reveals date_creation section", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const selectAll = page.locator("#selectAll");
        await expect(selectAll).toBeVisible();
        await selectAll.click();
        await expect(page.locator(".thumbnails input[type=checkbox]:checked").first()).toBeChecked();

        const actionSelect = page.locator('select[name="selectAction"]');
        await expect(actionSelect).toBeVisible();

        await actionSelect.selectOption("date_creation");
        await expect(page.locator("#action_date_creation")).toBeVisible();

        await actionSelect.selectOption("associate");
        await expect(page.locator("#action_date_creation")).toBeHidden();
        await expect(page.locator("#action_associate")).toBeVisible();

        await actionSelect.selectOption("author");
        await expect(page.locator("#action_associate")).toBeHidden();
        await expect(page.locator("#action_author")).toBeVisible();
    });

    test("applyActionBlock appears after action selection", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const selectAll = page.locator("#selectAll");
        await expect(selectAll).toBeVisible();
        await selectAll.click();
        await expect(page.locator(".thumbnails input[type=checkbox]:checked").first()).toBeChecked();

        const applyBlock = page.locator("#applyActionBlock");
        await expect(applyBlock).toBeHidden();

        await page.locator('select[name="selectAction"]').selectOption("author");
        await expect(applyBlock).toBeVisible();
    });

    test("delete_derivatives blocked without confirmation", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const selectAll = page.locator("#selectAll");
        await expect(selectAll).toBeVisible();
        await selectAll.click();
        await expect(page.locator(".thumbnails input[type=checkbox]:checked").first()).toBeChecked();

        await page.locator('select[name="selectAction"]').selectOption("delete_derivatives");

        const confirmError = page.locator("#confirmDel span.errors");
        await expect(confirmError).toHaveCSS("visibility", "hidden");

        await page.locator("#applyAction").click();
        await expect(confirmError).toHaveCSS("visibility", "visible");
    });

    test("add filter button reveals dropdown", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const addFilterBtn = page.locator(".addFilter-button");
        await expect(addFilterBtn).toBeVisible();

        const dropdown = page.locator(".addFilter-dropdown");
        await expect(dropdown).toBeHidden();

        await addFilterBtn.click();
        await expect(dropdown).toBeVisible();
    });

    test("selectedMessage shows count after select all", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const selectAll = page.locator("#selectAll");
        await expect(selectAll).toBeVisible();

        const selectedMsg = page.locator("#selectedMessage");
        const before = await selectedMsg.textContent();

        await selectAll.click();
        await expect(page.locator(".thumbnails input[type=checkbox]:checked").first()).toBeChecked();

        await expect(selectedMsg).not.toBeEmpty();
        await expect(selectedMsg).not.toHaveText(before ?? "");
    });

    test("select all checks all photo checkboxes", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const thumbnails = page.locator(".thumbnails input[type=checkbox]");
        const thumbCount = await thumbnails.count();
        expect(thumbCount).toBeGreaterThan(0);

        const selectAll = page.locator("#selectAll, input[name=selectAll], .selectAll").first();
        expect(await selectAll.count()).toBeGreaterThan(0);

        await selectAll.click();
        await expect(page.locator(".thumbnails input[type=checkbox]:checked").first()).toBeChecked();

        const checkedCount = page.locator(".thumbnails input[type=checkbox]:checked");
        await expect(checkedCount).toHaveCount(thumbCount);
    });

    test("datepicker opens after selecting date_creation action", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const selectAll = page.locator("#selectAll");
        await expect(selectAll).toBeVisible();
        await selectAll.click();
        await expect(page.locator(".thumbnails input[type=checkbox]:checked").first()).toBeChecked();

        await page.locator('select[name="selectAction"]').selectOption("date_creation");

        const dpInput = page.locator("#action_date_creation [data-datepicker]").first();
        await expect(dpInput).toBeVisible();
        await dpInput.click();

        const calendar = page.locator(".flatpickr-calendar");
        await expect(calendar).toBeVisible();

        const availableDay = calendar.locator(".flatpickr-day:not(.disabled):not(.flatpickr-disabled)").first();
        await availableDay.click();
        const hiddenInput = page.locator('input[name="date_creation"]');
        await expect(hiddenInput).not.toHaveValue("");
    });

    test("select invert inverts selection", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const selectAll = page.locator("#selectAll");
        expect(await selectAll.count()).toBeGreaterThan(0);
        await selectAll.click();
        await expect(page.locator(".thumbnails input[type=checkbox]:checked").first()).toBeChecked();

        const totalCount = await page.locator(".thumbnails input[type=checkbox]").count();
        let checkedCount = page.locator(".thumbnails input[type=checkbox]:checked");
        await expect(checkedCount).toHaveCount(totalCount);

        const invertBtn = page.locator("#selectInvert");
        await invertBtn.click();

        // After inverting from all-selected, no checkboxes should be checked
        checkedCount = page.locator(".thumbnails input[type=checkbox]:checked");
        await expect(checkedCount).toHaveCount(0);
    });

    test("filter prefilter contextual buttons", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const prefilterCheckbox = page.locator(".useFilterCheckbox").first();
        expect(await prefilterCheckbox.count()).toBeGreaterThan(0);

        await prefilterCheckbox.check();
        await expect(prefilterCheckbox).toBeChecked();

        const prefilterSelect = page.locator('select[name=filter_prefilter]');
        expect(await prefilterSelect.count()).toBeGreaterThan(0);

        await prefilterSelect.selectOption("caddie");

        await expect(page.locator("#empty_caddie")).toBeVisible();
    });

    test("associate action shows album selector", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const selectAll = page.locator("#selectAll");
        expect(await selectAll.count()).toBeGreaterThan(0);
        await selectAll.click();
        await expect(page.locator(".thumbnails input[type=checkbox]:checked").first()).toBeChecked();

        const actionSelect = page.locator('select[name="selectAction"]');
        const hasAssociate = await actionSelect.evaluate((el: HTMLSelectElement) =>
            Array.from(el.options).some((opt) => opt.value === "associate"),
        );
        expect(hasAssociate).toBeTruthy();

        await actionSelect.selectOption("associate");

        await expect(page.locator("#action_associate")).toBeVisible();
    });

    test("generate_derivatives shows apply block", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const selectAll = page.locator("#selectAll");
        expect(await selectAll.count()).toBeGreaterThan(0);
        await selectAll.click();
        await expect(page.locator(".thumbnails input[type=checkbox]:checked").first()).toBeChecked();

        const actionSelect = page.locator('select[name="selectAction"]');
        const hasGenerateOption = await actionSelect.evaluate((el: HTMLSelectElement) =>
            Array.from(el.options).some((opt) => opt.value === "generate_derivatives"),
        );
        expect(hasGenerateOption).toBeTruthy();

        await actionSelect.selectOption("generate_derivatives");

        const applyBlock = page.locator("#applyActionBlock");
        const applyBtn = page.locator("#applyAction");
        await expect(applyBtn).toBeVisible();
        await expect(applyBlock).toBeVisible();
    });

    test("select set (whole set) checkbox", async ({ page }) => {
        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        const setSelected = page.locator('input[name=setSelected]');
        expect(await setSelected.count()).toBeGreaterThan(0);

        const selectSetBtn = page.locator("#selectSet");
        await selectSetBtn.click();
        await expect(setSelected).toBeChecked();

        const wholeSet = page.locator('input[name=whole_set]');
        const value = await wholeSet.inputValue();
        expect(value.length).toBeGreaterThan(0);

        const selectNone = page.locator("#selectNone");
        await selectNone.click();
        await expect(setSelected).not.toBeChecked();
    });

    // -------------------------------------------------------------------------
    // Apply author action — select first photo, apply new author, verify DB
    // -------------------------------------------------------------------------

    test("apply author action → verify DB → restore original author", async ({ page }) => {
        const photoId = await dbGetFirstPhotoId();
        expect(photoId, "At least one photo must exist").not.toBeNull();

        const originalAuthor = await dbGetPhotoAuthor(photoId!);
        const testAuthor = `batch_test_author_${Date.now()}`;

        const getIssues = collectConsoleIssues(page);
        await page.goto("admin.php?page=batch_manager&section=global");
        await assertNoErrors(page, getIssues());

        // Checkboxes are CSS-hidden; click the wrapping label which has the click handler
        const firstLabel = page.locator(".thumbnails .wrap1 label").first();
        await expect(firstLabel).toBeVisible();
        await firstLabel.click();
        const firstCheckbox = page.locator(".thumbnails input[type=checkbox]").first();
        await expect(firstCheckbox).toBeChecked();

        // Select the "author" action
        const actionSelect = page.locator('select[name="selectAction"]');
        await expect(actionSelect).toBeVisible();
        await actionSelect.selectOption("author");

        // The #action_author section should appear
        const authorSection = page.locator("#action_author");
        await expect(authorSection).toBeVisible();

        // Fill the author field
        const authorInput = authorSection.getByPlaceholder('Type the author name here');
        await expect(authorInput).toBeVisible();
        await authorInput.fill(testAuthor);

        // Click Apply
        const applyBtn = page.locator("#applyAction");
        await expect(applyBtn).toBeVisible();
        await applyBtn.click();
        await page.waitForLoadState("load");

        // Verify DB: the photo's author has been updated
        const updatedAuthor = await dbGetPhotoAuthor(photoId!);
        expect(updatedAuthor, `Photo ${photoId!} author should be '${testAuthor}' in DB`).toBe(testAuthor);

        // Restore the original author via the unit section (uses session's current selection)
        await page.goto("admin.php?page=batch_manager&mode=unit");
        await page.waitForLoadState("load");

        const unitAuthorInput = page.locator('input[name^="author-"]').first();
        await expect(unitAuthorInput).toBeVisible();
        await unitAuthorInput.fill(originalAuthor ?? "");
        const saveBtn = page.locator('input[type=submit][name="submit"], button[type=submit]').first();
        await expect(saveBtn).toBeVisible();
        await saveBtn.click();
        await page.waitForLoadState("load");
    });
});
