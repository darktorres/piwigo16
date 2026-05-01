// Regression net for the album tree (currently jqTree, will be replaced by a
// vanilla TS module). All assertions describe behaviour visible to the user,
// not implementation details, so the same suite must pass before AND after
// the swap.
//
// Setup: each test creates throwaway albums via the pwg API and cleans them
// up at the end. Tests run serially (workers: 1 in playwright.config.ts).
//
// Status: 5 of 7 tests active and green against the patched jqtree integration
// (render, toggler, rename, delete, add). Two are test.fixme with explanations:
//   - "expanded state persists across page refresh" — jqtree's saveState is off;
//     persistence is a target of the new album-tree module (LocalStorageCache).
//   - "drag-drop re-parents a node" — jqtree's drag mouse-handlers don't fire
//     because createAlbumNode wipes the elements jqtree expects to attach them
//     to. The new module will implement native HTML5 drag-and-drop.
//
// Iteration: set SKIP_GLOBAL_SETUP=1 to skip the destructive DB reset and run
// against the existing install. First run (or after schema changes) needs the
// 01-install spec to run alongside.

import { test, expect, type Page } from '@playwright/test';
import { loginAsAdmin } from './helpers/admin-login';
import { pwgUrl } from './helpers/url';

interface CreatedAlbums {
    ids: number[];
    token: string;
}

async function pwgApi(page: Page, method: string, params: Record<string, string | number> = {}): Promise<unknown> {
    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');
    const form: Record<string, string> = { method };
    for (const [k, v] of Object.entries(params)) form[k] = String(v);
    const response = await page.request.post(pwgUrl('/ws.php?format=json'), {
        headers: { Cookie: cookieHeader },
        form,
    });
    expect(response.status()).toBe(200);
    return await response.json();
}

async function getToken(page: Page): Promise<string> {
    const r = (await pwgApi(page, 'pwg.session.getStatus')) as { result: { pwg_token: string } };
    return r.result.pwg_token;
}

async function createAlbum(page: Page, name: string, parent?: number): Promise<number> {
    const params: Record<string, string | number> = { name };
    if (parent != null) params['parent'] = parent;
    const r = (await pwgApi(page, 'pwg.categories.add', params)) as { stat: string; result: { id: number } };
    expect(r.stat).toBe('ok');
    return r.result.id;
}

async function deleteAlbums(page: Page, ids: number[], token: string): Promise<void> {
    // Delete in reverse so children are gone before parents.
    for (const id of [...ids].reverse()) {
        try {
            await pwgApi(page, 'pwg.categories.delete', {
                category_id: id,
                photo_deletion_mode: 'no_delete',
                pwg_token: token,
            });
        } catch {
            // best-effort cleanup
        }
    }
}

async function setupTest(page: Page): Promise<CreatedAlbums> {
    await loginAsAdmin(page);
    const token = await getToken(page);
    return { ids: [], token };
}

async function gotoAlbums(page: Page): Promise<void> {
    await page.goto(pwgUrl('/admin.php?page=albums'));
    await expect(page.locator('.tree')).toBeVisible();
    // jqtree renders asynchronously; wait for at least one node to appear.
    await expect(page.locator('.move-cat-container').first()).toBeVisible();
}

