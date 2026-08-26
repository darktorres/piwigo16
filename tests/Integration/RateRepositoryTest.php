<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Rate\Projection\RaterInfo;
use Piwigo\Rate\Projection\RateSummary;
use Piwigo\Rate\Projection\RateSummaryForElement;
use Piwigo\Rate\Projection\RatingReportRow;
use Piwigo\Rate\Projection\RatingScoreUpdate;
use Piwigo\Rate\RateEntity;
use Piwigo\Rate\RateRepository;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * Every mutating test below either operates on a disposable rate row
 * (inserted and cleaned up within the same test, never colliding with a
 * fixture (element_id, user_id) pair) or restores the exact fixture value
 * it touched before returning -- the fixture loads once per class (not per
 * test), and a first pass at this file left several tests silently
 * order-dependent on each other's mutations (same lesson as
 * CommentRepositoryTest).
 */
final class RateRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private RateRepository $repo;

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

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(RateEntity::class), RateRepository::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testFindElementIdsForUserAndAnonymousId(): void
    {
        // fixture: user_id 1 rated element 1 and element 3, both with
        // anonymous_id ''
        $ids = $this->repo->findElementIdsForUserAndAnonymousId(UserId::from(1), '');

        sort($ids);
        self::assertSame([1, 3], $ids);
    }

    public function testFindElementIdsReturnsEmptyForNoMatch(): void
    {
        self::assertSame([], $this->repo->findElementIdsForUserAndAnonymousId(UserId::from(1), '10.0.0'));
    }

    public function testDeleteByUserAnonymousAndElements(): void
    {
        $this->repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-a', 3);

        $this->repo->deleteByUserAnonymousAndElements(UserId::from(2), 'disp-a', [5]);

        self::assertSame([], $this->repo->findElementIdsForUserAndAnonymousId(UserId::from(2), 'disp-a'));
    }

    public function testDeleteByUserAnonymousAndElementsIsANoOpForEmptyIds(): void
    {
        $this->repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-b', 3);

        try {
            $this->repo->deleteByUserAnonymousAndElements(UserId::from(2), 'disp-b', []);

            self::assertSame([5], $this->repo->findElementIdsForUserAndAnonymousId(UserId::from(2), 'disp-b'));
        } finally {
            $this->repo->deleteByUserAnonymousAndElements(UserId::from(2), 'disp-b', [5]);
        }
    }

    public function testReassignAnonymousId(): void
    {
        $this->repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-c-old', 3);

        try {
            $this->repo->reassignAnonymousId(UserId::from(2), 'disp-c-old', 'disp-c-new');

            self::assertSame([], $this->repo->findElementIdsForUserAndAnonymousId(UserId::from(2), 'disp-c-old'));
            self::assertSame([5], $this->repo->findElementIdsForUserAndAnonymousId(UserId::from(2), 'disp-c-new'));
        } finally {
            $this->repo->deleteByUserAnonymousAndElements(UserId::from(2), 'disp-c-new', [5]);
        }
    }

    public function testDeleteExistingRateScopedToAnonymousId(): void
    {
        $this->repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-d', 1);

        try {
            // mismatched anonymous_id -- must not delete
            $this->repo->deleteExistingRate(ImageId::from(5), UserId::from(2), 'wrong-ip');

            self::assertSame(1, $this->fetchRateCount(5, 2));
        } finally {
            $this->repo->deleteExistingRate(ImageId::from(5), UserId::from(2), null);
        }
    }

    public function testDeleteExistingRateWithNullAnonymousIdMatchesAny(): void
    {
        $this->repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-e', 1);

        $this->repo->deleteExistingRate(ImageId::from(5), UserId::from(2), null);

        self::assertSame(0, $this->fetchRateCount(5, 2));
    }

    public function testInsertRate(): void
    {
        $this->repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-f', 3);

        try {
            $value = $this->conn->createQueryBuilder()
                ->select('rate')
                ->from('rate')
                ->where('element_id = 5')
                ->andWhere('user_id = 2')
                ->executeQuery()
                ->fetchOne();

            self::assertSame(3, is_numeric($value) ? (int) $value : null);
        } finally {
            $this->repo->deleteExistingRate(ImageId::from(5), UserId::from(2), null);
        }
    }

    /**
     * findRateSummaries()'s own `! is_numeric($row['element_id'])`
     * defensive `continue` (and findUsersWithStatusByIdUsername()'s
     * identically-shaped one below) is unreachable through any real row:
     * `rate.element_id`/`users.id` are both NOT NULL integer PKs enforced
     * by the schema (see tests/Fixtures/piwigo-17.0.sql's own CREATE
     * TABLE), so a genuine DB row can never produce a non-numeric value
     * here -- same "confirmed unreachable, not worth a forced test"
     * treatment as this project's documented HttpClientService-only
     * skip list, just via a schema constraint instead of a network call.
     */
    public function testFindRateSummariesMatchesTheFixture(): void
    {
        $summaries = $this->repo->findRateSummaries();

        // element 1: rates 5 (user 1) + 4 (user 3)
        self::assertEquals(new RateSummary(2, 9.0), $summaries[1]);
        // element 2: rate 3 (user 4)
        self::assertEquals(new RateSummary(1, 3.0), $summaries[2]);
        // element 5 has no rate at all
        self::assertArrayNotHasKey(5, $summaries);
    }

    public function testUpdateRatingScores(): void
    {
        $original = $this->fetchRatingScore(1);

        try {
            $this->repo->updateRatingScores([
                new RatingScoreUpdate(id: 1, ratingScore: 4.75),
            ]);

            self::assertSame(4.75, $this->fetchRatingScore(1));
        } finally {
            $this->conn->createQueryBuilder()
                ->update('images')
                ->set('rating_score', ':score')
                ->where('id = 1')
                ->setParameter('score', $original)
                ->executeStatement();
        }
    }

    public function testUpdateRatingScoresIsANoOpForAnEmptyList(): void
    {
        $original = $this->fetchRatingScore(1);

        $this->repo->updateRatingScores([]);

        self::assertSame($original, $this->fetchRatingScore(1));
    }

    public function testFindImageIdsWithStaleRatingScore(): void
    {
        // element 4 (rating_score 2.00) has a rate row in the fixture, so
        // it's not "stale"; simulate its rate being deleted without the
        // score being recomputed yet.
        $deletedRow = $this->conn->createQueryBuilder()
            ->select('*')
            ->from('rate')
            ->where('element_id = 4')
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($deletedRow);

        $this->conn->createQueryBuilder()
            ->delete('rate')
            ->where('element_id = 4')
            ->executeStatement();

        try {
            self::assertSame([4], $this->repo->findImageIdsWithStaleRatingScore());
        } finally {
            $this->conn->createQueryBuilder()
                ->insert('rate')
                ->values([
                    'user_id' => ':userId',
                    'element_id' => ':elementId',
                    'anonymous_id' => ':anonymousId',
                    'rate' => ':rate',
                    'date' => ':date',
                ])
                ->setParameter('userId', $deletedRow['user_id'])
                ->setParameter('elementId', $deletedRow['element_id'])
                ->setParameter('anonymousId', $deletedRow['anonymous_id'])
                ->setParameter('rate', $deletedRow['rate'])
                ->setParameter('date', $deletedRow['date'])
                ->executeStatement();
        }
    }

    public function testClearRatingScores(): void
    {
        $original1 = $this->fetchRatingScore(1);
        $original2 = $this->fetchRatingScore(2);

        try {
            $this->repo->clearRatingScores([1, 2]);

            self::assertNull($this->fetchRatingScore(1));
            self::assertNull($this->fetchRatingScore(2));
            // untouched
            self::assertSame(5.0, $this->fetchRatingScore(3));
        } finally {
            $this->conn->createQueryBuilder()
                ->update('images')
                ->set('rating_score', ':score')
                ->where('id = 1')
                ->setParameter('score', $original1)
                ->executeStatement();
            $this->conn->createQueryBuilder()
                ->update('images')
                ->set('rating_score', ':score')
                ->where('id = 2')
                ->setParameter('score', $original2)
                ->executeStatement();
        }
    }

    public function testClearRatingScoresIsANoOpForEmptyIds(): void
    {
        $original = $this->fetchRatingScore(1);

        $this->repo->clearRatingScores([]);

        self::assertSame($original, $this->fetchRatingScore(1));
    }

    /**
     * fixture: user1 rated element1(=5) and element3(=5); user3 rated
     * element1(=4) and element4(=2); user4 rated element2(=3). images
     * rating_score: 1=>4.50, 2=>3.00, 3=>5.00, 4=>2.00, 5=>NULL.
     */
    public function testFindUsernamesByIdMapsIdToUsername(): void
    {
        $usernames = $this->repo->findUsernamesById();

        ksort($usernames);
        self::assertSame(
            [
                1 => 'fixture_admin',
                2 => 'guest',
                3 => 'regular_user',
                4 => 'power_user',
            ],
            $usernames
        );
    }

    public function testCountRatedElementsWithNoFilters(): void
    {
        self::assertSame(4, $this->repo->countRatedElements(null, false, []));
    }

    public function testCountRatedElementsFilteredByCategory(): void
    {
        // category 1 -> images [1,2,3], all three rated at least once
        self::assertSame(3, $this->repo->countRatedElements(null, false, [1]));
        // category 2 -> images [4,5]; only 4 is rated
        self::assertSame(1, $this->repo->countRatedElements(null, false, [2]));
    }

    public function testCountRatedElementsFilteredByUser(): void
    {
        // user 1 rated elements 1 and 3
        self::assertSame(2, $this->repo->countRatedElements(UserId::from(1), false, []));
        // everyone except user 1 rated elements 1, 2, 4
        self::assertSame(3, $this->repo->countRatedElements(UserId::from(1), true, []));
    }

    public function testFindRatingReportMatchesTheFixture(): void
    {
        $rows = $this->repo->findRatingReport(null, false, [], 'score', 10, 0);

        self::assertCount(4, $rows);
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
        }

        self::assertSame(4.5, $byId[1]->score);
        self::assertSame(4.5, $byId[1]->avgRates);
        self::assertSame(2, $byId[1]->nbRates);
        self::assertSame(9.0, $byId[1]->sumRates);

        self::assertSame(1, $byId[3]->nbRates);
        self::assertSame(5.0, $byId[3]->sumRates);
    }

    public function testFindRatingReportFiltersByCategory(): void
    {
        $rows = $this->repo->findRatingReport(null, false, [2], 'i.id ASC', 10, 0);

        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]->id);
    }

    public function testFindRatingReportOrdersAndPaginates(): void
    {
        $rows = $this->repo->findRatingReport(null, false, [], 'sum_rates', 2, 0);

        self::assertCount(2, $rows);
        self::assertSame([1, 3], array_map(static fn (RatingReportRow $r): int => $r->id, $rows));
    }

    public function testFindRateRowsForElement(): void
    {
        $rows = $this->repo->findRateRowsForElement(ImageId::from(1));

        self::assertCount(2, $rows);
        self::assertSame(9, array_sum(array_column($rows, 'rate')));
        self::assertSame([1, 1], array_map(static fn (ImageId $id): int => $id->value, array_column($rows, 'elementId')));
    }

    public function testFindRateRowsForElementReturnsEmptyForAnUnratedElement(): void
    {
        self::assertSame([], $this->repo->findRateRowsForElement(ImageId::from(5)));
    }

    public function testCountAllRatesMatchesTheFixture(): void
    {
        self::assertSame(5, $this->repo->countAllRates());
    }

    public function testFindUsersWithStatusByIdUsername(): void
    {
        $users = $this->repo->findUsersWithStatusByIdUsername();

        self::assertCount(4, $users);
        $byId = [];
        foreach ($users as $user) {
            $byId[$user->id] = $user;
        }

        self::assertEquals(new RaterInfo(1, 'fixture_admin', 'webmaster'), $byId[1]);
        self::assertEquals(new RaterInfo(2, 'guest', 'guest'), $byId[2]);
    }

    public function testFindAllRatesOrderedByDateDescMatchesTheFixture(): void
    {
        $rows = $this->repo->findAllRatesOrderedByDateDesc();

        self::assertCount(5, $rows);
        self::assertSame(19, array_sum(array_column($rows, 'rate')));
        $rowUserIds = array_map(static fn (UserId $id): int => $id->value, array_column($rows, 'userId'));
        sort($rowUserIds);
        self::assertSame([1, 1, 3, 3, 4], $rowUserIds);
    }

    public function testFindImageThumbInfoByIds(): void
    {
        $rows = $this->repo->findImageThumbInfoByIds([1, 4]);

        self::assertCount(2, $rows);
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
        }

        self::assertSame('Photo 1', $byId[1]->name);
        self::assertSame('fixture-photo-4.jpg', $byId[4]->file);
    }

    public function testFindImageThumbInfoByIdsIsANoOpForEmptyIds(): void
    {
        self::assertSame([], $this->repo->findImageThumbInfoByIds([]));
    }

    public function testFindAverageRatePerElement(): void
    {
        $averages = $this->repo->findAverageRatePerElement();

        self::assertSame(4.5, $averages[1]);
        self::assertSame(3.0, $averages[2]);
        self::assertSame(5.0, $averages[3]);
        self::assertSame(2.0, $averages[4]);
        self::assertArrayNotHasKey(5, $averages);
    }

    public function testFindTopRatedImageIdsOrdersByRatingScoreDesc(): void
    {
        // rating_score: 3=5.00, 1=4.50, 2=3.00, 4=2.00, 5=NULL (sorts last)
        self::assertSame([3, 1, 2], $this->repo->findTopRatedImageIds(3));
        self::assertSame([3, 1, 2, 4, 5], $this->repo->findTopRatedImageIds(10));
    }

    /**
     * fixture: element 1 has 2 rates (5 from user 1, 4 from user 3) ->
     * count=2, average=ROUND((5+4)/2, 2)=4.5.
     */
    public function testFindRateSummaryForElementMatchesTheFixture(): void
    {
        self::assertEquals(new RateSummaryForElement(2, 4.5), $this->repo->findRateSummaryForElement(ImageId::from(1)));
    }

    /**
     * element 5 has zero rate rows -- COUNT(rate)/AVG(rate) without a
     * GROUP BY still returns exactly one row (count=0, average=NULL),
     * never a false fetchAssociative() result: this exercises the same
     * "count" cast and the `average` null-fallback as the has-rates case
     * above, just with the opposite values.
     */
    public function testFindRateSummaryForElementIsZeroForAnUnratedElement(): void
    {
        self::assertEquals(new RateSummaryForElement(0, null), $this->repo->findRateSummaryForElement(ImageId::from(5)));
    }

    public function testFindUserRateReturnsTheUsersOwnRate(): void
    {
        self::assertSame(5, $this->repo->findUserRate(ImageId::from(1), UserId::from(1), null));
    }

    public function testFindUserRateMatchesANonNullAnonymousId(): void
    {
        self::assertSame(5, $this->repo->findUserRate(ImageId::from(1), UserId::from(1), ''));
    }

    public function testFindUserRateReturnsNullWhenTheAnonymousIdDoesNotMatch(): void
    {
        self::assertNull($this->repo->findUserRate(ImageId::from(1), UserId::from(1), 'no-such-anonymous-id'));
    }

    public function testFindUserRateReturnsNullForAUserWithNoRateOnThatElement(): void
    {
        self::assertNull($this->repo->findUserRate(ImageId::from(1), UserId::from(999999), null));
    }

    private function fetchRateCount(int $elementId, int $userId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('rate')
            ->where('element_id = :elementId')
            ->andWhere('user_id = :userId')
            ->setParameter('elementId', $elementId)
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Doctrine's mysqli driver returns a FLOAT column (rating_score) as a
     * native PHP float, not a string -- is_numeric()/(float) cast, not
     * is_string(), is the correct narrowing here.
     */
    private function fetchRatingScore(int $imageId): ?float
    {
        $value = $this->conn->createQueryBuilder()
            ->select('rating_score')
            ->from('images')
            ->where('id = :id')
            ->setParameter('id', $imageId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (float) $value : null;
    }
}
