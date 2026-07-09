<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsCommentsTest extends ContractTestCase
{
    public function test_userComments_getList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10,
            'page'     => 0,
            'status'   => 'all',
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.userComments.getList', $response);
    }

    public function test_userComments_getList_contains_summary_and_comments(): void
    {
        $response = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10,
            'page'     => 0,
            'status'   => 'all',
        ]);

        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('summary', $result);
        self::assertArrayHasKey('comments', $result);
        self::assertIsArray($result['comments']);
    }

    public function test_userComments_getList_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.userComments.getList', [
            'per_page' => 10,
            'page'     => 0,
            'status'   => 'all',
        ]);

        self::assertSame('fail', $response['stat']);
    }
}
