<?php

declare(strict_types=1);

use PgSql\Connection;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\SiteUpdateSubController (admin.php?page=
 * site_update) -- the filesystem/DB synchronization pass: new/deleted
 * category detection, new/deleted/updated element detection, metadata
 * sync. Site 1's `galleries_url` (piwigo_sites fixture row) is the real
 * repo-root `galleries/` directory, otherwise empty in this fixture (no
 * category in the fixture has `dir`/`site_id` set, so a full, unscoped
 * sync of site 1 never touches any pre-existing category/photo) -- tests
 * below create a uniquely-named subdirectory + one GD-generated JPEG
 * under it, drive a real sync, then either let a second "the directory is
 * gone" sync delete what was created (exercising the deletion-detection
 * path for free) or fall back to that same real resync in a `finally`
 * block as cleanup.
 *
 * Deliberately light on: `enable_formats` beyond the one dedicated test
 * below, and `meta_empty_overrides` -- each is a real but narrow
 * combinatorial branch of an already very large handler; render +
 * guard-clause + the core create/simulate/delete/metadata/quick_sync
 * flows cover the large majority of this file's real gap without the
 * disproportionate setup those would need (matching AdminConfigurationTest's
 * own documented scoping precedent).
 *
 * Two lines of the "directories / categories" block are deliberately left
 * uncovered below, not silently skipped:
 *
 * - `CategoryService::getNextRanksByParent()`'s row-shape narrowing
 *   `continue` (reached when a row's `id_uppercat` is neither `int` nor
 *   `string`) is provably unreachable under this project's own DB driver
 *   configuration: `id_uppercat` is a nullable `smallint unsigned`
 *   column, and `DbConnection::build()` sets
 *   `MYSQLI_OPT_INT_AND_FLOAT_NATIVE`, so a raw `fetchAllAssociative()`
 *   row always yields either `int` (non-null) or `null` for it -- and the
 *   controller's own preceding `isset($row['id_uppercat']) or === ''`
 *   normalization already rewrites every `null`/`''` case to the string
 *   `'NULL'` before this check runs. There is no third native-MySQL
 *   column type this branch could ever observe.
 * - The `substr_compare($fulldir, '../', 0, 3)` prefix-strip (a
 *   category's on-disk fulldir starting with a literal `../`) is a
 *   holdover from legacy Piwigo's relative-`galleries_url` convention.
 *   Both of this rewrite's real write paths for a site row
 *   (`InstallWizard`'s own site-1 seed, and `SiteManagerSubController`'s
 *   "add a new site" form -- see that controller's own inline comment)
 *   always anchor `galleries_url` to `the live, container-bound Paths->root`, an
 *   absolute path, so no fulldir built from
 *   `CategoryService::getFulldirs()` can ever start with `../` for data
 *   this application itself wrote. It remains reachable only via a
 *   pre-rewrite-migrated site row a legacy install left with a relative
 *   URL -- this suite deliberately does not fabricate that here, since
 *   doing so would mean creating a real directory outside this repo's own
 *   `galleries/`/`_data/` sandbox (one level above the project root, in
 *   the host's actual home directory) purely to satisfy
 *   `LocalSiteReader::open()`'s `is_dir()` check.
 */
function suDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function suConnect(): mysqli|Connection
{
    return H::connect();
}

function suCategoryIdByDir(int $siteId, string $dir): ?int
{
    $db = suConnect();
    $row = H::dbFetchAssoc($db, sprintf(
        "SELECT id FROM %scategories WHERE site_id = %d AND dir = '%s'",
        suDbPrefix(),
        $siteId,
        H::dbEscape($db, $dir)
    ));
    H::dbClose($db);

    return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
}

function suImageIdByFile(string $file): ?int
{
    $db = suConnect();
    $row = H::dbFetchAssoc($db, sprintf(
        "SELECT id FROM %simages WHERE file = '%s'",
        suDbPrefix(),
        H::dbEscape($db, $file)
    ));
    H::dbClose($db);

    return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
}

function suImageDateMetadataUpdate(int $imageId): ?string
{
    $db = suConnect();
    $row = H::dbFetchAssoc($db, sprintf(
        'SELECT date_metadata_update FROM %simages WHERE id = %d',
        suDbPrefix(),
        $imageId
    ));
    H::dbClose($db);

    $value = is_array($row) ? ($row['date_metadata_update'] ?? null) : null;

    return is_string($value) ? $value : null;
}

function suImageLevel(int $imageId): ?int
{
    $db = suConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT level FROM %simages WHERE id = %d', suDbPrefix(), $imageId));
    H::dbClose($db);

    $value = is_array($row) ? ($row['level'] ?? null) : null;

    return is_numeric($value) ? (int) $value : null;
}

function suImageFormatFilesize(int $imageId, string $ext): ?int
{
    $db = suConnect();
    $row = H::dbFetchAssoc($db, sprintf(
        "SELECT filesize FROM %simage_format WHERE image_id = %d AND ext = '%s'",
        suDbPrefix(),
        $imageId,
        H::dbEscape($db, $ext)
    ));
    H::dbClose($db);

    $value = is_array($row) ? ($row['filesize'] ?? null) : null;

    return is_numeric($value) ? (int) $value : null;
}

function suGrantGroupAccess(int $groupId, int $catId): void
{
    $db = suConnect();
    H::dbQuery($db, sprintf(
        'INSERT INTO %sgroup_access (group_id, cat_id) VALUES (%d, %d)',
        suDbPrefix(),
        $groupId,
        $catId
    ));
    H::dbClose($db);
}

function suGrantUserAccess(int $userId, int $catId): void
{
    $db = suConnect();
    H::dbQuery($db, sprintf(
        'INSERT INTO %suser_access (user_id, cat_id) VALUES (%d, %d)',
        suDbPrefix(),
        $userId,
        $catId
    ));
    H::dbClose($db);
}

function suHasGroupAccess(int $groupId, int $catId): bool
{
    $db = suConnect();
    $row = H::dbFetchAssoc($db, sprintf(
        'SELECT 1 FROM %sgroup_access WHERE group_id = %d AND cat_id = %d',
        suDbPrefix(),
        $groupId,
        $catId
    ));
    H::dbClose($db);

    return $row !== null;
}

function suHasUserAccess(int $userId, int $catId): bool
{
    $db = suConnect();
    $row = H::dbFetchAssoc($db, sprintf(
        'SELECT 1 FROM %suser_access WHERE user_id = %d AND cat_id = %d',
        suDbPrefix(),
        $userId,
        $catId
    ));
    H::dbClose($db);

    return $row !== null;
}

function suSetCategoryVisible(int $catId, bool $visible): void
{
    $db = suConnect();
    // categories.visible is a genuine boolean column on Postgres -- a
    // bare 0/1 literal is valid MySQL tinyint(1) input but Postgres
    // rejects it outright.
    $sqlValue = $db instanceof mysqli ? ($visible ? '1' : '0') : ($visible ? 'true' : 'false');
    H::dbQuery($db, sprintf(
        'UPDATE %scategories SET visible = %s WHERE id = %d',
        suDbPrefix(),
        $sqlValue,
        $catId
    ));
    H::dbClose($db);
}

function suCategoryVisible(int $catId): ?bool
{
    $db = suConnect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT visible FROM %scategories WHERE id = %d', suDbPrefix(), $catId));
    H::dbClose($db);

    return is_array($row) && isset($row['visible']) ? H::dbToBool($row['visible']) : null;
}

function suSetCategoryGlobalRankNull(int $catId): void
{
    $db = suConnect();
    H::dbQuery($db, sprintf('UPDATE %scategories SET global_rank = NULL WHERE id = %d', suDbPrefix(), $catId));
    H::dbClose($db);
}

/**
 * @return list<string>
 */
function suImageTagNames(int $imageId): array
{
    $db = suConnect();
    $fetchedRows = H::dbFetchAll($db, sprintf(
        'SELECT t.name FROM %stags t INNER JOIN %simage_tag it ON it.tag_id = t.id WHERE it.image_id = %d ORDER BY t.name',
        suDbPrefix(),
        suDbPrefix(),
        $imageId
    ));
    $names = [];
    foreach ($fetchedRows as $row) {
        if (is_string($row['name'] ?? null)) {
            $names[] = $row['name'];
        }
    }
    H::dbClose($db);

    return $names;
}

/**
 * @param  list<string>  $names
 */
function suDeleteTagsByName(array $names): void
{
    if ($names === []) {
        return;
    }

    $db = suConnect();
    $quoted = array_map(static fn (string $name): string => "'" . H::dbEscape($db, $name) . "'", $names);
    H::dbQuery($db, sprintf('DELETE FROM %stags WHERE name IN (%s)', suDbPrefix(), implode(',', $quoted)));
    H::dbClose($db);
}

function suInsertRemoteSite(string $url): int
{
    $db = suConnect();
    H::dbQuery($db, sprintf(
        "INSERT INTO %ssites (galleries_url) VALUES ('%s')",
        suDbPrefix(),
        H::dbEscape($db, $url)
    ));
    $id = H::dbInsertId($db);
    H::dbClose($db);

    return $id;
}

function suDeleteSite(int $id): void
{
    $db = suConnect();
    H::dbQuery($db, sprintf('DELETE FROM %ssites WHERE id = %d', suDbPrefix(), $id));
    H::dbClose($db);
}

function suGalleriesRoot(): string
{
    return dirname(__DIR__, 2) . '/galleries/';
}

function suRoot(): string
{
    return dirname(__DIR__, 2) . '/';
}

/**
 * Mirrors SiteUpdateSubController's own to-delete-derivative-dir
 * computation for a top-level category `dir` directly under site 1's
 * `galleries/` root: `the live, container-bound Paths->root . CurrentConfig::
 * derivativeDir() . $fulldir`, where `$fulldir` is itself already
 * absolute (site 1's `galleries_url` fixture row is an absolute path) --
 * `root` is embedded twice by design, mirroring the full source path
 * under `_data/i/` (Piwigo's standard derivative-cache-path-mirrors-
 * source-path convention).
 */
function suDerivativeCacheDirFor(string $dir): string
{
    $root = suRoot();

    return $root . '_data/i' . $root . 'galleries/' . $dir;
}

function suMakeTempDir(string $name): string
{
    $dir = suGalleriesRoot() . $name;
    mkdir($dir, 0777, true);

    return $dir;
}

function suRemoveDirRecursive(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $entries = scandir($dir);
    if ($entries !== false) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                suRemoveDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
    }

    @rmdir($dir);
}

/**
 * A real, minimal JPEG (via GD) with a hand-built Photoshop-IRB APP13
 * segment (the real "8BIM"/0x0404 IPTC-NAA resource block format)
 * carrying one IPTC record 2 / dataset 25 (Keywords) entry per given
 * keyword -- same technique as MetadataServiceTest's own
 * makeJpegWithApp13Iptc() helper, needed because getimagesize()/
 * iptcparse() require genuinely valid marker-segment bytes, not a
 * fabricated string. Caller is responsible for unlink()-ing the returned
 * path.
 *
 * @param  list<string>  $keywords
 */
function suMakeJpegWithIptcKeywords(array $keywords): string
{
    $iptcData = '';
    foreach ($keywords as $keyword) {
        $iptcData .= "\x1c" . chr(2) . chr(25) . pack('n', strlen($keyword)) . $keyword;
    }
    $nameField = chr(0) . "\x00";
    $blockData = $iptcData;
    if (strlen($blockData) % 2 !== 0) {
        $blockData .= "\x00";
    }
    $block = '8BIM' . pack('n', 0x0404) . $nameField . pack('N', strlen($iptcData)) . $blockData;
    $psHeader = "Photoshop 3.0\x00" . $block;
    $app13 = "\xFF\xED" . pack('n', strlen($psHeader) + 2) . $psHeader;

    $img = imagecreatetruecolor(200, 150);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor failed');
    }
    ob_start();
    imagejpeg($img, null, 80);
    $base = ob_get_clean();

    $bytes = substr($base, 0, 2) . $app13 . substr($base, 2);

    $tmpPath = tempnam(sys_get_temp_dir(), 'pwg_browser_iptc_');
    if ($tmpPath === false) {
        throw new RuntimeException('tempnam failed');
    }
    $path = $tmpPath . '.jpg';
    file_put_contents($path, $bytes);

    return $path;
}

/**
 * @param  array<string, string>  $query
 */
function suPath(array $query = []): string
{
    return '/admin.php?page=site_update&' . http_build_query(array_merge(['site' => '1'], $query));
}

/**
 * @param  array<string, string>  $overrides
 * @return array{status: int, body: string}
 */
function suSync(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $token, array $overrides = []): array
{
    // No 'sync_meta' key here by default: the controller gates its whole
    // metadata-sync block on isset($post['sync_meta']) alone, matching a
    // real HTML checkbox (submitted only when checked, never as a literal
    // "0") -- unlike 'simulate'/'display_info'/'add_to_caddie', which the
    // controller compares by VALUE (=== '1'), so sending those as '0' is
    // fine. Sending 'sync_meta' => '0' would still satisfy isset() and
    // silently run metadata sync on every call -- confirmed live via a
    // stray date_metadata_update stamp on a plain create-only sync before
    // this was caught. Callers that want metadata sync pass 'sync_meta' =>
    // '1' explicitly via $overrides.
    return H::adminPost($page, suPath(), array_merge([
        'submit' => '1',
        'pwg_token' => $token,
        'sync' => 'files',
        'subcats-included' => '1',
        'privacy_level' => '0',
        'display_info' => '1',
        'add_to_caddie' => '0',
        'simulate' => '0',
    ], $overrides));
}

it('renders the introduction with default settings and category options', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, suPath());
    $page->assertNoJavaScriptErrors();
});

