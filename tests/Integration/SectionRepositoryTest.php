<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\SqlCondition;
use Piwigo\Section\SectionRepository;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * Piwigo\Section\SectionRepository -- had no dedicated test file; only
 * ever exercised indirectly through SectionPopulator/SectionInitializer's
 * own hand-built SQL. Same direct-repository-method pattern as
 * SearchRepositoryTest's own sibling class.
 *
 * Same fixture shape as SearchRepositoryTest: images 1-5 (image_category
 * assigns 1,2,3 to category 1 and 4,5 to category 2, category 2's own
 * uppercats is '1,2'); images 1-4 have rating_score 4.50/3.00/5.00/2.00,
 * image 5 has NULL; every image's hit starts at 0.
 */
final class SectionRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SectionRepository $repo;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $this->conn = DbConnection::build();
        $this->repo = new SectionRepository(EntityManagerFactory::build($this->conn));
    }

    #[Override]
    protected function tearDown(): void
    {
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testFindVisibleSubcategoryIdsReturnsDirectSubcategories(): void
    {
        $ids = $this->repo->findVisibleSubcategoryIds('1', SqlCondition::fromRawSql(''));

        self::assertSame(['2'], $ids);
    }

    public function testFindVisibleSubcategoryIdsReturnsEmptyForALeafCategory(): void
    {
        self::assertSame([], $this->repo->findVisibleSubcategoryIds('1,2', SqlCondition::fromRawSql('')));
    }

    public function testFindTopRatedImageIdsReturnsIdsOrderedByRatingDesc(): void
    {
        $ids = $this->repo->findTopRatedImageIds(SqlCondition::fromRawSql(''), 3);

        self::assertSame(['3', '1', '2'], $ids);
    }

    public function testFindTopRatedImageIdsRespectsTheLimit(): void
    {
        self::assertSame(['3'], $this->repo->findTopRatedImageIds(SqlCondition::fromRawSql(''), 1));
    }

    public function testFindTopByHitsImageIdsReturnsIdsOrderedByHitDesc(): void
    {
        $this->conn->executeStatement('UPDATE images SET hit = 5 WHERE id = 2');
        $this->conn->executeStatement('UPDATE images SET hit = 10 WHERE id = 4');

        try {
            $ids = $this->repo->findTopByHitsImageIds(SqlCondition::fromRawSql(''), 5);

            self::assertSame(['4', '2'], $ids);
        } finally {
            $this->conn->executeStatement('UPDATE images SET hit = 0 WHERE id IN (2, 4)');
        }
    }

    public function testFindTopByHitsImageIdsReturnsEmptyWhenNoImageHasAHit(): void
    {
        self::assertSame([], $this->repo->findTopByHitsImageIds(SqlCondition::fromRawSql(''), 5));
    }
}
