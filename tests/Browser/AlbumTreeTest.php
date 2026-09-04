<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Behavioural regression net for the album tree (admin/themes/admin/
 * default/js/albums.ts, native since P49-B group 6B —
 * `themes/default/js/vendor/widgets/jqtree.ts`). Assertions describe what the
 * user sees, not implementation details.
 *
 * Drag-and-drop reordering *is* covered below, unlike 16.x-v2's own
 * version of this test (which found jqTree's own jQuery mouse-event
 * state machine unreachable from Playwright's synthesised events): the
 * native port's drag handling is plain `mousedown`/`mousemove`/`mouseup`
 * listeners, which a real dispatched `MouseEvent` sequence drives
 * exactly like any other native handler. Pest Browser's own
 * locator-to-locator `drag()` helper still isn't used, since it can't
 * target the sub-row-height "before"/"inside"/"after" hit-area bands
 * `vendor/widgets/jqtree.ts`'s own hit-area generator produces (`docs/PLAN.md`'s
 * P49-B group 6B entry) — a raw dispatched sequence gives pixel control
 * the helper doesn't.
 */
/**
 * @return array{0: Webpage|PendingAwaitablePage|AwaitableWebpage, 1: string}
 */
function albumTreePwgToken(object $test): array
{
    $page = H::asAdmin($test);
    $token = H::pwgToken($page);

    return [$page, $token];
}

function gotoAlbumsTree(Webpage|PendingAwaitablePage|AwaitableWebpage $page): Webpage|PendingAwaitablePage|AwaitableWebpage
{
    $page = H::navigateOk($page, '/admin.php?page=albums');
    $page->assertPresent('.tree');
    $page->assertPresent('.move-cat-container');

    return $page;
}

/**
 * Narrows a `POST /api/v1/categories` response to its `id`.
 *
 * @param  array<array-key, mixed>  $response
 */
function addedCategoryId(array $response): int
{
    $id = $response['id'] ?? null;
    if (! is_numeric($id)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($response, true));
    }

    return (int) $id;
}

/**
 * Narrows a `GET /api/v1/categories` response to a list of {id, name}
 * pairs — the only fields this file needs for its cleanup-by-name lookup —
 * skipping any entry that doesn't carry both in the expected shape.
 *
 * @param  array<array-key, mixed>  $response
 * @return list<array{id: int, name: string}>
 */
function adminListCategories(array $response): array
{
    $categories = $response['categories'] ?? null;
    if (! is_array($categories)) {
        throw new RuntimeException('listCategoriesAdmin response missing categories: ' . var_export($response, true));
    }

    $out = [];
    foreach ($categories as $cat) {
        if (! is_array($cat)) {
            continue;
        }

        $catId = $cat['id'] ?? null;
        $catName = $cat['name'] ?? null;
        if (! is_numeric($catId) || ! is_string($catName)) {
            continue;
        }

        $out[] = [
            'id' => (int) $catId,
            'name' => $catName,
        ];
    }

    return $out;
}

it('tree renders and shows a created album as a node', function (): void {
    [$page, $token] = albumTreePwgToken($this);
    $album = H::createCategory($page, [
        'name' => 'treetest_render_' . uniqid(),
    ]);
    $id = addedCategoryId($album);

    $page = gotoAlbumsTree($page);
    $page->assertPresent('#cat-' . $id);

    H::deleteCategory($page, [
        'category_id' => $id,
        'photo_deletion_mode' => 'no_delete',
        'pwg_token' => $token,
    ]);
});

it('clicking the toggler expands and collapses a parent node', function (): void {
    [$page, $token] = albumTreePwgToken($this);
    $ts = uniqid();
    $parent = H::createCategory($page, [
        'name' => "treetest_parent_{$ts}",
    ]);
    $parentId = addedCategoryId($parent);
    $child = H::createCategory($page, [
        'name' => "treetest_child_{$ts}",
        'parent' => $parentId,
    ]);
    $childId = addedCategoryId($child);

    $page = gotoAlbumsTree($page);
    $page->assertVisible('#cat-' . $parentId);
    $page->assertMissing('#cat-' . $childId);

    $page->click('#cat-' . $parentId . ' .move-cat-toogler');
    $page->assertVisible('#cat-' . $childId);

    $page->click('#cat-' . $parentId . ' .move-cat-toogler');
    $page->assertMissing('#cat-' . $childId);

    H::deleteCategory($page, [
        'category_id' => $parentId,
        'photo_deletion_mode' => 'no_delete',
        'pwg_token' => $token,
    ]);
});

