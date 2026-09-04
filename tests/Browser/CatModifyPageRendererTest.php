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
 *
 * 3 "row not found" Exception guards (the aggregate photo-count query in
 * render() itself, plus getLocalDir()/getSiteUrl()'s own "category not
 * found" throws) are not exercised: the first is unreachable because a bare
 * COUNT()/MIN()/MAX() query with no GROUP BY always returns exactly one row
 * in MySQL even when zero photos match (confirmed by direct read of the
 * surrounding has_images gate, which only enters this branch when at least
 * one image_category row already exists); the other two would need
 * render() to be called with a category id that stops existing mid-request
 * -- AlbumSubController (the one real caller) already validates cat_id
 * against a fresh DB read immediately before dispatching here, so this
 * can't happen through the real request path either.
 */
it('shows the real photo/sub-album counts for a parent album with sub-albums', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=1&tab=properties');
    $page->assertNoJavaScriptErrors();

    expect($page->value('#cat-name'))
        ->toBe('Sample Album');
    $page->assertSeeIn('.cat-photos .cat-modify-info-content', '3 photos');
    $page->assertSeeIn('.cat-photos .cat-modify-info-subcontent', '5 including sub-albums');
    $page->assertSeeIn('.cat-albums .cat-modify-info-content', '1 sub-albums');
    $page->assertSeeIn('.cat-albums .cat-modify-info-subcontent', '1 in whole branch');
});

it('single-escapes an HTML-special-character-bearing category name/comment, not double-escaped (P44-F)', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Cat Modify Escaping Album & "Quote" ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $db = H::connect();
    H::dbQuery($db, sprintf("UPDATE categories SET comment = 'Comment & \"Quote\"' WHERE id = %d", $albumId));
    H::dbClose($db);

    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');
    $page->assertNoJavaScriptErrors();

    // WebDriver's own .value getter returns the browser-decoded string --
    // double-escaping would leave a literal '&amp;'/'&quot;' in this value
    // instead of the real '&'/'"' characters.
    expect($page->value('#cat-name'))
        ->toContain('Album & "Quote"');
    expect($page->value('#cat-comment'))
        ->toBe('Comment & "Quote"');
});

