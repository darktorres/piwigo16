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

    public function test_add_permission_returns_ok(): void
    {
        $token = $this->getPwgToken();
        $cat   = $this->callWs('pwg.categories.add', [
            'name'   => 'ct_private_' . uniqid(),
            'status' => 'private',
        ]);
        $this->privateCatId = self::resultId($cat);

        $users  = $this->callWs('pwg.users.getList', []);
        $userId = self::firstUserId($users);

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
        $this->privateCatId = self::resultId($cat);

        $users  = $this->callWs('pwg.users.getList', []);
        $userId = self::firstUserId($users);

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
