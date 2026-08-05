<?php

declare(strict_types=1);

use Piwigo\Core\StringHelper;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * ElementSetRanksPageRenderer (admin.php?page=album&cat_id=X&tab=sort_order,
 * the "sort_order" tab of the "album" page) -- 0% coverage before this
 * file.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): category 1 "Sample Album"
 * has 3 direct photos (image_id 1/2/3, image_category.rank 1/2/3, ORDER BY
 * `rank` in the renderer's own query) named "Photo 1"/"Photo 2"/"Photo 3".
 * The renderer's own RANK template value is `(++$current_rank) * 10`
 * starting from 1 -- a faithful port of the legacy admin/element_set_ranks.php
 * (confirmed identical, not a rewrite regression), so the FIRST thumbnail
 * gets rank 20, not 10 -- asserted explicitly below since it's a genuinely
 * surprising off-by-one to lock in.
 */
it('renders one ranked thumbnail per photo, in rank order, with the legacy off-by-one multiplier', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=1&tab=sort_order');
    $page->assertNoJavaScriptErrors();

    expect($page->value('input[name="rank_of_image[1]"]'))->toBe('20');
    expect($page->value('input[name="rank_of_image[2]"]'))->toBe('30');
    expect($page->value('input[name="rank_of_image[3]"]'))->toBe('40');
    $page->assertPresent('img[alt="Photo 1"]');
    $page->assertPresent('img[alt="Photo 2"]');
    $page->assertPresent('img[alt="Photo 3"]');

    // category.image_order is NULL in the fixture -- the renderer's own
    // "elseif ($category->imageOrder !== '')" check treats null as
    // non-empty (null !== ''), so "automatic order" (user_define) is
    // pre-selected instead of "Use the default photo sort order", even
    // though no image_order was ever explicitly set. This is the same
    // (faithfully ported) behaviour as legacy admin/element_set_ranks.php,
    // confirmed by direct comparison -- not a rewrite regression, but a
    // real, surprising-enough-to-lock-in quirk.
    $page->assertRadioSelected('image_order_choice', 'user_define');
    $page->assertRadioNotSelected('image_order_choice', 'default');
});

// Edge case: an album with zero photos skips the whole "Manual order"
// thumbnails block entirely ({if !empty($thumbnails)} in
// element_set_ranks.tpl), a genuinely different branch from the populated
// case above.
it('renders no manual-order thumbnails block for an album with no photos', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Empty Ranks Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (!is_array($albumResult) || !isset($albumResult['id']) || !is_numeric($albumResult['id'])) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $pwgToken = H::pwgToken($page);

    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId . '&tab=sort_order');
    $page->assertNoJavaScriptErrors();

    // Note: the "Sort order" fieldset's own 2nd radio choice label is
    // literally "manual order" (lowercase, always rendered) -- a plain
    // assertDontSee('Manual order') would false-positive-match it
    // case-insensitively, so this checks for the *thumbnails* fieldset's own
    // distinguishing elements (its icon class and the <ul> it wraps)
    // instead of the ambiguous shared words.
    $page->assertMissing('.icon-sort-alt-down');
    $page->assertMissing('ul.thumbnails');

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $albumId, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => $pwgToken,
    ]);
});

/**
 * Two of this class's own real-but-untested lines are deliberately NOT
 * covered below, after a full trace of the only real production call
 * path: AlbumSubController::handle() (the sole caller of
 * elementSetRanksPageRenderer()->render(), confirmed via a repo-wide grep)
 * already resolves `cat_id` from the SAME `$_GET['cat_id']` this class's
 * own ElementSetRanksRequest::fromGlobals() re-reads, and already calls
 * its own `CategoryRepository::findById()` + `htmlService()->fatalError()`
 * (a genuine, unconditional 500 -- not a soft/non-halting trigger_error)
 * BEFORE ever dispatching to this renderer, for every tab. A missing or
 * non-numeric cat_id, or a numeric cat_id with no matching row, is
 * therefore always rejected by AlbumSubController itself, before
 * ElementSetRanksPageRenderer::render() ever runs -- so the "missing
 * cat_id param" trigger_error() (~line 78) and the "category not found"
 * pageNotFound() (~line 143) it can cascade into are only reachable if a
 * category genuinely exists with id 0 (the one fallback value this
 * class's own Request DTO ever produces for an invalid cat_id), which no
 * real Piwigo installation can ever have -- categories.id is a normal
 * AUTO_INCREMENT PK starting at 1 (confirmed against the fixture). Not a
 * real, externally-reachable behaviour to test.
 */
