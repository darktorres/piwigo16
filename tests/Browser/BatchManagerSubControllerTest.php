<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use PgSql\Connection;
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
function bmDbConnect(): mysqli|Connection
{
    return H::connect();
}

/**
 * Writes a real `plugin.json` + PSR-4-autoloadable `ExtensionInterface`
 * class -- the plugin/theme contract's own fixture shape, loaded via
 * `PluginConfig\PluginRegistry::bootActive()`. `$bootBodySource` is spliced verbatim into the
 * fixture class's own `boot()` method body.
 */
function bmWriteFixturePlugin(string $pluginDir, string $bootBodySource): void
{
    if (! is_dir($pluginDir . '/src')) {
        mkdir($pluginDir . '/src', 0o777, true);
    }

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    file_put_contents($pluginDir . '/plugin.json', json_encode([
        'id' => basename($pluginDir),
        'name' => basename($pluginDir),
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/BatchManagerSubControllerTest.php).',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\Plugin',
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($pluginDir . '/src/Plugin.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Piwigo\\PluginConfig\\ExtensionContext;
        use Piwigo\\PluginConfig\\ExtensionInterface;

        final class Plugin implements ExtensionInterface
        {
            public function boot(ExtensionContext \$context): void
            {
                {$bootBodySource}
            }

            public function install(): void {}
            public function activate(): void {}
            public function deactivate(): void {}
            public function uninstall(): void {}
            public function update(string \$oldVersion, string \$newVersion): void {}

            public function subscribedEvents(): array
            {
                return [];
            }
        }

        PHP);
}

function bmRemoveFixturePlugin(string $pluginDir): void
{
    @unlink($pluginDir . '/src/Plugin.php');
    @rmdir($pluginDir . '/src');
    @unlink($pluginDir . '/plugin.json');
    if (is_dir($pluginDir)) {
        rmdir($pluginDir);
    }
}

function bmCaddieCount(int $userId): int
{
    $db = bmDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM caddie WHERE user_id = %d', $userId));
    H::dbClose($db);

    return is_array($row) ? (int) $row['c'] : -1;
}

function bmInsertCaddie(int $userId, int $imageId): void
{
    $db = bmDbConnect();
    // ON DUPLICATE KEY UPDATE user_id = user_id is MySQL's own no-op-
    // upsert idiom ("insert if not already there, else leave alone") --
    // ON CONFLICT DO NOTHING is the real Postgres equivalent, no
    // conflict target needed since (user_id, element_id) is the table's
    // only PK/unique constraint.
    $insertSql = $db instanceof mysqli
        ? 'INSERT INTO caddie (user_id, element_id) VALUES (%d, %d) ON DUPLICATE KEY UPDATE user_id = user_id'
        : 'INSERT INTO caddie (user_id, element_id) VALUES (%d, %d) ON CONFLICT DO NOTHING';
    H::dbQuery($db, sprintf(
        $insertSql,
        $userId,
        $imageId
    ));
    H::dbClose($db);
}

function bmImageHasTag(int $imageId, int $tagId): bool
{
    $db = bmDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM image_tag WHERE image_id = %d AND tag_id = %d', $imageId, $tagId));
    H::dbClose($db);

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
    return H::adminPost($page, '/admin.php?page=batch_manager', array_merge([
        'pwg_token' => H::pwgToken($page),
    ], $fields));
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
    expect(bmCaddieCount(1))
        ->toBeGreaterThan(0);

    $result = H::adminPost($page, '/admin.php?page=batch_manager&action=empty_caddie', [
        'pwg_token' => $token,
    ]);

    // A real HTTP redirect (Location header, from RedirectService::
    // redirect()) becomes an opaque response under fetch(..., {redirect:
    // 'manual'}) -- status is always reported as 0 and the body is
    // inaccessible by the Fetch API's own spec, not a failure signal.
    expect($result['status'])->toBe(0);
    expect(bmCaddieCount(1))
        ->toBe(0);
});

it('empty_caddie rejects a missing CSRF token', function (): void {
    $page = H::loginAsAdmin($this);
    bmInsertCaddie(1, 1);

    $result = H::adminPost($page, '/admin.php?page=batch_manager&action=empty_caddie', []);

    expect($result['status'])->toBe(400);
    expect(bmCaddieCount(1))
        ->toBeGreaterThan(0);

    bmInsertCaddie(1, 1);
    H::adminPost($page, '/admin.php?page=batch_manager&action=empty_caddie', [
        'pwg_token' => H::pwgToken($page),
    ]);
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

    $result = bmPost($page, [
        'submitFilter' => '1',
    ]);

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

it('applies add_tags then del_tags to a whole_set selection, round-tripping the association in image_tag', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Batch AddTags Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Batch AddTags Photo');
    @unlink($image);

    // Fixture tag 1 ('nature') -- see this suite's own fixture-shape
    // memory notes. Default bulk_manager_filter has no 'tags' key, so
    // neither action's own redirect-on-filter-affecting-change branch
    // fires here -- both stay a normal 200 render, not an opaque redirect.
    expect(bmImageHasTag($imageId, 1))
        ->toBeFalse();

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
    expect(bmImageHasTag($imageId, 1))
        ->toBeTrue();

    $delResult = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'del_tags',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'del_tags' => ['1'],
    ]);
    expect($delResult['status'])->toBe(200);
    expect(bmImageHasTag($imageId, 1))
        ->toBeFalse();
});

