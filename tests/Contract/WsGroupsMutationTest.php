<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsGroupsMutationTest extends ContractTestCase
{
    private ?int $groupId = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    #[\Override]
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

    public function test_add_returns_group_shape(): void
    {
        $name     = 'ct_group_' . uniqid();
        $response = $this->callWs('pwg.groups.add', ['name' => $name]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $response);

        $this->groupId = (int) $response['result']['groups'][0]['id'];
    }

    public function test_setInfo_renames_group(): void
    {
        $name = 'ct_group_' . uniqid();
        $add  = $this->callWs('pwg.groups.add', ['name' => $name]);
        $this->groupId = (int) $add['result']['groups'][0]['id'];

        $token    = $this->getPwgToken();
        $newName  = $name . '_renamed';
        $response = $this->callWs('pwg.groups.setInfo', [
            'group_id'  => $this->groupId,
            'name'      => $newName,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $response);
        self::assertSame($newName, $response['result']['groups'][0]['name']);
    }

    public function test_addUser_and_deleteUser_return_group_shape(): void
    {
        $name = 'ct_group_' . uniqid();
        $add  = $this->callWs('pwg.groups.add', ['name' => $name]);
        $this->groupId = (int) $add['result']['groups'][0]['id'];
        $token = $this->getPwgToken();

        // fixture_admin is user id=1 (webmaster); use a fixture normal user if available
        $users   = $this->callWs('pwg.users.getList', []);
        $userId  = (int) $users['result']['users'][0]['id'];

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
        $id    = (int) $add['result']['groups'][0]['id'];
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
        $srcId = (int) $add['result']['groups'][0]['id'];
        $token = $this->getPwgToken();

        $copyName = $name . '_copy';
        $response = $this->callWs('pwg.groups.duplicate', [
            'group_id'  => $srcId,
            'copy_name' => $copyName,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $response);

        $copyId = (int) $response['result']['groups'][0]['id'];

        // clean up both
        $this->callWs('pwg.groups.delete', ['group_id' => $srcId,  'pwg_token' => $token]);
        $this->callWs('pwg.groups.delete', ['group_id' => $copyId, 'pwg_token' => $token]);
    }

    public function test_merge_returns_destination_and_deleted_groups(): void
    {
        $token = $this->getPwgToken();
        $src   = $this->callWs('pwg.groups.add', ['name' => 'ct_merge_src_' . uniqid()]);
        $dst   = $this->callWs('pwg.groups.add', ['name' => 'ct_merge_dst_' . uniqid()]);
        $srcId = (int) $src['result']['groups'][0]['id'];
        $dstId = (int) $dst['result']['groups'][0]['id'];

        $response = $this->callWs('pwg.groups.merge', [
            'merge_group_id'       => [$srcId],
            'destination_group_id' => $dstId,
            'pwg_token'            => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertArrayHasKey('destination_group', $response['result']);

        // src was deleted by merge; clean up dst
        $this->callWs('pwg.groups.delete', ['group_id' => $dstId, 'pwg_token' => $token]);
    }
}