it('rejects a save-order submission with no CSRF token as a bad request', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Ranks CSRF Missing Token Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    // No 'pwg_token' key at all -- CsrfTokenRequest::fromArray() reads it
    // as null, CsrfService::check() returns null (distinct from a
    // wrong-but-present token, which returns false), and checkOrFail()
    // routes that to badRequest(), not accessDenied().
    $result = H::adminPost($page, '/admin.php?page=album-' . $albumId . '-sort_order', [
        'submit' => '1',
    ]);

    expect($result['status'])->toBe(400);
    expect($result['body'])->toContain('Bad request');
    expect($result['body'])->toContain('missing token');

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $albumId, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => H::pwgToken($page),
    ]);
});

it('rejects a save-order submission with a wrong CSRF token as access denied', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Ranks CSRF Wrong Token Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    // A present-but-wrong token -- CsrfService::check() returns false (not
    // null), routing checkOrFail() to accessDenied() instead of
    // badRequest(). fixture_admin is logged in and not a guest, so
    // accessDenied() takes its 401 branch, not the identification.php
    // redirect branch.
    $result = H::adminPost($page, '/admin.php?page=album-' . $albumId . '-sort_order', [
        'submit' => '1',
        'pwg_token' => 'not-the-real-token',
    ]);

    expect($result['status'])->toBe(401);
    expect($result['body'])->toContain('You are not authorized to access the requested page');

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $albumId, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => H::pwgToken($page),
    ]);
});

it('saves a manual rank_of_image POST as the real image_category rank order', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Ranks Manual Save Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    $imagePathA = H::makeTestImage('Rank A');
    $imageIdA = H::uploadPhotoViaApi($imagePathA, $albumId, 'Rank Save Photo A ' . uniqid());
    @unlink($imagePathA);
    $imagePathB = H::makeTestImage('Rank B');
    $imageIdB = H::uploadPhotoViaApi($imagePathB, $albumId, 'Rank Save Photo B ' . uniqid());
    @unlink($imagePathB);
    $imagePathC = H::makeTestImage('Rank C');
    $imageIdC = H::uploadPhotoViaApi($imagePathC, $albumId, 'Rank Save Photo C ' . uniqid());
    @unlink($imagePathC);

    // Posted deliberately out of upload/id order (B < C < A) --
    // ImageService::saveImagesOrder() re-ranks strictly by the *posted*
    // rank_of_image values (ElementSetRanksRequest::fromArrays()'s own
    // asort(..., SORT_NUMERIC)), 1-based in that sorted order, so matching
    // DB rank order to this posted order (not upload order) is what
    // actually proves the real category_id + array_keys-as-ints plumbing.
    $result = H::adminPost($page, '/admin.php?page=album-' . $albumId . '-sort_order', [
        'pwg_token' => H::pwgToken($page),
        'submit' => '1',
        'rank_of_image' => [
            (string) $imageIdA => '30',
            (string) $imageIdB => '10',
            (string) $imageIdC => '20',
        ],
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Album updated successfully');

    $db = H::connect();
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';

    // `rank` is a reserved word on both platforms (MySQL 8.0.2+'s window
    // functions, Postgres always) -- backtick/double-quote per platform.
    $rankIdentifier = $db instanceof mysqli ? '`rank`' : '"rank"';
    $fetchedRows = H::dbFetchAll($db, sprintf('SELECT image_id, %1$s FROM %2$simage_category WHERE category_id = %3$d ORDER BY %1$s', $rankIdentifier, $prefix, $albumId));
    $rows = [];
    foreach ($fetchedRows as $row) {
        $rows[] = ['image_id' => (int) $row['image_id'], 'rank' => (int) $row['rank']];
    }
    H::dbClose($db);

    expect(array_column($rows, 'image_id'))->toBe([$imageIdB, $imageIdC, $imageIdA]);
    expect(array_column($rows, 'rank'))->toBe([1, 2, 3]);

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $albumId, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => H::pwgToken($page),
    ]);
});

