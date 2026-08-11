<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Db\DbConnection;

/**
 * Ws\PwgImages::addImageCategoryRelations() -- a private helper only
 * reachable through pwg.images.setInfo()'s `categories` parameter (or
 * pwg.images.add(), already exercised for its "digits prefix" branch by
 * WsImagesChunkedUploadTest). Covers the branches WsImagesSetInfoTest
 * doesn't reach: the categories_string==='' shortcut (both replace and
 * non-replace mode), the "no valid digit token survives filtering" shortcut,
 * the "diff against existing associations leaves nothing new" early return,
 * and the auto-rank branch for a category with zero prior ranked images.
 */
final class WsImagesCategoryRelationsTest extends ContractTestCase
{
    private const int FIXTURE_CAT_ID = 1;

    private Connection $conn;

    /**
     * @var list<int>
     */
    private array $imageIdsToDelete = [];

    /**
     * @var list<int>
     */
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
            $this->conn->executeStatement('DELETE FROM image_category WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        }
        foreach (array_reverse($this->categoryIdsToDelete) as $categoryId) {
            $this->conn->executeStatement('DELETE FROM image_category WHERE category_id = ?', [$categoryId]);
            $this->conn->executeStatement('DELETE FROM categories WHERE id = ?', [$categoryId]);
        }
        parent::tearDown();
    }

    private function pwgToken(): string
    {
        $this->loginAsAdmin();

        return $this->getPwgToken();
    }

    /**
     * @param list<int> $categoryIds
     */
    private function insertThrowawayImage(array $categoryIds = []): int
    {
        $filename = 'cat-relations-test-' . uniqid() . '.jpg';
        $this->conn->executeStatement(
            'INSERT INTO images (file, path, md5sum) VALUES (?, ?, ?)',
            [$filename, 'upload/2026/08/01/' . $filename, md5($filename)]
        );
        $id = (int) $this->conn->lastInsertId();
        $this->imageIdsToDelete[] = $id;

        foreach ($categoryIds as $catId) {
            $this->conn->executeStatement(
                'INSERT INTO image_category (image_id, category_id) VALUES (?, ?)',
                [$id, $catId]
            );
        }

        return $id;
    }

    /**
     * Creates a virtual category (no prior photos, no prior ranked associations) via the WS API.
     */
    private function createFreshCategory(): int
    {
        $response = $this->callWs('pwg.categories.add', [
            'name' => 'ct_relations_' . uniqid(),
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

    /**
     * @return list<int>
     */
    private function categoryIdsOf(int $imageId): array
    {
        return $this->conn->fetchFirstColumn(
            'SELECT category_id FROM image_category WHERE image_id = ?',
            [$imageId]
        );
    }

    public function testCategoriesEmptyStringWithReplaceModeDeletesAllAssociations(): void
    {
        $imageId = $this->insertThrowawayImage([self::FIXTURE_CAT_ID]);
        self::assertSame([self::FIXTURE_CAT_ID], $this->categoryIdsOf($imageId));

        $response = $this->callWs('pwg.images.setInfo', [
            'image_id' => $imageId,
            'categories' => '',
            'multiple_value_mode' => 'replace',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame([], $this->categoryIdsOf($imageId));
    }

    public function testCategoriesEmptyStringWithAppendModeLeavesAssociationsUntouched(): void
    {
        $imageId = $this->insertThrowawayImage([self::FIXTURE_CAT_ID]);

        $response = $this->callWs('pwg.images.setInfo', [
            'image_id' => $imageId,
            'categories' => '',
            'multiple_value_mode' => 'append',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame([self::FIXTURE_CAT_ID], $this->categoryIdsOf($imageId));
    }

    public function testCategoriesWithNoValidDigitTokensAndReplaceModeIsANoop(): void
    {
        $imageId = $this->insertThrowawayImage([self::FIXTURE_CAT_ID]);

        $response = $this->callWs('pwg.images.setInfo', [
            'image_id' => $imageId,
            'categories' => 'not-a-digit;also-not',
            'multiple_value_mode' => 'replace',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        // every token was filtered out by the '/^\d+$/' check -- cat_ids ends
        // up empty, hitting the *same* unconditional "delete every existing
        // association for this image_id" query as the literal ''
        // categories_string branch (the code doesn't distinguish "explicitly
        // empty" from "non-empty but nothing survived filtering"), so
        // replace_mode still wipes the pre-existing association.
        self::assertSame([], $this->categoryIdsOf($imageId));
    }

    public function testCategoriesReassociatingAnAlreadyAssociatedCategoryIsANoop(): void
    {
        $imageId = $this->insertThrowawayImage([self::FIXTURE_CAT_ID]);

        $response = $this->callWs('pwg.images.setInfo', [
            'image_id' => $imageId,
            'categories' => (string) self::FIXTURE_CAT_ID,
            'multiple_value_mode' => 'append',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        // new_cat_ids = array_diff([1], [1]) = [] -- addImageCategoryRelations()
        // returns true immediately without touching the rank/insert machinery.
        self::assertSame([self::FIXTURE_CAT_ID], $this->categoryIdsOf($imageId));
    }

    public function testCategoriesMixedValidAndInvalidTokensSkipsTheInvalidOne(): void
    {
        $freshCatId = $this->createFreshCategory();
        $imageId = $this->insertThrowawayImage();

        $response = $this->callWs('pwg.images.setInfo', [
            'image_id' => $imageId,
            // 'not-a-digit' fails the '/^\d+$/' per-token filter and hits
            // the loop's own `continue` -- distinct from
            // test_categories_with_no_valid_digit_tokens_..._is_a_noop
            // above, where *every* token is filtered out and cat_ids ends
            // up empty. Here one real, known category token survives
            // alongside the skipped one.
            'categories' => 'not-a-digit;' . $freshCatId,
            'multiple_value_mode' => 'append',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame([$freshCatId], $this->categoryIdsOf($imageId));
    }

    public function testCategoriesAutoRankOnABrandNewCategoryStartsAtOne(): void
    {
        $freshCatId = $this->createFreshCategory();
        $imageId = $this->insertThrowawayImage();

        $response = $this->callWs('pwg.images.setInfo', [
            'image_id' => $imageId,
            'categories' => (string) $freshCatId,
            'multiple_value_mode' => 'append',
            'pwg_token' => $this->pwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);

        // The category has zero prior images, so the MAX(`rank`) aggregate
        // for it returns no row at all -- current_rank_of[$cat_id] defaults
        // to 0, then the 'auto' branch adds 1.
        $rankIdentifier = $this->conn->getDatabasePlatform()
            ->quoteSingleIdentifier('rank');
        $rank = $this->conn->fetchOne(
            "SELECT {$rankIdentifier} FROM " . 'image_category WHERE image_id = ? AND category_id = ?',
            [$imageId, $freshCatId]
        );
        self::assertSame(1, is_numeric($rank) ? (int) $rank : 0);
    }
}
