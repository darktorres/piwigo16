# Test Failures — Root Cause Analysis

Branch: `tests2`  
Date: 2026-04-16  
Suite: 89 tests, ~18 failing

---

## 1. `admin/albums.test.ts:15` — create virtual album (1 min timeout)

**Error:** `await submitBtn.click()` at line 28 times out (full 60 s timeout consumed).  
**Root cause:** `page.getByRole("button", { name: "Create" })` may not match the actual submit element on `cat_list` (form might use `<input type="submit">` not a `<button>`), or the form submits via AJAX and `waitForLoadState("load")` hangs waiting for a navigation that never fires.  
**Fix needed:** Check the actual HTML of `admin.php?page=cat_list`. If AJAX-based, replace `waitForLoadState("load")` with a response/element wait. If the button role is wrong, use `input[type=submit]` or `getByRole("button")` without the name filter.

---

## 2. `admin/batch-manager.test.ts:259` — apply author action

**Error:** `expect(firstCheckbox).toBeVisible()` fails — checkbox `.thumbnails input[type=checkbox]` is CSS-hidden (`hidden`).  
**Root cause:** Batch-manager checkboxes are styled hidden by default; they need `{ force: true }` on the `.check()` call, and the `toBeVisible()` assertion before the check must be removed or replaced with `toBeAttached()`.  
**Fix needed:** Replace `await expect(firstCheckbox).toBeVisible()` with `await expect(firstCheckbox).toBeAttached()` and use `await firstCheckbox.check({ force: true })`.

---

## 3. `admin/groups.test.ts:44` — create/delete group

**Error:** `locator('.jconfirm-box a.btn-red')` not found after clicking `#GroupDelete`.  
**Root cause:** The delete confirmation dialog selector is wrong. Piwigo does not use jquery-confirm here; it likely uses `window.confirm()` (native browser dialog) or a different modal library. The `btn-red` class selector is incorrect.  
**Fix needed:** Intercept the native `dialog` event with `page.on("dialog", d => d.accept())` before clicking delete, OR find the correct confirm button selector in the Piwigo group_list JS source.

---

## 4. `admin/rating.test.ts:6` — category filter reveal/hide

**Error:** `TypeError: Cannot read properties of undefined (reading 'value')` — `select.options[1]` is undefined.  
**Root cause:** The category filter `<select name="cat">` has only 1 option ("All") when no ratings exist in the DB. `options[1]` is out of bounds.  
**Fix needed:** Either (a) pre-seed a rating row in `beforeAll` so a category option appears, or (b) rewrite the test to check the option count and skip the value selection when `options.length < 2`, or (c) change the test to interact with the select via Selectize's own UI rather than raw DOM manipulation.

---

## 5. `admin/tags.test.ts:40` — create/delete tag

**Error:** Same as groups: `locator('.jconfirm-box a.btn-red')` not found.  
**Root cause:** Same — wrong confirmation dialog selector. Tags use a different confirm mechanism than jquery-confirm.  
**Fix needed:** Same as groups — use `page.on("dialog", ...)` for native confirms, or find the real dialog button.

---

## 6. `admin/users.test.ts:40` and `admin/users.test.ts:83` — create/delete user

**Error:** `locator('#AddUserSuccess')` resolves to hidden element (CSS `display: none` or similar) after form submit.  
**Root cause:** The `#AddUserSuccess` div is present in the DOM but styled hidden initially; the show animation/class toggle hasn't fired, or the success state uses a different class/visibility mechanism.  
**Fix needed:** Check the actual HTML after user creation — may need to wait for a specific CSS class change, or use a different success indicator (e.g. a flash message, toast, or the user row appearing in the list).

---

## 7. `gallery/comments.test.ts:21` and `gallery/comments.test.ts:43` — comment form

**Error:** `locator('#commentAdd')` not found/not visible.  
**Root cause:** `activate_comments = false` in the `config` table. This is the default after a fresh Piwigo install (which `global-setup.ts` performs on every `bun test` run). The comment block is gated by `if ($conf->activate_comments)` in `picture.php:938`. There is also a secondary gate: `comments_forall = false` means guests can't comment (but logged-in admin can, once `activate_comments` is true).  
**Fix needed:** Enable comments via the admin UI **or** add a DB update step in `global-setup.ts` after the install: `UPDATE config SET value='true' WHERE param='activate_comments'`. Then the form `#commentAdd` / `#addComment` (form inside the div) will render.  
**Where to enable in UI:** `admin.php?page=configuration&section=comments` — "Allow users to add comments".

---

## 8. `gallery/interactions.test.ts:17` — quick search redirect

