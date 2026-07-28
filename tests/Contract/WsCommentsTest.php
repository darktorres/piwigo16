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

    public function test_userComments_getList_invalid_status_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'not-a-real-status',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Status must be: all, pending or validated', $response['message']);
    }

    public function test_userComments_getList_invalid_per_page_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 7, 'page' => 0, 'status' => 'all',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Per page must be: 5, 10, 25 or 50', $response['message']);
    }

    public function test_userComments_getList_invalid_f_min_date_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'all', 'f_min_date' => 'not-a-date',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Invalid f_min_date', $response['message']);
    }

    public function test_userComments_getList_invalid_f_max_date_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'all', 'f_max_date' => 'not-a-date',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Invalid f_max_date', $response['message']);
    }

    public function test_userComments_getList_filters_by_pending_status(): void
    {
        // fixture comment id 5 (image 4) is the only pending (validated=0)
        // comment -- confirmed live via a direct DB read while writing an
        // earlier PwgImages test in this same batch.
        $response = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'pending',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $comments = $result['comments'];
        self::assertIsArray($comments);
        self::assertNotEmpty($comments);
        foreach ($comments as $comment) {
            self::assertIsArray($comment);
            self::assertTrue($comment['is_pending']);
        }
    }

    public function test_userComments_getList_filters_by_validated_status(): void
    {
        $response = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'validated',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $comments = $result['comments'];
        self::assertIsArray($comments);
        self::assertNotEmpty($comments);
        foreach ($comments as $comment) {
            self::assertIsArray($comment);
            self::assertFalse($comment['is_pending']);
        }
    }

    public function test_userComments_getList_filters_by_image_id(): void
    {
        $response = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'all', 'image_id' => 4,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $comments = $result['comments'];
        self::assertIsArray($comments);
        self::assertNotEmpty($comments);
        foreach ($comments as $comment) {
            self::assertIsArray($comment);
            self::assertIsString($comment['admin_link']);
            self::assertStringContainsString('photo-4', $comment['admin_link']);
        }
    }

    public function test_userComments_getList_search_overrides_other_filters(): void
    {
        // 'search' resets $where_clauses to '1=1' and only applies the
        // content LIKE filter -- confirmed via reading getList()'s own
        // source ("reset all filters during search").
        $response = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'all',
            'image_id' => 999999, // would otherwise exclude every real comment
            'search' => 'Fixture comment',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $comments = $result['comments'];
        self::assertIsArray($comments);
        self::assertNotEmpty($comments);
        foreach ($comments as $comment) {
            self::assertIsArray($comment);
            self::assertIsString($comment['raw_content']);
            self::assertStringContainsString('Fixture comment', $comment['raw_content']);
        }
    }
}
