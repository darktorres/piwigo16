<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\AlbumsPageRenderer (admin.php?page=albums) -- the album
 * tree manager, plus its own POST-driven "auto order" action
 * (simpleAutoOrder/recursiveAutoOrder), which reorders a set of sibling
 * albums by name/date/natural order and persists the new rank via
 * CategoryService::saveCategoriesOrder().
 */
function albumsPageDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

/** @return array<int, string> category id => name, ordered by rank ASC */
function albumsPageChildrenOrderedByRank(int $parentId): array
{
    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = albumsPageDbPrefix();
    $result = $db->query(sprintf(
        'SELECT id, name FROM %scategories WHERE id_uppercat = %d ORDER BY `rank` ASC',
        $prefix,
        $parentId
    ));
    $ordered = [];
    if ($result instanceof mysqli_result) {
        while (is_array($row = $result->fetch_assoc())) {
            $ordered[(int) $row['id']] = (string) $row['name'];
        }
    }
    $db->close();

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