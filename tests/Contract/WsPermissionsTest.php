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

        $result = $response['result'];
        if (!is_array($result)) {
            self::fail('pwg.permissions.getList result is not an array');
        }
        self::assertArrayHasKey('categories', $result);
        self::assertIsArray($result['categories']);
    }

    public function test_getList_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.permissions.getList');

        self::assertSame('fail', $response['stat']);
    }
}
