<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Rate\RateRepository;

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

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new RateRepository($this->conn);
    }

    public function test_find_element_ids_for_user_and_anonymous_id(): void
    {
        // fixture: user_id 1 rated element 1 and element 3, both with
        // anonymous_id ''
        $ids = $this->repo->findElementIdsForUserAndAnonymousId(1, '');

        sort($ids);
        self::assertSame([1, 3], $ids);
    }

    public function test_find_element_ids_returns_empty_for_no_match(): void
    {
        self::assertSame([], $this->repo->findElementIdsForUserAndAnonymousId(1, '10.0.0'));
    }

    public function test_delete_by_user_anonymous_and_elements(): void
    {
        $this->repo->insertRate(5, 2, 'disp-a', 3);

        $this->repo->deleteByUserAnonymousAndElements(2, 'disp-a', [5]);

        self::assertSame([], $this->repo->findElementIdsForUserAndAnonymousId(2, 'disp-a'));
    }

    public function test_delete_by_user_anonymous_and_elements_is_a_no_op_for_empty_ids(): void
    {
        $this->repo->insertRate(5, 2, 'disp-b', 3);

        try {
            $this->repo->deleteByUserAnonymousAndElements(2, 'disp-b', []);

            self::assertSame([5], $this->repo->findElementIdsForUserAndAnonymousId(2, 'disp-b'));
        } finally {
            $this->repo->deleteByUserAnonymousAndElements(2, 'disp-b', [5]);
        }
    }

    public function test_reassign_anonymous_id(): void
    {
        $this->repo->insertRate(5, 2, 'disp-c-old', 3);

        try {
            $this->repo->reassignAnonymousId(2, 'disp-c-old', 'disp-c-new');

            self::assertSame([], $this->repo->findElementIdsForUserAndAnonymousId(2, 'disp-c-old'));
            self::assertSame([5], $this->repo->findElementIdsForUserAndAnonymousId(2, 'disp-c-new'));
        } finally {
            $this->repo->deleteByUserAnonymousAndElements(2, 'disp-c-new', [5]);
        }
    }

    public function test_delete_existing_rate_scoped_to_anonymous_id(): void
    {
        $this->repo->insertRate(5, 2, 'disp-d', 1);

        try {
            // mismatched anonymous_id -- must not delete
            $this->repo->deleteExistingRate(5, 2, 'wrong-ip');

            self::assertSame(1, $this->fetchRateCount(5, 2));
        } finally {
            $this->repo->deleteExistingRate(5, 2, null);
        }
    }

    public function test_delete_existing_rate_with_null_anonymous_id_matches_any(): void
    {
        $this->repo->insertRate(5, 2, 'disp-e', 1);

        $this->repo->deleteExistingRate(5, 2, null);

        self::assertSame(0, $this->fetchRateCount(5, 2));
    }

    public function test_insert_rate(): void
    {
        $this->repo->insertRate(5, 2, 'disp-f', 3);

        try {
            $value = $this->conn->createQueryBuilder()
                ->select('rate')
                ->from(Tables::rate())
                ->where('element_id = 5')
                ->andWhere('user_id = 2')
                ->executeQuery()
                ->fetchOne();

            self::assertSame(3, is_numeric($value) ? (int) $value : null);
        } finally {
            $this->repo->deleteExistingRate(5, 2, null);
        }
    }

    public function test_find_rate_summaries_matches_the_fixture(): void
    {
        $summaries = $this->repo->findRateSummaries();

        // element 1: rates 5 (user 1) + 4 (user 3)
        self::assertSame(['rcount' => 2, 'rsum' => 9.0], $summaries[1]);
        // element 2: rate 3 (user 4)
        self::assertSame(['rcount' => 1, 'rsum' => 3.0], $summaries[2]);
        // element 5 has no rate at all
        self::assertArrayNotHasKey(5, $summaries);
    }

    public function test_update_rating_scores(): void
    {
        $original = $this->fetchRatingScore(1);

        try {
            $this->repo->updateRatingScores([
                ['id' => 1, 'ratingScore' => 4.75],
            ]);

            self::assertSame(4.75, $this->fetchRatingScore(1));
        } finally {
            $this->conn->createQueryBuilder()
                ->update(Tables::images())
                ->set('rating_score', ':score')
                ->where('id = 1')
                ->setParameter('score', $original)
                ->executeStatement();
        }
    }

    public function test_find_image_ids_with_stale_rating_score(): void
    {
        // element 4 (rating_score 2.00) has a rate row in the fixture, so
        // it's not "stale"; simulate its rate being deleted without the
        // score being recomputed yet.
        $deletedRow = $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::rate())
            ->where('element_id = 4')
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($deletedRow);

        $this->conn->createQueryBuilder()
            ->delete(Tables::rate())
            ->where('element_id = 4')
            ->executeStatement();

        try {
            self::assertSame([4], $this->repo->findImageIdsWithStaleRatingScore());
        } finally {
            $this->conn->createQueryBuilder()
                ->insert(Tables::rate())
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

    public function test_clear_rating_scores(): void
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
                ->update(Tables::images())
                ->set('rating_score', ':score')
                ->where('id = 1')
                ->setParameter('score', $original1)
                ->executeStatement();
            $this->conn->createQueryBuilder()
                ->update(Tables::images())
                ->set('rating_score', ':score')
                ->where('id = 2')
                ->setParameter('score', $original2)
                ->executeStatement();
        }
    }

    public function test_clear_rating_scores_is_a_no_op_for_empty_ids(): void
    {
        $original = $this->fetchRatingScore(1);

        $this->repo->clearRatingScores([]);

        self::assertSame($original, $this->fetchRatingScore(1));
    }

    private function fetchRateCount(int $elementId, int $userId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::rate())
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
            ->from(Tables::images())
            ->where('id = :id')
            ->setParameter('id', $imageId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (float) $value : null;
    }
}
