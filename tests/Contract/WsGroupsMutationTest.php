<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;

/**
 * Ws\Groups::delete()'s own `if ($deleted_groups === false) { return
 * new WsErrorResponse(500, 'There is no group to delete'); }` branch is NOT
 * chased here: GroupService::delete() only returns `false` when
 * `count($groupIds) === 0`, but `group_id` is registered with
 * WsParamFlag::FORCE_ARRAY and no WsParamFlag::OPTIONAL/'default' key
 * (mandatory) -- Server::invoke() itself rejects any request that
 * doesn't supply at least one real element before this method's own body
 * ever runs (confirmed live: a bare `group_id=` -- or the key omitted
 * entirely -- fails at the WS layer with "Missing parameters: group_id"
 * first, and Server::checkType() rejects any non-positive-integer
 * element with its own error too). Genuinely unreachable through the real
 * WS route, not a gap in test coverage.
 */
final class WsGroupsMutationTest extends ContractTestCase
{
    private ?int $groupId = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->groupId !== null) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.groups.delete', [
                'group_id' => $this->groupId,
                'pwg_token' => $token,
            ]);
            $this->groupId = null;
        }

        parent::tearDown();
    }

    /**
     * Narrows a decoded WS response down to result.$collection[0], asserting
     * the shape at every level. callWs() returns array<string, mixed>, so
     * nothing below the top level is known without explicit checks.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private static function firstItem(array $response, string $collection): array
    {
        $result = $response['result'] ?? null;
        self::assertIsArray($result, 'WS response "result" is not an array');

        $items = $result[$collection] ?? null;
        self::assertIsArray($items, sprintf('WS response "result.%s" is not an array', $collection));

        $item = $items[0] ?? null;
        self::assertIsArray($item, sprintf('WS response "result.%s[0]" is not an array', $collection));

        // assertIsArray() only proves "array", not the key type. WS collection
        // items are decoded from JSON objects (field names like id/name/...),
        // so keys are always strings in practice.
        /** @var array<string, mixed> $item */
        return $item;
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function firstItemId(array $response, string $collection): int
    {
        $item = self::firstItem($response, $collection);
        $id = $item['id'] ?? null;
        self::assertTrue(
            is_int($id) || (is_string($id) && is_numeric($id)),
            sprintf('%s[0].id is missing or not numeric', $collection)
        );

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function firstItemName(array $response, string $collection): string
    {
        $item = self::firstItem($response, $collection);
        $name = $item['name'] ?? null;
        self::assertIsString($name, sprintf('%s[0].name is missing or not a string', $collection));

        return $name;
    }

    public function testAddReturnsGroupShape(): void
    {
        $name = 'ct_group_' . uniqid();
        $response = $this->callWs('pwg.groups.add', [
            'name' => $name,
            'pwg_token' => $this->getPwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $response);

        $this->groupId = self::firstItemId($response, 'groups');
    }

    public function testSetInfoRenamesGroup(): void
    {
        $name = 'ct_group_' . uniqid();
        $add = $this->callWs('pwg.groups.add', [
            'name' => $name,
            'pwg_token' => $this->getPwgToken(),
        ]);
        $this->groupId = self::firstItemId($add, 'groups');

        $token = $this->getPwgToken();
        $newName = $name . '_renamed';
        $response = $this->callWs('pwg.groups.setInfo', [
            'group_id' => $this->groupId,
            'name' => $newName,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $response);
        self::assertSame($newName, self::firstItemName($response, 'groups'));
    }

    /**
     * setInfo()'s own `if (isset($params['is_default'])) { $updates['is_default']
     * = $params['is_default']; }` branch -- test_setInfo_renames_group()
     * above only ever sends 'name'.
     */
    public function testSetInfoUpdatesIsDefault(): void
    {
        $name = 'ct_group_' . uniqid();
        $add = $this->callWs('pwg.groups.add', [
            'name' => $name,
            'pwg_token' => $this->getPwgToken(),
        ]);
        $this->groupId = self::firstItemId($add, 'groups');

        $token = $this->getPwgToken();
        $response = $this->callWs('pwg.groups.setInfo', [
            'group_id' => $this->groupId,
            'is_default' => true,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'] ?? null;
        self::assertIsArray($result, 'WS response "result" is not an array');
        $groups = $result['groups'] ?? null;
        self::assertIsArray($groups, 'WS response "result.groups" is not an array');
        $group = $groups[0] ?? null;
        self::assertIsArray($group, 'WS response "result.groups[0]" is not an array');
        self::assertTrue((bool) $group['is_default']);
    }

    public function testAddUserAndDeleteUserReturnGroupShape(): void
    {
        $name = 'ct_group_' . uniqid();
        $add = $this->callWs('pwg.groups.add', [
            'name' => $name,
            'pwg_token' => $this->getPwgToken(),
        ]);
        $this->groupId = self::firstItemId($add, 'groups');
        $token = $this->getPwgToken();

        // fixture_admin is user id=1 (webmaster); use a fixture normal user if available
        $users = $this->callWs('pwg.users.getList', []);
        $userId = self::firstItemId($users, 'users');

        $addUser = $this->callWs('pwg.groups.addUser', [
            'group_id' => $this->groupId,
            'user_id' => [$userId],
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $addUser['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $addUser);

        $delUser = $this->callWs('pwg.groups.deleteUser', [
            'group_id' => $this->groupId,
            'user_id' => [$userId],
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $delUser['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $delUser);
    }

    public function testDeleteReturnsOk(): void
    {
        $name = 'ct_group_' . uniqid();
        $add = $this->callWs('pwg.groups.add', [
            'name' => $name,
            'pwg_token' => $this->getPwgToken(),
        ]);
        $id = self::firstItemId($add, 'groups');
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.groups.delete', [
            'group_id' => $id,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        // already deleted — don't set $this->groupId
    }

    public function testDuplicateReturnsNewGroup(): void
    {
        $name = 'ct_group_' . uniqid();
        $add = $this->callWs('pwg.groups.add', [
            'name' => $name,
            'pwg_token' => $this->getPwgToken(),
        ]);
        $srcId = self::firstItemId($add, 'groups');
        $token = $this->getPwgToken();

        $copyName = $name . '_copy';
        $response = $this->callWs('pwg.groups.duplicate', [
            'group_id' => $srcId,
            'copy_name' => $copyName,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $response);

        $copyId = self::firstItemId($response, 'groups');

        // clean up both
        $this->callWs('pwg.groups.delete', [
            'group_id' => $srcId,
            'pwg_token' => $token,
        ]);
        $this->callWs('pwg.groups.delete', [
            'group_id' => $copyId,
            'pwg_token' => $token,
        ]);
    }

    public function testMergeReturnsDestinationAndDeletedGroups(): void
    {
        $token = $this->getPwgToken();
        $src = $this->callWs('pwg.groups.add', [
            'name' => 'ct_merge_src_' . uniqid(),
            'pwg_token' => $this->getPwgToken(),
        ]);
        $dst = $this->callWs('pwg.groups.add', [
            'name' => 'ct_merge_dst_' . uniqid(),
            'pwg_token' => $this->getPwgToken(),
        ]);
        $srcId = self::firstItemId($src, 'groups');
        $dstId = self::firstItemId($dst, 'groups');

        $response = $this->callWs('pwg.groups.merge', [
            'merge_group_id' => [$srcId],
            'destination_group_id' => $dstId,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'] ?? null;
        self::assertIsArray($result, 'WS response "result" is not an array');
        self::assertArrayHasKey('destination_group', $result);

        // src was deleted by merge; clean up dst
        $this->callWs('pwg.groups.delete', [
            'group_id' => $dstId,
            'pwg_token' => $token,
        ]);
    }

    public function testAddWithADuplicateNameReturnsError(): void
    {
        // 'Editors' is a real fixture group name.
        $response = $this->callWs('pwg.groups.add', [
            'name' => 'Editors',
            'pwg_token' => $this->getPwgToken(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('This name is already used by another group.', $response['message']);
    }

    /**
     * SEC finding 5 (related): pwg.groups.add carried no pwg_token param
     * and no CSRF check of any kind, unlike every one of its five sibling
     * Groups mutations (delete/setInfo/addUser/deleteUser/merge/duplicate).
     */
    public function testAddInvalidTokenReturnsError(): void
    {
        $response = $this->callWs('pwg.groups.add', [
            'name' => 'ct_group_' . uniqid(),
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testAddWithNoTokenReturnsError(): void
    {
        $response = $this->callWs('pwg.groups.add', [
            'name' => 'ct_group_' . uniqid(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1002, $response['err']);
    }

    public function testDeleteInvalidTokenReturnsError(): void
    {
        $response = $this->callWs('pwg.groups.delete', [
            'group_id' => [1],
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testSetInfoInvalidTokenReturnsError(): void
    {
        $response = $this->callWs('pwg.groups.setInfo', [
            'group_id' => 1,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testAddUserInvalidTokenReturnsError(): void
    {
        $response = $this->callWs('pwg.groups.addUser', [
            'group_id' => 1,
            'user_id' => [1],
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testDeleteUserInvalidTokenReturnsError(): void
    {
        $response = $this->callWs('pwg.groups.deleteUser', [
            'group_id' => 1,
            'user_id' => [1],
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testMergeInvalidTokenReturnsError(): void
    {
        $response = $this->callWs('pwg.groups.merge', [
            'destination_group_id' => 1,
            'merge_group_id' => [2],
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testDuplicateInvalidTokenReturnsError(): void
    {
        $response = $this->callWs('pwg.groups.duplicate', [
            'group_id' => 1,
            'copy_name' => 'x',
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testGetListInvalidOrderReturnsError(): void
    {
        $response = $this->callWs('pwg.groups.getList', [
            'order' => '1 DROP TABLE',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid input parameter order', $response['message']);
    }

    public function testGetListOrderByNameDescSortsResultsDescending(): void
    {
        $token = $this->getPwgToken();
        $prefix = 'ct_order_' . uniqid();
        $nameA = $prefix . '_a';
        $nameB = $prefix . '_b';

        $addA = $this->callWs('pwg.groups.add', [
            'name' => $nameA,
            'pwg_token' => $token,
        ]);
        $groupIdA = self::firstItemId($addA, 'groups');

        $addB = $this->callWs('pwg.groups.add', [
            'name' => $nameB,
            'pwg_token' => $this->getPwgToken(),
        ]);
        $groupIdB = self::firstItemId($addB, 'groups');

        try {
            $response = $this->callWs('pwg.groups.getList', [
                'order' => 'name desc',
            ]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            $groups = $result['groups'];
            self::assertIsArray($groups);

            $names = array_map(
                static fn (mixed $g): string => is_array($g) && is_string($g['name'] ?? null) ? $g['name'] : '',
                $groups
            );
            $posA = array_search($nameA, $names, true);
            $posB = array_search($nameB, $names, true);
            self::assertIsInt($posA);
            self::assertIsInt($posB);
            self::assertLessThan($posA, $posB, 'order=name desc must place "' . $nameB . '" before "' . $nameA . '"');
        } finally {
            $this->callWs('pwg.groups.delete', [
                'group_id' => [$groupIdA, $groupIdB],
                'pwg_token' => $this->getPwgToken(),
            ]);
        }
    }

    public function testSetInfoOnANonexistentGroupReturnsError(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.groups.setInfo', [
            'group_id' => 999999,
            'name' => 'irrelevant',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('This group does not exist.', $response['message']);
    }

    public function testAddUserOnANonexistentGroupReturnsError(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.groups.addUser', [
            'group_id' => 999999,
            'user_id' => [1],
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('This group does not exist.', $response['message']);
    }

    public function testDeleteUserOnANonexistentGroupReturnsError(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.groups.deleteUser', [
            'group_id' => 999999,
            'user_id' => [1],
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('This group does not exist.', $response['message']);
    }

    public function testMergeWithANonexistentSourceGroupReturnsError(): void
    {
        $token = $this->getPwgToken();
        $dst = $this->callWs('pwg.groups.add', [
            'name' => 'ct_merge_bad_dst_' . uniqid(),
            'pwg_token' => $this->getPwgToken(),
        ]);
        $this->groupId = self::firstItemId($dst, 'groups');

        $response = $this->callWs('pwg.groups.merge', [
            'destination_group_id' => $this->groupId,
            'merge_group_id' => [999999],
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('All groups does not exist.', $response['message']);
    }

    public function testDuplicateANonexistentGroupReturnsError(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.groups.duplicate', [
            'group_id' => 999999,
            'copy_name' => 'irrelevant',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('This group does not exist.', $response['message']);
    }
}