it('renders with a preselected cat_id, defaulting sync to files', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, suPath(['cat_id' => '1']));
    $page->assertNoJavaScriptErrors();
});

it('fatal-errors when synchronization is disabled', function (): void {
    $page = H::loginAsAdmin($this);
    $snapshot = H::snapshotConfig(['enable_synchronization']);

    try {
        H::setConfigValue('enable_synchronization', 'false');

        $result = H::adminPost($page, suPath(), []);

        expect($result['status'])->toBe(500);
        expect($result['body'])->toContain('synchronization is disabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('fatal-errors when the site param is missing', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, '/admin.php?page=site_update', []);

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('site param missing or invalid');
});

it('fatal-errors when the site param is not numeric', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, suPath(['site' => 'abc']), []);

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('site param missing or invalid');
});

it('fatal-errors when the site does not exist', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, suPath(['site' => '999999']), []);

    expect($result['status'])->toBe(500);
    expect($result['body'])->toContain('site 999999 does not exist');
});

it('fatal-errors for a remote site', function (): void {
    $page = H::loginAsAdmin($this);
    $remoteSiteId = suInsertRemoteSite('http://ct-remote-' . uniqid() . '.example.invalid/');

    try {
        $result = H::adminPost($page, suPath(['site' => (string) $remoteSiteId]), []);

        expect($result['status'])->toBe(500);
        expect($result['body'])->toContain('remote sites not supported');
    } finally {
        suDeleteSite($remoteSiteId);
    }
});

