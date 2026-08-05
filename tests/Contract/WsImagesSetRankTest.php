<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Ws\PwgImages::setRank() -- WsImagesMutationTest covers the multi-image
 * "reorder" branch and a plain single-image success. This file covers the
 * remaining single-image branches: not-found guards, and the two rank
 * resolution outcomes (clamped to max_rank+1, or defaulted to 1 when the
 * category has no ranked images at all).
 *
 * setRank()'s own "image_id is missing" guard (image_id === null after
 * array_shift()) is NOT chased here: image_id is registered with
 * WsParamFlag::FORCE_ARRAY and no 'default', so PwgServer::addMethod()
 * never ORs in WsParamFlag::OPTIONAL for it (that only happens when a
 * 'default' key is present) -- a caller can never get past the WS layer's
 * own "Missing parameters: image_id" without image_id already being a
 * non-empty array, so array_shift() can never return null. Confirmed live:
 * omitting image_id returns the WS-layer's generic message, never
 * setRank()'s own text.
 *
 * The `$row === false` "max-rank aggregate query returned no row" Exception
 * is not chased either -- a bare `SELECT MAX(...)` with no GROUP BY always
 * returns exactly one row (NULL when there's no match), so fetchAssociative()
 * can never return false here; same "DB-invariant defensive check" shape as
 * this file's other untested `throw new Exception(...)` sites.
 */
final class WsImagesSetRankTest extends ContractTestCase
{
    private const int FIXTURE_IMAGE_ID = 1;

    private const int FIXTURE_CAT_ID = 1;

    private Connection $conn;

    /** @var list<int> */
    private array $imageIdsToDelete = [];

    /** @var list<int> */
    private array $categoryIdsToDelete = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
        $this->loginAsAdmin();
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->imageIdsToDelete as $imageId) {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        }
        foreach (array_reverse($this->categoryIdsToDelete) as $categoryId) {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE category_id = ?', [$categoryId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ?', [$categoryId]);
        }
        parent::tearDown();
    }

    private function pwgToken(): string
    {
        $this->loginAsAdmin();

        return $this->getPwgToken();
    }

    private function createFreshCategory(): int
    {
        $response = $this->callWs('pwg.categories.add', [
            'name' => 'ct_setrank_' . uniqid(),
            'pwg_token' => $this->pwgToken(),
        ]);
        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $id = $result['id'];
        self::assertIsInt($id);
        $this->categoryIdsToDelete[] = $id;

        return $id;
    }

    private function insertThrowawayImage(int $categoryId, ?int $rank = null): int
    {
        $filename = 'setrank-test-' . uniqid() . '.jpg';
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum) VALUES (?, ?, ?)',
            [$filename, 'upload/2026/08/01/' . $filename, md5($filename)]
        );
        $id = (int) $this->conn->lastInsertId();
        $this->imageIdsToDelete[] = $id;

        $rankIdentifier = $this->conn->getDatabasePlatform()
            ->quoteSingleIdentifier('rank');
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::imageCategory() . " (image_id, category_id, {$rankIdentifier}) VALUES (?, ?, ?)",
            [$id, $categoryId, $rank]
        );

        return $id;
    }

    public function test_rank_omitted_returns_missing_param_error(): void
    {
        $response = $this->callWs('pwg.images.setRank', [
            'image_id' => self::FIXTURE_IMAGE_ID,
            'category_id' => self::FIXTURE_CAT_ID,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1002, $response['err']);
        self::assertSame('rank is missing', $response['message']);
    }

    public function test_unknown_image_id_returns_404(): void
    {
        $response = $this->callWs('pwg.images.setRank', [
            'image_id' => 999999,
            'category_id' => self::FIXTURE_CAT_ID,
            'rank' => 1,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('image_id not found', $response['message']);
    }

    public function test_image_not_associated_to_the_category_returns_404(): void
    {
        $freshCatId = $this->createFreshCategory();

        $response = $this->callWs('pwg.images.setRank', [
            'image_id' => self::FIXTURE_IMAGE_ID,
            'category_id' => $freshCatId,
            'rank' => 1,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('This image is not associated to this category', $response['message']);
    }

    public function test_rank_defaults_to_one_when_the_category_has_no_ranked_images(): void
    {
        $freshCatId = $this->createFreshCategory();
        $imageId = $this->insertThrowawayImage($freshCatId, null);

        $response = $this->callWs('pwg.images.setRank', [
            'image_id' => $imageId,
            'category_id' => $freshCatId,
            // A high requested rank is deliberate: it proves the value is
            // being *replaced* by the "no ranked images yet" default (1),
            // not merely accepted as-is.
            'rank' => 99,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(['image_id' => $imageId, 'category_id' => $freshCatId, 'rank' => 1], $response['result']);

        $rankIdentifier = $this->conn->getDatabasePlatform()
            ->quoteSingleIdentifier('rank');
        $stored = $this->conn->fetchOne(
            "SELECT {$rankIdentifier} FROM " . Tables::imageCategory() . ' WHERE image_id = ? AND category_id = ?',
            [$imageId, $freshCatId]
        );
        self::assertSame(1, is_numeric($stored) ? (int) $stored : 0);
    }

    public function test_rank_above_the_current_max_is_clamped_to_max_plus_one(): void
    {
        $freshCatId = $this->createFreshCategory();
        $this->insertThrowawayImage($freshCatId, 1);
        $secondImageId = $this->insertThrowawayImage($freshCatId, null);

        $response = $this->callWs('pwg.images.setRank', [
            'image_id' => $secondImageId,
            'category_id' => $freshCatId,
            'rank' => 99,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(['image_id' => $secondImageId, 'category_id' => $freshCatId, 'rank' => 2], $response['result']);
    }
}
