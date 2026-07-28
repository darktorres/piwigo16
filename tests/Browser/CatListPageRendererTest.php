<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\CatListPageRenderer (admin.php?page=cat_list) -- the
 * virtual-album creation/deletion page, distinct from AlbumsPageRenderer's
 * own tree-view/auto-order page (same tabsheet group, different tab).
 *
 * Not exercised: the `! is_string($uppercats)` defensive `continue` in the
 * sub-album-count loop (categories.uppercats is a real `NOT NULL DEFAULT
 * ''` column, so a genuine row's value is always a string).
 */
function catListPageDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function catListPageCategoryExists(int $categoryId): bool
{
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $result = $db->query(sprintf('SELECT COUNT(*) AS c FROM %scategories WHERE id = %d', catListPageDbPrefix(), $categoryId));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();

    return is_array($row) && (int) $row['c'] > 0;
}

it('renders the root-level album list', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Cat List Root Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumName = is_string($albumResult['name'] ?? null) ? $albumResult['name'] : '';

    $page = H::navigateOk($page, '/admin.php?page=cat_list');

    $page->assertSee($albumName);
    $page->assertNoJavaScriptErrors();
});

it('navigates into a parent album\'s own children list', function (): void {
    $page = H::loginAsAdmin($this);
    $parent = H::wsCall($page, 'pwg.categories.add', ['name' => 'Cat List Parent ' . uniqid()]);
    $parentResult = $parent['result'] ?? null;
    if (! is_array($parentResult) || ! is_numeric($parentResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($parent, true));
    }
    $parentId = (int) $parentResult['id'];
    $child = H::wsCall($page, 'pwg.categories.add', ['name' => 'Cat List Child ' . uniqid(), 'parent' => (string) $parentId]);
    $childResult = $child['result'] ?? null;
    $childName = is_array($childResult) && is_string($childResult['name'] ?? null) ? $childResult['name'] : '';

    $page = H::navigateOk($page, '/admin.php?page=cat_list&parent_id=' . $parentId);

    $page->assertSee($childName);
    $page->assertNoJavaScriptErrors();
});