it('renaming an album updates the visible name in the tree', function (): void {
    [$page, $token] = albumTreePwgToken($this);
    $ts = uniqid();
    $originalName = "treetest_rename_{$ts}";
    $newName = "treetest_renamed_{$ts}";
    $album = H::createCategory($page, [
        'name' => $originalName,
    ]);
    $id = addedCategoryId($album);

    $page = gotoAlbumsTree($page);
    $page->assertSeeIn('#cat-' . $id . ' .move-cat-title', $originalName);

    $page->click('#cat-' . $id . ' .move-cat-title-container');
    $page->assertVisible('#RenameAlbum');

    $page = $page
        ->fill('#RenameAlbum .RenameAlbumLabelUsername input', $newName)
        ->click('.RenameAlbumSubmit');

    $page->assertMissing('#RenameAlbum');
    $page->assertSeeIn('#cat-' . $id . ' .move-cat-title', $newName);

    H::deleteCategory($page, [
        'category_id' => $id,
        'photo_deletion_mode' => 'no_delete',
        'pwg_token' => $token,
    ]);
});

it('deleting an album removes its node from the tree', function (): void {
    [$page, $token] = albumTreePwgToken($this);
    $album = H::createCategory($page, [
        'name' => 'treetest_delete_' . uniqid(),
    ]);
    $id = addedCategoryId($album);

    $page = gotoAlbumsTree($page);
    $page->assertVisible('#cat-' . $id);

    $page->click('#cat-' . $id . ' .move-cat-delete');
    $page->assertVisible('#DeleteAlbum');
    $page->click('.DeleteAlbumSubmit');

    $page->assertMissing('#DeleteAlbum');
    $page->assertMissing('#cat-' . $id);

    // Already deleted via the UI — pwg_token unused here but kept for
    // symmetry with the other tests' cleanup pattern.
    unset($token);
});

it('add-album from header creates a new node visible in the tree', function (): void {
    [$page, $token] = albumTreePwgToken($this);
    $newName = 'treetest_added_' . uniqid();

    $page = gotoAlbumsTree($page);
    $page->click('.add-album-button');
    $page->assertVisible('#AddAlbum');

    $page = $page
        ->fill('#AddAlbum .AddAlbumLabelUsername .user-property-input', $newName)
        ->click('.AddAlbumSubmit');

    $page->assertMissing('#AddAlbum');
    $page->assertSee($newName);

    // Clean up via the admin list rather than scraping the new node's id
    // out of the DOM — simpler and equally reliable. Asserting the match
    // count itself (not just deleting whatever matches) is load-bearing,
    // not incidental: a real bug found this session had a single click
    // on `.AddAlbumSubmit` fire 2 real `POST /api/v1/categories`
    // requests, and `assertSee($newName)` above passes exactly the same
    // whether 1 or 2 real categories got created -- only counting the
    // admin list's own matches catches that class of regression.
    $list = H::listCategoriesAdmin($page);
    $matches = array_values(array_filter(
        adminListCategories($list),
        static fn (array $cat): bool => $cat['name'] === $newName
    ));
    expect($matches)
        ->toHaveCount(1);

    foreach ($matches as $cat) {
        H::deleteCategory($page, [
            'category_id' => $cat['id'],
            'photo_deletion_mode' => 'no_delete',
            'pwg_token' => $token,
        ]);
    }
});

