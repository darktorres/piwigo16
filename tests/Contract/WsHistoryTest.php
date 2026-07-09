<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsHistoryTest extends ContractTestCase
{
    public function test_activityGetList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.activity.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.activity.getList', $response);
    }

    public function test_activityGetList_contains_result_lines(): void
    {
        $response = $this->wsAdmin('pwg.activity.getList');

        $result = $response['result'];
        if (!is_array($result)) {
            self::fail('pwg.activity.getList result is not an array');
        }

        self::assertArrayHasKey('result_lines', $result);
        self::assertIsArray($result['result_lines']);
    }

    public function test_activityGetList_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.activity.getList');

        self::assertSame('fail', $response['stat']);
    }

    public function test_historySearch_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.history.search');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.history.search', $response);
    }

    public function test_historySearch_contains_lines(): void
    {
        $response = $this->wsAdmin('pwg.history.search');

        $result = $response['result'];
        if (!is_array($result)) {
            self::fail('pwg.history.search result is not an array');
        }

        self::assertArrayHasKey('lines', $result);
        self::assertIsArray($result['lines']);
    }
}
