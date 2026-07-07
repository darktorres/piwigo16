<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsUsersTest extends ContractTestCase
{
    public function test_getList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics']);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('users.getList', $response);
    }

    public function test_getList_includes_admin_user(): void
    {
        $response = $this->wsAdmin('pwg.users.getList', ['display' => 'basics']);

        $usernames = array_column($response['result']['users'], 'username');
        self::assertContains('fixture_admin', $usernames, 'fixture_admin must appear in user list');
    }

    public function test_getList_is_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.users.getList');

        self::assertSame('fail', $response['stat']);
        self::assertArrayHasKey('err', $response);
    }

    public function test_favorites_getList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.users.favorites.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.users.favorites.getList', $response);
    }

    public function test_favorites_getList_returns_paging_and_images(): void
    {
        $response = $this->wsAdmin('pwg.users.favorites.getList');

        $result = $response['result'];
        self::assertArrayHasKey('paging', $result);
        self::assertArrayHasKey('images', $result);
    }

    public function test_favorites_getList_fails_for_guest(): void
    {
        $response = $this->ws('pwg.users.favorites.getList');

        self::assertArrayHasKey('stat', $response);
    }
}
