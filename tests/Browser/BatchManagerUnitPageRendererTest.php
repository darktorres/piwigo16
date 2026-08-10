<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\BatchManagerUnitPageRenderer (admin.php?page=batch_manager&
 * mode=unit) -- the per-photo inline edit grid. BatchManagerSubController
 * Test's own "renders the unit tab" test visits this with the default
 * (empty caddie) filter, so the real per-image thumbnail-grid loop (~400
 * lines) never runs there -- this file filters to a real category with a
 * real photo instead, and exercises the unit-mode form submission
 * (name/author/level/description/date/tags, per image).
 *
 * Not exercised, genuinely unreachable via real inputs (coverage-gap
 * closure pass confirmed each via the real merged HTML coverage report,
 * not just static reading):
 *
 *  - 3 "row id"/"category_id" is-not-a-real-scalar defensive guards (the
 *    `continue;` bodies around $row['id'] and $item['category_id']/
 *    ['uppercats']) -- 'images'.id and 'image_category'.
 *    category_id/'categories'.uppercats are all NOT NULL columns
 *    (auto_increment PK for id), so a real query can never produce the
 *    shape these guard against.
 *  - The `if ($row_cat_id !== null and in_array(...))` true-branch (the
 *    5-line makePictureUrl() call building U_JUMPTO from $row['cat_id'])
 *    -- findBatchManagerUnitRows()'s own `SELECT * FROM images [JOIN
 *    image_category ON id = image_id]` can never populate a 'cat_id' key:
 *    neither piwigo_images nor piwigo_image_category has a column by that
 *    name (only `id`/`image_id` and `category_id`). $row_cat_id is
 *    therefore always null for real data, so `in_array($row_cat_id, ...)`
 *    on the right side of that `and` never even evaluates (PHP
 *    short-circuits), and every real request falls straight into the
 *    always-live `else` foreach beneath it instead -- this predates the
 *    17.x rewrite entirely (the original admin/batch_manager_unit.php had
 *    the exact same `isset($row['cat_id'])` shape against the exact same
 *    query), so per this class's own "mechanical port doesn't fold in
 *    unrelated fixes" precedent it stays as-is, not "fixed" here.
 */
function batchManagerUnitDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

/** @return array{name: ?string, author: ?string, level: int, comment: ?string, date_creation: ?string} */
function batchManagerUnitImageRow(int $imageId): array
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf(
        'SELECT name, author, level, comment, date_creation FROM %simages WHERE id = %d',
        batchManagerUnitDbPrefix(),
        $imageId
    ));
    H::dbClose($db);
    if (! is_array($row)) {
        throw new RuntimeException("expected a real image row for id {$imageId}");
    }
    $name = $row['name'];
    $author = $row['author'];
    $comment = $row['comment'];
    $dateCreation = $row['date_creation'];

    return [
        'name' => is_string($name) ? $name : null,
        'author' => is_string($author) ? $author : null,
        'level' => (int) $row['level'],
        'comment' => is_string($comment) ? $comment : null,
        'date_creation' => is_string($dateCreation) ? $dateCreation : null,
    ];
}

it('renders the per-image thumbnail grid for a real category filter', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Unit Grid Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Unit Grid Photo');
    @unlink($image);

    $filterResult = H::adminPost($page, '/admin.php?page=batch_manager', [
        'pwg_token' => H::pwgToken($page),
        'submitFilter' => '1',
        'filter_category_use' => '1',
        'filter_category' => (string) $albumId,
    ]);
    expect($filterResult['status'])->toBe(200);

    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');

    // STORAGE_CATEGORY/TITLE/DIMENSIONS/FILESIZE/DATE fields all render off
    // this photo's own real row -- confirms the ~400-line per-image loop
    // (categories, tags, jump-to link, level options, filesize/date
    // formatting) executes without a fatal error for a real photo. The
    // title is an <input> value, not a visible text node -- confirmed
    // live via a screenshot -- so a raw-content check, not assertSee(),
    // is the correct way to confirm it rendered.
    expect(H::rawWebpage($page)->content())->toContain('value="Batch Unit Grid Photo"');
    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'batch_manager unit-mode thumbnail grid');
});

it('submits the unit-mode edit form for a whole_set selection and mass-updates every field', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Unit Submit Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Unit Submit Photo');
    @unlink($image);

    $result = H::adminPost($page, '/admin.php?page=batch_manager&mode=unit', [
        'pwg_token' => H::pwgToken($page),
        'submit' => '1',
        'element_ids' => (string) $imageId,
        'name-' . $imageId => 'Unit Edited Title',
        'author-' . $imageId => 'Unit Edited Author',
        'level-' . $imageId => '3',
        'description-' . $imageId => 'Unit edited description',
        'date_creation-' . $imageId => '2026-03-10',
        'tags-' . $imageId => ['~~1~~'],
    ]);

    expect($result['status'])->toBe(200);
    // The loaded en_UK catalog rephrases this from its literal PHP source
    // msgid ("Photo informations updated") -- confirmed live.
    expect($result['body'])->toContain('Photo information updated');

    $row = batchManagerUnitImageRow($imageId);
    expect($row['name'])->toBe('Unit Edited Title');
    expect($row['author'])->toBe('Unit Edited Author');
    expect($row['level'])->toBe(3);
    expect($row['comment'])->toBe('Unit edited description');
    expect($row['date_creation'])->toBe('2026-03-10 00:00:00');
});

it('accepts a single non-array tag string for the per-image tags field', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Unit Scalar Tag Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Unit Scalar Tag Photo');
    @unlink($image);

    // '~~2~~' selects existing fixture tag 2 by id (see this suite's own
    // fixture-shape memory notes) -- sent as a bare string, not wrapped in
    // an array, unlike every other test in this file.
    $result = H::adminPost($page, '/admin.php?page=batch_manager&mode=unit', [
        'pwg_token' => H::pwgToken($page),
        'submit' => '1',
        'element_ids' => (string) $imageId,
        'level-' . $imageId => '0',
        'tags-' . $imageId => '~~2~~',
    ]);

    expect($result['status'])->toBe(200);

    $db = H::connect();
    $tagAssoc = H::dbFetchAssoc($db, sprintf(
        'SELECT COUNT(*) AS c FROM %simage_tag WHERE image_id = %d AND tag_id = 2',
        batchManagerUnitDbPrefix(),
        $imageId
    ));
    H::dbClose($db);
    expect(is_array($tagAssoc) ? (int) $tagAssoc['c'] : -1)->toBe(1);
});

it('accepts nb_photos_deleted/whole_set/selection[] as alternative ways to seed the display-only collection', function (): void {
    $page = H::loginAsAdmin($this);

    // None of these submit `submit=1`, so the CSRF-gated mass-update
    // branch never runs -- only the separate, always-executed "collection"
    // block (used for FilterPanelRenderer's own display) is exercised.
    $deletedResult = H::adminPost($page, '/admin.php?page=batch_manager&mode=unit', [
        'nb_photos_deleted' => '3',
    ]);
    expect($deletedResult['status'])->toBe(200);

    $wholeSetResult = H::adminPost($page, '/admin.php?page=batch_manager&mode=unit', [
        'setSelected' => '1',
        'whole_set' => '1,2,3',
    ]);
    expect($wholeSetResult['status'])->toBe(200);

    $selectionResult = H::adminPost($page, '/admin.php?page=batch_manager&mode=unit', [
        'selection' => ['1', '2'],
    ]);
    expect($selectionResult['status'])->toBe(200);
});

it('highlights STORAGE_CATEGORY and honors a category-specific image_order for a physically-owning album filter', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Unit Storage Cat Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Unit Storage Cat Photo');
    @unlink($image);

    $db = H::connect();
    $prefix = batchManagerUnitDbPrefix();
    // pwg.images.addSimple() never populates storage_category_id (confirmed
    // live -- it stays NULL for every virtual-album upload), so the
    // STORAGE_CATEGORY-highlight branch (only reachable when a photo's own
    // storage_category_id matches the active category filter) and the
    // per-category image_order override both need direct SQL, simulating
    // what a real physically-synced album would already have set.
    H::dbQuery($db, sprintf('UPDATE %simages SET storage_category_id = %d WHERE id = %d', $prefix, $albumId, $imageId));
    H::dbQuery($db, sprintf("UPDATE %scategories SET image_order = 'name ASC' WHERE id = %d", $prefix, $albumId));

    try {
        $filterResult = H::adminPost($page, '/admin.php?page=batch_manager', [
            'pwg_token' => H::pwgToken($page),
            'submitFilter' => '1',
            'filter_category_use' => '1',
            'filter_category' => (string) $albumId,
        ]);
        expect($filterResult['status'])->toBe(200);

        $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit&display=10');
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'batch_manager unit-mode storage-category/image-order branch');
    } finally {
        H::dbQuery($db, sprintf('UPDATE %scategories SET image_order = NULL WHERE id = %d', $prefix, $albumId));
        H::dbClose($db);
    }
});

it('strips HTML tags from the description when HTML descriptions are disabled', function (): void {
    $snapshot = H::snapshotConfig(['allow_html_descriptions']);
    H::setConfigValue('allow_html_descriptions', 'false');

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Unit HTML Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Unit HTML Photo');
        @unlink($image);

        // level-<id> is read unconditionally into $data['level'] (a real
        // NOT NULL column) with no isset() guard, unlike name/author --
        // omitting it crashes with a genuine "Column 'level' cannot be
        // null" DB error (confirmed live), so it must always be submitted
        // even when this test only cares about the description field.
        $result = H::adminPost($page, '/admin.php?page=batch_manager&mode=unit', [
            'pwg_token' => H::pwgToken($page),
            'submit' => '1',
            'element_ids' => (string) $imageId,
            'level-' . $imageId => '0',
            'description-' . $imageId => '<b>bold</b> text',
        ]);

        expect($result['status'])->toBe(200);
        expect(batchManagerUnitImageRow($imageId)['comment'])->toBe('bold text');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('fatal-errors on an invalid whole_set element (the "Hacking attempt" guard)', function (): void {
    $page = H::loginAsAdmin($this);

    // Every whole_set element must match /^\d+$/ -- one non-digit element
    // ('not-a-digit') is enough to fail the per-element preg_match() loop
    // and hit HtmlRenderingInterface::fatalError(), a real 500 HTML error
    // page (Piwigo\Html\HtmlService::fatalError()), not an exception
    // PHPUnit would otherwise swallow.
    $result = H::adminPost($page, '/admin.php?page=batch_manager&mode=unit', [
        'setSelected' => '1',
        'whole_set' => '1,2,not-a-digit',
    ]);

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('[Hacking attempt] the input parameter "whole_set" is not valid');
});

it('falls back to 5 images per page when the configured value is not 5/10/50 and no display param is given', function (): void {
    $snapshot = H::snapshotConfig(['batch_manager_images_per_page_unit']);
    // 7 is deliberately outside the [5, 10, 50] allow-list the renderer
    // checks with in_array(..., true) -- forces the final `else` fallback
    // to the literal 5, distinct from both the display=<n> GET-param
    // branch and the "config value is one of 5/10/50" branch.
    H::setConfigValue('batch_manager_images_per_page_unit', '7');

    try {
        $page = H::loginAsAdmin($this);

        // batch_manager_unit.tpl only renders the per-page pagination
        // links at all inside a `{if !empty($elements)}` guard -- with
        // the default, unfiltered (empty caddie) filter this file's own
        // top docblock documents, $elements is empty and the whole block
        // (including the "5" link) never appears, regardless of what
        // $per_page was computed as. A real category+photo filter (same
        // setup as "renders the per-image thumbnail grid...” above) is
        // needed so $elements is non-empty and the pagination widget
        // actually renders.
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Unit Per Page Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, $albumId, 'Batch Unit Per Page Photo');
        @unlink($image);

        $filterResult = H::adminPost($page, '/admin.php?page=batch_manager', [
            'pwg_token' => H::pwgToken($page),
            'submitFilter' => '1',
            'filter_category_use' => '1',
            'filter_category' => (string) $albumId,
        ]);
        expect($filterResult['status'])->toBe(200);

        $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'batch_manager unit-mode per-page fallback-to-5');

        // per_page drives batch_manager_unit.tpl's own pagination-size
        // links -- the "5" link only gets the selected-pagination class
        // when $per_page === 5, a real behavioral signal that the
        // fallback (not the unconfigured config value 7, and not a
        // display= override) is what the renderer actually used.
        // H::settledContent(), not H::rawWebpage($page)->content() -- see
        // that method's own docblock for the known Playwright
        // stale-pre-navigation race.
        expect(H::settledContent($page))->toContain('selected-pagination">5</a>');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('applies the duplicates-prefilter ORDER BY override ("file, id") when the session prefilter is "duplicates"', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Unit Dup Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    // Two photos with distinct pixel content (distinct md5sum, so
    // UploadService's own md5-based duplicate detection -- on by default
    // -- doesn't collapse them into a single image) but the exact same
    // uploaded basename, so they land in the same `file`-column duplicate
    // group findIdsGroupedByDuplicateFields(['file']) groups by.
    $dupBasename = 'dup_' . uniqid() . '.jpg';
    $dirA = sys_get_temp_dir() . '/pwg_dupA_' . uniqid();
    $dirB = sys_get_temp_dir() . '/pwg_dupB_' . uniqid();
    mkdir($dirA);
    mkdir($dirB);
    $pathA = $dirA . '/' . $dupBasename;
    $pathB = $dirB . '/' . $dupBasename;
    rename(H::makeTestImage('Batch Unit Dup A'), $pathA);
    rename(H::makeTestImage('Batch Unit Dup B'), $pathB);

    $imageIdA = H::uploadPhotoViaApi($pathA, $albumId, 'Batch Unit Dup A');
    $imageIdB = H::uploadPhotoViaApi($pathB, $albumId, 'Batch Unit Dup B');
    @unlink($pathA);
    @unlink($pathB);
    @rmdir($dirA);
    @rmdir($dirB);

    // filter_prefilter_use + filter_prefilter=duplicates with none of the
    // filter_duplicates_* option checkboxes set defaults to grouping by
    // filename alone (BatchManagerSubController::resolveSessionFilter()'s
    // own "!$has_options" branch) -- exactly the 'file' grouping our two
    // same-basename photos above satisfy.
    $filterResult = H::adminPost($page, '/admin.php?page=batch_manager', [
        'pwg_token' => H::pwgToken($page),
        'submitFilter' => '1',
        'filter_prefilter_use' => '1',
        'filter_prefilter' => 'duplicates',
    ]);
    expect($filterResult['status'])->toBe(200);

    // display=1000 so our pair (which may not sort first among any other
    // real duplicate-filename groups already in the fixture) is never
    // pushed past the first page by the default 5-per-page limit.
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit&display=1000');
    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'batch_manager unit-mode duplicates-prefilter ORDER BY');

    $html = H::rawWebpage($page)->content();
    expect($html)->toContain('value="Batch Unit Dup A"');
    expect($html)->toContain('value="Batch Unit Dup B"');

    // Real behavioral check of the ORDER BY itself, not just that the page
    // didn't 500: both rows tie on `file` (identical basename), so "file,
    // id" breaks the tie by id ascending -- A (uploaded, thus assigned a
    // lower id, first) must render before B.
    $posA = strpos($html, 'value="Batch Unit Dup A"');
    $posB = strpos($html, 'value="Batch Unit Dup B"');
    expect($imageIdA)->toBeLessThan($imageIdB);
    expect($posA)->not->toBeFalse();
    expect($posB)->not->toBeFalse();
    assert(is_int($posA) && is_int($posB));
    expect($posA)->toBeLessThan($posB);
});

it('sets the "see-out" jump-to link when the current admin is authorized for the photo\'s only category', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Unit Jumpto Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Unit Jumpto Photo');
    @unlink($image);

    $filterResult = H::adminPost($page, '/admin.php?page=batch_manager', [
        'pwg_token' => H::pwgToken($page),
        'submitFilter' => '1',
        'filter_category_use' => '1',
        'filter_category' => (string) $albumId,
    ]);
    expect($filterResult['status'])->toBe(200);

    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');
    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'batch_manager unit-mode jump-to link');

    // U_JUMPTO (and therefore the tpl's "see-out" link) is only assigned
    // when $url_img got set inside the per-image loop's authorized-
    // categories foreach -- since $row['cat_id'] is always null for real
    // data (see this file's own top docblock), the *only* code path that
    // can ever set it is the `else` branch's single-iteration foreach
    // over $authorizeds, which this fresh public album + its one photo
    // satisfies (the admin test user is authorized for every public
    // category). A real "see-out" anchor rendering is therefore a real
    // behavioral signal that foreach body -- through its terminal
    // break -- actually ran, not just that the page returned 200.
    // H::settledContent(), not H::rawWebpage($page)->content() -- see that
    // method's own docblock for the known Playwright stale-pre-navigation
    // race.
    //
    // 'class="see-out"' alone isn't specific enough -- the tpl's own
    // disabled fallback (no U_JUMPTO) renders 'class="see-out disabled"',
    // which also contains that substring, so a bare check can't tell the
    // two apart. UrlService::makePictureUrl() also confirmed live to
    // build a *relative* URL ('picture.php?/...', no leading '/' --
    // getRootUrl() is empty in this admin-page context), so the real
    // signal is the exact enabled-variant markup plus the relative
    // picture.php URL, not a leading-slash '/picture' substring.
    $html = H::settledContent($page);
    expect($html)->toContain('class="see-out" href="picture.php');
});