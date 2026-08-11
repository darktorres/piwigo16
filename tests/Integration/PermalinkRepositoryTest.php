<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permalink\OldPermalinkSortField;
use Piwigo\Permalink\PermalinkRepository;

final class PermalinkRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PermalinkRepository $repo;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new PermalinkRepository(EntityManagerFactory::build($this->conn));
    }

    #[Override]
    protected function tearDown(): void
    {
        // Resets the fixture's own seeded baseline (piwigo-17.0.sql:
        // hit=42, last_hit='2026-08-01 00:00:00') after any test that
        // mutates it.
        $this->conn->executeStatement("UPDATE old_permalinks SET hit = 42, last_hit = '2026-08-01 00:00:00' WHERE permalink = 'old-sample-album'");
        parent::tearDown();
    }

    public function testSetThenFindCategoryIdByPermalinkRoundTrips(): void
    {
        $slug = 'p17-test-' . bin2hex(random_bytes(4));

        $this->repo->setCategoryPermalink(1, $slug);

        self::assertSame(1, $this->repo->findCategoryIdByPermalink($slug));
        self::assertSame($slug, $this->repo->findPermalinkByCategoryId(1));

        $this->repo->clearCategoryPermalink(1);
    }

    public function testFindCategoryIdByPermalinkReturnsNullWhenUnused(): void
    {
        self::assertNull($this->repo->findCategoryIdByPermalink('does-not-exist-' . bin2hex(random_bytes(4))));
    }

    public function testFindPermalinkByCategoryIdReturnsNullWhenUnset(): void
    {
        $this->repo->clearCategoryPermalink(1);

        self::assertNull($this->repo->findPermalinkByCategoryId(1));
    }

    public function testClearCategoryPermalinkRemovesIt(): void
    {
        $slug = 'p17-test-' . bin2hex(random_bytes(4));
        $this->repo->setCategoryPermalink(1, $slug);

        $this->repo->clearCategoryPermalink(1);

        self::assertNull($this->repo->findPermalinkByCategoryId(1));
        self::assertNull($this->repo->findCategoryIdByPermalink($slug));
    }

    public function testInsertOldPermalinkDeletedThenFindOldCategoryIdRoundTrips(): void
    {
        $slug = 'p17-old-test-' . bin2hex(random_bytes(4));

        $this->repo->insertOldPermalinkDeleted(1, $slug);

        self::assertSame(1, $this->repo->findOldCategoryId($slug));

        $this->repo->deleteOldPermalink(1, $slug);
    }

    public function testFindOldCategoryIdReturnsNullWhenNeverUsed(): void
    {
        self::assertNull($this->repo->findOldCategoryId('never-used-' . bin2hex(random_bytes(4))));
    }

    public function testMarkOldPermalinkDeletedUpdatesAnExistingRow(): void
    {
        $slug = 'p17-old-test-' . bin2hex(random_bytes(4));
        $this->repo->insertOldPermalinkDeleted(1, $slug);

        // Should not throw / should not insert a duplicate row -- updates
        // the existing (cat_id, permalink) row's date_deleted instead.
        $this->repo->markOldPermalinkDeleted(1, $slug);

        self::assertSame(1, $this->repo->findOldCategoryId($slug));

        $this->repo->deleteOldPermalink(1, $slug);
    }

    public function testDeleteOldPermalinkRemovesTheRow(): void
    {
        $slug = 'p17-old-test-' . bin2hex(random_bytes(4));
        $this->repo->insertOldPermalinkDeleted(1, $slug);

        $this->repo->deleteOldPermalink(1, $slug);

        self::assertNull($this->repo->findOldCategoryId($slug));
    }

    public function testDeleteOldPermalinkByValueRemovesTheRowAndReturnsTrue(): void
    {
        $slug = 'p17-old-test-' . bin2hex(random_bytes(4));
        $this->repo->insertOldPermalinkDeleted(1, $slug);

        self::assertTrue($this->repo->deleteOldPermalinkByValue($slug));
        self::assertNull($this->repo->findOldCategoryId($slug));
    }

    public function testDeleteOldPermalinkByValueReturnsFalseWhenNothingMatches(): void
    {
        self::assertFalse($this->repo->deleteOldPermalinkByValue('never-used-' . bin2hex(random_bytes(4))));
    }

    public function testClearCategoryPermalinkOnAnUnknownCategoryIsASilentNoop(): void
    {
        // Should neither throw nor affect any real category -- em->find()
        // returns null for a nonexistent id, so this exercises the early
        // `return;` guard directly.
        $this->expectNotToPerformAssertions();

        $this->repo->clearCategoryPermalink(999999);
    }

    public function testSetCategoryPermalinkOnAnUnknownCategoryIsASilentNoop(): void
    {
        $this->repo->setCategoryPermalink(999999, 'p17-test-' . bin2hex(random_bytes(4)));

        self::assertNull($this->repo->findCategoryIdByPermalink('p17-test-does-not-matter'));
    }

    public function testFindAllOrderedByAppliesTheGivenOrderColumn(): void
    {
        $lowSlug = 'aaa-order-test-' . bin2hex(random_bytes(4));
        $highSlug = 'zzz-order-test-' . bin2hex(random_bytes(4));
        $this->repo->insertOldPermalinkDeleted(1, $lowSlug);
        $this->repo->insertOldPermalinkDeleted(1, $highSlug);

        try {
            $rows = $this->repo->findAllOrderedBy(OldPermalinkSortField::Permalink);
            $permalinks = array_map(static fn ($row) => $row->permalink->value, $rows);

            $lowIndex = array_search($lowSlug, $permalinks, true);
            $highIndex = array_search($highSlug, $permalinks, true);

            self::assertIsInt($lowIndex);
            self::assertIsInt($highIndex);
            self::assertLessThan($highIndex, $lowIndex, 'ascending order by permalink puts the lexicographically-earlier slug first');
        } finally {
            $this->repo->deleteOldPermalink(1, $lowSlug);
            $this->repo->deleteOldPermalink(1, $highSlug);
        }
    }

    public function testFindPermalinkMatchesFindsOldAndCurrentPermalinks(): void
    {
        $this->conn->executeStatement("UPDATE categories SET permalink = 'sample-album' WHERE id = 1");

        $matches = $this->repo->findPermalinkMatches(['old-sample-album', 'sample-album']);

        // id/is_old come back as native int under this project's mysqli
        // driver config (unlike varchar columns like 'permalink').
        self::assertSame(1, $matches['old-sample-album']['id']);
        self::assertSame(1, $matches['old-sample-album']['is_old']);
        self::assertSame(1, $matches['sample-album']['id']);
        self::assertSame(0, $matches['sample-album']['is_old']);

        $this->conn->executeStatement('UPDATE categories SET permalink = NULL WHERE id = 1');
    }

    public function testFindPermalinkMatchesReturnsEmptyForNoPermalinks(): void
    {
        self::assertSame([], $this->repo->findPermalinkMatches([]));
    }

    public function testTouchOldPermalinkHitIncrementsTheCounter(): void
    {
        $this->repo->touchOldPermalinkHit('old-sample-album', 1);

        $hit = $this->conn->createQueryBuilder()
            ->select('hit')
            ->from('old_permalinks')
            ->where('permalink = :permalink')
            ->setParameter('permalink', 'old-sample-album')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(43, is_numeric($hit) ? (int) $hit : null);
    }

    public function testDeleteOldPermalinksForCategoriesIsANoOpForNoIds(): void
    {
        $this->repo->deleteOldPermalinksForCategories([]);

        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*) AS c')
            ->from('old_permalinks')
            ->executeQuery()
            ->fetchOne();
        self::assertSame(1, is_numeric($count) ? (int) $count : 0);
    }
}
