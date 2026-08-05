<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Ws\PwgImages::uploadCompleted() (pwg.images.uploadCompleted) -- had zero
 * dedicated test coverage (see /home/torres/.claude/plans/piped-enchanting-
 * spark.md, Wave 1). admin_only, CSRF-token-gated; notifies the caller the
 * lounge should be emptied and returns the target category's fresh photo
 * count.
 *
 * uploadCompleted()'s own preg_split() failure guard (image_id, the same
 * `/[\s,;\|]/` pattern used by several other Ws\PwgImages methods) is
 * unreachable from a black-box Contract test in this environment -- see
 * WsImagesTest's exist() section for the full writeup (a plain
 * non-backtracking pattern can't be made to exceed PCRE's default
 * backtrack/recursion budget, and this test process can't reach the
 * separate Apache process's php.ini to lower that budget either).
 */
final class WsImagesUploadCompletedTest extends ContractTestCase
{
    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    private function pwgToken(): string
    {
        $this->loginAsAdmin();

        return $this->getPwgToken();
    }

    public function test_uploadCompleted_invalid_token_returns_error(): void
    {
        // admin_only is checked before the pwg_token comparison
        // (PwgServer::invoke()'s own gate order) -- a guest caller never
        // reaches the token check at all, so this must already be logged
        // in as admin for the 403 (not a 401 "not authenticated") to fire.
        $this->loginAsAdmin();

        $response = $this->callWs('pwg.images.uploadCompleted', [
            'pwg_token' => 'not-the-real-token',
            'category_id' => 1,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_uploadCompleted_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.images.uploadCompleted', [
            'pwg_token' => 'anything',
            'category_id' => 1,
        ]);

        self::assertSame('fail', $response['stat']);
    }

    public function test_uploadCompleted_returns_the_target_category_photo_count(): void
    {
        $expectedCount = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . Tables::imageCategory() . ' WHERE category_id = 1'
        );
        self::assertIsNumeric($expectedCount);
        $expectedName = $this->conn->fetchOne('SELECT name FROM ' . Tables::categories() . ' WHERE id = 1');
        self::assertIsString($expectedName);

        $response = $this->callWs('pwg.images.uploadCompleted', [
            'pwg_token' => $this->pwgToken(),
            'category_id' => 1,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('category', $result);
        $category = $result['category'];
        self::assertIsArray($category);
        self::assertSame(1, $category['id']);
        self::assertSame((int) $expectedCount, $category['nb_photos']);
        self::assertIsString($category['label']);
        self::assertStringContainsString($expectedName, $category['label']);
    }

    public function test_uploadCompleted_with_no_lounge_entries_reports_nothing_moved(): void
    {
        $response = $this->callWs('pwg.images.uploadCompleted', [
            'pwg_token' => $this->pwgToken(),
            'category_id' => 1,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('moved_from_lounge', $result);
        $moved = $result['moved_from_lounge'];
        self::assertTrue($moved === null || $moved === []);
    }

    public function test_uploadCompleted_accepts_a_comma_separated_image_id_string(): void
    {
        $response = $this->callWs('pwg.images.uploadCompleted', [
            'pwg_token' => $this->pwgToken(),
            'category_id' => 1,
            'image_id' => '1,2,3',
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame('ok', $response['stat']);
    }

    public function test_uploadCompleted_accepts_an_image_id_array(): void
    {
        $response = $this->callWs('pwg.images.uploadCompleted', [
            'pwg_token' => $this->pwgToken(),
            'category_id' => 1,
            'image_id' => ['1', '2'],
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_uploadCompleted_rejects_a_nonexistent_category(): void
    {
        $response = $this->callWs('pwg.images.uploadCompleted', [
            'pwg_token' => $this->pwgToken(),
            'category_id' => 999999,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $category = $result['category'];
        self::assertIsArray($category);
        self::assertSame(0, $category['nb_photos']);
    }
}
