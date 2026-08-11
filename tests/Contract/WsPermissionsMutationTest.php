<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;

/**
 * Ws\Permissions::getList()'s 3 "malformed row" guards (direct-users,
 * indirect-users, and groups loops -- each `! isset($row['cat_id']) ||
 * ! is_numeric($row['cat_id'])` then `continue;`) are NOT chased here:
 * PermissionRepository::findDirectUserAccessRows()/
 * findIndirectUserAccessRows()/findGroupAccessRows() each select `cat_id`
 * directly off user_access/group_access, both real
 * `smallint unsigned NOT NULL` columns (part of a composite PK, per
 * tests/Fixtures/piwigo-17.0.sql's own CREATE TABLE) -- a fetched row can
 * never lack that key or hold a non-numeric value. Genuinely unreachable
 * through any real DB-backed call, not a gap in test coverage.
 */
final class WsPermissionsMutationTest extends ContractTestCase
{
    private ?int $privateCatId = null;

    /**
     * @var list<int>
     */
    private array $groupIdsToDelete = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->privateCatId !== null) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.categories.delete', [
                'category_id' => $this->privateCatId,
                'photo_deletion_mode' => 'no_delete',
                'pwg_token' => $token,
            ]);
            $this->privateCatId = null;
        }

        if ($this->groupIdsToDelete !== []) {
            $token = $this->getPwgToken();
            foreach ($this->groupIdsToDelete as $groupId) {
                $this->callWs('pwg.groups.delete', [
                    'group_id' => $groupId,
                    'pwg_token' => $token,
                ]);
            }
            $this->groupIdsToDelete = [];
        }

        parent::tearDown();
    }

    /**
     * Narrows a decoded WS response down to result.id, asserting the shape
     * at every level. callWs() returns array<string, mixed>, so nothing
     * below the top level is known without explicit checks.
     *
     * @param array<string, mixed> $response
     */
    private static function resultId(array $response): int
    {
        $result = $response['result'] ?? null;
        self::assertIsArray($result, 'WS response "result" is not an array');

        $id = $result['id'] ?? null;
        self::assertTrue(
            is_int($id) || (is_string($id) && is_numeric($id)),
            'result.id is missing or not numeric'
        );

        return (int) $id;
    }

    /**
     * Narrows a decoded WS response down to result.users[0].id, asserting
     * the shape at every level.
     *
     * @param array<string, mixed> $response
     */
    private static function firstUserId(array $response): int
    {
        $result = $response['result'] ?? null;
        self::assertIsArray($result, 'WS response "result" is not an array');

        $users = $result['users'] ?? null;
        self::assertIsArray($users, 'WS response "result.users" is not an array');

        $user = $users[0] ?? null;
        self::assertIsArray($user, 'WS response "result.users[0]" is not an array');

        $id = $user['id'] ?? null;
        self::assertTrue(
            is_int($id) || (is_string($id) && is_numeric($id)),
            'result.users[0].id is missing or not numeric'
        );

        return (int) $id;
    }

    public function testAddPermissionReturnsOk(): void
    {
        $token = $this->getPwgToken();
        $cat = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);

        $users = $this->callWs('pwg.users.getList', []);
        $userId = self::firstUserId($users);

        $response = $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'user_id' => [$userId],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function testRemovePermissionReturnsOk(): void
    {
        $token = $this->getPwgToken();
        $cat = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);

        $users = $this->callWs('pwg.users.getList', []);
        $userId = self::firstUserId($users);

        $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'user_id' => [$userId],
            'pwg_token' => $token,
        ]);

        $response = $this->callWs('pwg.permissions.remove', [
            'cat_id' => [$this->privateCatId],
            'user_id' => [$userId],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    /**
     * add()'s own group_id branch (getUppercatIds()/getSubcatIds() +
     * groupAccess mass-insert) -- WsPermissionsMutationTest's other tests
     * only ever pass user_id, never group_id.
     */
    public function testAddGroupPermissionGrantsGroupAccessToTheCategory(): void
    {
        $token = $this->getPwgToken();
        $cat = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_group_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);

        $response = $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'group_id' => [1],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);
        self::assertNotEmpty($categories);
        $entry = $categories[0];
        self::assertIsArray($entry);
        self::assertSame($this->privateCatId, $entry['id']);
        self::assertIsArray($entry['groups']);
        self::assertContains(1, $entry['groups']);
    }

    public function testRemoveGroupPermissionRevokesGroupAccess(): void
    {
        $token = $this->getPwgToken();
        $cat = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_group_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);

        $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'group_id' => [1],
            'pwg_token' => $token,
        ]);

        $response = $this->callWs('pwg.permissions.remove', [
            'cat_id' => [$this->privateCatId],
            'group_id' => [1],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);
        $entry = $categories[0] ?? null;
        if ($entry !== null) {
            self::assertIsArray($entry);
            self::assertIsArray($entry['groups']);
            self::assertNotContains(1, $entry['groups']);
        }
    }

    public function testGetListTooManyFiltersReturnsError(): void
    {
        $response = $this->callWs('pwg.permissions.getList', [
            'cat_id' => [1],
            'group_id' => [1],
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Too many parameters, provide cat_id OR user_id OR group_id', $response['message']);
    }

    public function testGetListFiltersByGroupId(): void
    {
        $token = $this->getPwgToken();
        $cat = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_group_filter_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);
        $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'group_id' => [1],
            'pwg_token' => $token,
        ]);

        $matching = $this->callWs('pwg.permissions.getList', [
            'group_id' => [1],
        ]);
        self::assertSame('ok', $matching['stat']);
        $matchingResult = $matching['result'];
        self::assertIsArray($matchingResult);
        self::assertIsArray($matchingResult['categories']);
        $matchingIds = array_column($matchingResult['categories'], 'id');
        self::assertContains($this->privateCatId, $matchingIds);

        $nonMatching = $this->callWs('pwg.permissions.getList', [
            'group_id' => [999999],
        ]);
        self::assertSame('ok', $nonMatching['stat']);
        $nonMatchingResult = $nonMatching['result'];
        self::assertIsArray($nonMatchingResult);
        self::assertSame([], $nonMatchingResult['categories']);
    }

    /**
     * getList()'s groups-loop own `if (! isset($perms[$cat_id])) { $perms[$cat_id]['id']
     * = $cat_id; }` first-touch assignment. Every other private-category
     * test in this file passes `status: 'private'` straight to
     * `pwg.categories.add`, which -- for a *top-level* private category --
     * unconditionally auto-grants DIRECT `user_access` to every
     * admin id plus the creating user (CategoryService::addCategory()'s
     * own `elseif ($insert['status'] === 'private')` branch, confirmed by
     * reading its source: the sibling "inherit" branch above it only ever
     * applies to a category with a real parent). That means the
     * direct-users loop always touches $perms[$cat_id] first in every
     * other test here, before the groups loop ever runs. Creating the
     * category as public first and flipping it private via a separate
     * `pwg.categories.setInfo` call instead (CategoryService::setCatStatus()
     * -- confirmed to only flip the `status` column, no permission side
     * effect) avoids that auto-grant entirely; combined with a
     * freshly-created, memberless group (so the indirect-users loop's own
     * inner join through user_group also finds nothing), the groups
     * loop is left as the first and only one to touch this category.
     */
    public function testAddGroupPermissionWithAMemberlessGroupSetsTheCategoryViaTheGroupsLoop(): void
    {
        $token = $this->getPwgToken();
        $group = $this->callWs('pwg.groups.add', [
            'name' => 'ct_permless_group_' . uniqid(),
        ]);
        $groupResult = $group['result'] ?? null;
        self::assertIsArray($groupResult);
        $groups = $groupResult['groups'] ?? null;
        self::assertIsArray($groups);
        $firstGroup = $groups[0] ?? null;
        self::assertIsArray($firstGroup);
        $groupId = $firstGroup['id'] ?? null;
        self::assertTrue(is_int($groupId) || (is_string($groupId) && is_numeric($groupId)));
        $groupId = (int) $groupId;
        $this->groupIdsToDelete[] = $groupId;

        $cat = $this->callWs('pwg.categories.add', [
            'name' => 'ct_memberless_group_' . uniqid(),
        ]);
        $this->privateCatId = self::resultId($cat);

        $setInfo = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $this->privateCatId,
            'status' => 'private',
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $setInfo['stat']);

        $response = $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'group_id' => [$groupId],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);
        $entry = $categories[0] ?? null;
        self::assertIsArray($entry, 'the groups loop must have created the category entry on its own');
        self::assertSame($this->privateCatId, $entry['id']);
        self::assertIsArray($entry['groups']);
        self::assertContains($groupId, $entry['groups']);
        self::assertSame([], $entry['users'], 'no direct user access must exist for this category');
        self::assertSame([], $entry['users_indirect'], 'a memberless group must never produce an indirect user');
    }

    /**
     * getList()'s user_id filter branch (both the `users_indirect`/`users`
     * intersection checks and the resulting unset()+continue) --
     * test_getList_filters_by_group_id() above only ever exercises the
     * sibling group_id filter.
     */
    public function testGetListFiltersByUserIdWithNoMatchExcludesTheCategory(): void
    {
        $token = $this->getPwgToken();
        $cat = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_user_filter_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);

        $users = $this->callWs('pwg.users.getList', []);
        $userId = self::firstUserId($users);

        $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'user_id' => [$userId],
            'pwg_token' => $token,
        ]);

        $matching = $this->callWs('pwg.permissions.getList', [
            'user_id' => [$userId],
        ]);
        self::assertSame('ok', $matching['stat']);
        $matchingResult = $matching['result'];
        self::assertIsArray($matchingResult);
        self::assertIsArray($matchingResult['categories']);
        $matchingIds = array_column($matchingResult['categories'], 'id');
        self::assertContains($this->privateCatId, $matchingIds);

        $nonMatching = $this->callWs('pwg.permissions.getList', [
            'user_id' => [999999],
        ]);
        self::assertSame('ok', $nonMatching['stat']);
        $nonMatchingResult = $nonMatching['result'];
        self::assertIsArray($nonMatchingResult);
        self::assertSame([], $nonMatchingResult['categories'], 'a category with no matching direct or indirect user must be unset from the result');
    }

    public function testAddWithAnInvalidTokenReturnsError(): void
    {
        $cat = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_wrong_token_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);

        $response = $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'user_id' => [1],
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function testRemoveWithAnInvalidTokenReturnsError(): void
    {
        $response = $this->callWs('pwg.permissions.remove', [
            'cat_id' => [1],
            'user_id' => [1],
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    /**
     * add()'s `recursive` branch merges getSubcatIds() on top of
     * getUppercatIds() before computing $private_cats -- every other
     * group_id test in this file passes the (default `false`) `recursive`
     * omitted entirely, only ever reaching getUppercatIds().
     */
    public function testAddRecursiveGroupPermissionAlsoGrantsAccessToASubcategory(): void
    {
        $token = $this->getPwgToken();
        $parent = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_recursive_parent_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($parent);

        $child = $this->callWs('pwg.categories.add', [
            'name' => 'ct_private_recursive_child_' . uniqid(),
            'status' => 'private',
            'parent' => $this->privateCatId,
        ]);
        $childId = self::resultId($child);

        $response = $this->callWs('pwg.permissions.add', [
            'cat_id' => [$this->privateCatId],
            'group_id' => [1],
            'recursive' => true,
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $response['stat']);

        $childList = $this->callWs('pwg.permissions.getList', [
            'cat_id' => [$childId],
        ]);
        self::assertSame('ok', $childList['stat']);
        $childResult = $childList['result'];
        self::assertIsArray($childResult);
        self::assertIsArray($childResult['categories']);
        $entry = $childResult['categories'][0] ?? null;
        self::assertIsArray($entry, 'recursive=true must have propagated group access down to the subcategory');
        self::assertIsArray($entry['groups']);
        self::assertContains(1, $entry['groups']);

        // categories.id_uppercat is ON DELETE SET NULL, not CASCADE
        // -- deleting the parent (below, via tearDown()'s own
        // $this->privateCatId handling) would orphan the child rather than
        // remove it, so it's deleted explicitly here first.
        $this->callWs('pwg.categories.delete', [
            'category_id' => $childId,
            'photo_deletion_mode' => 'no_delete',
            'pwg_token' => $token,
        ]);
    }
}
