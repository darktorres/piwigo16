<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsPermissionsTest extends ContractTestCase
{
    public function testGetListResponseMatchesSchema(): void
    {
        $response = $this->wsAdmin('pwg.permissions.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.permissions.getList', $response);
    }

    public function testGetListResultKeyIsCategories(): void
    {
        $response = $this->wsAdmin('pwg.permissions.getList');

        $result = $response['result'];
        if (! is_array($result)) {
            self::fail('pwg.permissions.getList result is not an array');
        }
        self::assertArrayHasKey('categories', $result);
        self::assertIsArray($result['categories']);
    }

    public function testGetListForbiddenForGuest(): void
    {
        $response = $this->ws('pwg.permissions.getList');

        self::assertSame('fail', $response['stat']);
    }
}
