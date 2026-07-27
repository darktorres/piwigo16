<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * CatModifyPageRenderer (admin.php?page=album&cat_id=X&tab=properties, the
 * "properties" tab of the "album" page) -- 0% coverage before this file.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): category 1 "Sample Album"
 * has 3 direct photos (image_category rows 1,2,3) and 1 direct sub-album
 * (category 2, id_uppercat=1) -- so its own recursive image count spans
 * both albums (5), while category 2 "Nested Sub Album" has 2 direct photos
 * (4, 5) and no sub-albums of its own. Both counts are asserted below so a
 * renderer that swapped which category's data it queried (e.g. always
 * querying category 1 regardless of $category_id) would fail immediately.
 */
it('shows the real photo/sub-album counts for a parent album with sub-albums', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=1&tab=properties');
    $page->assertNoJavaScriptErrors();

    expect($page->value('#cat-name'))->toBe('Sample Album');
    $page->assertSeeIn('.cat-photos .cat-modify-info-content', '3 photos');
    $page->assertSeeIn('.cat-photos .cat-modify-info-subcontent', '5 including sub-albums');
    $page->assertSeeIn('.cat-albums .cat-modify-info-content', '1 sub-albums');
    $page->assertSeeIn('.cat-albums .cat-modify-info-subcontent', '1 in whole branch');
});

it('shows the real photo count and zero sub-albums for a leaf album', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=2&tab=properties');
    $page->assertNoJavaScriptErrors();

    expect($page->value('#cat-name'))->toBe('Nested Sub Album');
    $page->assertSeeIn('.cat-photos .cat-modify-info-content', '2 photos');
    $page->assertSeeIn('.cat-photos .cat-modify-info-subcontent', '2 including sub-albums');
    $page->assertSeeIn('.cat-albums .cat-modify-info-content', '0 sub-albums');
    $page->assertSeeIn('.cat-albums .cat-modify-info-subcontent', '0 in whole branch');
});

// Edge case: a brand new album has no photos at all (has_images = false) --
// a genuinely different branch from the 2 tests above (both of which have
// has_images = true): no "manage album photos" link, and the representative
// thumbnail area falls back to its empty placeholder instead of a real
// picture.
it('shows the empty-album placeholder and no manage-photos link for a freshly created album', function (): void {
    $page = H::loginAsAdmin($this);
    $albumName = 'Empty Modify Test Album ' . uniqid();
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => $albumName]);
    $albumResult = $album['result'] ?? null;
    if (!is_array($albumResult) || !isset($albumResult['id']) || !is_numeric($albumResult['id'])) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $pwgToken = H::pwgToken($page);

    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');
    $page->assertNoJavaScriptErrors();

    expect($page->value('#cat-name'))->toBe($albumName);
    $page->assertSeeIn('.cat-photos .cat-modify-info-content', '0 photos');
    $page->assertSeeIn('.cat-albums .cat-modify-info-content', '0 sub-albums');
    $page->assertMissing('.icon-th.tiptip');
    $page->assertPresent('.cat-modify-representative.icon-file-image');
    $page->assertAttribute(
        '.cat-modify-representative',
        'title',
        'No photos in the current album, no thumbnail available'
    );

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $albumId, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => $pwgToken,
    ]);
});