it('builds a comma-joined user_define image_order string, filtering out an invalid sort field', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Ranks Order Fields Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    // image_order[1] is not a real key of $sort_fields -- the renderer's
    // own in_array($order_value, array_keys($sort_fields), true) filter
    // must drop it silently (no stray/leading/double comma), while indices
    // 0 and 2 still get comma-joined in order.
    $result = H::adminPost($page, '/admin.php?page=album-' . $albumId . '-sort_order', [
        'pwg_token' => H::pwgToken($page),
        'submit' => '1',
        'image_order_choice' => 'user_define',
        'image_order' => ['file ASC', 'bogus_field_xyz', 'name DESC'],
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Album updated successfully');

    $db = H::connect();
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $row = H::fetchAssocOrFail($db, sprintf('SELECT image_order FROM %scategories WHERE id = %d', $prefix, $albumId));
    H::dbClose($db);

    expect($row['image_order'])->toBe('file ASC,name DESC');

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $albumId, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => H::pwgToken($page),
    ]);
});

it('persists the literal `rank` ASC order string for the manual "rank" choice, with its own distinct success message', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Ranks Rank Choice Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    $result = H::adminPost($page, '/admin.php?page=album-' . $albumId . '-sort_order', [
        'pwg_token' => H::pwgToken($page),
        'submit' => '1',
        'image_order_choice' => 'rank',
    ]);

    expect($result['status'])->toBe(200);
    // Distinct from the 'user_define'/'default' branches' generic "Album
    // updated successfully" -- and the en_UK translation drops "was" (see
    // this suite's own AlbumNotificationPageRendererTest.php for the same
    // class of source-string-vs-translation mismatch).
    expect($result['body'])->toContain('Images manual order saved');

    $db = H::connect();
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $row = H::fetchAssocOrFail($db, sprintf('SELECT image_order FROM %scategories WHERE id = %d', $prefix, $albumId));
    H::dbClose($db);

    expect($row['image_order'])->toBe('`rank` ASC');

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $albumId, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => H::pwgToken($page),
    ]);
});

it('falls back to the filename-derived name when a photo has no explicit name', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Ranks NoName Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    $image = H::makeTestImage('Ranks NoName Photo');
    // An empty name -- pwg.images.addSimple leaves images.name NULL/empty
    // rather than inventing one (same precedent already established by
    // CommentsControllerTest.php's own "falls back to the filename-derived
    // name" test), exercising this renderer's own
    // StringHelper::getFilenameWoExtension() + str_replace('_', ' ', ...)
    // fallback (~line 196-197) instead of the happy-path images.name
    // column read a few lines above it.
    $imageId = H::uploadPhotoViaApi($image, $albumId, '');
    @unlink($image);

    $db = H::connect();
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $row = H::dbFetchAssoc($db, sprintf('SELECT file, name FROM %simages WHERE id = %d', $prefix, $imageId));
    H::dbClose($db);
    if (! is_array($row)) {
        throw new RuntimeException("expected a real images row for id {$imageId}");
    }
    $realName = $row['name'] ?? null;
    if (is_string($realName) && $realName !== '') {
        throw new RuntimeException('this test requires an empty images.name to exercise the fallback, got: ' . var_export($realName, true));
    }
    // StringHelper::getNameFromFile() is str_replace('_', ' ',
    // getFilenameWoExtension($f)) verbatim -- the exact same two calls this
    // renderer makes inline -- so this mirrors production, not re-derives
    // its own separate expectation logic.
    $expectedName = StringHelper::getNameFromFile((string) $row['file']);

    $page = H::navigateOk($page, '/admin.php?page=album-' . $albumId . '-sort_order');
    $page->assertNoJavaScriptErrors();
    $page->assertPresent('img[alt="' . $expectedName . '"]');

    H::wsCall($page, 'pwg.categories.delete', [
        'category_id' => $albumId, 'photo_deletion_mode' => 'no_delete', 'pwg_token' => H::pwgToken($page),
    ]);
});
