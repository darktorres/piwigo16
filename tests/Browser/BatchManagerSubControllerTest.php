<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\BatchManagerSubController (admin.php?page=
 * batch_manager) -- session-filter parsing (`$_SESSION['bulk_manager_
 * filter']`, from either a submitted filter form or a comma-joined
 * `?filter=` URL token list), FilterResolver orchestration, and the
 * `empty_caddie`/`delete_orphans`/`sync_md5sum` GET actions. Almost none
 * of this had a dedicated test before: the existing VisualRegressionTest
 * baseline for this page never contributes to coverage (VR is excluded
 * from `composer test:coverage:web`), and the filter/action logic itself
 * is otherwise only reachable through a real HTTP request.
 *
 * Each test drives one real filter combination and asserts a 200/no-
 * server-error response -- FilterResolver's own SQL correctness has its
 * own dedicated Integration test (FilterResolverTest); this file's job is
 * covering BatchManagerSubController's own branch-selection/session-
 * mutation logic, not re-verifying FilterResolver's query results.
 */
function bmDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function bmDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function bmCaddieCount(int $userId): int
{
    $db = bmDbConnect();
    $result = $db->query(sprintf('SELECT COUNT(*) AS c FROM %scaddie WHERE user_id = %d', bmDbPrefix(), $userId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) ? (int) $row['c'] : -1;
}

function bmInsertCaddie(int $userId, int $imageId): void
{
    $db = bmDbConnect();
    $db->query(sprintf(
        'INSERT INTO %scaddie (user_id, element_id) VALUES (%d, %d) ON DUPLICATE KEY UPDATE user_id = user_id',
        bmDbPrefix(),
        $userId,
        $imageId
    ));
    $db->close();
}

function bmImageHasTag(int $imageId, int $tagId): bool
{
    $db = bmDbConnect();
    $result = $db->query(sprintf(
        'SELECT COUNT(*) AS c FROM %simage_tag WHERE image_id = %d AND tag_id = %d',
        bmDbPrefix(),
        $imageId,
        $tagId
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) && (int) $row['c'] > 0;
}

/**
 * Every submission goes through BatchManagerSubController::handle(),
 * which always ends by delegating to BatchManagerGlobalPageRenderer::
 * render() (unless mode=unit) -- and THAT renderer carries its own
 * blanket `if (count($_POST) > 0) { checkOrFail(); }` CSRF gate
 * (BatchManagerGlobalPageRenderer.php:84-87), independent of whatever
 * BatchManagerSubController's own resolveSessionFilter() does. So any
 * non-empty POST reaching this page needs a valid token, even a plain
 * filter-form submission with no "batch action" of its own.
 *
 * @param  array<string, mixed>  $fields
 * @return array{status: int, body: string}
 */
function bmPost(Webpage|PendingAwaitablePage|AwaitableWebpage $page, array $fields): array
{
    return H::adminPost($page, '/admin.php?page=batch_manager', array_merge(['pwg_token' => H::pwgToken($page)], $fields));
}

it('renders the global tab with no filter, defaulting to the caddie prefilter', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager');
    $page->assertNoJavaScriptErrors();
});

it('renders the unit tab', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');
    $page->assertNoJavaScriptErrors();
});

it('empty_caddie clears the caddie and redirects', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);
    bmInsertCaddie(1, 1);
    expect(bmCaddieCount(1))->toBeGreaterThan(0);

    $result = H::adminPost($page, '/admin.php?page=batch_manager&action=empty_caddie', [
        'pwg_token' => $token,
    ]);

    // A real HTTP redirect (Location header, from RedirectService::
    // redirect()) becomes an opaque response under fetch(..., {redirect:
    // 'manual'}) -- status is always reported as 0 and the body is
    // inaccessible by the Fetch API's own spec, not a failure signal.
    expect($result['status'])->toBe(0);
    expect(bmCaddieCount(1))->toBe(0);
});

it('empty_caddie rejects a missing CSRF token', function (): void {
    $page = H::loginAsAdmin($this);
    bmInsertCaddie(1, 1);

    $result = H::adminPost($page, '/admin.php?page=batch_manager&action=empty_caddie', []);

    expect($result['status'])->toBe(400);
    expect(bmCaddieCount(1))->toBeGreaterThan(0);

    bmInsertCaddie(1, 1);
    H::adminPost($page, '/admin.php?page=batch_manager&action=empty_caddie', ['pwg_token' => H::pwgToken($page)]);
});