**Error:** `expect(page.url()).toMatch(/qsearch|search/)` fails — URL stays at `index.php`.  
**Root cause:** The quick-search form may submit via AJAX and show results inline without a URL redirect. After pressing Enter, the results might load on the same page, or the form action routes to `index.php?/qsearch/...` which may not match if there are no results for "photo".  
**Fix needed:** Check what actually happens in the browser when searching. May need to assert on visible results elements instead of the URL, or use a more reliable search term.

---

## 9. `gallery/interactions.test.ts:133` — download link ERR_ABORTED

**Error:** `assertNoErrors` fails because `GET action.php?id=1&part=e&download: net::ERR_ABORTED` is recorded as a network error.  
**Root cause:** Clicking a download link in a browser causes the navigation to be aborted (the browser intercepts it as a file download). `ERR_ABORTED` for download-triggered requests is expected behavior, not a real error.  
**Fix needed:** Either (a) filter `ERR_ABORTED` out in `collectConsoleIssues` / `assertNoErrors`, or (b) call `assertNoErrors` before the download click (not after), or (c) catch the network event and accept it as valid.

---

## 10. `gallery/interactions.test.ts:206` — Modus thumb.pop.js hover preview

**Error:** `locator('#thumbnails li:not(.album) img[data-pop]')` — no elements found.  
**Root cause:** The modus theme's `thumb.pop.js` hover feature may use a different attribute name (not `data-pop`), or the feature is disabled/removed.  
**Fix needed:** Check the modus thumbnails template (`themes/modus/template/thumbnails.tpl`) for the actual attribute name or whether the hover preview feature exists at all. May need to delete this test if the feature is not present.

---

## 11. `gallery/navigation.test.ts:37` — keyboard arrow navigation

**Error:** `expect(newUrl).not.toBe(currentUrl)` fails — URL unchanged after `ArrowRight` press.  
**Root cause:** The keyboard handler in Piwigo's picture page may require the page body or a specific element to have focus first, or it's bound only when the PhotoSwipe lightbox is not open. Alternatively the first photo may be the last/only photo in the album and has no "next".  
**Fix needed:** Check the Piwigo picture JS for keyboard handler requirements. May need to `page.locator("body").focus()` or click on the main image before pressing the key. Also verify there are at least 2 photos in the first album.

---

## 12. `gallery/navigation.test.ts:78` — GDThumb legend overlay click

**Error:** `expect(page.url()).not.toBe(currentUrl)` — URL unchanged after clicking `.thumbLegend.bottom`.  
**Root cause:** The `.thumbLegend.bottom` element may be a caption overlay without its own `href` — its parent `<a>` carries the link. Clicking the legend text itself might not trigger navigation if the `<a>` wraps the whole thumbnail differently.  
**Fix needed:** Inspect the actual HTML structure. May need to click the parent `<a>` link around the legend, or look for `.thumbLegend a` instead.

---

## 13. `gallery/rating.test.ts:20,47,69` — rating widget not visible

**Error:** `locator('.rateButton, .rateButtonSelected').first()` not found.  
**Root cause:** DB shows `rate = true`, so rating should be enabled. The selector `.rateButton` / `.rateButtonSelected` may not match the actual HTML class names in the modus theme. The rating widget classes may differ from the default theme.  
**Fix needed:** Check `themes/modus/template/` (or the default `themes/default/template/picture.tpl`) for the actual CSS class names of the rating stars.

---

## Summary Table

| File | Test | Root Cause |
|---|---|---|
| `admin/albums.test.ts:15` | create virtual album | `waitForLoadState("load")` hangs after AJAX submit or wrong button selector |
| `admin/batch-manager.test.ts:259` | apply author | checkbox is CSS-hidden; `toBeVisible()` fails, need `toBeAttached()` + `{ force: true }` |
| `admin/groups.test.ts:44` | create/delete group | wrong confirm dialog selector (`.jconfirm-box a.btn-red`) |
| `admin/rating.test.ts:6` | category filter | `select.options[1]` undefined when no ratings in DB |
| `admin/tags.test.ts:40` | create/delete tag | same wrong confirm dialog selector |
| `admin/users.test.ts:40,83` | create/delete user | `#AddUserSuccess` present but hidden; wrong success indicator |
| `gallery/comments.test.ts:21,43` | comment form | `activate_comments = false` after fresh install |
| `gallery/interactions.test.ts:17` | quick search | URL doesn't change (inline results or no redirect) |
| `gallery/interactions.test.ts:133` | download link | `ERR_ABORTED` for download falsely flagged as error |
| `gallery/interactions.test.ts:206` | thumb.pop hover | `img[data-pop]` attribute not present in modus theme |
| `gallery/navigation.test.ts:37` | arrow key nav | keyboard handler needs focus or only 1 photo in album |
| `gallery/navigation.test.ts:78` | legend click | `.thumbLegend.bottom` has no `href`; parent `<a>` carries the link |
| `gallery/rating.test.ts:20,47,69` | rating widget | wrong CSS class names for modus theme |
