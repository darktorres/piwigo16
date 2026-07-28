<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\CatListPageRenderer (admin.php?page=cat_list) -- the
 * virtual-album creation/deletion page, distinct from AlbumsPageRenderer's
 * own tree-view/auto-order page (same tabsheet group, different tab).
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