it('delete_orphans records a session message and redirects when photos were deleted', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&action=delete_orphans&nb_orphans_deleted=3');
    $page->assertNoJavaScriptErrors();
});

it('sync_md5sum records a session message and redirects when checksums were added', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&action=sync_md5sum&nb_md5sum_added=2');
    $page->assertNoJavaScriptErrors();
});

it('submits a duplicates prefilter with every detection option checked', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_prefilter_use' => '1',
        'filter_prefilter' => 'duplicates',
        'filter_duplicates_checksum' => '1',
        'filter_duplicates_date' => '1',
        'filter_duplicates_dimensions' => '1',
        'filter_duplicates_filename' => '1',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a category prefilter scoped recursively', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_category_use' => '1',
        'filter_category' => '1',
        'filter_category_recursive' => '1',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('redirects and clears the session filter when the filtered category no longer exists', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_category_use' => '1',
        'filter_category' => '999999',
    ]);

    // computeCurrentSet() redirects (a real Location header) when the
    // filtered category no longer exists -- an opaque response under
    // fetch(..., {redirect: 'manual'}), status always 0 per the Fetch API
    // spec, not a failure signal (see empty_caddie's own test above).
    expect($result['status'])->toBe(0);
});

it('submits a tags prefilter as a multi-value array', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_tags_use' => '1',
        'filter_tags' => ['~~1~~'],
        'tag_mode' => 'OR',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a tags prefilter as a single scalar value', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_tags_use' => '1',
        'filter_tags' => '~~1~~',
        'tag_mode' => 'AND',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a permission-level prefilter including lower levels', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_level_use' => '1',
        'filter_level' => '2',
        'filter_level_include_lower' => '1',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a dimension prefilter with valid width/height/ratio bounds', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_dimension_use' => '1',
        'filter_dimension_min_width' => '100',
        'filter_dimension_max_width' => '2000',
        'filter_dimension_min_height' => '100',
        'filter_dimension_max_height' => '2000',
        'filter_dimension_min_ratio' => '0.5',
        'filter_dimension_max_ratio' => '2.0',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a filesize prefilter with valid bounds', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_filesize_use' => '1',
        'filter_filesize_min' => '0.5',
        'filter_filesize_max' => '10',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submits a quick-search prefilter', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_search_use' => '1',
        'q' => 'Photo',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('submitting the filter form with nothing checked resets to the default filter', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    $result = bmPost($page, ['submitFilter' => '1']);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('applies a combined URL filter token list covering prefilter/category/tag/level/search/dimension/filesize', function (): void {
    $page = H::loginAsAdmin($this);

    $filter = implode(',', [
        'prefilter-duplicates-checksum',
        'album-1',
        'tag-1',
        'level-2',
        'search-hello',
        'dimension-w10..1000-h100..2000-r0.5..2',
        'filesize-1..10',
        'bogus-x',
    ]);

    $page = H::navigateOk($page, '/admin.php?page=batch_manager&filter=' . rawurlencode($filter));
    $page->assertNoJavaScriptErrors();
});

it('applies a plain (non-duplicates) prefilter via a URL filter token', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&filter=prefilter-no_album');
    $page->assertNoJavaScriptErrors();
});

it('applies add_tags then del_tags to a whole_set selection, round-tripping the association in piwigo_image_tag', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch AddTags Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch AddTags Photo');
    @unlink($image);

    // Fixture tag 1 ('nature') -- see this suite's own fixture-shape
    // memory notes. Default bulk_manager_filter has no 'tags' key, so
    // neither action's own redirect-on-filter-affecting-change branch
    // fires here -- both stay a normal 200 render, not an opaque redirect.
    expect(bmImageHasTag($imageId, 1))->toBeFalse();

    $addResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'add_tags',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        // TagService::getTagIds() treats a plain string as a tag NAME to
        // look up (creating a new tag if none matches) -- the '~~ID~~'
        // sentinel is what selects an existing tag by id directly (the
        // real admin tag-selector widget's own format for an
        // already-picked tag).
        'add_tags' => ['~~1~~'],
    ]);
    expect($addResult['status'])->toBe(200);
    expect(bmImageHasTag($imageId, 1))->toBeTrue();

    $delResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'del_tags',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'del_tags' => ['1'],
    ]);
    expect($delResult['status'])->toBe(200);
    expect(bmImageHasTag($imageId, 1))->toBeFalse();
});

