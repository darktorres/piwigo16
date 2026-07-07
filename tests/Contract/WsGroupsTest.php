<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsGroupsTest extends ContractTestCase
{
    public function test_getList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.groups.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.groups.getList', $response);
    }

    public function test_getList_returns_paging_and_groups(): void
    {
        $response = $this->wsAdmin('pwg.groups.getList');

        $result = $response['result'];
        self::assertArrayHasKey('paging', $result);
        self::assertArrayHasKey('groups', $result);
        self::assertIsArray($result['groups']);
    }

    public function test_getList_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.groups.getList');

        self::assertSame('fail', $response['stat']);
    }
}
