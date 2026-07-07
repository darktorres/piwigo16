<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsPermissionsTest extends ContractTestCase
{
    public function test_getList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.permissions.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.permissions.getList', $response);
    }

    public function test_getList_result_key_is_categories(): void
    {
        $response = $this->wsAdmin('pwg.permissions.getList');

        self::assertArrayHasKey('categories', $response['result']);
        self::assertIsArray($response['result']['categories']);
    }

    public function test_getList_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.permissions.getList');

        self::assertSame('fail', $response['stat']);
    }
}