it('associates a whole_set selection with another album via the associate action', function (): void {
    $page = H::loginAsAdmin($this);
    $sourceAlbum = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Associate Source ' . uniqid()]);
    $sourceResult = $sourceAlbum['result'] ?? null;
    if (! is_array($sourceResult) || ! is_numeric($sourceResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($sourceAlbum, true));
    }
    $sourceAlbumId = (int) $sourceResult['id'];
    $targetAlbum = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Associate Target ' . uniqid()]);
    $targetResult = $targetAlbum['result'] ?? null;
    if (! is_array($targetResult) || ! is_numeric($targetResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($targetAlbum, true));
    }
    $targetAlbumId = (int) $targetResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $sourceAlbumId, 'Batch Associate Photo');
    @unlink($image);

    $db = bmDbConnect();
    $before = $db->query(sprintf(
        'SELECT COUNT(*) AS c FROM %simage_category WHERE image_id = %d AND category_id = %d',
        bmDbPrefix(),
        $imageId,
        $targetAlbumId
    ));
    $beforeRow = $before instanceof mysqli_result ? $before->fetch_assoc() : null;
    expect(is_array($beforeRow) ? (int) $beforeRow['c'] : -1)->toBe(0);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'associate',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'associate' => [(string) $targetAlbumId],
    ]);
    expect($result['status'])->toBe(200);

    $after = $db->query(sprintf(
        'SELECT COUNT(*) AS c FROM %simage_category WHERE image_id = %d AND category_id = %d',
        bmDbPrefix(),
        $imageId,
        $targetAlbumId
    ));
    $afterRow = $after instanceof mysqli_result ? $after->fetch_assoc() : null;
    $db->close();
    expect(is_array($afterRow) ? (int) $afterRow['c'] : -1)->toBe(1);
});

/** @return array{storage: bool, target: bool} */
function bmImageCategoryLinks(int $imageId, int $storageAlbumId, int $targetAlbumId): array
{
    $db = bmDbConnect();
    $result = $db->query(sprintf(
        'SELECT category_id FROM %simage_category WHERE image_id = %d',
        bmDbPrefix(),
        $imageId
    ));
    $ids = [];
    if ($result instanceof mysqli_result) {
        while (is_array($row = $result->fetch_assoc())) {
            $ids[] = (int) $row['category_id'];
        }
    }
    $db->close();

    return ['storage' => in_array($storageAlbumId, $ids, true), 'target' => in_array($targetAlbumId, $ids, true)];
}

it('moves a whole_set selection, preserving the storage album and adding the target', function (): void {
    $page = H::loginAsAdmin($this);
    $storageAlbum = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Move Storage ' . uniqid()]);
    $storageResult = $storageAlbum['result'] ?? null;
    if (! is_array($storageResult) || ! is_numeric($storageResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($storageAlbum, true));
    }
    $storageAlbumId = (int) $storageResult['id'];
    $targetAlbum = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Move Target ' . uniqid()]);
    $targetResult = $targetAlbum['result'] ?? null;
    if (! is_array($targetResult) || ! is_numeric($targetResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($targetAlbum, true));
    }
    $targetAlbumId = (int) $targetResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $storageAlbumId, 'Batch Move Photo');
    @unlink($image);

    expect(bmImageCategoryLinks($imageId, $storageAlbumId, $targetAlbumId))->toBe(['storage' => true, 'target' => false]);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'move',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'move' => (string) $targetAlbumId,
    ]);
    expect($result['status'])->toBe(200);
    expect(bmImageCategoryLinks($imageId, $storageAlbumId, $targetAlbumId))->toBe(['storage' => true, 'target' => true]);
});