it('drag-and-drop reorders a root album to right after its sibling', function (): void {
    // Real, mutation-verified coverage of `vendor/widgets/jqtree.ts`'s own
    // drag-and-drop handler (P49-B group 6B) -- the hardest single piece
    // of new code in the whole P49 campaign, and never behaviourally
    // driven before (see this file's own leading comment for why the
    // jQuery-based version couldn't be). A raw dispatched mousedown/
    // mousemove/mouseup sequence (not Pest Browser's own locator-to-
    // locator `drag()`, which can't target a specific sub-row hit-area
    // band) steps through the target's row, watching for the real ghost
    // drop-hint to land immediately after it -- not merely "somewhere
    // near it" -- before releasing, so this passes only if hit-area
    // generation, the drop hint, the `tree.move` event, and the real
    // server-side reorder round-trip all actually work end-to-end.
    //
    // 3 siblings, not 2: `POST /api/v1/categories` (no explicit position)
    // prepends each new album, so creating `moved` then `target` alone
    // leaves `target` sitting *directly above* `moved` already --
    // "drop right after target" would then be a real, correctly-skipped
    // no-op position (jqtree never offers "after" a node when its own
    // next sibling is the one being dragged), not a meaningful move. A
    // `middle` sibling between `target` and `moved` keeps the move real.
    [$page, $token] = albumTreePwgToken($this);
    $ts = uniqid();
    $moved = H::createCategory($page, [
        'name' => "treetest_dnd_moved_{$ts}",
    ]);
    $movedId = addedCategoryId($moved);
    $middle = H::createCategory($page, [
        'name' => "treetest_dnd_middle_{$ts}",
    ]);
    $middleId = addedCategoryId($middle);
    $target = H::createCategory($page, [
        'name' => "treetest_dnd_target_{$ts}",
    ]);
    $targetId = addedCategoryId($target);

    $page = gotoAlbumsTree($page);
    $page->assertVisible('#cat-' . $movedId);
    $page->assertVisible('#cat-' . $middleId);
    $page->assertVisible('#cat-' . $targetId);

    $result = H::scriptJson($page, <<<JS
        new Promise((resolve, reject) => {
            const wait = (ms) => new Promise((r) => setTimeout(r, ms));

            function dispatch(type, x, y) {
                const ev = new MouseEvent(type, {
                    bubbles: true,
                    cancelable: true,
                    view: window,
                    clientX: x,
                    clientY: y,
                    button: 0,
                    buttons: type === 'mouseup' ? 0 : 1,
                });
                (document.elementFromPoint(x, y) || document).dispatchEvent(ev);
            }

            (async () => {
                const movedDiv = document.querySelector('#cat-{$movedId}');
                const targetDiv = document.querySelector('#cat-{$targetId}');
                if (!movedDiv || !targetDiv) {
                    reject(new Error('rows not found'));
                    return;
                }
                const targetLi = targetDiv.closest('li');
                const movedRect = movedDiv.getBoundingClientRect();
                const targetRect = targetDiv.getBoundingClientRect();

                const startX = movedRect.left + 20;
                const startY = movedRect.top + movedRect.height / 2;
                const x = targetRect.left + 20;

                dispatch('mousedown', startX, startY);
                // jqtree's own real mouseDelay (300ms) gates when a
                // mousemove is even allowed to start the drag -- the
                // very first move dispatched before it elapses is
                // silently ignored.
                await wait(400);

                let landed = false;
                for (let y = targetRect.top + 5; y <= targetRect.bottom + 5; y += 5) {
                    dispatch('mousemove', x, y);
                    await wait(20);
                    const ghost = document.querySelector('.jqtree-ghost');
                    if (
                        ghost
                        && ghost.previousElementSibling === targetLi
                        && !ghost.classList.contains('jqtree-inside')
                    ) {
                        landed = true;
                        dispatch('mouseup', x, y);
                        break;
                    }
                }
                if (!landed) {
                    dispatch('mouseup', x, targetRect.bottom + 5);
                    reject(new Error('drop hint never landed right after the target row'));
                    return;
                }

                const deadline = Date.now() + 5000;
                const check = () => {
                    const ids = Array.from(document.querySelectorAll('.tree > ul > li'))
                        .map((li) => li.querySelector('.jqtree-element').id);
                    const movedIndex = ids.indexOf('cat-{$movedId}');
                    const targetIndex = ids.indexOf('cat-{$targetId}');
                    if (movedIndex === targetIndex + 1) {
                        resolve(JSON.stringify({ landed, order: ids }));
                        return;
                    }
                    if (Date.now() > deadline) {
                        reject(new Error('moved node never landed after target: ' + JSON.stringify(ids)));
                        return;
                    }
                    setTimeout(check, 100);
                };
                check();
            })();
        })
        JS);

    expect($result['landed'] ?? null)->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'album tree drag-and-drop reorder');

    H::deleteCategory($page, [
        'category_id' => $movedId,
        'photo_deletion_mode' => 'no_delete',
        'pwg_token' => $token,
    ]);
    H::deleteCategory($page, [
        'category_id' => $middleId,
        'photo_deletion_mode' => 'no_delete',
        'pwg_token' => $token,
    ]);
    H::deleteCategory($page, [
        'category_id' => $targetId,
        'photo_deletion_mode' => 'no_delete',
        'pwg_token' => $token,
    ]);
});

