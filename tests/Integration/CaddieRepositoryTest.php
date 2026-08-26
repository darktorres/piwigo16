<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * caddie is empty in the fixture and only 4 real (FK-valid) user ids exist,
 * so tests can't rely on disjoint user ids for isolation the way most other
 * Repository tests do -- each test cleans up its own rows via
 * try/finally instead (same reasoning as CommentRepositoryTest/
 * RateRepositoryTest's own order-dependence fixes).
 */
final class CaddieRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private CaddieRepository $repo;

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
        $this->repo = TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(CaddieEntity::class), CaddieRepository::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testAddElementsInsertsNewRowsAndReturnsTheCount(): void
    {
        try {
            $added = $this->repo->addElements(1, [1, 2, 3]);

            self::assertSame(3, $added);
            self::assertSame([1, 2, 3], $this->fetchElementIds(1));
        } finally {
            $this->clearCaddie(1);
        }
    }

    public function testAddElementsSkipsElementsAlreadyInTheCaddie(): void
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

    public function testAddElementsReturnsZeroForAnEmptyList(): void
    {
        self::assertSame(0, $this->repo->addElements(4, []));
    }

    public function testAddElementsSilentlySkipsANonexistentImageId(): void
    {
        $added = $this->repo->addElements(1, [999999]);

        self::assertSame(0, $added);
        self::assertSame([], $this->fetchElementIds(1));
    }

    public function testAddElementsScopesToTheGivenUser(): void
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

    public function testRemoveElementsForUserDeletesOnlyTheGivenElements(): void
    {
        try {
            $this->repo->addElements(1, [1, 2, 3]);

            $this->repo->removeElementsForUser(1, [2]);

            self::assertSame([1, 3], $this->fetchElementIds(1));
        } finally {
            $this->clearCaddie(1);
        }
    }

    public function testRemoveElementsForUserIsANoOpForAnEmptyList(): void
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

    public function testFindElementIdsForUserReturnsOnlyThatUsersOwnElements(): void
    {
        try {
            $this->repo->addElements(1, [1, 2]);
            $this->repo->addElements(3, [3]);

            self::assertSame([1, 2], $this->repo->findElementIdsForUser(1));
            self::assertSame([3], $this->repo->findElementIdsForUser(3));
        } finally {
            $this->clearCaddie(1);
            $this->clearCaddie(3);
        }
    }

    public function testFindElementIdsForUserReturnsEmptyForAUserWithNoCaddie(): void
    {
        self::assertSame([], $this->repo->findElementIdsForUser(4));
    }

    public function testReplaceForUserEmptiesTheExistingCaddieThenInsertsTheNewElements(): void
    {
        try {
            $this->repo->addElements(1, [1, 2, 3]);

            $this->repo->replaceForUser(1, [4, 5]);

            self::assertSame([4, 5], $this->fetchElementIds(1));
        } finally {
            $this->clearCaddie(1);
        }
    }

    public function testReplaceForUserEmptiesTheCaddieForAnEmptyReplacementList(): void
    {
        try {
            $this->repo->addElements(1, [1, 2]);

            $this->repo->replaceForUser(1, []);

            self::assertSame([], $this->fetchElementIds(1));
        } finally {
            $this->clearCaddie(1);
        }
    }

    private function clearCaddie(int $userId): void
    {
        $this->conn->createQueryBuilder()
            ->delete('caddie')
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
            ->from('caddie')
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