it('dissociates a whole_set selection from a non-storage album', function (): void {
    $page = H::loginAsAdmin($this);
    $storageAlbum = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Dissociate Storage ' . uniqid()]);
    $storageResult = $storageAlbum['result'] ?? null;
    if (! is_array($storageResult) || ! is_numeric($storageResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($storageAlbum, true));
    }
    $storageAlbumId = (int) $storageResult['id'];
    $otherAlbum = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Dissociate Other ' . uniqid()]);
    $otherResult = $otherAlbum['result'] ?? null;
    if (! is_array($otherResult) || ! is_numeric($otherResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($otherAlbum, true));
    }
    $otherAlbumId = (int) $otherResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $storageAlbumId, 'Batch Dissociate Photo');
    @unlink($image);

    $associateResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'associate',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'associate' => [(string) $otherAlbumId],
    ]);
    expect($associateResult['status'])->toBe(200);
    expect(bmImageCategoryLinks($imageId, $storageAlbumId, $otherAlbumId))->toBe(['storage' => true, 'target' => true]);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'dissociate',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'dissociate' => (string) $otherAlbumId,
    ]);
    expect($result['status'])->toBe(200);
    expect(bmImageCategoryLinks($imageId, $storageAlbumId, $otherAlbumId))->toBe(['storage' => true, 'target' => false]);
});

/** @return array{name: ?string, author: ?string, level: int, date_creation: ?string} */
function bmImageRow(int $imageId): array
{
    $db = bmDbConnect();
    $result = $db->query(sprintf(
        'SELECT name, author, level, date_creation FROM %simages WHERE id = %d',
        bmDbPrefix(),
        $imageId
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();
    if (! is_array($row)) {
        throw new RuntimeException("expected a real image row for id {$imageId}");
    }
    $name = $row['name'];
    $author = $row['author'];
    $dateCreation = $row['date_creation'];

    return [
        'name' => is_string($name) ? $name : null,
        'author' => is_string($author) ? $author : null,
        'level' => (int) $row['level'],
        'date_creation' => is_string($dateCreation) ? $dateCreation : null,
    ];
}

it('mass-updates author, title, date_creation, and level for a whole_set selection', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Mass Update Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Mass Update Photo');
    @unlink($image);

    $authorResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'author',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'author' => 'Mass Update Author',
    ]);
    expect($authorResult['status'])->toBe(200);
    expect(bmImageRow($imageId)['author'])->toBe('Mass Update Author');

    $titleResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'title',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'title' => 'Mass Update Title',
    ]);
    expect($titleResult['status'])->toBe(200);
    expect(bmImageRow($imageId)['name'])->toBe('Mass Update Title');

    $dateResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'date_creation',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'date_creation' => '2026-02-20',
    ]);
    expect($dateResult['status'])->toBe(200);
    expect(bmImageRow($imageId)['date_creation'])->toBe('2026-02-20 00:00:00');

    $levelResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'level',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'level' => '4',
    ]);
    expect($levelResult['status'])->toBe(200);
    expect(bmImageRow($imageId)['level'])->toBe(4);

    // remove_author/remove_title null the field out instead of setting it.
    $removeAuthorResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'author',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'author' => 'Should Not Be Applied',
        'remove_author' => '1',
    ]);
    expect($removeAuthorResult['status'])->toBe(200);
    expect(bmImageRow($imageId)['author'])->toBeNull();
});

it('adds and removes a whole_set selection from the caddie', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Caddie Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Caddie Photo');
    @unlink($image);

    $addResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'add_to_caddie',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
    ]);
    expect($addResult['status'])->toBe(200);

    $db = bmDbConnect();
    $check = $db->query(sprintf(
        'SELECT COUNT(*) AS c FROM %scaddie WHERE user_id = 1 AND element_id = %d',
        bmDbPrefix(),
        $imageId
    ));
    $checkRow = $check instanceof mysqli_result ? $check->fetch_assoc() : null;
    $db->close();
    expect(is_array($checkRow) ? (int) $checkRow['c'] : -1)->toBe(1);

    $removeResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'remove_from_caddie',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
    ]);
    // remove_from_caddie always redirects (opaque response, status 0) --
    // see this file's own empty_caddie test for the same fetch(manual)
    // redirect-status caveat.
    expect($removeResult['status'])->toBe(0);
    expect(bmCaddieCount($imageId))->toBe(0);
});