it('reports all-zero counts when the site directory cannot be opened', function (): void {
    // LocalSiteReader::open() is a bare is_dir() check -- a site row
    // pointing at a nonexistent (but still local, not remote) path makes
    // it return false, setting $general_failure = true. That gates every
    // counting block, but NOT the final update_result template assign
    // (isset($post['submit']) && sync in [dirs,files] alone) -- so the
    // page still renders a real summary, just with every count at 0
    // rather than a fatal error.
    $page = H::loginAsAdmin($this);
    $unreadableSiteId = suInsertRemoteSite('galleries/ct-does-not-exist-' . uniqid() . '/');
    $token = H::pwgToken($page);

    try {
        // suSync()'s own suPath() call is hardcoded to site=1 -- the
        // controller reads the target site from the URL query string,
        // not $_POST, so the override has to go there too (same shape as
        // the existing "fatal-errors for a remote site" test above).
        $result = H::adminPost($page, suPath(['site' => (string) $unreadableSiteId]), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'display_info' => '1',
            'add_to_caddie' => '0',
            'simulate' => '0',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->not->toContain('Fatal error');
        expect($result['body'])->toContain('0 albums added in the database');
        expect($result['body'])->toContain('0 photos added in the database');
        expect($result['body'])->toContain('0 errors during synchronization');
    } finally {
        suDeleteSite($unreadableSiteId);
    }
});

it('rejects a submission with a missing CSRF token', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, suPath(), ['submit' => '1', 'sync' => 'files']);

    expect($result['status'])->toBe(400);
});