it('drag-and-drop re-parents a root album onto its sibling', function (): void {
    // Distinct branch from the "after" reorder above: dropping directly
    // onto a *leaf* album's own row is real `position: "inside"` (not
    // "after"), which shows a border drop-hint around the whole row
    // rather than a ghost insertion line (`mustShowBorderDropHint()`
    // returns true for a non-folder node at "inside" unconditionally --
    // real source, `vendor/widgets/jqtree.ts`), and takes `applyMove()`'s
    // `changeParent()` API path rather than `changeRank()`. Confirms the
    // moved album actually nests inside the target's own subtree in the
    // real re-rendered DOM, not just that some AJAX call fired.
    [$page, $token] = albumTreePwgToken($this);
    $ts = uniqid();
    $moved = H::createCategory($page, [
        'name' => "treetest_dnd_reparent_moved_{$ts}",
    ]);
    $movedId = addedCategoryId($moved);
    $target = H::createCategory($page, [
        'name' => "treetest_dnd_reparent_target_{$ts}",
    ]);
    $targetId = addedCategoryId($target);

    $page = gotoAlbumsTree($page);
    $page->assertVisible('#cat-' . $movedId);
    $page->assertVisible('#cat-' . $targetId);

    $result = H::scriptJson($page, <<<JS
        new Promise((resolve, reject) => {
            const wait = (ms) => new Promise((r) => setTimeout(r, ms));

            function dispatch(type, x, y) {
                const ev = new MouseEvent(type, {
                    bubbles: true,
                    cancelable: true,
                    view: window,
                    clientX: x,
                    clientY: y,
                    button: 0,
                    buttons: type === 'mouseup' ? 0 : 1,
                });
                (document.elementFromPoint(x, y) || document).dispatchEvent(ev);
            }

            (async () => {
                const movedDiv = document.querySelector('#cat-{$movedId}');
                const targetDiv = document.querySelector('#cat-{$targetId}');
                if (!movedDiv || !targetDiv) {
                    reject(new Error('rows not found'));
                    return;
                }
                const movedRect = movedDiv.getBoundingClientRect();
                const targetRect = targetDiv.getBoundingClientRect();

                const startX = movedRect.left + 20;
                const startY = movedRect.top + movedRect.height / 2;
                const x = targetRect.left + 20;

                dispatch('mousedown', startX, startY);
                await wait(400);

                let landed = false;
                for (let y = targetRect.top + 5; y <= targetRect.bottom - 5; y += 5) {
                    dispatch('mousemove', x, y);
                    await wait(20);
                    const border = document.querySelector('.jqtree-border');
                    if (border && border.parentElement === targetDiv) {
                        landed = true;
                        dispatch('mouseup', x, y);
                        break;
                    }
                }
                if (!landed) {
                    dispatch('mouseup', x, targetRect.bottom - 5);
                    reject(new Error('border drop hint never landed on the target row'));
                    return;
                }

                const deadline = Date.now() + 5000;
                const check = () => {
                    const targetLi = document.querySelector('#cat-{$targetId}')?.closest('li');
                    const nestedMoved = targetLi ? targetLi.querySelector('#cat-{$movedId}') : null;
                    if (nestedMoved) {
                        resolve(JSON.stringify({
                            landed,
                            nested: true,
                            targetIsFolder: targetLi.classList.contains('jqtree-folder'),
                        }));
                        return;
                    }
                    if (Date.now() > deadline) {
                        reject(new Error('moved album never nested under the target'));
                        return;
                    }
                    setTimeout(check, 100);
                };
                check();
            })();
        })
        JS);

    expect($result['landed'] ?? null)->toBeTrue();
    expect($result['nested'] ?? null)->toBeTrue();
    expect($result['targetIsFolder'] ?? null)->toBeTrue();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'album tree drag-and-drop re-parent');

    // Deleting the (now-parent) target cascades to its new child --
    // matches the real "album and its N sub-albums" delete semantics
    // this same file's own "deleting an album" test exercises directly.
    H::deleteCategory($page, [
        'category_id' => $targetId,
        'photo_deletion_mode' => 'no_delete',
        'pwg_token' => $token,
    ]);
});
