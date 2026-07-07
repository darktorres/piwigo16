<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsPermissionsMutationTest extends ContractTestCase
{
    private ?int $privateCatId = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->privateCatId !== null) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.categories.delete', [
                'category_id'         => $this->privateCatId,
                'photo_deletion_mode' => 'no_delete',
                'pwg_token'           => $token,
            ]);
            $this->privateCatId = null;
        }

        parent::tearDown();
    }

    public function test_add_permission_returns_ok(): void
    {
        $token = $this->getPwgToken();
        $cat   = $this->callWs('pwg.categories.add', [
            'name'   => 'ct_private_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = (int) $cat['result']['id'];

        $users   = $this->callWs('pwg.users.getList', []);
        $userId  = (int) $users['result']['users'][0]['id'];

        $response = $this->callWs('pwg.permissions.add', [
            'cat_id'    => [$this->privateCatId],
            'user_id'   => [$userId],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_remove_permission_returns_ok(): void
    {
        $token = $this->getPwgToken();
        $cat   = $this->callWs('pwg.categories.add', [
            'name'   => 'ct_private_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = (int) $cat['result']['id'];

        $users  = $this->callWs('pwg.users.getList', []);
        $userId = (int) $users['result']['users'][0]['id'];

        $this->callWs('pwg.permissions.add', [
            'cat_id'    => [$this->privateCatId],
            'user_id'   => [$userId],
            'pwg_token' => $token,
        ]);

        $response = $this->callWs('pwg.permissions.remove', [
            'cat_id'    => [$this->privateCatId],
            'user_id'   => [$userId],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }
}
