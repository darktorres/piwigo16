<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * @return array<int, string> category id => name, ordered by rank ASC
 */
function albumsPageChildrenOrderedByRank(int $parentId): array
{
    $db = H::connect();
    // Root categories store id_uppercat as a real SQL NULL, never 0 --
    // `id_uppercat = 0` matches nothing for them, confirmed live against
    // the real fixture schema.
    $whereClause = $parentId === 0 ? 'id_uppercat IS NULL' : sprintf('id_uppercat = %d', $parentId);
    // `rank` is a reserved word on both platforms (MySQL 8.0.2+'s window
    // functions, Postgres always) -- backtick/double-quote per platform.
    $rankIdentifier = $db instanceof mysqli ? '`rank`' : '"rank"';
    $rows = H::dbFetchAll($db, sprintf('SELECT id, name FROM categories WHERE %s ORDER BY %s ASC', $whereClause, $rankIdentifier));
    $ordered = [];
    foreach ($rows as $row) {
        $ordered[(int) $row['id']] = (string) $row['name'];
    }
    H::dbClose($db);

    return $ordered;
}

it('renders the album tree with real nested albums', function (): void {
    $page = H::loginAsAdmin($this);
    $parent = H::createCategory($page, [
        'name' => 'Albums Page Parent ' . uniqid(),
    ]);
    if (! is_numeric($parent['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($parent, true));
    }
    $parentId = (int) $parent['id'];
    $parentName = is_string($parent['name'] ?? null) ? $parent['name'] : '';

    $page = H::navigateOk($page, '/admin.php?page=albums');

    $page->assertSee($parentName);
    $page->assertNoJavaScriptErrors();
});

it('reorders root-level albums alphabetically ascending via simpleAutoOrder', function (): void {
    $page = H::loginAsAdmin($this);
    $suffix = uniqid();
    $zebra = H::createCategory($page, [
        'name' => 'Zebra Auto Order ' . $suffix,
    ]);
    $apple = H::createCategory($page, [
        'name' => 'Apple Auto Order ' . $suffix,
    ]);
    if (! is_numeric($zebra['id'] ?? null) || ! is_numeric($apple['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return numeric ids');
    }
    $zebraId = (int) $zebra['id'];
    $appleId = (int) $apple['id'];

    $result = H::adminPost($page, '/admin.php?page=albums', [
        'pwg_token' => H::pwgToken($page),
        'simpleAutoOrder' => '1',
        'id' => '-1',
        'order' => 'name ASC',
    ]);
    expect($result['status'])->toBe(200);

    $ordered = albumsPageChildrenOrderedByRank(0);
    $orderedIds = array_keys($ordered);
    $applePos = array_search($appleId, $orderedIds, true);
    $zebraPos = array_search($zebraId, $orderedIds, true);
    if ($applePos === false || $zebraPos === false) {
        throw new RuntimeException('Expected both albums to be present in the ordered children list');
    }
    expect($applePos)
        ->toBeLessThan($zebraPos);
});

it('reorders a specific parent\'s direct children via simpleAutoOrder scoped by id', function (): void {
    $page = H::loginAsAdmin($this);
    $suffix = uniqid();
    $parent = H::createCategory($page, [
        'name' => 'Scoped Auto Order Parent ' . $suffix,
    ]);
    if (! is_numeric($parent['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id');
    }
    $parentId = (int) $parent['id'];

    $childB = H::createCategory($page, [
        'name' => 'B Child',
        'parent' => (string) $parentId,
    ]);
    $childA = H::createCategory($page, [
        'name' => 'A Child',
        'parent' => (string) $parentId,
    ]);
    if (! is_numeric($childB['id'] ?? null) || ! is_numeric($childA['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return numeric ids for children');
    }
    $childBId = (int) $childB['id'];
    $childAId = (int) $childA['id'];

    $result = H::adminPost($page, '/admin.php?page=albums', [
        'pwg_token' => H::pwgToken($page),
        'simpleAutoOrder' => '1',
        'id' => (string) $parentId,
        'order' => 'name ASC',
    ]);
    expect($result['status'])->toBe(200);

    $ordered = albumsPageChildrenOrderedByRank($parentId);
    expect(array_keys($ordered))
        ->toBe([$childAId, $childBId]);
});

it('reorders recursively (recursiveAutoOrder) including grandchildren', function (): void {
    $page = H::loginAsAdmin($this);
    $suffix = uniqid();
    $parent = H::createCategory($page, [
        'name' => 'Recursive Auto Order Parent ' . $suffix,
    ]);
    if (! is_numeric($parent['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id');
    }
    $parentId = (int) $parent['id'];

    $childB = H::createCategory($page, [
        'name' => 'B Recursive Child',
        'parent' => (string) $parentId,
    ]);
    $childA = H::createCategory($page, [
        'name' => 'A Recursive Child',
        'parent' => (string) $parentId,
    ]);
    if (! is_numeric($childB['id'] ?? null) || ! is_numeric($childA['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return numeric ids for children');
    }
    $childAId = (int) $childA['id'];

    // recursiveAutoOrder runs CategoryService::getSubcatIds() over the
    // whole tree under $post_id first (rather than simpleAutoOrder's own
    // direct-children-only id list) -- exercised here via a real 2-level
    // tree, distinct from the sibling simpleAutoOrder tests above.
    $result = H::adminPost($page, '/admin.php?page=albums', [
        'pwg_token' => H::pwgToken($page),
        'recursiveAutoOrder' => '1',
        'id' => (string) $parentId,
        'order' => 'name ASC',
    ]);
    expect($result['status'])->toBe(200);

    $ordered = albumsPageChildrenOrderedByRank($parentId);
    expect(array_keys($ordered)[0])->toBe($childAId);
});

it('sorts by date_creation via the date-based auto-order branch', function (): void {
    $page = H::loginAsAdmin($this);
    $suffix = uniqid();
    $parent = H::createCategory($page, [
        'name' => 'Date Auto Order Parent ' . $suffix,
    ]);
    if (! is_numeric($parent['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id');
    }
    $parentId = (int) $parent['id'];
    $child = H::createCategory($page, [
        'name' => 'Date Auto Order Child',
        'parent' => (string) $parentId,
    ]);
    if (! is_numeric($child['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id for the child');
    }

    // A date_* order routes through CategoryAdminService::getCategoriesRefDate()
    // (str_starts_with($order_by_field, 'date_')) instead of the plain
    // name-based sort every other test in this file uses -- exercised here
    // for real, even though neither child has any photo yet (ref_dates
    // falls back to null per category in that case, still a valid sort key).
    $result = H::adminPost($page, '/admin.php?page=albums', [
        'pwg_token' => H::pwgToken($page),
        'simpleAutoOrder' => '1',
        'id' => (string) $parentId,
        'order' => 'date_creation DESC',
    ]);

    expect($result['status'])->toBe(200);
});

it('rejects an invalid auto-order sort value', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, '/admin.php?page=albums', [
        'pwg_token' => H::pwgToken($page),
        'simpleAutoOrder' => '1',
        'id' => '-1',
        'order' => 'not-a-real-sort-order',
    ]);

    expect($result['body'])->toContain('Invalid sort order');
});
