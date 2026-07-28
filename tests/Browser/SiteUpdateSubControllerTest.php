<?php

declare(strict_types=1);

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
 * Deliberately light on: the private-category permission-inheritance
 * branch (`inheritance_by_default`), `enable_formats`, a
 * `subcats-included=0` scoped-to-one-category sync, and
 * `meta_empty_overrides` -- each is a real but narrow combinatorial
 * branch of an already very large handler; render + guard-clause + the
 * core create/simulate/delete/metadata/quick_sync flows cover the large
 * majority of this file's real gap without the disproportionate setup
 * those would need (matching AdminConfigurationTest's own documented
 * scoping precedent).
 */
function suDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function suConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function suCategoryIdByDir(int $siteId, string $dir): ?int
{
    $db = suConnect();
    $result = $db->query(sprintf(
        "SELECT id FROM %scategories WHERE site_id = %d AND dir = '%s'",
        suDbPrefix(),
        $siteId,
        $db->real_escape_string($dir)
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
}

function suImageIdByFile(string $file): ?int
{
    $db = suConnect();
    $result = $db->query(sprintf(
        "SELECT id FROM %simages WHERE file = '%s'",
        suDbPrefix(),
        $db->real_escape_string($file)
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
}

function suImageDateMetadataUpdate(int $imageId): ?string
{
    $db = suConnect();
    $result = $db->query(sprintf(
        'SELECT date_metadata_update FROM %simages WHERE id = %d',
        suDbPrefix(),
        $imageId
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    $value = is_array($row) ? ($row['date_metadata_update'] ?? null) : null;

    return is_string($value) ? $value : null;
}

function suInsertRemoteSite(string $url): int
{
    $db = suConnect();
    $db->query(sprintf(
        "INSERT INTO %ssites (galleries_url) VALUES ('%s')",
        suDbPrefix(),
        $db->real_escape_string($url)
    ));
    $id = (int) $db->insert_id;
    $db->close();

    return $id;
}

function suDeleteSite(int $id): void
{
    $db = suConnect();
    $db->query(sprintf('DELETE FROM %ssites WHERE id = %d', suDbPrefix(), $id));
    $db->close();
}

function suGalleriesRoot(): string
{
    return dirname(__DIR__, 2) . '/galleries/';
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