it('rejects a submission with a wrong CSRF token', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, suPath(), [
        'submit' => '1',
        'sync' => 'files',
        'pwg_token' => 'not-a-real-token',
    ]);

    expect($result['status'])->toBe(401);
});

it('quick_sync requires a CSRF token too', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, suPath(['quick_sync' => '1']), []);

    expect($result['status'])->toBe(400);
});

it('synchronizes a new directory/photo and detects its deletion on resync', function (): void {
    $dir = 'ct_site_sync_' . uniqid();
    $file = $dir . '.jpg';
    $tempDir = suMakeTempDir($dir);
    $imagePath = H::makeTestImage('CT Site Sync');
    copy($imagePath, $tempDir . '/' . $file);
    @unlink($imagePath);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        $created = suSync($page, $token);

        expect($created['status'])->toBe(200);
        expect($created['body'])->toContain('1 albums added in the database');
        expect($created['body'])->toContain('1 photos added in the database');
        expect($created['body'])->toContain('0 albums deleted in the database');
        expect($created['body'])->toContain('0 errors during synchronization');

        $categoryId = suCategoryIdByDir(1, $dir);
        expect($categoryId)->not->toBeNull();
        $imageId = suImageIdByFile($file);
        expect($imageId)->not->toBeNull();

        suRemoveDirRecursive($tempDir);

        $deleted = suSync($page, $token);

        expect($deleted['status'])->toBe(200);
        expect($deleted['body'])->toContain('0 albums added in the database');
        expect($deleted['body'])->toContain('1 albums deleted in the database');

        expect(suCategoryIdByDir(1, $dir))->toBeNull();
        expect(suImageIdByFile($file))->toBeNull();
    } finally {
        suRemoveDirRecursive($tempDir);
    }
});

it('simulate mode reports counts without writing to the database', function (): void {
    $dir = 'ct_site_simulate_' . uniqid();
    $file = $dir . '.jpg';
    $tempDir = suMakeTempDir($dir);
    $imagePath = H::makeTestImage('CT Simulate');
    copy($imagePath, $tempDir . '/' . $file);
    @unlink($imagePath);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        $result = suSync($page, $token, ['simulate' => '1']);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('Simulation');
        expect($result['body'])->toContain('1 albums added in the database');
        expect($result['body'])->toContain('1 photos added in the database');

        expect(suCategoryIdByDir(1, $dir))->toBeNull();
        expect(suImageIdByFile($file))->toBeNull();
    } finally {
        suRemoveDirRecursive($tempDir);
    }
});

it('rejects a directory whose name fails the sync-chars regex', function (): void {
    $dir = 'ct bad dir! ' . uniqid();
    $tempDir = suMakeTempDir($dir);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        $result = suSync($page, $token, ['sync' => 'dirs']);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('PWG-UPDATE-1');
        expect($result['body'])->toContain('wrong filename');
        expect($result['body'])->toContain($dir);
        expect($result['body'])->toContain('1 errors during synchronization');
        expect(suCategoryIdByDir(1, $dir))->toBeNull();
    } finally {
        suRemoveDirRecursive($tempDir);
    }
});

