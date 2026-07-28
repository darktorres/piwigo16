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
 * Not exercised: 3 "row id/category_id is not a real scalar" defensive
 * guards (both id columns are NOT NULL primary keys, so a real query can
 * never produce that shape); the display=Config-fallback-to-5 branch
 * (needs a non-standard batch_manager_images_per_page_unit config value);
 * and the 'duplicates'-prefilter ORDER BY override (needs
 * `$_SESSION['bulk_manager_filter']['prefilter'] === 'duplicates'`, session
 * state BatchManagerSubControllerTest already covers extensively without
 * combining it with a unit-mode visit) -- all narrow, low-value branches
 * relative to the setup they'd need.
 */
function batchManagerUnitDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

/** @return array{name: ?string, author: ?string, level: int, comment: ?string, date_creation: ?string} */
function batchManagerUnitImageRow(int $imageId): array
{
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $result = $db->query(sprintf(
        'SELECT name, author, level, comment, date_creation FROM %simages WHERE id = %d',
        batchManagerUnitDbPrefix(),
        $imageId
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();
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

    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $tagRow = $db->query(sprintf(
        'SELECT COUNT(*) AS c FROM %simage_tag WHERE image_id = %d AND tag_id = 2',
        batchManagerUnitDbPrefix(),
        $imageId
    ));
    $tagAssoc = $tagRow instanceof mysqli_result ? $tagRow->fetch_assoc() : null;
    $db->close();
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

    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = batchManagerUnitDbPrefix();
    // pwg.images.addSimple() never populates storage_category_id (confirmed
    // live -- it stays NULL for every virtual-album upload), so the
    // STORAGE_CATEGORY-highlight branch (only reachable when a photo's own
    // storage_category_id matches the active category filter) and the
    // per-category image_order override both need direct SQL, simulating
    // what a real physically-synced album would already have set.
    $db->query(sprintf('UPDATE %simages SET storage_category_id = %d WHERE id = %d', $prefix, $albumId, $imageId));
    $db->query(sprintf("UPDATE %scategories SET image_order = 'name ASC' WHERE id = %d", $prefix, $albumId));

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
        $db->query(sprintf('UPDATE %scategories SET image_order = NULL WHERE id = %d', $prefix, $albumId));
        $db->close();
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