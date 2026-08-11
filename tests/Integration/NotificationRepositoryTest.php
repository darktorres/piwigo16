<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Permission\SqlCondition;

/**
 * The committed fixture seeds every comment/image/user at one uniform
 * timestamp (2026-08-01 00:00:00, matching PIWIGO_TEST_NOW) -- setUp()
 * below explicitly restores graduated dates this class's own tests need to
 * exercise date-RANGE filtering meaningfully, scoped to this class's own DB
 * session only. Resulting shape: comments 1-4 validated (validation_date
 * '2026-07-07 05:02:38'), comment 5 unvalidated (validated=0, on image 4);
 * images 1-2 date_available '...05:02:36', 3-4 '...05:02:37', 5
 * '...05:02:38'; users 1-2 registered '...05:02:35', 3-4 '...05:02:38'.
 */
final class NotificationRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private NotificationRepository $repo;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        $freshFixture = ! self::$fixtureReady;
        if ($freshFixture) {
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
        $this->repo = new NotificationRepository(EntityManagerFactory::build($this->conn));

        if ($freshFixture) {
            // The committed fixture seeds every comment/image/user at one
            // uniform timestamp (2026-08-01 00:00:00, matching
            // PIWIGO_TEST_NOW) -- this class's own tests need graduated,
            // distinguishable dates to exercise date-RANGE filtering
            // meaningfully, so they're set explicitly here, scoped to this
            // test class's own DB session only (never touches the shared
            // fixture file). See this class's own docblock for the exact
            // shape these values match.
            // validated is a genuine boolean column -- a bare `1` literal
            // in the SQL text (unlike a bound parameter, which the driver
            // coerces implicitly) is rejected outright by Postgres.
            $validatedLiteral = $this->dbDriver === 'pgsql' ? 'true' : '1';
            $this->conn->executeStatement("UPDATE comments SET validation_date = '2026-07-07 05:02:38' WHERE validated = {$validatedLiteral}");
            $this->conn->executeStatement("UPDATE images SET date_available = '2026-07-07 05:02:36' WHERE id IN (1, 2)");
            $this->conn->executeStatement("UPDATE images SET date_available = '2026-07-07 05:02:37' WHERE id IN (3, 4)");
            $this->conn->executeStatement("UPDATE images SET date_available = '2026-07-07 05:02:38' WHERE id = 5");
            $this->conn->executeStatement("UPDATE user_infos SET registration_date = '2026-07-07 05:02:35' WHERE user_id IN (1, 2)");
            $this->conn->executeStatement("UPDATE user_infos SET registration_date = '2026-07-07 05:02:38' WHERE user_id IN (3, 4)");
        }
    }

    public function testCountByTypeCountsNewCommentsInRange(): void
    {
        $count = $this->repo->countByType('new_comments', '2026-07-07 05:02:37', '2026-07-07 05:02:39', new SqlCondition(''));

        self::assertSame(4, $count);
    }

    public function testFindIdsByTypeReturnsNewCommentIds(): void
    {
        $ids = $this->repo->findIdsByType('new_comments', '2026-07-07 05:02:37', '2026-07-07 05:02:39', new SqlCondition(''));

        sort($ids);
        self::assertSame([1, 2, 3, 4], $ids);
    }

    public function testCountByTypeExcludesCommentsOutsideTheRange(): void
    {
        $count = $this->repo->countByType('new_comments', '2026-07-07 05:02:39', '2026-07-07 05:02:40', new SqlCondition(''));

        self::assertSame(0, $count);
    }

    public function testCountByTypeCountsUnvalidatedComments(): void
    {
        // Fixture comment 5 is already unvalidated (validated=0) on its
        // own -- this insert adds a second, proving the filter counts
        // every matching row, not just one.
        $this->conn->executeStatement(
            'INSERT INTO comments (image_id, date, author, anonymous_id, content, validated) VALUES (1, NOW(), ?, ?, ?, ?)',
            ['test author', '127.0.0.9', 'pending test comment', 0]
        );

        $count = $this->repo->countByType('unvalidated_comments', null, null, new SqlCondition(''));

        self::assertSame(2, $count);

        $this->conn->executeStatement("DELETE FROM comments WHERE author = 'test author'");
    }

    public function testCountByTypeCountsNewElementsInRange(): void
    {
        $count = $this->repo->countByType('new_elements', '2026-07-07 05:02:36', '2026-07-07 05:02:38', new SqlCondition(''));

        self::assertSame(3, $count);
    }

    public function testFindIdsByTypeReturnsNewElementIds(): void
    {
        $ids = $this->repo->findIdsByType('new_elements', '2026-07-07 05:02:36', '2026-07-07 05:02:38', new SqlCondition(''));

        sort($ids);
        self::assertSame([3, 4, 5], $ids);
    }

    public function testCountByTypeCountsUpdatedCategoriesInRange(): void
    {
        // updated_categories shares new_elements' image_category-driven
        // query shape, just aliased to category_id instead of image_id.
        $count = $this->repo->countByType('updated_categories', '2026-07-07 05:02:36', '2026-07-07 05:02:38', new SqlCondition(''));

        self::assertSame(2, $count);
    }

    public function testCountByTypeCountsNewUsersInRange(): void
    {
        $count = $this->repo->countByType('new_users', '2026-07-07 05:02:35', '2026-07-07 05:02:39', new SqlCondition(''));

        self::assertSame(2, $count);
    }

    public function testFindIdsByTypeReturnsNewUserIds(): void
    {
        $ids = $this->repo->findIdsByType('new_users', '2026-07-07 05:02:35', '2026-07-07 05:02:39', new SqlCondition(''));

        sort($ids);
        self::assertSame([3, 4], $ids);
    }

    public function testCountByTypeAppliesTheRestrictSqlFragment(): void
    {
        // A restrict fragment that excludes every image_category row --
        // proves it's actually appended to the query, not ignored. DQL
        // property path (ic.categoryId), not the raw SQL column name --
        // see NotificationRepository's own docblock.
        $count = $this->repo->countByType('new_elements', null, null, new SqlCondition('ic.categoryId = -1'));

        self::assertSame(0, $count);
    }

    public function testCountByTypeRejectsAnUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repo->countByType('bogus_type', null, null, new SqlCondition(''));
    }

    public function testFindRecentPostDatesGroupsByDate(): void
    {
        $dates = $this->repo->findRecentPostDates(new SqlCondition('1 = 1'), 10);

        self::assertCount(3, $dates);
        $byDate = [];
        foreach ($dates as $row) {
            $dateAvailable = $row->dateAvailable;
            if (is_string($dateAvailable)) {
                $byDate[$dateAvailable] = $row;
            }
        }

        self::assertSame(2, $byDate['2026-07-07 05:02:36']->nbElements);
        self::assertSame(1, $byDate['2026-07-07 05:02:38']->nbElements);
    }

    public function testFindRecentElementsForDateReturnsMatchingRows(): void
    {
        $rows = $this->repo->findRecentElementsForDate(new SqlCondition('1 = 1'), '2026-07-07 05:02:36', 10);

        $ids = array_column($rows, 'id');
        sort($ids);
        self::assertSame([1, 2], $ids);

        // The full-row-by-id fetch is a DQL ImageEntity fetch mapped
        // back through Image\Projection\Image::fromEntity()->toArray()
        // -- confirms the real snake_case keys DerivativeImage::thumbUrl()/
        // SrcImage's own constructor read are actually present, not
        // just 'id'.
        $byId = [];
        foreach ($rows as $row) {
            if (is_numeric($row['id'])) {
                $byId[(int) $row['id']] = $row;
            }
        }
        // The upload path's own hash suffix is baked into each fixture
        // file at regen time and genuinely differs between
        // piwigo-17.0.sql and piwigo-17.0-pgsql.sql -- both were
        // generated via separate, independent install+upload runs
        // (confirmed live: every one of the 5 real fixture photos has a
        // different suffix between the two files), not a stale value.
        self::assertSame('fixture-photo-1.jpg', $byId[1]['file']);
        self::assertSame(
            $this->dbDriver === 'pgsql'
                ? 'upload/2026/08/01/20260801000000-2e7e2ce3.jpg'
                : 'upload/2026/08/01/20260801000000-2e7e6c90.jpg',
            $byId[1]['path']
        );
        self::assertSame('fixture-photo-2.jpg', $byId[2]['file']);
        self::assertSame(
            $this->dbDriver === 'pgsql'
                ? 'upload/2026/08/01/20260801000000-4a0136e2.jpg'
                : 'upload/2026/08/01/20260801000000-4a014fb5.jpg',
            $byId[2]['path']
        );
    }

    public function testFindRecentCategoriesForDateReturnsMatchingRows(): void
    {
        $rows = $this->repo->findRecentCategoriesForDate(new SqlCondition('1 = 1'), '2026-07-07 05:02:36', 10);

        self::assertNotSame([], $rows);
    }
}
