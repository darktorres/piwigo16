<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;

/**
 * Ws\PwgGroups::delete()'s own `if ($deleted_groups === false) { return
 * new PwgError(500, 'There is no group to delete'); }` branch is NOT
 * chased here: GroupService::delete() only returns `false` when
 * `count($groupIds) === 0`, but `group_id` is registered with
 * WsParamFlag::FORCE_ARRAY and no WsParamFlag::OPTIONAL/'default' key
 * (mandatory) -- PwgServer::invoke() itself rejects any request that
 * doesn't supply at least one real element before this method's own body
 * ever runs (confirmed live: a bare `group_id=` -- or the key omitted
 * entirely -- fails at the WS layer with "Missing parameters: group_id"
 * first, and PwgServer::checkType() rejects any non-positive-integer
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
                'group_id'  => $this->groupId,
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

    /** @param array<string, mixed> $response */
    private static function firstItemId(array $response, string $collection): int
    {
        $item = self::firstItem($response, $collection);
        $id   = $item['id'] ?? null;
        self::assertTrue(
            is_int($id) || (is_string($id) && is_numeric($id)),
            sprintf('%s[0].id is missing or not numeric', $collection)
        );

        return (int) $id;
    }

    /** @param array<string, mixed> $response */
    private static function firstItemName(array $response, string $collection): string
    {
        $item = self::firstItem($response, $collection);
        $name = $item['name'] ?? null;
        self::assertIsString($name, sprintf('%s[0].name is missing or not a string', $collection));

        return $name;
    }

    public function test_add_returns_group_shape(): void
    {
        $name     = 'ct_group_' . uniqid();
        $response = $this->callWs('pwg.groups.add', ['name' => $name]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $response);

        $this->groupId = self::firstItemId($response, 'groups');
    }

    public function test_setInfo_renames_group(): void
    {
        $name = 'ct_group_' . uniqid();
        $add  = $this->callWs('pwg.groups.add', ['name' => $name]);
        $this->groupId = self::firstItemId($add, 'groups');

        $token    = $this->getPwgToken();
        $newName  = $name . '_renamed';
        $response = $this->callWs('pwg.groups.setInfo', [
            'group_id'  => $this->groupId,
            'name'      => $newName,
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
    public function test_setInfo_updates_is_default(): void
    {
        $name = 'ct_group_' . uniqid();
        $add  = $this->callWs('pwg.groups.add', ['name' => $name]);
        $this->groupId = self::firstItemId($add, 'groups');

        $token    = $this->getPwgToken();
        $response = $this->callWs('pwg.groups.setInfo', [
            'group_id'   => $this->groupId,
            'is_default' => true,
            'pwg_token'  => $token,
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

    public function test_addUser_and_deleteUser_return_group_shape(): void
    {
        $name = 'ct_group_' . uniqid();
        $add  = $this->callWs('pwg.groups.add', ['name' => $name]);
        $this->groupId = self::firstItemId($add, 'groups');
        $token = $this->getPwgToken();

        // fixture_admin is user id=1 (webmaster); use a fixture normal user if available
        $users   = $this->callWs('pwg.users.getList', []);
        $userId  = self::firstItemId($users, 'users');

        $addUser = $this->callWs('pwg.groups.addUser', [
            'group_id'  => $this->groupId,
            'user_id'   => [$userId],
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $addUser['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $addUser);

        $delUser = $this->callWs('pwg.groups.deleteUser', [
            'group_id'  => $this->groupId,
            'user_id'   => [$userId],
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $delUser['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $delUser);
    }

    public function test_delete_returns_ok(): void
    {
        $name  = 'ct_group_' . uniqid();
        $add   = $this->callWs('pwg.groups.add', ['name' => $name]);
        $id    = self::firstItemId($add, 'groups');
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.groups.delete', [
            'group_id'  => $id,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        // already deleted — don't set $this->groupId
    }

    public function test_duplicate_returns_new_group(): void
    {
        $name  = 'ct_group_' . uniqid();
        $add   = $this->callWs('pwg.groups.add', ['name' => $name]);
        $srcId = self::firstItemId($add, 'groups');
        $token = $this->getPwgToken();

        $copyName = $name . '_copy';
        $response = $this->callWs('pwg.groups.duplicate', [
            'group_id'  => $srcId,
            'copy_name' => $copyName,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $response);

        $copyId = self::firstItemId($response, 'groups');

        // clean up both
        $this->callWs('pwg.groups.delete', ['group_id' => $srcId,  'pwg_token' => $token]);
        $this->callWs('pwg.groups.delete', ['group_id' => $copyId, 'pwg_token' => $token]);
    }

    public function test_merge_returns_destination_and_deleted_groups(): void
    {
        $token = $this->getPwgToken();
        $src   = $this->callWs('pwg.groups.add', ['name' => 'ct_merge_src_' . uniqid()]);
        $dst   = $this->callWs('pwg.groups.add', ['name' => 'ct_merge_dst_' . uniqid()]);
        $srcId = self::firstItemId($src, 'groups');
        $dstId = self::firstItemId($dst, 'groups');

        $response = $this->callWs('pwg.groups.merge', [
            'merge_group_id'       => [$srcId],
            'destination_group_id' => $dstId,
            'pwg_token'            => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'] ?? null;
        self::assertIsArray($result, 'WS response "result" is not an array');
        self::assertArrayHasKey('destination_group', $result);

        // src was deleted by merge; clean up dst
        $this->callWs('pwg.groups.delete', ['group_id' => $dstId, 'pwg_token' => $token]);
    }

    public function test_add_with_a_duplicate_name_returns_error(): void
    {
        // 'Editors' is a real fixture group name.
        $response = $this->callWs('pwg.groups.add', ['name' => 'Editors']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('This name is already used by another group.', $response['message']);
    }

    public function test_delete_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.groups.delete', ['group_id' => [1], 'pwg_token' => 'wrong']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_setInfo_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.groups.setInfo', ['group_id' => 1, 'pwg_token' => 'wrong']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_addUser_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.groups.addUser', ['group_id' => 1, 'user_id' => [1], 'pwg_token' => 'wrong']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_deleteUser_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.groups.deleteUser', ['group_id' => 1, 'user_id' => [1], 'pwg_token' => 'wrong']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_merge_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.groups.merge', ['destination_group_id' => 1, 'merge_group_id' => [2], 'pwg_token' => 'wrong']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_duplicate_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.groups.duplicate', ['group_id' => 1, 'copy_name' => 'x', 'pwg_token' => 'wrong']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_getList_invalid_order_returns_error(): void
    {
        $response = $this->callWs('pwg.groups.getList', ['order' => '1 DROP TABLE']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid input parameter order', $response['message']);
    }

    public function test_setInfo_on_a_nonexistent_group_returns_error(): void
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

    public function test_addUser_on_a_nonexistent_group_returns_error(): void
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

    public function test_deleteUser_on_a_nonexistent_group_returns_error(): void
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

    public function test_merge_with_a_nonexistent_source_group_returns_error(): void
    {
        $token = $this->getPwgToken();
        $dst = $this->callWs('pwg.groups.add', ['name' => 'ct_merge_bad_dst_' . uniqid()]);
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

    public function test_duplicate_a_nonexistent_group_returns_error(): void
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
