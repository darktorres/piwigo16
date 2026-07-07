<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Behavioural regression net for the album tree (admin/themes/default/js/
 * albums.js + cat_list.js, jqTree-based at this point in the replay — P24
 * converts it to a native module). Assertions describe what the user sees,
 * not implementation details.
 *
 * Drag-drop re-parenting is intentionally not ported: 16.x-v2's own version
 * of this test notes jqTree's DnD only responds to its internal jQuery
 * mouse-event state machine, which Playwright's synthesised mouse events
 * don't reliably enter — not a meaningful regression net either way.
 */
function albumTreePwgToken(object $test): array
{
    $page = H::loginAsAdmin($test);
    $token = H::pwgToken($page);

    return [$page, $token];
}

function gotoAlbumsTree(object $page): object
{
    $page = H::navigateOk($page, '/admin.php?page=albums');
    $page->assertPresent('.tree');
    $page->assertPresent('.move-cat-container');

    return $page;
}

it('tree renders and shows a created album as a node', function (): void {
    [$page, $token] = albumTreePwgToken($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'treetest_render_' . uniqid()]);
    $id = (int) $album['result']['id'];

    $page = gotoAlbumsTree($page);
    $page->assertPresent('#cat-' . $id);

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $id, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => $token,
    ]);
});

it('clicking the toggler expands and collapses a parent node', function (): void {
    [$page, $token] = albumTreePwgToken($this);
    $ts = uniqid();
    $parent = H::wsCall($page, 'pwg.categories.add', ['name' => "treetest_parent_{$ts}"]);
    $parentId = (int) $parent['result']['id'];
    $child = H::wsCall($page, 'pwg.categories.add', ['name' => "treetest_child_{$ts}", 'parent' => $parentId]);
    $childId = (int) $child['result']['id'];

    $page = gotoAlbumsTree($page);
    $page->assertVisible('#cat-' . $parentId);
    $page->assertMissing('#cat-' . $childId);

    $page->click('#cat-' . $parentId . ' .move-cat-toogler');
    $page->assertVisible('#cat-' . $childId);

    $page->click('#cat-' . $parentId . ' .move-cat-toogler');
    $page->assertMissing('#cat-' . $childId);

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $parentId, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => $token,
    ]);
});

it('renaming an album updates the visible name in the tree', function (): void {
    [$page, $token] = albumTreePwgToken($this);
    $ts = uniqid();
    $originalName = "treetest_rename_{$ts}";
    $newName = "treetest_renamed_{$ts}";
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => $originalName]);
    $id = (int) $album['result']['id'];

    $page = gotoAlbumsTree($page);
    $page->assertSeeIn('#cat-' . $id . ' .move-cat-title', $originalName);

    $page->click('#cat-' . $id . ' .move-cat-title-container');
    $page->assertVisible('#RenameAlbum');

    $page = $page
        ->fill('#RenameAlbum .RenameAlbumLabelUsername input', $newName)
        ->click('.RenameAlbumSubmit');

    $page->assertMissing('#RenameAlbum');
    $page->assertSeeIn('#cat-' . $id . ' .move-cat-title', $newName);

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $id, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => $token,
    ]);
});

it('deleting an album removes its node from the tree', function (): void {
    [$page, $token] = albumTreePwgToken($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'treetest_delete_' . uniqid()]);
    $id = (int) $album['result']['id'];

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
    $list = H::wsCall($page, 'pwg.categories.getAdminList');
    foreach ($list['result']['categories'] as $cat) {
        if ($cat['name'] === $newName) {
            H::wsCall($page, 'pwg.categories.delete', [
                'category_id' => $cat['id'], 'photo_deletion_mode' => 'no_delete', 'pwg_token' => $token,
            ]);
        }
    }
});
