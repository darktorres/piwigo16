<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsPluginsTest extends ContractTestCase
{
    public function test_getList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.plugins.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.plugins.getList', $response);
    }

    public function test_getList_result_is_an_array(): void
    {
        $response = $this->wsAdmin('pwg.plugins.getList');

        self::assertIsArray($response['result']);
    }

    public function test_getList_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.plugins.getList');

        self::assertSame('fail', $response['stat']);
    }
}