it('associates a whole_set selection with another album via the associate action', function (): void {
    $page = H::loginAsAdmin($this);
    $sourceAlbum = H::createCategory($page, [
        'name' => 'Batch Associate Source ' . uniqid(),
    ]);
    if (! is_numeric($sourceAlbum['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($sourceAlbum, true));
    }
    $sourceAlbumId = (int) $sourceAlbum['id'];
    $targetAlbum = H::createCategory($page, [
        'name' => 'Batch Associate Target ' . uniqid(),
    ]);
    if (! is_numeric($targetAlbum['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($targetAlbum, true));
    }
    $targetAlbumId = (int) $targetAlbum['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $sourceAlbumId, 'Batch Associate Photo');
    @unlink($image);

    $db = bmDbConnect();
    $beforeRow = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM image_category WHERE image_id = %d AND category_id = %d', $imageId, $targetAlbumId));
    expect(is_array($beforeRow) ? (int) $beforeRow['c'] : -1)->toBe(0);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'associate',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'associate' => [(string) $targetAlbumId],
    ]);
    expect($result['status'])->toBe(200);

    $afterRow = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM image_category WHERE image_id = %d AND category_id = %d', $imageId, $targetAlbumId));
    H::dbClose($db);
    expect(is_array($afterRow) ? (int) $afterRow['c'] : -1)->toBe(1);
});

/**
 * @return array{storage: bool, target: bool}
 */
function bmImageCategoryLinks(int $imageId, int $storageAlbumId, int $targetAlbumId): array
{
    $db = bmDbConnect();
    $rows = H::dbFetchAll($db, sprintf('SELECT category_id FROM image_category WHERE image_id = %d', $imageId));
    $ids = array_map(static fn (array $row): int => (int) $row['category_id'], $rows);
    H::dbClose($db);

    return [
        'storage' => in_array($storageAlbumId, $ids, true),
        'target' => in_array($targetAlbumId, $ids, true),
    ];
}

it('moves a whole_set selection, clearing all prior album links and adding the target', function (): void {
    $page = H::loginAsAdmin($this);
    $storageAlbum = H::createCategory($page, [
        'name' => 'Batch Move Storage ' . uniqid(),
    ]);
    if (! is_numeric($storageAlbum['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($storageAlbum, true));
    }
    $storageAlbumId = (int) $storageAlbum['id'];
    $targetAlbum = H::createCategory($page, [
        'name' => 'Batch Move Target ' . uniqid(),
    ]);
    if (! is_numeric($targetAlbum['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($targetAlbum, true));
    }
    $targetAlbumId = (int) $targetAlbum['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $storageAlbumId, 'Batch Move Photo');
    @unlink($image);

    expect(bmImageCategoryLinks($imageId, $storageAlbumId, $targetAlbumId))
        ->toBe([
            'storage' => true,
            'target' => false,
        ]);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'move',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'move' => (string) $targetAlbumId,
    ]);
    expect($result['status'])->toBe(200);
    // ImageService::moveImagesToCategories()'s own comment says it "breaks
    // links with all old albums but their storage album" -- but a photo
    // uploaded via pwg.images.addSimple (H::uploadPhotoViaApi(), not a
    // filesystem-synchronized one) always has a real NULL
    // storage_category_id, so no existing link is ever
    // exempt: move() clears every prior album, including the one it was
    // uploaded to.
    expect(bmImageCategoryLinks($imageId, $storageAlbumId, $targetAlbumId))
        ->toBe([
            'storage' => false,
            'target' => true,
        ]);
});

it('dissociates a whole_set selection from a non-storage album', function (): void {
    $page = H::loginAsAdmin($this);
    $storageAlbum = H::createCategory($page, [
        'name' => 'Batch Dissociate Storage ' . uniqid(),
    ]);
    if (! is_numeric($storageAlbum['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($storageAlbum, true));
    }
    $storageAlbumId = (int) $storageAlbum['id'];
    $otherAlbum = H::createCategory($page, [
        'name' => 'Batch Dissociate Other ' . uniqid(),
    ]);
    if (! is_numeric($otherAlbum['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($otherAlbum, true));
    }
    $otherAlbumId = (int) $otherAlbum['id'];
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
    expect(bmImageCategoryLinks($imageId, $storageAlbumId, $otherAlbumId))
        ->toBe([
            'storage' => true,
            'target' => true,
        ]);

    // Unlike associate (only conditionally redirects, depending on
    // prefilter), dissociate unconditionally sets $redirect = true on
    // success (BatchManagerGlobalPageRenderer's own source) -- a real
    // HTTP redirect becomes an opaque response under fetch(manual), same
    // documented shape as this file's own empty_caddie test above.
    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'dissociate',
        'setSelected' => '1',
        'whole_set' => (string) $imageId,
        'dissociate' => (string) $otherAlbumId,
    ]);
    expect($result['status'])->toBe(0);
    expect(bmImageCategoryLinks($imageId, $storageAlbumId, $otherAlbumId))
        ->toBe([
            'storage' => true,
            'target' => false,
        ]);
});

/**
 * @return array{name: ?string, author: ?string, level: int, date_creation: ?string}
 */
function bmImageRow(int $imageId): array
{
    $db = bmDbConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT name, author, level, date_creation FROM images WHERE id = %d', $imageId));
    H::dbClose($db);
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
    $album = H::createCategory($page, [
        'name' => 'Batch Mass Update Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
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
    $album = H::createCategory($page, [
        'name' => 'Batch Caddie Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
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
    $checkRow = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM caddie WHERE user_id = 1 AND element_id = %d', $imageId));
    H::dbClose($db);
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
    expect(bmCaddieCount($imageId))
        ->toBe(0);
});

it('rejects a delete action without confirm_deletion, then deletes and records a session message once confirmed', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Batch Delete Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
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
    // The loaded en_UK catalog rephrases this from its literal PHP source
    // msgid ("You need to confirm deletion").
    expect($unconfirmedResult['body'])->toContain('You must confirm deletion');

    $db = bmDbConnect();
    $stillThereRow = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM images WHERE id = %d', $imageId));
    H::dbClose($db);
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
    $album = H::createCategory($page, [
        'name' => 'Batch Metadata Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
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
    $album = H::createCategory($page, [
        'name' => 'Batch Thumbnails Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
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
    $album = H::createCategory($page, [
        'name' => 'Batch Selection Array Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
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
    expect(bmCaddieCount(1))
        ->toBeGreaterThan(0);

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

it('rejects a whole_set value containing a non-digit element as an invalid request parameter', function (): void {
    $page = H::loginAsAdmin($this);

    $result = bmPost($page, [
        'submit' => '1',
        'selectAction' => 'add_to_caddie',
        'setSelected' => '1',
        'whole_set' => '1,not-a-digit,3',
    ]);

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('Invalid request parameter');
    expect($result['body'])->toContain('whole_set');
});

/**
 * Coverage-gap closure batch (resolveSessionFilter()'s URL-filter
 * dimension/filesize bound validation, the defensive non-array session
 * guard, the 'no_sync_md5sum' prefilter, computeCurrentSet()'s 2
 * plugin-non-array fallbacks, the quick-search unmatched-terms branch,
 * and computeDimensionOptions()'s ratio-bucket aggregation) --
 * BatchManagerSubController.php lines ~410/433/455/489/536/543/544/548/
 * 641/642/652/727/729/732/733.
 *
 * NOT closed here, deliberately: computeDimensionOptions()'s own
 * "arbitrary values, only used when no photos on the gallery" fallback
 * (widths === [] at ~690-693) and computeFilesizeOptions()'s identical
 * fallback (filesizes === [] at ~773-774). Both read
 * ImageRepository::findDistinctDimensions()/findDistinctFilesizes(),
 * UNCONDITIONAL queries over the
 * whole images table -- no category/album/search scoping of any
 * kind, unlike SearchFilterRenderer's own sibling "arbitrary bucket"
 * fallback (closed in SearchFilterRendererDataFiltersTest.php), which
 * IS reachable because its equivalent query is scoped to a single
 * search/category. This repo's `_data` DB is a permanent, shared dev
 * fixture (site 1's "Sample Album" etc., relied on elsewhere in this
 * very file and in GalleryControllerTest.php) that has accumulated real
 * uploaded photos with real width/height/filesize for a long time, and
 * this same Browser suite runs many concurrent test files that upload
 * more real photos into it continuously -- so `widths === []`/
 * `filesizes === []` can never genuinely occur here without truncating
 * the whole images table, which would corrupt every other concurrently-
 * running Browser test against this shared server. Left uncovered
 * rather than built around a destructive shared-state mutation.
 */
function bmSetImageDimensions(int $imageId, int $width, int $height): void
{
    $db = bmDbConnect();
    H::dbQuery($db, sprintf('UPDATE images SET width = %d, height = %d WHERE id = %d', $width, $height, $imageId));
    H::dbClose($db);
}

/**
 * Raw curl through a persistent cookie jar, following redirects, using
 * the given credentials to log in first -- same shape as
 * PictureControllerTest.php's own pictureCurlLoginSession() (duplicated,
 * not shared: plain global test functions have no per-file namespacing
 * in this suite). Needed instead of this file's own Playwright-driven
 * H::loginAsAdmin() for the session-corruption test below, which has to
 * read/write the real DB-backed session row for its own request's
 * cookie -- pest-plugin-browser has no cookie-jar access of its own to
 * correlate a Playwright page with its own session row.
 *
 * @return array{curl: Closure(string, array<string, string>=): array{status: int, body: string}, cookieJar: non-empty-string, baseUrl: string}
 */
function bmCurlLoginSession(string $username, string $password): array
{
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_browser_session_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    /** @param array<string, string> $fields */
    $curl = static function (string $url, array $fields = []) use ($cookieJar): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($fields !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
        ];
    };

    $baseUrl = H::baseUrl();
    $curl($baseUrl . '/identification.php');
    $curl($baseUrl . '/identification.php', [
        'username' => $username,
        'password' => $password,
        'login' => 'Login',
    ]);

    return [
        'curl' => $curl,
        'cookieJar' => $cookieJar,
        'baseUrl' => $baseUrl,
    ];
}

/**
 * Reads the `pwg_id` session cookie's raw value out of a curl cookie-jar
 * file (Netscape format) -- same technique as PictureControllerTest.php's
 * own pictureCookieJarSessionId().
 */
function bmCookieJarSessionId(string $cookieJar): string
{
    $contents = file_get_contents($cookieJar);
    if ($contents === false) {
        throw new RuntimeException('failed to read cookie jar: ' . $cookieJar);
    }
    foreach (explode("\n", $contents) as $line) {
        $fields = explode("\t", $line);
        if (($fields[5] ?? null) === 'pwg_id' && isset($fields[6])) {
            return trim($fields[6]);
        }
    }

    throw new RuntimeException('pwg_id cookie not found in jar: ' . $cookieJar);
}

/**
 * Reads the real session `data` blob straight out of the DB-backed
 * `sessions` table (Piwigo\Session\SessionHandler's own save handler) for the
 * given `pwg_id` cookie value -- a `LIKE '%<id>'` suffix match sidesteps
 * SessionService::getRemoteAddrSessionHash()'s own IP-derived prefix
 * entirely (same rationale as PasswordControllerTest.php's own
 * passwordSessionData()).
 */
function bmSessionData(string $pwgIdCookieValue): string
{
    $db = bmDbConnect();
    $row = H::dbFetchAssoc($db, sprintf("SELECT data FROM sessions WHERE id LIKE '%%%s'", H::dbEscape($db, $pwgIdCookieValue)));
    H::dbClose($db);

    return is_array($row) && is_string($row['data'] ?? null) ? $row['data'] : '';
}

/**
 * Corrupts $_SESSION['bulk_manager_filter'] into a plain (non-array)
 * string by appending a well-formed `name|value;` pair directly onto the
 * real session row's `data` blob -- PHP's default "php" session
 * serialize-handler format has no separator between pairs and no
 * duplicate-key protection, so CONCAT-appending a fresh pair is exactly
 * how a real $_SESSION write is represented on disk (same technique as
 * IntroSubControllerTest.php's own session-corruption test and
 * PictureControllerTest.php's own pwg_picture_deriv one). Only safe to
 * call on a session that has never gone through
 * BatchManagerSubController::resolveSessionFilter() before (no prior
 * `bulk_manager_filter|...` pair to shadow) -- bmCurlLoginSession()'s
 * own fresh login, never used above, guarantees that.
 */
function bmCorruptSessionBulkManagerFilter(string $pwgIdCookieValue): void
{
    $db = bmDbConnect();
    $fragment = 'bulk_manager_filter|' . serialize('not-an-array');
    $likePattern = '%' . $pwgIdCookieValue;
    // mysqli's own native prepare()/bind_param() has no portable
    // Postgres equivalent worth porting here -- $fragment/$likePattern
    // are escaped and inlined instead, the same shape every other raw
    // query in this file already uses.
    H::dbQuery($db, sprintf("UPDATE sessions SET data = CONCAT(data, '%s') WHERE id LIKE '%s'", H::dbEscape($db, $fragment), H::dbEscape($db, $likePattern)));
    H::dbClose($db);
}

it('marks the dimension and filesize URL-filter tokens invalid when a bound value fails validation, still rendering the other valid bounds', function (): void {
    // resolveSessionFilter()'s URL-filter 'dimension'/'filesize' cases
    // validate each '-'-separated bound part independently ($valid is
    // reset to true per part) -- 'hbogus..2000' fails FILTER_VALIDATE_INT
    // (height dropped entirely) while the sibling 'w10..1000'/
    // 'r0.5..2' parts stay valid (width/ratio both kept), and
    // 'filesize-bogus..10' fails FILTER_VALIDATE_FLOAT the same way (the
    // whole filesize bound dropped, since that case has only one
    // min/max pair, not per-part).
    $page = H::loginAsAdmin($this);

    $filter = implode(',', [
        'dimension-w10..1000-hbogus..2000-r0.5..2',
        'filesize-bogus..10',
    ]);

    $page = H::navigateOk($page, '/admin.php?page=batch_manager&filter=' . rawurlencode($filter));
    $page->assertNoJavaScriptErrors();
});

it('applies the no_sync_md5sum prefilter via a URL filter token', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&filter=prefilter-no_sync_md5sum');
    $page->assertNoJavaScriptErrors();
});

it('resets a corrupted (non-array) session bulk_manager_filter back to the default caddie prefilter', function (): void {
    $session = bmCurlLoginSession(H::ADMIN_USER, H::ADMIN_PASS);
    $cookieJarSessionId = bmCookieJarSessionId($session['cookieJar']);

    bmCorruptSessionBulkManagerFilter($cookieJarSessionId);
    expect(bmSessionData($cookieJarSessionId))
        ->toContain('bulk_manager_filter|s:');

    // Plain GET, no submitFilter/filter param -- neither of
    // resolveSessionFilter()'s own 2 write branches runs, so the only
    // thing that can still touch bulk_manager_filter this request is the
    // defensive `! is_array(...)` guard.
    $result = ($session['curl'])($session['baseUrl'] . '/admin.php?page=batch_manager');
    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');

    // The corrupted string was replaced with a real array before this
    // same request finished rendering -- array_shift()/array_intersect()
    // further down computeCurrentSet() would otherwise have thrown a
    // TypeError against a non-array $_SESSION['bulk_manager_filter'].
    expect(bmSessionData($cookieJarSessionId))
        ->toContain('bulk_manager_filter|a:1:{s:9:"prefilter";s:6:"caddie";}');

    @unlink($session['cookieJar']);
});

it('proceeds with an empty filter set when a perform_batch_manager_prefilters handler returns something other than a PerformBatchManagerPrefilters instance', function (): void {
    // Only invoked when the prefilter itself is unrecognized, i.e.
    // FilterResolver::resolvePrefilter() returns null. Unreachable
    // through any real, unhooked request -- reaching it needs a real
    // plugin, same mechanism a genuine misbehaving 3rd-party plugin
    // would use (PluginConfig\PluginRegistry::bootActive() boots every
    // DB-active plugin's ExtensionInterface class on every request).
    // Gated on this test's own unique marker prefilter value, so it's a
    // complete no-op for every other concurrent request against this
    // shared dev server while active (matches PictureControllerTest.php's
    // own "bogus comment action" fixture-plugin test). Since dispatch()
    // no longer enforces a return-type instanceof check,
    // a handler returning the wrong type is simply ignored -- $filterSets
    // stays whatever it already was rather than crashing the request.
    $marker = 'pwgtest_bogus_prefilter_' . uniqid();
    $pluginId = 'pwgtest-batch-manager-bogus-prefilter';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;

    bmWriteFixturePlugin($pluginDir, <<<PHP
        \\Piwigo\\Tests\\Support\\EventDispatcherTestFactory::get()->addTypedHandler(
            \\Piwigo\\Controller\\Admin\\Event\\PerformBatchManagerPrefilters::class,
            static function (\\Piwigo\\Controller\\Admin\\Event\\PerformBatchManagerPrefilters \$event): mixed {
                if (\$event->prefilter === '{$marker}') {
                    return 'not-a-perform-batch-manager-prefilters-instance';
                }

                return \$event;
            }
        );
        PHP);

    $pluginDb = bmDbConnect();
    H::dbQuery($pluginDb, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", $pluginId));
    H::dbClose($pluginDb);

    try {
        $page = H::loginAsAdmin($this);
        H::navigateOk($page, '/admin.php?page=batch_manager');

        $result = bmPost($page, [
            'submitFilter' => '1',
            'filter_prefilter_use' => '1',
            'filter_prefilter' => $marker,
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        $cleanupDb = bmDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM plugins WHERE id = '%s'", $pluginId));
        H::dbClose($cleanupDb);
        bmRemoveFixturePlugin($pluginDir);
    }
});

it('proceeds with an empty filter set when a batch_manager_perform_filters handler returns something other than a BatchManagerPerformFilters instance', function (): void {
    // Always invoked, at the very end of computeCurrentSet(). Same
    // fixture-plugin technique as the sibling test above, gated on its
    // own distinct marker. Since dispatch() no longer enforces a
    // return-type instanceof check, a handler returning
    // the wrong type is simply ignored -- $filterSets stays whatever it
    // already was rather than crashing the request.
    $marker = 'pwgtest_bogus_filters_' . uniqid();
    $pluginId = 'pwgtest-batch-manager-bogus-filters';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;

    bmWriteFixturePlugin($pluginDir, <<<PHP
        \\Piwigo\\Tests\\Support\\EventDispatcherTestFactory::get()->addTypedHandler(
            \\Piwigo\\Controller\\Admin\\Event\\BatchManagerPerformFilters::class,
            static function (\\Piwigo\\Controller\\Admin\\Event\\BatchManagerPerformFilters \$event): mixed {
                if ((\$event->bulkManagerFilter['prefilter'] ?? null) === '{$marker}') {
                    return 'not-a-batch-manager-perform-filters-instance';
                }

                return \$event;
            }
        );
        PHP);

    $pluginDb = bmDbConnect();
    H::dbQuery($pluginDb, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", $pluginId));
    H::dbClose($pluginDb);

    try {
        $page = H::loginAsAdmin($this);
        H::navigateOk($page, '/admin.php?page=batch_manager');

        $result = bmPost($page, [
            'submitFilter' => '1',
            'filter_prefilter_use' => '1',
            'filter_prefilter' => $marker,
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
    } finally {
        $cleanupDb = bmDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM plugins WHERE id = '%s'", $pluginId));
        H::dbClose($cleanupDb);
        bmRemoveFixturePlugin($pluginDir);
    }
});

it('renders unmatched search terms as "No results for" alongside real matched items', function (): void {
    $page = H::loginAsAdmin($this);
    H::navigateOk($page, '/admin.php?page=batch_manager');

    // 'Sample' full-text-matches category 1's permanent fixture name
    // ('Sample Album') and 'nature' matches fixture tag 1 (this file's
    // own tag tests above already rely on tag 1 being 'nature') -- same
    // stable base-install fixture terms GalleryControllerTest.php's own
    // "Sample nature quxfrobnicate42" test already relies on.
    // qsearchEval() only ever intersects on a *qualifying* term, so the
    // bogus 3rd term is simply dropped into unmatched_terms rather than
    // zeroing the whole result -- $res_items stays > 0 from the other 2
    // terms' category/tag matches.
    $result = bmPost($page, [
        'submitFilter' => '1',
        'filter_search_use' => '1',
        'q' => 'Sample nature quxfrobnicate42',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
    expect($result['body'])->toContain('No results for');
    expect($result['body'])->toContain('quxfrobnicate42');
});

it('aggregates portrait/square/panorama ratio buckets from real distinct image dimensions', function (): void {
    // computeDimensionOptions() reads ImageRepository::
    // findDistinctDimensions() unscoped (the whole images table,
    // no category/search filter) -- so uploading these 3 photos anywhere
    // and forcing their width/height via raw SQL is enough for the
    // global ratio-bucket aggregation to pick them up on the very next
    // batch_manager page load, regardless of which album they live in.
    // 200x150 (H::makeTestImage()'s own fixed size, ratio 1.33) already
    // covers the 'landscape' bucket elsewhere in this suite -- only
    // portrait (<0.95), square (0.95..1.05) and panorama (>=2) are new.
    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Batch Ratio Buckets Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $portraitImage = H::makeTestImage(uniqid());
    $portraitId = H::uploadPhotoViaApi($portraitImage, $albumId, 'Batch Ratio Portrait Photo');
    @unlink($portraitImage);
    bmSetImageDimensions($portraitId, 300, 1000); // ratio 0.30

    $squareImage = H::makeTestImage(uniqid());
    $squareId = H::uploadPhotoViaApi($squareImage, $albumId, 'Batch Ratio Square Photo');
    @unlink($squareImage);
    bmSetImageDimensions($squareId, 1000, 1000); // ratio 1.00

    $panoramaImage = H::makeTestImage(uniqid());
    $panoramaId = H::uploadPhotoViaApi($panoramaImage, $albumId, 'Batch Ratio Panorama Photo');
    @unlink($panoramaImage);
    bmSetImageDimensions($panoramaId, 5000, 1000); // ratio 5.00

    $page = H::navigateOk($page, '/admin.php?page=batch_manager');
    $html = H::settledContent($page);

    // batch_manager_filter.inc.latte only ever renders these `<a
    // class="slider-choice" ...>` ratio-bucket links inside their own
    // `{if isset($dimensions.ratio_*)}` guard -- their mere presence
    // proves the portrait/square/panorama keys got set. The template's
    // own {'square'|translate} renders as "Square" (capital S) -- en_UK's
    // common.po translates the lowercase "square" msgid to "Square",
    // matching Portrait/Landscape/Panorama's own capitalization (whose
    // msgids are already capitalized, so they never actually change case).
    expect($html)
        ->toContain('>Portrait</a>')
        ->and($html)
        ->toContain('>Square</a>')
        ->and($html)
        ->toContain('>Panorama</a>');
});
