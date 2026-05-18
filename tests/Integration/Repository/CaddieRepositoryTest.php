<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see CaddieRepository}. The fixture
 * seeds no caddie rows. Verifies FK CASCADE on both image and user
 * delete (fk_caddie_element_id, fk_caddie_user_id).
 */
final class CaddieRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private CaddieRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new CaddieRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_insertImageIdsBatch_round_trips_via_findImagesNotInCaddie(): void
    {
        // Pre: no rows for user 3. All input image ids should come back as
        // "not in caddie".
        self::assertSame([1, 2, 3], $this->repo->findImagesNotInCaddie([1, 2, 3], 3));

        $this->repo->insertImageIdsBatch(3, [1, 2]);

        // Post: ids 1 and 2 are now in the caddie; id 3 is still not.
        self::assertSame([3], $this->repo->findImagesNotInCaddie([1, 2, 3], 3));
    }

    public function test_findImagesNotInCaddie_filters_to_existing_images(): void
    {
        // Image id 9999 doesn't exist in the fixture — must not appear in
        // the "not in caddie" result either (the LEFT JOIN starts from images).
        $result = $this->repo->findImagesNotInCaddie([1, 9999], 3);
        self::assertSame([1], $result);
    }

    public function test_findImagesNotInCaddie_returns_empty_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findImagesNotInCaddie([], 3));
    }

    /**
     * fk_caddie_element_id ON DELETE CASCADE — image delete clears caddie.
     */
    public function test_image_delete_cascades_to_caddie(): void
    {
        $this->repo->insertImageIdsBatch(3, [1]);
        self::assertSame([], $this->repo->findImagesNotInCaddie([1], 3), 'precondition');

        $this->conn->executeStatement('DELETE FROM piwigo_images WHERE id = 1');

        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_caddie WHERE user_id = 3'
        )->fetchOne();
        self::assertSame(0, $count);
    }

    /**
     * fk_caddie_user_id ON DELETE CASCADE — user delete clears caddie.
     */
    public function test_user_delete_cascades_to_caddie(): void
    {
        $this->repo->insertImageIdsBatch(3, [1, 2]);

        $this->conn->executeStatement('DELETE FROM piwigo_users WHERE id = 3');

        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_caddie WHERE user_id = 3'
        )->fetchOne();
        self::assertSame(0, $count);
    }
}
