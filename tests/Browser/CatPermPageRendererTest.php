<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * CatPermPageRenderer (admin.php?page=album&cat_id=X&tab=permissions, the
 * "permissions" tab of the "album" page) -- 0% coverage before this file.
 *
 * Fixture shape (tests/Fixtures/piwigo-17.0.sql): piwigo_group_access grants
 * all 3 groups (1 "Editors", 2 "Reviewers", 3 "Guests") access to category 1
 * "Sample Album", but only group 1 access to category 2 "Nested Sub Album" --
 * so asserting the exact granted-group-id set for each category (not just
 * "some groups are granted") catches a renderer that queried the wrong
 * category's group_access rows. piwigo_user_access has no rows at all in
 * this fixture, so users_selected is always empty regardless of category.
 *
 * render()'s own bare `$users = []`/`$user_granted_indirect_ids = []`
 * literal-array-assignment lines (~L126/141) execute on every request this
 * file makes (both sit unconditionally ahead of the first real branch, and
 * the category-1 test below drives $group_granted_ids to all 3 fixture
 * groups, definitely entering the `count($group_granted_ids) > 0` block
 * that follows L141) -- a coverage tool still reporting either as
 * uncovered is the documented OPcache constant-array-folding artifact
 * (see tests/Browser/CatListPageRendererTest.php's own docblock), not a
 * real gap.
 *
 * Not exercised: the `(! is_int($row_group_id) && ! is_string($row_group_id))
 * || (...)` defensive `continue` inside the indirect-membership loop.
 * piwigo_user_group's own group_id/user_id columns are both real `NOT
 * NULL` numeric columns (tests/Fixtures/piwigo-17.0.sql's own CREATE
 * TABLE), so a genuine row from getMembersByGroupIds() is always int or a
 * numeric string -- this branch is provably unreachable through real
 * data, same class of guard as CatModifyPageRenderer's own
 * representative_picture_id narrowing.
 */
afterEach(function (): void {
    H::setCategoryPrivate(2, private: false);
});

it('shows the public status and all 3 granted groups for category 1', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=1&tab=permissions');
    $page->assertNoJavaScriptErrors();

    $page->assertRadioSelected('status', 'public');
    $page->assertRadioNotSelected('status', 'private');

    $groupsSelected = $page->attribute('[data-selectize=groups]', 'data-value');
    expect($groupsSelected)->not->toBeNull();
    $decodedGroups = json_decode((string) $groupsSelected, true);
    if (! is_array($decodedGroups)) {
        throw new \RuntimeException('expected an array from data-value JSON, got: ' . var_export($decodedGroups, true));
    }
    sort($decodedGroups);
    expect($decodedGroups)->toBe([1, 2, 3]);

    $usersSelected = $page->attribute('[data-selectize=users]', 'data-value');
    expect(json_decode((string) $usersSelected, true))->toBe([]);
});

it('shows the private status and the single granted group for category 2', function (): void {
    H::setCategoryPrivate(2, private: true);

    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=2&tab=permissions');
    $page->assertNoJavaScriptErrors();

    $page->assertRadioSelected('status', 'private');
    $page->assertRadioNotSelected('status', 'public');

    $groupsSelected = $page->attribute('[data-selectize=groups]', 'data-value');
    expect(json_decode((string) $groupsSelected, true))->toBe([1]);
});

it('submits a status/group permission change and persists it', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Cat Perm Submit Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    // Fixture group 3 ("Guests") -- see this file's own fixture-shape
    // memory note.
    $result = H::adminPost($page, '/admin.php?page=album-' . $albumId . '-permissions', [
        'pwg_token' => H::pwgToken($page),
        'status' => 'private',
        'groups' => ['3'],
        'users' => [],
    ]);

    expect($result['status'])->toBe(200);
    expect($result['body'])->toContain('Album updated successfully');

    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';

    $statusRow = $db->query(sprintf('SELECT status FROM %scategories WHERE id = %d', $prefix, $albumId));
    $statusAssoc = $statusRow instanceof mysqli_result ? $statusRow->fetch_assoc() : null;
    expect(is_array($statusAssoc) ? $statusAssoc['status'] : 'MISSING')->toBe('private');

    $groupRow = $db->query(sprintf('SELECT group_id FROM %sgroup_access WHERE cat_id = %d', $prefix, $albumId));
    $groupAssoc = $groupRow instanceof mysqli_result ? $groupRow->fetch_assoc() : null;
    expect(is_array($groupAssoc) ? (int) $groupAssoc['group_id'] : -1)->toBe(3);
    $db->close();
});
