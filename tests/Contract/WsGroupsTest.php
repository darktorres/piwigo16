<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsGroupsTest extends ContractTestCase
{
    public function testGetListResponseMatchesSchema(): void
    {
        $response = $this->wsAdmin('pwg.groups.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $response);
    }

    public function testGetListReturnsPagingAndGroups(): void
    {
        $response = $this->wsAdmin('pwg.groups.getList');

        $result = $response['result'];
        if (! is_array($result)) {
            self::fail('pwg.groups.getList result is not an array');
        }
        self::assertArrayHasKey('paging', $result);
        self::assertArrayHasKey('groups', $result);
        self::assertIsArray($result['groups']);
    }

    public function testGetListForbiddenForGuest(): void
    {
        $response = $this->ws('pwg.groups.getList');

        self::assertSame('fail', $response['stat']);
    }
}