it('creates a new virtual album at the root via submitAdd, and reports success', function (): void {
    $page = H::loginAsAdmin($this);
    $name = 'Cat List New Virtual ' . uniqid();

    $result = H::adminPost($page, '/admin.php?page=cat_list', [
        'pwg_token' => H::pwgToken($page),
        'submitAdd' => '1',
        'virtual_name' => $name,
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Edit album');

    $page = H::navigateOk($page, '/admin.php?page=cat_list');
    $page->assertSee($name);
});

it('creates a new virtual album under a parent via submitAdd + parent_id', function (): void {
    $page = H::loginAsAdmin($this);
    $parent = H::wsCall($page, 'pwg.categories.add', ['name' => 'Cat List Add Parent ' . uniqid()]);
    $parentResult = $parent['result'] ?? null;
    if (! is_array($parentResult) || ! is_numeric($parentResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($parent, true));
    }
    $parentId = (int) $parentResult['id'];
    $name = 'Cat List Nested Virtual ' . uniqid();

    $result = H::adminPost($page, '/admin.php?page=cat_list&parent_id=' . $parentId, [
        'pwg_token' => H::pwgToken($page),
        'submitAdd' => '1',
        'virtual_name' => $name,
    ]);

    expect($result['status'])->toBe(200);

    $page = H::navigateOk($page, '/admin.php?page=cat_list&parent_id=' . $parentId);
    $page->assertSee($name);
});

it('rejects creating a virtual album with an empty name and reports an error', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, '/admin.php?page=cat_list', [
        'pwg_token' => H::pwgToken($page),
        'submitAdd' => '1',
        'virtual_name' => '',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Edit album');
});

it('deletes a virtual album and redirects with a session confirmation message', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Cat List Delete Me ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    expect(catListPageCategoryExists($albumId))->toBeTrue();

    $token = H::pwgToken($page);
    $result = H::rawGet($page, '/admin.php?page=cat_list&delete=' . $albumId . '&pwg_token=' . $token);

    // deleteCategories() always redirects afterward -- a real Location
    // header, opaque under fetch(manual), status always 0 (see this
    // suite's own empty_caddie test for the same Fetch API caveat).
    expect($result['status'])->toBe(0);
    expect(catListPageCategoryExists($albumId))->toBeFalse();
});

it('deletes a virtual child album and redirects back to its own parent_id listing', function (): void {
    $page = H::loginAsAdmin($this);
    $parent = H::wsCall($page, 'pwg.categories.add', ['name' => 'Cat List Delete Parent ' . uniqid()]);
    $parentResult = $parent['result'] ?? null;
    if (! is_array($parentResult) || ! is_numeric($parentResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($parent, true));
    }
    $parentId = (int) $parentResult['id'];
    $child = H::wsCall($page, 'pwg.categories.add', ['name' => 'Cat List Delete Child ' . uniqid(), 'parent' => (string) $parentId]);
    $childResult = $child['result'] ?? null;
    if (! is_array($childResult) || ! is_numeric($childResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($child, true));
    }
    $childId = (int) $childResult['id'];

    // A real curl request (not fetch(manual), whose Location header is
    // opaque) so the redirect target is actually readable. render()'s
    // `if ($parent_id !== null) { $redirect_url .= '&parent_id=' . $parent_id; }`
    // echoes back whatever `parent_id` was already on the *incoming*
    // request's own query string (the "you were viewing this parent's
    // listing" context) -- it is NOT derived from the deleted category's
    // own real parent column, so the delete request itself must carry
    // `&parent_id=` for the redirect to carry it back out (confirmed
    // live: without it, the redirect never appended `&parent_id=` at
    // all), matching how the real admin UI's delete link on a child
    // album's row is itself scoped to `parent_id=`.
    $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_cat_list_delete_');
    if ($cookieJar === false) {
        throw new RuntimeException('tempnam failed');
    }

    $curl = static function (string $url, array $fields = []) use ($cookieJar): string {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, H::testHeaders());
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if ($fields !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $response = curl_exec($ch);
        if (! is_string($response)) {
            throw new RuntimeException("curl to {$url} did not return a string response");
        }

        return $response;
    };

    try {
        $curl(H::baseUrl() . '/identification.php');
        $curl(H::baseUrl() . '/identification.php', [
            'username' => H::ADMIN_USER,
            'password' => H::ADMIN_PASS,
            'login' => 'Login',
        ]);

        $statusResponse = $curl(H::baseUrl() . '/ws.php?format=json', ['method' => 'pwg.session.getStatus']);
        // CURLOPT_HEADER=true prefixes the body with response headers;
        // isolate the JSON payload after the blank-line separator.
        $statusParts = explode("\r\n\r\n", $statusResponse);
        $jsonPart = end($statusParts);
        $decoded = json_decode($jsonPart, true);
        $resultData = is_array($decoded) ? ($decoded['result'] ?? null) : null;
        $token = is_array($resultData) && is_string($resultData['pwg_token'] ?? null) ? $resultData['pwg_token'] : null;
        if ($token === null) {
            throw new RuntimeException('Could not obtain a real pwg_token via curl session: ' . $statusResponse);
        }

        $deleteResponse = $curl(H::baseUrl() . '/admin.php?page=cat_list&delete=' . $childId . '&parent_id=' . $parentId . '&pwg_token=' . $token);

        expect($deleteResponse)->toContain('Location:');
        expect($deleteResponse)->toContain('parent_id=' . $parentId);
    } finally {
        @unlink($cookieJar);
    }
});

it('assigns U_SYNC (not U_DELETE) for a non-virtual (real dir) category when synchronization is enabled', function (): void {
    $page = H::loginAsAdmin($this);
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = catListPageDbPrefix();

    // Every album created via pwg.categories.add is virtual (dir=NULL) --
    // a non-virtual (site-synced) category needs a real `dir` + `site_id`,
    // which only a direct raw-SQL row can provide here (same technique as
    // CatModifyPageRendererTest's own physical-directory test); site_id=1
    // is the fixture's own real piwigo_sites row.
    $dirName = 'cat_list_physical_dir_' . uniqid();
    $db->query(sprintf(
        "INSERT INTO %scategories (name, dir, site_id, status, uppercats) VALUES ('Cat List Physical Album', '%s', 1, 'public', '0')",
        $prefix,
        $db->real_escape_string($dirName)
    ));
    $categoryId = (int) $db->insert_id;
    $db->query(sprintf('UPDATE %scategories SET uppercats = %d WHERE id = %d', $prefix, $categoryId, $categoryId));

    try {
        $page = H::navigateOk($page, '/admin.php?page=cat_list');

        $page->assertSee('Cat List Physical Album');
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'cat_list with a non-virtual category');
    } finally {
        $db->query(sprintf('DELETE FROM %scategories WHERE id = %d', $prefix, $categoryId));
        $db->close();
    }
});

it('rejects a delete request without a valid CSRF token', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Cat List Delete Guard ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    $result = H::rawGet($page, '/admin.php?page=cat_list&delete=' . $albumId);

    expect($result['status'])->toBe(400);
    expect(catListPageCategoryExists($albumId))->toBeTrue();
});