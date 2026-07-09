<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsUsersMutationTest extends ContractTestCase
{
    private ?int $userId = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.users.delete', [
                'user_id'   => [$this->userId],
                'pwg_token' => $token,
            ]);
            $this->userId = null;
        }

        parent::tearDown();
    }

    /**
     * Narrows a decoded WS response down to result.users[0], asserting the
     * shape at every level. callWs() returns array<string, mixed>, so
     * nothing below the top level is known without explicit checks.
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

    public function test_add_returns_user_list_shape(): void
    {
        $token    = $this->getPwgToken();
        $username = 'ct_user_' . uniqid();
        $response = $this->callWs('pwg.users.add', [
            'username'   => $username,
            'password'   => 'Test1234!',
            'email'      => $username . '@test.local',
            'pwg_token'  => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('users.getList', $response);

        $this->userId = self::firstUserId($response);
    }

    public function test_setInfo_returns_user_list_shape(): void
    {
        $token    = $this->getPwgToken();
        $username = 'ct_user_' . uniqid();
        $add      = $this->callWs('pwg.users.add', [
            'username'  => $username,
            'password'  => 'Test1234!',
            'pwg_token' => $token,
        ]);
        $this->userId = self::firstUserId($add);

        $response = $this->callWs('pwg.users.setInfo', [
            'user_id'   => $this->userId,
            'status'    => 'normal',
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('users.getList', $response);
    }

    public function test_setMyInfo_returns_ok(): void
    {
        $token    = $this->getPwgToken();
        $response = $this->callWs('pwg.users.setMyInfo', [
            'nb_image_page' => 24,
            'pwg_token'     => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_preferences_set_returns_ok(): void
    {
        $response = $this->callWs('pwg.users.preferences.set', [
            'param' => 'nb_image_page',
            'value' => 20,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_delete_returns_ok(): void
    {
        $token    = $this->getPwgToken();
        $username = 'ct_user_' . uniqid();
        $add      = $this->callWs('pwg.users.add', [
            'username'  => $username,
            'password'  => 'Test1234!',
            'pwg_token' => $token,
        ]);
        $id = self::firstUserId($add);

        $response = $this->callWs('pwg.users.delete', [
            'user_id'   => [$id],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }
}
