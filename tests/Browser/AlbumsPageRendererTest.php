<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\AlbumsPageRenderer (admin.php?page=albums) -- the album
 * tree manager, plus its own POST-driven "auto order" action
 * (simpleAutoOrder/recursiveAutoOrder), which reorders a set of sibling
 * albums by name/date/natural order and persists the new rank via
 * CategoryService::saveCategoriesOrder().
 *
 * Not exercised: assocToOrderedTree()'s own "$cat['cat'] missing/not an
 * array" defensive `continue` -- its own comment documents this as an
 * algorithmic invariant of how $associatedTree is built just above (every
 * reachable node always gets a real 'cat' row), not a real reachable state.
 */
function albumsPageDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

/** @return array<int, string> category id => name, ordered by rank ASC */
function albumsPageChildrenOrderedByRank(int $parentId): array
{
    $db = H::connect();
    $prefix = albumsPageDbPrefix();
    // Root categories store id_uppercat as a real SQL NULL, never 0 --
    // `id_uppercat = 0` matches nothing for them, confirmed live against
    // the real fixture schema.
    $whereClause = $parentId === 0 ? 'id_uppercat IS NULL' : sprintf('id_uppercat = %d', $parentId);
    // `rank` is a reserved word on both platforms (MySQL 8.0.2+'s window
    // functions, Postgres always) -- backtick/double-quote per platform.
    $rankIdentifier = $db instanceof \mysqli ? '`rank`' : '"rank"';
    $rows = H::dbFetchAll($db, sprintf(
        'SELECT id, name FROM %scategories WHERE %s ORDER BY %s ASC',
        $prefix,
        $whereClause,
        $rankIdentifier
    ));
    $ordered = [];
    foreach ($rows as $row) {
        $ordered[(int) $row['id']] = (string) $row['name'];
    }
    H::dbClose($db);

    return $ordered;
}

it('renders the album tree with real nested albums', function (): void {
    $page = H::loginAsAdmin($this);
    $parent = H::wsCall($page, 'pwg.categories.add', ['name' => 'Albums Page Parent ' . uniqid()]);
    $parentResult = $parent['result'] ?? null;
    if (! is_array($parentResult) || ! is_numeric($parentResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($parent, true));
    }
    $parentId = (int) $parentResult['id'];
    $parentName = is_string($parentResult['name'] ?? null) ? $parentResult['name'] : '';

    $page = H::navigateOk($page, '/admin.php?page=albums');

    $page->assertSee($parentName);
    $page->assertNoJavaScriptErrors();
});

it('reorders root-level albums alphabetically ascending via simpleAutoOrder', function (): void {
    $page = H::loginAsAdmin($this);
    $suffix = uniqid();
    $zebra = H::wsCall($page, 'pwg.categories.add', ['name' => 'Zebra Auto Order ' . $suffix]);
    $apple = H::wsCall($page, 'pwg.categories.add', ['name' => 'Apple Auto Order ' . $suffix]);
    $zebraResult = $zebra['result'] ?? null;
    $appleResult = $apple['result'] ?? null;
    if (! is_array($zebraResult) || ! is_numeric($zebraResult['id'] ?? null) || ! is_array($appleResult) || ! is_numeric($appleResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return numeric ids');
    }
    $zebraId = (int) $zebraResult['id'];
    $appleId = (int) $appleResult['id'];

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
    expect($applePos)->toBeLessThan($zebraPos);
});

it('reorders a specific parent\'s direct children via simpleAutoOrder scoped by id', function (): void {
    $page = H::loginAsAdmin($this);
    $suffix = uniqid();
    $parent = H::wsCall($page, 'pwg.categories.add', ['name' => 'Scoped Auto Order Parent ' . $suffix]);
    $parentResult = $parent['result'] ?? null;
    if (! is_array($parentResult) || ! is_numeric($parentResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id');
    }
    $parentId = (int) $parentResult['id'];

    $childB = H::wsCall($page, 'pwg.categories.add', ['name' => 'B Child', 'parent' => (string) $parentId]);
    $childA = H::wsCall($page, 'pwg.categories.add', ['name' => 'A Child', 'parent' => (string) $parentId]);
    $childBResult = $childB['result'] ?? null;
    $childAResult = $childA['result'] ?? null;
    if (! is_array($childBResult) || ! is_numeric($childBResult['id'] ?? null) || ! is_array($childAResult) || ! is_numeric($childAResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return numeric ids for children');
    }
    $childBId = (int) $childBResult['id'];
    $childAId = (int) $childAResult['id'];

    $result = H::adminPost($page, '/admin.php?page=albums', [
        'pwg_token' => H::pwgToken($page),
        'simpleAutoOrder' => '1',
        'id' => (string) $parentId,
        'order' => 'name ASC',
    ]);
    expect($result['status'])->toBe(200);

    $ordered = albumsPageChildrenOrderedByRank($parentId);
    expect(array_keys($ordered))->toBe([$childAId, $childBId]);
});

it('reorders recursively (recursiveAutoOrder) including grandchildren', function (): void {
    $page = H::loginAsAdmin($this);
    $suffix = uniqid();
    $parent = H::wsCall($page, 'pwg.categories.add', ['name' => 'Recursive Auto Order Parent ' . $suffix]);
    $parentResult = $parent['result'] ?? null;
    if (! is_array($parentResult) || ! is_numeric($parentResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id');
    }
    $parentId = (int) $parentResult['id'];

    $childB = H::wsCall($page, 'pwg.categories.add', ['name' => 'B Recursive Child', 'parent' => (string) $parentId]);
    $childA = H::wsCall($page, 'pwg.categories.add', ['name' => 'A Recursive Child', 'parent' => (string) $parentId]);
    $childBResult = $childB['result'] ?? null;
    $childAResult = $childA['result'] ?? null;
    if (! is_array($childBResult) || ! is_numeric($childBResult['id'] ?? null) || ! is_array($childAResult) || ! is_numeric($childAResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return numeric ids for children');
    }
    $childAId = (int) $childAResult['id'];

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
    $parent = H::wsCall($page, 'pwg.categories.add', ['name' => 'Date Auto Order Parent ' . $suffix]);
    $parentResult = $parent['result'] ?? null;
    if (! is_array($parentResult) || ! is_numeric($parentResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id');
    }
    $parentId = (int) $parentResult['id'];
    $child = H::wsCall($page, 'pwg.categories.add', ['name' => 'Date Auto Order Child', 'parent' => (string) $parentId]);
    $childResult = $child['result'] ?? null;
    if (! is_array($childResult) || ! is_numeric($childResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id for the child');
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