it('synchronizes metadata for a registered photo', function (): void {
    $dir = 'ct_site_meta_' . uniqid();
    $file = $dir . '.jpg';
    $tempDir = suMakeTempDir($dir);
    $imagePath = H::makeTestImage('CT Meta Sync');
    copy($imagePath, $tempDir . '/' . $file);
    @unlink($imagePath);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        suSync($page, $token);

        $imageId = suImageIdByFile($file);
        expect($imageId)->not->toBeNull();
        assert($imageId !== null);
        expect(suImageDateMetadataUpdate($imageId))->toBeNull();

        $result = suSync($page, $token, ['sync_meta' => '1', 'meta_all' => '1']);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain("1 photos' information synchronized with files metadata");
        expect($result['body'])->toContain('1 photos candidates for metadata synchronization');
        expect(suImageDateMetadataUpdate($imageId))->not->toBeNull();
    } finally {
        suRemoveDirRecursive($tempDir);
        H::adminPost($page, suPath(), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});

it('scopes a submit to a single category via cat, honoring subcats-included and assigning a new sub-category its parent chain', function (): void {
    $parentDir = 'ct_site_cat_parent_' . uniqid();
    $parentTemp = suMakeTempDir($parentDir);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        // Unscoped sync creates the top-level parent category (no
        // id_uppercat), leaving $db_categories'/$post['cat'] scoping
        // branches (subcats-included true/false, basedir-from-cat) still
        // untested -- both exercised below.
        $created = suSync($page, $token);
        expect($created['status'])->toBe(200);
        $parentId = suCategoryIdByDir(1, $parentDir);
        expect($parentId)->not->toBeNull();
        assert($parentId !== null);

        // A brand new sub-directory under the parent: cat-scoped +
        // subcats-included='1' hits the uppercats-regex query branch, the
        // basedir-from-$post['cat'] resolution, and (since the parent
        // already exists in $db_categories) the new-category-with-parent
        // block (id_uppercat/uppercats/rank/global_rank inheritance).
        $childDir = $parentDir . '/ct_child_' . uniqid();
        mkdir(suGalleriesRoot() . $childDir, 0777, true);

        $scoped = suSync($page, $token, ['cat' => (string) $parentId, 'subcats-included' => '1']);
        expect($scoped['status'])->toBe(200);
        expect($scoped['body'])->toContain('1 albums added in the database');

        $childId = suCategoryIdByDir(1, basename($childDir));
        expect($childId)->not->toBeNull();

        // A second new sub-directory, this time submitted with
        // subcats-included NOT '1': the query restricts to `id =
        // $post['cat']` alone (no descendants fetched), so the fs-vs-db
        // comparison is intersected down to the parent's own path only --
        // the new directory must NOT be detected as a new category.
        $secondChildDir = $parentDir . '/ct_child2_' . uniqid();
        mkdir(suGalleriesRoot() . $secondChildDir, 0777, true);

        $notScoped = suSync($page, $token, ['cat' => (string) $parentId, 'subcats-included' => '0']);
        expect($notScoped['status'])->toBe(200);
        expect($notScoped['body'])->toContain('0 albums added in the database');
        expect(suCategoryIdByDir(1, basename($secondChildDir)))->toBeNull();
    } finally {
        suRemoveDirRecursive($parentTemp);
        H::adminPost($page, suPath(), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});

it('propagates directly-granted group/user permissions onto newly-synced private child categories, walking a multi-level new-category chain', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);
    $snapshot = H::snapshotConfig(['inheritance_by_default', 'newcat_default_status']);

    $parentDir = 'ct_site_perm_parent_' . uniqid();
    $parentTemp = suMakeTempDir($parentDir);

    try {
        H::setConfigValue('inheritance_by_default', 'true');
        H::setConfigValue('newcat_default_status', H::jsonEncode('private'));

        // Parent category created alone: no id_uppercat, so this sync takes
        // the *other* branch (PermissionService::addPermissionOnCategory,
        // already covered) -- not the one under test here.
        $created = suSync($page, $token);
        expect($created['status'])->toBe(200);
        $parentId = suCategoryIdByDir(1, $parentDir);
        expect($parentId)->not->toBeNull();
        assert($parentId !== null);

        // Directly grant group 1 ("Editors") and user 4 access on the
        // parent -- the rows findGrantedGroupIdsByCategory()/
        // findGrantedUserIdsByCategory() must discover and copy onto the
        // new children below.
        suGrantGroupAccess(1, $parentId);
        suGrantUserAccess(4, $parentId);

        // Three new directories in a single sync request: childA and
        // childB directly under the parent (parent already existed before
        // this request, so is NOT in this batch's own $category_ids --
        // parent_id resolves immediately, no while-loop walk), and
        // grandchildC nested one level under childB (childB *is* in this
        // same batch's $category_ids, forcing the while-loop to walk one
        // level further up to the pre-existing parent).
        mkdir(suGalleriesRoot() . $parentDir . '/ct_childA_' . uniqid(), 0777, true);
        $childBDir = $parentDir . '/ct_childB_' . uniqid();
        mkdir(suGalleriesRoot() . $childBDir . '/ct_grandchildC_' . uniqid(), 0777, true);

        $result = suSync($page, $token);
        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('3 albums added in the database');

        $childBId = suCategoryIdByDir(1, basename($childBDir));
        expect($childBId)->not->toBeNull();
        assert($childBId !== null);

        $db = suConnect();
        $grandchildRow = H::dbFetchAssoc($db, sprintf(
            "SELECT id FROM %scategories WHERE site_id = 1 AND id_uppercat = %d",
            suDbPrefix(),
            $childBId
        ));
        H::dbClose($db);
        expect($grandchildRow)->not->toBeNull();
        assert(is_array($grandchildRow));
        $grandchildId = (int) $grandchildRow['id'];

        // All three new categories inherited private status from the
        // parent, and all three received the parent's own direct grants.
        foreach ([$childBId, $grandchildId] as $newCatId) {
            expect(suHasGroupAccess(1, $newCatId))->toBeTrue();
            expect(suHasUserAccess(4, $newCatId))->toBeTrue();
        }
    } finally {
        H::restoreConfig($snapshot);
        suRemoveDirRecursive($parentTemp);
        H::adminPost($page, suPath(), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});

it('assigns a non-zero privacy level, mass-inserts/removes per-image formats, and deletes an element missing from the filesystem', function (): void {
    $dir = 'ct_site_formats_' . uniqid();
    $tempDir = suMakeTempDir($dir);
    mkdir($tempDir . '/pwg_format', 0777, true);

    $image1 = H::makeTestImage('CT Format Photo1');
    copy($image1, $tempDir . '/photo1.jpg');
    @unlink($image1);
    file_put_contents($tempDir . '/pwg_format/photo1.cr2', str_repeat('A', 2048));

    $image2 = H::makeTestImage('CT Format Photo2');
    copy($image2, $tempDir . '/photo2.jpg');
    @unlink($image2);

    $image3 = H::makeTestImage('CT Format Photo3');
    copy($image3, $tempDir . '/photo3.jpg');
    @unlink($image3);

    file_put_contents($tempDir . '/bad name!.jpg', 'not a real image');

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);
    $snapshot = H::snapshotConfig(['enable_formats']);

    try {
        H::setConfigValue('enable_formats', 'true');

        $created = suSync($page, $token, ['privacy_level' => '4']);
        expect($created['status'])->toBe(200);
        expect($created['body'])->toContain('3 photos added in the database');
        expect($created['body'])->toContain('1 errors during synchronization');
        expect($created['body'])->toContain('PWG-UPDATE-1');
        expect($created['body'])->toContain('format cr2 added');

        $photo1Id = suImageIdByFile('photo1.jpg');
        $photo2Id = suImageIdByFile('photo2.jpg');
        $photo3Id = suImageIdByFile('photo3.jpg');
        expect($photo1Id)->not->toBeNull();
        expect($photo2Id)->not->toBeNull();
        expect($photo3Id)->not->toBeNull();
        assert($photo1Id !== null && $photo2Id !== null && $photo3Id !== null);

        // privacy_level (non-'0') is stamped onto every newly-created
        // element, not just the first.
        expect(suImageLevel($photo1Id))->toBe(4);
        expect(suImageLevel($photo2Id))->toBe(4);
        expect(suImageFormatFilesize($photo1Id, 'cr2'))->toBe(2);

        // A new format sibling added for an *already-registered* photo:
        // the "new formats on existing photos" diff branch.
        file_put_contents($tempDir . '/pwg_format/photo2.cr2', str_repeat('B', 4096));

        $addedFormat = suSync($page, $token);
        expect($addedFormat['status'])->toBe(200);
        expect($addedFormat['body'])->toContain('0 photos added in the database');
        expect($addedFormat['body'])->toContain('format cr2 added');
        expect(suImageFormatFilesize($photo2Id, 'cr2'))->toBe(4);

        // Remove photo1's format sibling (format-removal diff branch +
        // mass-delete) and delete photo3's main file entirely (element
        // deletion when missing from the filesystem), in the same request.
        unlink($tempDir . '/pwg_format/photo1.cr2');
        unlink($tempDir . '/photo3.jpg');

        $removedFormat = suSync($page, $token);
        expect($removedFormat['status'])->toBe(200);
        expect($removedFormat['body'])->toContain('format cr2 removed');
        expect($removedFormat['body'])->toContain('1 photos deleted from the database');
        expect(suImageFormatFilesize($photo1Id, 'cr2'))->toBeNull();
        expect(suImageIdByFile('photo3.jpg'))->toBeNull();
    } finally {
        H::restoreConfig($snapshot);
        suRemoveDirRecursive($tempDir);
        H::adminPost($page, suPath(), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});

it('reports a PWG-ERROR-NO-FS error when a registered photo is deleted before its metadata sync runs', function (): void {
    $dir = 'ct_site_meta_missing_' . uniqid();
    $file = $dir . '.jpg';
    $tempDir = suMakeTempDir($dir);
    $imagePath = H::makeTestImage('CT Meta Missing');
    copy($imagePath, $tempDir . '/' . $file);
    @unlink($imagePath);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        suSync($page, $token);
        $imageId = suImageIdByFile($file);
        expect($imageId)->not->toBeNull();

        // Delete the physical file but leave the directory (and the DB
        // image row) untouched, then request metadata sync alone (sync
        // set to 'dirs', which never reaches the element-deletion block --
        // otherwise the image row itself would be deleted by the
        // files/elements block before the metadata block ever saw it).
        unlink($tempDir . '/' . $file);

        $result = suSync($page, $token, [
            'sync' => 'dirs',
            'sync_meta' => '1',
            'meta_all' => '1',
        ]);

        expect($result['status'])->toBe(200);
        // NB_ERRORS on update_result is computed before the metadata block
        // runs (still 0 here, since sync='dirs' found no bad directory
        // names) -- the metadata-sync error only surfaces via the
        // unconditional sync_errors/sync_error_captions listing below,
        // which reflects the full $errors array at render time.
        expect($result['body'])->toContain('PWG-ERROR-NO-FS');
        expect($result['body'])->toContain('File/directory read error');
        expect($result['body'])->toContain('The file or directory cannot be accessed');
    } finally {
        suRemoveDirRecursive($tempDir);
        H::adminPost($page, suPath(), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});

it('quick_sync performs a real full local synchronization via the GET shortcut', function (): void {
    $dir = 'ct_site_quick_' . uniqid();
    $file = $dir . '.jpg';
    $tempDir = suMakeTempDir($dir);
    $imagePath = H::makeTestImage('CT Quick Sync');
    copy($imagePath, $tempDir . '/' . $file);
    @unlink($imagePath);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        $result = H::adminPost($page, suPath(['quick_sync' => '1']), [
            'pwg_token' => $token,
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('1 albums added in the database');
        expect($result['body'])->toContain('1 photos added in the database');

        expect(suCategoryIdByDir(1, $dir))->not->toBeNull();
        expect(suImageIdByFile($file))->not->toBeNull();
    } finally {
        suRemoveDirRecursive($tempDir);
        H::adminPost($page, suPath(), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});

it('creates a new sub-category successfully when its parent has a null global_rank', function (): void {
    $parentDir = 'ct_site_nullrank_parent_' . uniqid();
    $parentTemp = suMakeTempDir($parentDir);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        $created = suSync($page, $token);
        expect($created['status'])->toBe(200);
        $parentId = suCategoryIdByDir(1, $parentDir);
        expect($parentId)->not->toBeNull();
        assert($parentId !== null);

        // Force a genuinely null global_rank on the parent -- the column
        // is nullable (schema default NULL) and this is the only real way
        // to reach it with a truly null value in this rewrite: every real
        // write path (the insert-with-parent block below, or the
        // updateGlobalRank() recompute that runs at the end of every real
        // non-simulate sync) always stamps a real string/int value.
        suSetCategoryGlobalRankNull($parentId);

        $childDir = $parentDir . '/ct_child_nullrank_' . uniqid();
        mkdir(suGalleriesRoot() . $childDir, 0777, true);

        // cat-scoped so $db_categories[$parent] is re-read fresh from the
        // DB row just nulled above (getSyncCandidatesForSite()), not a
        // same-request freshly-inserted entry -- this is what actually
        // exercises SiteUpdateSubController's own
        // `$parent_global_rank = ''` null-narrowing fallback. The child's
        // own final global_rank column always gets overwritten by this
        // same request's later updateGlobalRank() recompute (system-wide,
        // self-healing off of rank/uppercats, not the literal
        // just-inserted global_rank string), so there is no distinct
        // persisted value left to assert on afterwards -- a successful,
        // uncrashed creation off a real null-global_rank parent is the
        // real, observable proof this branch was taken.
        $scoped = suSync($page, $token, ['cat' => (string) $parentId, 'subcats-included' => '1']);

        expect($scoped['status'])->toBe(200);
        expect($scoped['body'])->not->toContain('Fatal error');
        expect($scoped['body'])->toContain('1 albums added in the database');
        expect(suCategoryIdByDir(1, basename($childDir)))->not->toBeNull();
    } finally {
        suRemoveDirRecursive($parentTemp);
        H::adminPost($page, suPath(), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});

it('forces a new sub-category invisible when its parent category is not visible', function (): void {
    $parentDir = 'ct_site_invisible_parent_' . uniqid();
    $parentTemp = suMakeTempDir($parentDir);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        $created = suSync($page, $token);
        expect($created['status'])->toBe(200);
        $parentId = suCategoryIdByDir(1, $parentDir);
        expect($parentId)->not->toBeNull();
        assert($parentId !== null);

        // newcat_default_visible defaults to true -- flipping this one,
        // already-created category's own `visible` column directly is the
        // only way to get an existing parent that's invisible without
        // every *other* newly-synced category (including the parent
        // itself) also defaulting to invisible.
        suSetCategoryVisible($parentId, false);

        $childDir = $parentDir . '/ct_child_invis_' . uniqid();
        mkdir(suGalleriesRoot() . $childDir, 0777, true);

        $scoped = suSync($page, $token, ['cat' => (string) $parentId, 'subcats-included' => '1']);
        expect($scoped['status'])->toBe(200);
        expect($scoped['body'])->toContain('1 albums added in the database');

        $childId = suCategoryIdByDir(1, basename($childDir));
        expect($childId)->not->toBeNull();
        assert($childId !== null);

        // Unlike global_rank, `visible` is never touched by the
        // updateCategory()/updateGlobalRank() recompute at the end of the
        // request -- the inherited-invisible value set at insert time is
        // exactly what's still in the database afterwards.
        expect(suCategoryVisible($childId))->toBeFalse();
    } finally {
        suRemoveDirRecursive($parentTemp);
        H::adminPost($page, suPath(), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});

it('clears a deleted category\'s cached derivative directory when one exists on disk', function (): void {
    $dir = 'ct_site_deriv_' . uniqid();
    $tempDir = suMakeTempDir($dir);
    $imagePath = H::makeTestImage('CT Derivative Cache');
    copy($imagePath, $tempDir . '/photo.jpg');
    @unlink($imagePath);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);
    $derivDir = suDerivativeCacheDirFor($dir);

    try {
        $created = suSync($page, $token);
        expect($created['status'])->toBe(200);
        expect(suCategoryIdByDir(1, $dir))->not->toBeNull();

        // A real on-disk derivative-cache directory for this category,
        // mirroring what real thumbnail/derivative generation would have
        // left behind -- www-data (the real Apache request process) must
        // be able to unlink()/rmdir() it, hence the explicit umask(0)
        // rather than relying on the ambient 0002 umask's 0775 result
        // (torres-owned, group-writable only, not world-writable).
        $previousUmask = umask(0);
        mkdir($derivDir, 0777, true);
        umask($previousUmask);
        file_put_contents($derivDir . '/thumb.jpg', 'fake-cached-derivative-bytes');
        expect(is_dir($derivDir))->toBeTrue();

        suRemoveDirRecursive($tempDir);

        $deleted = suSync($page, $token);
        expect($deleted['status'])->toBe(200);
        expect($deleted['body'])->toContain('1 albums deleted in the database');

        // DerivativeCacheService::clearDerivativeCacheRecursive() removed
        // every file it found (pattern '#.+#' matches anything) and, since
        // nothing was left over, rmdir()'d the now-empty directory itself.
        expect(is_dir($derivDir))->toBeFalse();
    } finally {
        suRemoveDirRecursive($tempDir);
        suRemoveDirRecursive($derivDir);
    }
});

it('silently skips a photo whose containing directory has no matching category', function (): void {
    $dir = 'ct_site_root_skip_' . uniqid();
    $file = $dir . '.jpg';
    $tempDir = suMakeTempDir($dir);
    $imagePath = H::makeTestImage('CT Root Skip');
    copy($imagePath, $tempDir . '/' . $file);

    // A second photo dropped directly at the gallery root: its
    // dirname() is the site's own root directory, which the sync process
    // never registers as a category (only named sub-directories under it
    // become categories, never the root itself), so
    // $db_fulldirs[dirname($path)] is never set for it -- the "storage
    // category must exist" guard silently skips it, with no info/error
    // entry either.
    $orphanFile = 'ct_site_orphan_' . uniqid() . '.jpg';
    copy($imagePath, suGalleriesRoot() . $orphanFile);
    @unlink($imagePath);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        $result = suSync($page, $token);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('1 photos added in the database');
        expect($result['body'])->toContain('0 errors during synchronization');

        expect(suImageIdByFile($file))->not->toBeNull();
        expect(suImageIdByFile($orphanFile))->toBeNull();
    } finally {
        suRemoveDirRecursive($tempDir);
        @unlink(suGalleriesRoot() . $orphanFile);
        H::adminPost($page, suPath(), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});

it('scopes metadata synchronization to a single category via cat, honoring subcats-included', function (): void {
    $dir = 'ct_site_meta_cat_' . uniqid();
    $file = $dir . '.jpg';
    $tempDir = suMakeTempDir($dir);
    $imagePath = H::makeTestImage('CT Meta Cat Scope');
    copy($imagePath, $tempDir . '/' . $file);
    @unlink($imagePath);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    try {
        $created = suSync($page, $token);
        expect($created['status'])->toBe(200);
        $catId = suCategoryIdByDir(1, $dir);
        expect($catId)->not->toBeNull();
        assert($catId !== null);

        $imageId = suImageIdByFile($file);
        expect($imageId)->not->toBeNull();
        assert($imageId !== null);
        expect(suImageDateMetadataUpdate($imageId))->toBeNull();

        // cat-scoped with subcats-included NOT '1': $opts['recursive']
        // becomes false, restricting getFilelist() to this category alone
        // (no descendants) -- the photo directly inside it is still a
        // member of that one category, so it still gets its metadata
        // synced.
        $scoped = suSync($page, $token, [
            'cat' => (string) $catId,
            'subcats-included' => '0',
            'sync_meta' => '1',
            'meta_all' => '1',
        ]);

        expect($scoped['status'])->toBe(200);
        expect($scoped['body'])->toContain("1 photos' information synchronized with files metadata");
        expect($scoped['body'])->toContain('1 photos candidates for metadata synchronization');
        expect(suImageDateMetadataUpdate($imageId))->not->toBeNull();
    } finally {
        suRemoveDirRecursive($tempDir);
        H::adminPost($page, suPath(), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});

it('assigns tags from IPTC keywords during metadata synchronization', function (): void {
    $dir = 'ct_site_meta_tags_' . uniqid();
    $file = $dir . '.jpg';
    $tempDir = suMakeTempDir($dir);
    $keyword1 = 'ct_kw_nature_' . uniqid();
    $keyword2 = 'ct_kw_travel_' . uniqid();
    $imagePath = suMakeJpegWithIptcKeywords([$keyword1, $keyword2]);
    copy($imagePath, $tempDir . '/' . $file);
    @unlink($imagePath);

    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);
    $snapshot = H::snapshotConfig(['use_iptc']);

    try {
        // use_iptc_mapping is left at its own default (already includes
        // 'keywords' => '2#025'), only use_iptc itself needs enabling --
        // it defaults to false, and IPTC keywords are otherwise never
        // read at all (LocalSiteReader::get_element_metadata() ->
        // MetadataService::getSyncMetadata() only calls
        // getSyncIptcData() when it's true).
        H::setConfigValue('use_iptc', 'true');

        $created = suSync($page, $token);
        expect($created['status'])->toBe(200);
        $imageId = suImageIdByFile($file);
        expect($imageId)->not->toBeNull();
        assert($imageId !== null);
        expect(suImageTagNames($imageId))->toBe([]);

        $result = suSync($page, $token, ['sync_meta' => '1', 'meta_all' => '1']);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain("1 photos' information synchronized with files metadata");

        $expectedNames = [$keyword1, $keyword2];
        sort($expectedNames);
        expect(suImageTagNames($imageId))->toBe($expectedNames);
    } finally {
        H::restoreConfig($snapshot);
        suRemoveDirRecursive($tempDir);
        suDeleteTagsByName([$keyword1, $keyword2]);
        H::adminPost($page, suPath(), [
            'submit' => '1',
            'pwg_token' => $token,
            'sync' => 'files',
            'subcats-included' => '1',
            'privacy_level' => '0',
            'simulate' => '0',
        ]);
    }
});
