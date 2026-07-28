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

it('formats a multi-date info-title ("added between") when its photos span more than one date', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Multi Date Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    $imagePathA = H::makeTestImage('DateA');
    $imageIdA = H::uploadPhotoViaApi($imagePathA, $albumId, 'Multi Date Photo A ' . uniqid());
    @unlink($imagePathA);
    $imagePathB = H::makeTestImage('DateB');
    $imageIdB = H::uploadPhotoViaApi($imagePathB, $albumId, 'Multi Date Photo B ' . uniqid());
    @unlink($imagePathB);

    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    // Two widely-separated, distinct years (rather than the same year or
    // adjacent ones) so a MIN/MAX transposition bug would be obvious from
    // the ordering check below, not hidden by two nearly-identical values.
    $db->query(sprintf('UPDATE %simages SET date_available = \'2019-03-10\' WHERE id = %d', $prefix, $imageIdA));
    $db->query(sprintf('UPDATE %simages SET date_available = \'2024-11-20\' WHERE id = %d', $prefix, $imageIdB));

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
        expect($minPos)->toBeLessThan($maxPos);
    } finally {
        $db->close();
    }
});

it('shows the real physical directory info for a non-virtual (disk-synced) album', function (): void {
    $page = H::loginAsAdmin($this);

    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';

    // Every album created via pwg.categories.add is virtual (dir=NULL), as
    // are both fixture categories (1 and 2) -- confirmed by direct read of
    // tests/Fixtures/piwigo-17.0.sql's own INSERT INTO piwigo_categories
    // row. A non-virtual (site-synced) album needs a real `dir` + `site_id`,
    // which only a direct raw-SQL row can provide here; site_id=1 is the
    // fixture's own real `piwigo_sites` row
    // ('/home/torres/piwigo17-rewrite/galleries/').
    $dirName = 'physical_test_dir_' . uniqid();
    $db->query(sprintf(
        "INSERT INTO %scategories (name, dir, site_id, status, uppercats) VALUES ('Physical Test Album', '%s', 1, 'public', '0')",
        $prefix,
        $db->real_escape_string($dirName)
    ));
    $albumId = (int) $db->insert_id;
    // uppercats must resolve to (at least) this category's own id for
    // getLocalDir()'s `id IN (uppercats)` lookup to find it -- a root
    // category (no parent) has uppercats equal to its own id.
    $db->query(sprintf('UPDATE %scategories SET uppercats = %d WHERE id = %d', $prefix, $albumId, $albumId));

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
            '/home/torres/piwigo17-rewrite/galleries/' . $dirName
        );
    } finally {
        $db->query(sprintf('DELETE FROM %scategories WHERE id = %d', $prefix, $albumId));
        $db->close();
    }
});
