<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Ws\PwgComments::getList()'s summary/date-range "unable to compute" guards
 * (`$summary === null` / `$dates === null`, PwgError 500) are NOT chased
 * here: CommentRepository::findSummaryCounts()/findDateRange() each run a
 * bare `SELECT count(*)/sum(...)/MIN()/MAX() FROM ... WHERE ...` with no
 * GROUP BY -- an aggregate query with no GROUP BY always returns exactly
 * one row (count=0, sum/MIN/MAX=NULL, for a table with zero matching rows),
 * never zero rows, so Doctrine\DBAL\Connection::fetchAssociative() can
 * never return false here. Genuinely unreachable through any real DB-backed
 * call, not a gap in test coverage.
 *
 * getList()'s own `case 'validated': ... break;` is already exercised for
 * real by test_userComments_getList_filters_by_validated_status() below --
 * its trailing `break;` (the last case in the switch, with nothing but the
 * closing `}` after it) is provably eliminated by OPcache's real
 * optimizer (`opcache.optimization_level` enables jump-to-next-instruction
 * elision) on the live Apache-served process this suite runs against:
 * confirmed live by re-running an equivalent isolated switch/break snippet
 * through PCOV with `opcache.enable_cli=1` +
 * `opcache.optimization_level=0x7FFEBFFF` (this project's real
 * php.ini value) -- the trailing break's own line drops out of collected
 * coverage entirely, while an *identical* CLI run with opcache disabled
 * (this project's default `opcache.enable_cli=Off`) reports it as hit.
 * Same root cause as this project's own documented "OPcache
 * constant-array-folding coverage artifact" precedent, just a different
 * optimizer pass (jump elision vs. constant folding); not a gap in test
 * coverage.
 */
final class WsCommentsTest extends ContractTestCase
{
    private Connection $conn;

    /** @var list<int> */
    private array $commentIdsToDelete = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->commentIdsToDelete !== []) {
            $this->conn->executeStatement(
                'DELETE FROM ' . Tables::comments() . ' WHERE id IN (' . implode(',', array_fill(0, count($this->commentIdsToDelete), '?')) . ')',
                $this->commentIdsToDelete
            );
            $this->commentIdsToDelete = [];
        }
        parent::tearDown();
    }

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

    public function test_userComments_getList_returns_error_when_comments_are_disabled(): void
    {
        $this->conn->executeStatement(
            "UPDATE " . Tables::config() . " SET value = 'false' WHERE param = 'activate_comments'"
        );
        \Piwigo\Cache\CachePools::config()->clear();

        try {
            $response = $this->wsAdmin('pwg.userComments.getList', [
                'per_page' => 10, 'page' => 0, 'status' => 'all',
            ]);

            self::assertSame('fail', $response['stat']);
            self::assertSame(403, $response['err']);
            self::assertSame('Comments are disabled', $response['message']);
        } finally {
            $this->conn->executeStatement(
                "UPDATE " . Tables::config() . " SET value = 'true' WHERE param = 'activate_comments'"
            );
            \Piwigo\Cache\CachePools::config()->clear();
        }
    }

    /**
     * webmaster_id=1 in the fixture config; author_id=1 (fixture_admin)
     * left 2 real fixture comments (ids 1 and 5). Filtering to author_id=1
     * and asserting every returned row's `author_status` is 'main_user'
     * (only true for the real webmaster id) proves the WHERE author_id=...
     * clause actually applied, robust to any other concurrently-created
     * comments from other authors.
     */
    public function test_userComments_getList_filters_by_author_id(): void
    {
        $response = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'all', 'author_id' => 1,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $comments = $result['comments'];
        self::assertIsArray($comments);
        self::assertNotEmpty($comments);
        foreach ($comments as $comment) {
            self::assertIsArray($comment);
            self::assertSame('main_user', $comment['author_status']);
        }
    }

    /**
     * f_min_date's `date_format($min_date, 'Y-m-d 00:00:00')` +
     * `date >= '...'` clause. All 5 fixture comments share the same
     * '2026-08-01 00:00:00' date -- image_id=4 narrows to just fixture
     * comment id 5, keeping this concurrency-safe without needing
     * 'search' (which would reset the date filter entirely, per this
     * class's own docblock).
     */
    public function test_userComments_getList_filters_by_f_min_date(): void
    {
        $before = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'all', 'image_id' => 4,
            'f_min_date' => '2020-01-01',
        ]);
        self::assertSame('ok', $before['stat']);
        $beforeResult = $before['result'];
        self::assertIsArray($beforeResult);
        self::assertIsArray($beforeResult['comments']);
        self::assertNotEmpty($beforeResult['comments'], 'a threshold before the fixture date must still include it');

        $after = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'all', 'image_id' => 4,
            'f_min_date' => '2030-01-01',
        ]);
        self::assertSame('ok', $after['stat']);
        $afterResult = $after['result'];
        self::assertIsArray($afterResult);
        self::assertSame([], $afterResult['comments'], 'a threshold after the fixture date must exclude it');
    }

    /**
     * f_max_date's `date_format($max_date, 'Y-m-d 23:59:59')` +
     * `date <= '...'` clause -- the mirror image of f_min_date above.
     */
    public function test_userComments_getList_filters_by_f_max_date(): void
    {
        $after = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'all', 'image_id' => 4,
            'f_max_date' => '2030-01-01',
        ]);
        self::assertSame('ok', $after['stat']);
        $afterResult = $after['result'];
        self::assertIsArray($afterResult);
        self::assertIsArray($afterResult['comments']);
        self::assertNotEmpty($afterResult['comments'], 'a threshold after the fixture date must still include it');

        $before = $this->wsAdmin('pwg.userComments.getList', [
            'per_page' => 10, 'page' => 0, 'status' => 'all', 'image_id' => 4,
            'f_max_date' => '2020-01-01',
        ]);
        self::assertSame('ok', $before['stat']);
        $beforeResult = $before['result'];
        self::assertIsArray($beforeResult);
        self::assertSame([], $beforeResult['comments'], 'a threshold before the fixture date must exclude it');
    }

    /**
     * getList()'s `$row_author = ...; if (! is_numeric($row['author_id'])
     * or (int) $row['author_id'] === 0 or (int) $row['author_id'] ===
     * guestId()) { $author_name = $row_author; }` branch -- every fixture
     * comment (and every other test's own added comments) has a real
     * registered author, never a guest. Posting through the public,
     * unauthenticated pwg.images.addComment (no prior login on this test's
     * own cookie jar) makes CommentService::insertComment() stamp
     * author_id = CurrentConfig::guestId() for real, the only way to reach
     * this branch through the real WS route.
     *
     * PwgImages::getInfo() only populates its own 'comment_post' (the
     * ephemeral key addComment() needs) for a guest caller when
     * CurrentConfig::commentsForall() is true -- 'false' in the fixture
     * config, confirmed by reading getInfo()'s own
     * `(! AccessControl::isAGuest() or CurrentConfig::commentsForall())`
     * guard -- so it's flipped on for the duration of this test, same
     * config-toggle-then-restore pattern as
     * test_userComments_getList_returns_error_when_comments_are_disabled()
     * above.
     */
    public function test_userComments_getList_shows_the_raw_author_name_for_an_anonymous_comment(): void
    {
        $this->conn->executeStatement(
            "UPDATE " . Tables::config() . " SET value = 'true' WHERE param = 'comments_forall'"
        );
        \Piwigo\Cache\CachePools::config()->clear();

        try {
            $info = $this->ws('pwg.images.getInfo', ['image_id' => 1]);
            $infoResult = $info['result'] ?? null;
            self::assertIsArray($infoResult);
            $commentPost = $infoResult['comment_post'] ?? null;
            self::assertIsArray($commentPost);
            $rawKey = $commentPost['key'] ?? null;
            self::assertIsString($rawKey);
            sleep(3);

            $marker = 'ct anonymous comment ' . uniqid();
            $authorName = 'CT Guest Author';

            $add = $this->ws('pwg.images.addComment', [
                'image_id' => 1,
                'author' => $authorName,
                'content' => $marker,
                'key' => $rawKey,
            ]);
            self::assertSame('ok', $add['stat']);
            $addResult = $add['result'] ?? null;
            self::assertIsArray($addResult);
            $comment = $addResult['comment'] ?? null;
            self::assertIsArray($comment);
            $commentId = $comment['id'] ?? null;
            self::assertTrue(is_int($commentId) || (is_string($commentId) && is_numeric($commentId)));
            $this->commentIdsToDelete[] = (int) $commentId;

            $response = $this->wsAdmin('pwg.userComments.getList', [
                'per_page' => 10, 'page' => 0, 'status' => 'all', 'search' => $marker,
            ]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            $comments = $result['comments'];
            self::assertIsArray($comments);
            self::assertNotEmpty($comments);
            foreach ($comments as $commentRow) {
                self::assertIsArray($commentRow);
                self::assertSame($authorName, $commentRow['author']);
            }
        } finally {
            $this->conn->executeStatement(
                "UPDATE " . Tables::config() . " SET value = 'false' WHERE param = 'comments_forall'"
            );
            \Piwigo\Cache\CachePools::config()->clear();
        }
    }
}
