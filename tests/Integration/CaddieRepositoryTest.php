<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * caddie is empty in the fixture and only 4 real (FK-valid) user ids exist,
 * so tests can't rely on disjoint user ids for isolation the way most other
 * P18 Repository tests do -- each test cleans up its own rows via
 * try/finally instead (same reasoning as CommentRepositoryTest/
 * RateRepositoryTest's own order-dependence fixes).
 */
final class CaddieRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CaddieRepository $repo;

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

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new CaddieRepository($this->conn);
    }

    public function test_add_elements_inserts_new_rows_and_returns_the_count(): void
    {
        try {
            $added = $this->repo->addElements(1, [1, 2, 3]);

            self::assertSame(3, $added);
            self::assertSame([1, 2, 3], $this->fetchElementIds(1));
        } finally {
            $this->clearCaddie(1);
        }
    }

    public function test_add_elements_skips_elements_already_in_the_caddie(): void
    {
        try {
            $this->repo->addElements(3, [1, 2]);

            $added = $this->repo->addElements(3, [2, 3, 4]);

            self::assertSame(2, $added, 'only elements 3 and 4 are new; 2 was already there');
            self::assertSame([1, 2, 3, 4], $this->fetchElementIds(3));
        } finally {
            $this->clearCaddie(3);
        }
    }

    public function test_add_elements_returns_zero_for_an_empty_list(): void
    {
        self::assertSame(0, $this->repo->addElements(4, []));
    }

    public function test_add_elements_silently_skips_a_nonexistent_image_id(): void
    {
        $added = $this->repo->addElements(1, [999999]);

        self::assertSame(0, $added);
        self::assertSame([], $this->fetchElementIds(1));
    }

    public function test_add_elements_scopes_to_the_given_user(): void
    {
        try {
            $this->repo->addElements(1, [1]);
            $this->repo->addElements(3, [1]);

            self::assertSame([1], $this->fetchElementIds(1));
            self::assertSame([1], $this->fetchElementIds(3));
        } finally {
            $this->clearCaddie(1);
            $this->clearCaddie(3);
        }
    }

    public function test_remove_elements_for_user_deletes_only_the_given_elements(): void
    {
        try {
            $this->repo->addElements(1, [1, 2, 3]);

            $this->repo->removeElementsForUser(1, [2]);

            self::assertSame([1, 3], $this->fetchElementIds(1));
        } finally {
            $this->clearCaddie(1);
        }
    }

    public function test_remove_elements_for_user_is_a_no_op_for_an_empty_list(): void
    {
        try {
            $this->repo->addElements(1, [1, 2]);

            $this->repo->removeElementsForUser(1, []);

            // Guards against building `DELETE ... WHERE element_id IN ()`
            // for an empty list -- the real rows just inserted above must
            // survive untouched.
            self::assertSame([1, 2], $this->fetchElementIds(1));
        } finally {
            $this->clearCaddie(1);
        }
    }

    private function clearCaddie(int $userId): void
    {
        $this->conn->createQueryBuilder()
            ->delete(Tables::caddie())
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * @return list<int>
     */
    private function fetchElementIds(int $userId): array
    {
        $ids = $this->conn->createQueryBuilder()
            ->select('element_id')
            ->from(Tables::caddie())
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('element_id', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $ids
        );
    }
}
