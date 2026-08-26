<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Behavioural regression net for the album tree (admin/themes/default/js/
 * albums.js + cat_list.js, jqTree-based at this point — a future pass
 * converts it to a native module). Assertions describe what the user sees,
 * not implementation details.
 *
 * Drag-drop re-parenting is intentionally not ported: 16.x-v2's own version
 * of this test notes jqTree's DnD only responds to its internal jQuery
 * mouse-event state machine, which Playwright's synthesised mouse events
 * don't reliably enter — not a meaningful regression net either way.
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
    // out of the DOM — simpler and equally reliable.
    $list = H::listCategoriesAdmin($page);
    foreach (adminListCategories($list) as $cat) {
        if ($cat['name'] === $newName) {
            H::deleteCategory($page, [
                'category_id' => $cat['id'],
                'photo_deletion_mode' => 'no_delete',
                'pwg_token' => $token,
            ]);
        }
    }
});