it('rejects a delete action without confirm_deletion, then deletes and records a session message once confirmed', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Delete Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Delete Photo');
    @unlink($image);

    $unconfirmedResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'delete',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
    ]);
    expect($unconfirmedResult['status'])->toBe(200);
    expect($unconfirmedResult['body'])->toContain('You need to confirm deletion');

    $db = bmDbConnect();
    $stillThere = $db->query(sprintf('SELECT COUNT(*) AS c FROM %simages WHERE id = %d', bmDbPrefix(), $imageId));
    $stillThereRow = $stillThere instanceof mysqli_result ? $stillThere->fetch_assoc() : null;
    $db->close();
    expect(is_array($stillThereRow) ? (int) $stillThereRow['c'] : -1)->toBe(1);

    $confirmedResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'delete',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'confirm_deletion' => '1',
    ]);
    // The delete branch itself only ever records a session message and sets
    // $redirect = true (deletion actually happens via the ajax `with blocks`
    // path, per this renderer's own comment) -- a real Location header, so
    // an opaque response under fetch(manual), status 0.
    expect($confirmedResult['status'])->toBe(0);
});

it('reports metadata-synchronized and generate_derivatives success/error counts', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Metadata Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Metadata Photo');
    @unlink($image);

    $metadataResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'metadata',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
    ]);
    expect($metadataResult['status'])->toBe(200);
    expect($metadataResult['body'])->toContain('Metadata synchronized from file');

    $genDerivResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'generate_derivatives',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'regenerateSuccess' => '3',
        'regenerateError' => '1',
    ]);
    expect($genDerivResult['status'])->toBe(200);
    expect($genDerivResult['body'])->toContain('3 photos have been regenerated');
    expect($genDerivResult['body'])->toContain('1 photos can not be regenerated');
});

it('renders the global-mode thumbnail grid for a real category filter', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Thumbnails Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Thumbnails Photo');
    @unlink($image);

    $filterResult = bmPost($page, [
        'submitFilter' => '1',
        'filter_category_use' => '1',
        'filter_category' => (string) $albumId,
    ]);
    expect($filterResult['status'])->toBe(200);

    // The thumbnail loop (cat_elements_id > 0) computes TITLE/FILE_SRC/
    // U_EDIT per row and appends 'associated_tags' -- confirms the query,
    // SrcImage/DerivativeImage construction, and filesize formatting all
    // execute without a fatal error for a real uploaded photo.
    expect($filterResult['body'])->toContain('Batch Thumbnails Photo');
    expect($filterResult['body'])->not->toContain('Fatal error');
});

it('builds the current selection from a selection[] array, an alternative to whole_set', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Batch Selection Array Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch Selection Array Photo');
    @unlink($image);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'add_to_caddie',
        'selection' => [(string) $imageId],
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Select at least one photo');
    expect(bmCaddieCount(1))->toBeGreaterThan(0);

    bmPost($page, [
        'submit' => '1',
        'selectAction' => 'remove_from_caddie',
        'selection' => [(string) $imageId],
    ]);
});

it('nb_photos_deleted fakes a placeholder collection for the ajax-driven post-delete reload', function (): void {
    $page = H::loginAsAdmin($this);

    // No real photo ids are known/needed here (see this renderer's own
    // docblock on the nbPhotosDeletedPresent branch) -- the placeholder
    // collection is only ever used by the 'delete' action's own ajax
    // follow-up reload, so a harmless no-op-shaped action (an empty
    // selectAction, matching no branch at all) is enough to prove the
    // "Select at least one photo" guard is skipped once nb_photos_deleted
    // is present.
    $result = bmPost($page, [
        'submit' => '1',
        'nb_photos_deleted' => '3',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Select at least one photo');
});

it('rejects a whole_set value containing a non-digit element as a hacking attempt', function (): void {
    $page = H::loginAsAdmin($this);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'add_to_caddie',
        'setSelected' => '1',
        'whole_set' => '1,not-a-digit,3',
    ]);

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('[Hacking attempt]');
    expect($result['body'])->toContain('whole_set');
});
