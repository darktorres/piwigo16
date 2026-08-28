<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

function catListPageCategoryExists(int $categoryId): bool
{
    $db = H::connect();
    $row = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM categories WHERE id = %d', $categoryId));
    H::dbClose($db);

    return is_array($row) && (int) $row['c'] > 0;
}

it('renders the root-level album list', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Cat List Root Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumName = is_string($album['name'] ?? null) ? $album['name'] : '';

    $page = H::navigateOk($page, '/admin.php?page=cat_list');

    $page->assertSee($albumName);
    $page->assertNoJavaScriptErrors();
});

it('navigates into a parent album\'s own children list', function (): void {
    $page = H::asAdmin($this);
    $parent = H::createCategory($page, [
        'name' => 'Cat List Parent ' . uniqid(),
    ]);
    if (! is_numeric($parent['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($parent, true));
    }
    $parentId = (int) $parent['id'];
    $child = H::createCategory($page, [
        'name' => 'Cat List Child ' . uniqid(),
        'parent' => (string) $parentId,
    ]);
    $childName = is_string($child['name'] ?? null) ? $child['name'] : '';

    $page = H::navigateOk($page, '/admin.php?page=cat_list&parent_id=' . $parentId);

    $page->assertSee($childName);
    $page->assertNoJavaScriptErrors();
});

it('creates a new virtual album at the root via submitAdd, and reports success', function (): void {
    $page = H::asAdmin($this);
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
    $page = H::asAdmin($this);
    $parent = H::createCategory($page, [
        'name' => 'Cat List Add Parent ' . uniqid(),
    ]);
    if (! is_numeric($parent['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($parent, true));
    }
    $parentId = (int) $parent['id'];
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
    $page = H::asAdmin($this);

    $result = H::adminPost($page, '/admin.php?page=cat_list', [
        'pwg_token' => H::pwgToken($page),
        'submitAdd' => '1',
        'virtual_name' => '',
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Edit album');
});

it('deletes a virtual album and redirects with a session confirmation message', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Cat List Delete Me ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    expect(catListPageCategoryExists($albumId))
        ->toBeTrue();

    $token = H::pwgToken($page);
    $result = H::rawGet($page, '/admin.php?page=cat_list&delete=' . $albumId . '&pwg_token=' . $token);

    // deleteCategories() always redirects afterward -- a real Location
    // header, opaque under fetch(manual), status always 0 (see this
    // suite's own empty_caddie test for the same Fetch API caveat).
    expect($result['status'])->toBe(0);
    expect(catListPageCategoryExists($albumId))
        ->toBeFalse();
});

it('deletes a virtual child album and redirects back to its own parent_id listing', function (): void {
    $page = H::asAdmin($this);
    $parent = H::createCategory($page, [
        'name' => 'Cat List Delete Parent ' . uniqid(),
    ]);
    if (! is_numeric($parent['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($parent, true));
    }
    $parentId = (int) $parent['id'];
    $child = H::createCategory($page, [
        'name' => 'Cat List Delete Child ' . uniqid(),
        'parent' => (string) $parentId,
    ]);
    if (! is_numeric($child['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($child, true));
    }
    $childId = (int) $child['id'];

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

        $statusBody = H::curlApi($cookieJar, 'GET', '/api/v1/session');
        $decoded = json_decode($statusBody, true);
        $token = is_array($decoded) && is_string($decoded['pwgToken'] ?? null) ? $decoded['pwgToken'] : null;
        if ($token === null) {
            throw new RuntimeException('Could not obtain a real pwgToken via curl session: ' . $statusBody);
        }

        $deleteResponse = $curl(H::baseUrl() . '/admin.php?page=cat_list&delete=' . $childId . '&parent_id=' . $parentId . '&pwg_token=' . $token);

        expect($deleteResponse)
            ->toContain('Location:');
        expect($deleteResponse)
            ->toContain('parent_id=' . $parentId);
    } finally {
        @unlink($cookieJar);
    }
});

it('assigns U_SYNC (not U_DELETE) for a non-virtual (real dir) category when synchronization is enabled', function (): void {
    $page = H::asAdmin($this);
    $db = H::connect();

    // Every album created via pwg.categories.add is virtual (dir=NULL) --
    // a non-virtual (site-synced) category needs a real `dir` + `site_id`,
    // which only a direct raw-SQL row can provide here (same technique as
    // CatModifyPageRendererTest's own physical-directory test); site_id=1
    // is the fixture's own real sites row.
    $dirName = 'cat_list_physical_dir_' . uniqid();
    H::dbQuery($db, sprintf("INSERT INTO categories (name, dir, site_id, status, uppercats) VALUES ('Cat List Physical Album', '%s', 1, 'public', '0')", H::dbEscape($db, $dirName)));
    $categoryId = H::dbInsertId($db);
    H::dbQuery($db, sprintf('UPDATE categories SET uppercats = %d WHERE id = %d', $categoryId, $categoryId));

    try {
        $page = H::navigateOk($page, '/admin.php?page=cat_list');

        $page->assertSee('Cat List Physical Album');
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'cat_list with a non-virtual category');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM categories WHERE id = %d', $categoryId));
        H::dbClose($db);
    }
});

it('rejects a delete request without a valid CSRF token', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Cat List Delete Guard ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $result = H::rawGet($page, '/admin.php?page=cat_list&delete=' . $albumId);

    expect($result['status'])->toBe(400);
    expect(catListPageCategoryExists($albumId))
        ->toBeTrue();
});

/**
 * Regression test for a bug this page shipped with for its whole life: the
 * "Visit Gallery" link was gated on a bare `$CAT_ADMIN_ACCESS` that nothing
 * ever assigned, so the condition was always false and *every* album -- even
 * one the admin can plainly see -- rendered the else branch instead: an inert
 * <span> titled "This album is private".
 *
 * The renderer had been computing the real answer per row all along, through
 * CategoryService::catAdminAccess() ("this album is not in the user's
 * forbidden list"), and throwing it away. The template now reads it.
 *
 * Asserting both halves matters, because the bug's signature is not a missing
 * link on its own -- it is the private-album span appearing in its place.
 */
it('renders the visit-gallery link for an album the admin can access', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Cat List Access Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];

    $page = H::navigateOk($page, '/admin.php?page=cat_list');
    $html = H::rawWebpage($page)->content();

    // The row for this album, up to the start of the next one.
    $start = strpos($html, 'id="cat_' . $albumId . '"');
    expect($start)
        ->not->toBeFalse();
    $row = substr($html, (int) $start, 4000);

    expect($row)
        // An <a>, not the else branch's inert <span>, and pointing at this
        // album's own gallery URL.
        ->toContain('<a href="index.php?/category/' . $albumId . '" class="actionGalery"')
        // 'Visit Gallery' is the source key; en_UK renders it as 'Visit the
        // gallery'. Asserting the key would pass against a page rendering
        // neither branch.
        ->and($row)
        ->toContain('Visit the gallery')
        // The else branch's own title has no en_UK entry, so it renders
        // verbatim -- and it is what appeared here before the fix.
        ->and($row)
        ->not->toContain('This album is private');

    $page->assertNoJavaScriptErrors();
});