it('shows the real photo count and zero sub-albums for a leaf album', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=2&tab=properties');
    $page->assertNoJavaScriptErrors();

    expect($page->value('#cat-name'))
        ->toBe('Nested Sub Album');
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
    $page = H::asAdmin($this);
    $albumName = 'Empty Modify Test Album ' . uniqid();
    $album = H::createCategory($page, [
        'name' => $albumName,
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $pwgToken = H::pwgToken($page);

    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');
    $page->assertNoJavaScriptErrors();

    expect($page->value('#cat-name'))
        ->toBe($albumName);
    $page->assertSeeIn('.cat-photos .cat-modify-info-content', '0 photos');
    $page->assertSeeIn('.cat-albums .cat-modify-info-content', '0 sub-albums');
    $page->assertMissing('.icon-th.tiptip');
    $page->assertPresent('.cat-modify-representative.icon-file-image');
    $page->assertAttribute(
        '.cat-modify-representative',
        'title',
        'No photos in the current album, no thumbnail available'
    );

    H::deleteCategory($page, [
        'category_id' => $albumId,
        'photo_deletion_mode' => 'no_delete',
        'pwg_token' => $pwgToken,
    ]);
});

it('formats a multi-date info-title ("added between") when its photos span more than one date', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Multi Date Test Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $imagePathA = H::makeTestImage('DateA');
    $imageIdA = H::uploadPhotoViaApi($imagePathA, $albumId, 'Multi Date Photo A ' . uniqid());
    @unlink($imagePathA);
    $imagePathB = H::makeTestImage('DateB');
    $imageIdB = H::uploadPhotoViaApi($imagePathB, $albumId, 'Multi Date Photo B ' . uniqid());
    @unlink($imagePathB);

    $db = H::connect();
    // Two widely-separated, distinct years (rather than the same year or
    // adjacent ones) so a MIN/MAX transposition bug would be obvious from
    // the ordering check below, not hidden by two nearly-identical values.
    H::dbQuery($db, sprintf('UPDATE images SET date_available = \'2019-03-10\' WHERE id = %d', $imageIdA));
    H::dbQuery($db, sprintf('UPDATE images SET date_available = \'2024-11-20\' WHERE id = %d', $imageIdB));

    try {
        $result = H::rawGet($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('added between');
        // Not just "both years appear somewhere" -- the earlier (min) year
        // must render before the later (max) year, matching the source's
        // own `added between %s and %s` argument order (min_date, max_date).
        $minPos = strpos($result['body'], '2019');
        $maxPos = strpos($result['body'], '2024');
        if ($minPos === false || $maxPos === false) {
            throw new RuntimeException('expected both years to be present in the response body');
        }
        expect($minPos)
            ->toBeLessThan($maxPos);
    } finally {
        H::dbClose($db);
    }
});

// Category 2's own fixture parent is category 1 (id_uppercat=1) -- this
// asserts the real parent id reaches the typed page-data JSON island
// cat_modify.latte exposes it into (cat_id, parent_cat_id). A raw HTTP
// GET can't observe categories/modify.ts's own runtime computation of
// related_categories_ids, so this checks the JSON island's source
// values directly instead -- PageDataPayload::toJson() is a plain
// json_encode(), so an int value renders as a bare number, not a
// quoted string.
it('assigns the real parent id to PARENT_CAT_ID for a sub-album, not 0', function (): void {
    $page = H::asAdmin($this);
    $result = H::rawGet($page, '/admin.php?page=album&cat_id=2&tab=properties');

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('"cat_id":2');
    expect($result['body'])->toContain('"parent_cat_id":1');
    // Guards against a sub-album's parent id narrowing back to 0.
    expect($result['body'])->not->toContain('"parent_cat_id":0');
});

// ALLOW_DELETE (the "remove current representant" action) needs BOTH
// has_images AND allow_random_representative -- default config has the
// latter off, so #deleteRepresentative is absent even though category 1
// has images. Flipping the config on is the only real way to reach this
// branch: category 1's own fixture representative_picture_id (1) is
// already set, so has_images alone (already covered by the "parent album"
// test above) isn't enough by itself.
it('shows the delete-representative action when allow_random_representative is enabled', function (): void {
    $snapshot = H::snapshotConfig(['allow_random_representative']);
    H::setConfigValue('allow_random_representative', 'true');

    try {
        $page = H::asAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=album&cat_id=1&tab=properties');
        $page->assertNoJavaScriptErrors();

        $page->assertPresent('#deleteRepresentative');
    } finally {
        H::restoreConfig($snapshot);
    }
});

// The album with no direct photos but a representative thumbnail anyway --
// the one combination that reaches allowDelete without allowSetRandom, and
// the state cat_modify.latte's own wrapper guard used to swallow. It read
// `allowSetRandom || allowSetRandom`, a duplicated operand inherited
// verbatim from the original Smarty (62fdf2ab65, the commit that introduced
// the block), so the whole .cat-modify-representative-actions box was
// suppressed whenever allowSetRandom was false -- taking "Remove thumbnail"
// with it and leaving the album's thumbnail unremovable through the UI.
//
// Reachable through the public API alone: PUT /categories/{id}/representative
// checks only that both ids exist, not that the image is in the category.
it('offers the delete-representative action for an album with a thumbnail but no direct photos', function (): void {
    $page = H::asAdmin($this);
    $albumName = 'Representative Without Photos ' . uniqid();
    $album = H::createCategory($page, [
        'name' => $albumName,
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $pwgToken = H::pwgToken($page);

    try {
        H::setCategoryRepresentative($page, $albumId, 1);

        $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');
        $page->assertNoJavaScriptErrors();

        // has_images is false, so there is no "Refresh thumbnail" action --
        // asserting its absence is what pins this to the branch where the
        // wrapper's second operand is the only thing that can open the box.
        $page->assertMissing('#refreshRepresentative');
        $page->assertPresent('.cat-modify-representative-actions');
        $page->assertPresent('#deleteRepresentative');
        // The representative really did take effect, so the button acts on
        // something: the tile carries the thumbnail rather than the
        // "no thumbnail available" placeholder, and the delete link is not
        // hidden by its own no-picture u-hidden branch.
        $page->assertMissing('#deleteRepresentative.u-hidden');
        $page->assertPresent('.cat-modify-representative[style*="background-image"]');
    } finally {
        H::deleteCategory($page, [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'no_delete',
            'pwg_token' => $pwgToken,
        ]);
    }
});

it('shows the real physical directory info for a non-virtual (disk-synced) album', function (): void {
    $page = H::asAdmin($this);

    $db = H::connect();

    // Every album created via pwg.categories.add is virtual (dir=NULL), as
    // are both fixture categories (1 and 2) -- confirmed by direct read of
    // tests/Fixtures/piwigo-17.0.sql's own INSERT INTO categories
    // row. A non-virtual (site-synced) album needs a real `dir` + `site_id`,
    // which only a direct raw-SQL row can provide here; site_id=1 is the
    // fixture's own real `sites` row, its `galleries_url` corrected
    // to this checkout's own Paths::$root at fixture-load time (not the
    // literal committed in the fixture -- see
    // tools/reimport-fixture.sh's own docblock for why it can't be).
    $realRoot = dirname(__DIR__, 2) . '/';
    $dirName = 'physical_test_dir_' . uniqid();
    H::dbQuery($db, sprintf("INSERT INTO categories (name, dir, site_id, status, uppercats) VALUES ('Physical Test Album', '%s', 1, 'public', '0')", H::dbEscape($db, $dirName)));
    $albumId = H::dbInsertId($db);
    // uppercats must resolve to (at least) this category's own id for
    // getLocalDir()'s `id IN (uppercats)` lookup to find it -- a root
    // category (no parent) has uppercats equal to its own id.
    H::dbQuery($db, sprintf('UPDATE categories SET uppercats = %d WHERE id = %d', $albumId, $albumId));

    try {
        $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=properties');
        $page->assertNoJavaScriptErrors();

        // CAT_DIR_NAME = basename(getCompleteDir()) = basename(site_url . dir)
        // = the dir name itself; CAT_FULL_DIR = the full site_url+dir path,
        // exposed verbatim in both directory spans' own title attributes.
        $page->assertSeeIn('.cat-modify-info-content.directory', $dirName);
        $page->assertAttribute('.cat-modify-info-content.directory', 'title', $dirName);
        $page->assertAttribute(
            '.cat-modify-info-subcontent.directory',
            'title',
            $realRoot . 'galleries/' . $dirName
        );
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM categories WHERE id = %d', $albumId));
        H::dbClose($db);
    }
});