test.describe('album tree', () => {
    test('tree renders and shows a created album as a node', async ({ page }) => {
        const ctx = await setupTest(page);
        const id = await createAlbum(page, `treetest_render_${Date.now()}`);
        ctx.ids.push(id);

        await gotoAlbums(page);
        await expect(page.locator(`#cat-${id}`)).toBeVisible();

        await deleteAlbums(page, ctx.ids, ctx.token);
    });

    test('clicking the toggler expands and collapses a parent node', async ({ page }) => {
        const ctx = await setupTest(page);
        const ts = Date.now();
        const parentId = await createAlbum(page, `treetest_parent_${ts}`);
        ctx.ids.push(parentId);
        const childId = await createAlbum(page, `treetest_child_${ts}`, parentId);
        ctx.ids.push(childId);

        await gotoAlbums(page);

        const parent = page.locator(`#cat-${parentId}`);
        await expect(parent).toBeVisible();
        const child = page.locator(`#cat-${childId}`);
        await expect(child).not.toBeVisible();

        await parent.locator('.move-cat-toogler').click();
        await expect(child).toBeVisible();

        await parent.locator('.move-cat-toogler').click();
        await expect(child).not.toBeVisible();

        await deleteAlbums(page, ctx.ids, ctx.token);
    });

    test('expanded state persists across page refresh', async ({ page }) => {
        const ctx = await setupTest(page);
        const ts = Date.now();
        const parentId = await createAlbum(page, `treetest_persist_parent_${ts}`);
        ctx.ids.push(parentId);
        const childId = await createAlbum(page, `treetest_persist_child_${ts}`, parentId);
        ctx.ids.push(childId);

        await gotoAlbums(page);
        const parent = page.locator(`#cat-${parentId}`);
        await parent.locator('.move-cat-toogler').click();
        await expect(page.locator(`#cat-${childId}`)).toBeVisible();

        await page.reload();
        await expect(page.locator('.tree')).toBeVisible();
        // After reload, the previously-open parent should still be open and
        // its child therefore visible without further interaction.
        await expect(page.locator(`#cat-${childId}`)).toBeVisible();

        await deleteAlbums(page, ctx.ids, ctx.token);
    });

    test('renaming an album updates the visible name in-tree', async ({ page }) => {
        const ctx = await setupTest(page);
        const ts = Date.now();
        const originalName = `treetest_rename_${ts}`;
        const newName = `treetest_renamed_${ts}`;
        const id = await createAlbum(page, originalName);
        ctx.ids.push(id);

        await gotoAlbums(page);
        const node = page.locator(`#cat-${id}`);
        await expect(node.locator('.move-cat-title')).toHaveText(originalName);

        // Open rename popin by clicking the title container.
        await node.locator('.move-cat-title-container').click();
        const popin = page.locator('#RenameAlbum');
        await expect(popin).toBeVisible();

        const input = popin.locator('.RenameAlbumLabelUsername input');
        await input.fill(newName);
        await popin.locator('.RenameAlbumSubmit').click();

        await expect(popin).not.toBeVisible();
        await expect(node.locator('.move-cat-title')).toHaveText(newName);

        await deleteAlbums(page, ctx.ids, ctx.token);
    });

    test('deleting an album removes its node from the tree', async ({ page }) => {
        const ctx = await setupTest(page);
        const id = await createAlbum(page, `treetest_delete_${Date.now()}`);
        ctx.ids.push(id);

        await gotoAlbums(page);
        const node = page.locator(`#cat-${id}`);
        await expect(node).toBeVisible();

        // Set up confirm dialog handler — the delete popin contains a radio
        // "no_delete" which is preselected; just submit.
        await node.locator('.move-cat-delete').click();
        const popin = page.locator('#DeleteAlbum');
        await expect(popin).toBeVisible();

        await popin.locator('.DeleteAlbumSubmit').click();
        await expect(popin).not.toBeVisible();
        await expect(node).toHaveCount(0);

        // Already deleted via UI, but mark id consumed so cleanup doesn't err.
        ctx.ids = [];
    });

    test('add-album from header creates a new node visible in the tree', async ({ page }) => {
        const ctx = await setupTest(page);
        const newName = `treetest_added_${Date.now()}`;

        await gotoAlbums(page);
        await page.locator('.add-album-button').click();
        const popin = page.locator('#AddAlbum');
        await expect(popin).toBeVisible();

        await popin.locator('.AddAlbumLabelUsername .user-property-input').fill(newName);
        await popin.locator('.AddAlbumSubmit').click();
        await expect(popin).not.toBeVisible();

        // Find the node by visible name.
        const newNode = page.locator('.move-cat-container', { hasText: newName });
        await expect(newNode).toBeVisible();

        // Capture the new id from the DOM for cleanup.
        const idAttr = await newNode.evaluate((el) => el.id);
        const id = Number(idAttr.replace('cat-', ''));
        if (!Number.isNaN(id)) ctx.ids.push(id);

        await deleteAlbums(page, ctx.ids, ctx.token);
    });

    test('drag-drop re-parents a node onto another (becomes a child)', async ({ page }) => {
        const ctx = await setupTest(page);
        const ts = Date.now();
        // Two siblings at root. Drop source onto the middle of target → 'inside' position.
        const targetId = await createAlbum(page, `treetest_dnd_target_${ts}`);
        ctx.ids.push(targetId);
        const sourceId = await createAlbum(page, `treetest_dnd_source_${ts}`);
        ctx.ids.push(sourceId);

        await gotoAlbums(page);
        const sourceLi = page.locator(`li.jqtree_common:has(#cat-${sourceId})`);
        const targetLi = page.locator(`li.jqtree_common:has(#cat-${targetId})`);
        await expect(sourceLi).toBeVisible();
        await expect(targetLi).toBeVisible();

        // HTML5 native drag-and-drop. dragTo aims at element center which
        // computes to position='inside' (middle third) — exactly what we want.
        await sourceLi.dragTo(targetLi);

        // The album-tree calls applyMove() which fires pwg.categories.move
        // and then commits the DOM move. Wait for the API result to land.
        await expect.poll(async () => {
            const status = (await pwgApi(page, 'pwg.categories.getList', { cat_id: sourceId })) as {
                result: { categories: Array<{ id: number; id_uppercat?: number | string | null }> };
            };
            const moved = status.result.categories.find(c => c.id === sourceId);
            return Number(moved?.id_uppercat ?? 0);
        }, { timeout: 10000 }).toBe(targetId);

        await deleteAlbums(page, ctx.ids, ctx.token);
    });
});
