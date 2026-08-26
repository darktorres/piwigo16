<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Caddie\CaddieService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Users\User;

/**
 * CaddieService::fillCurrentUserCaddie() is a thin wrapper resolving the
 * current user id and delegating straight to
 * CaddieRepository::addElements() -- same fixture/style as
 * CaddieRepositoryTest, but exercised through the real
 * Piwigo\Users\CurrentUser singleton instead of a repository call, to
 * prove it reads the *current* user's id (not a hardcoded/mixed-up one)
 * and forwards the element list unchanged.
 */
final class CaddieServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->clearCaddie(1);
        $this->clearCaddie(3);
        $this->clearCaddie(4);
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testFillCurrentUserCaddieInsertsRowsForTheCurrentUserId(): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 3,
        ]));

        CaddieService::fillCurrentUserCaddie([2, 4, 1], CurrentUserTestFactory::get(), EntityManagerFactory::build($this->conn));

        self::assertSame([1, 2, 4], $this->fetchElementIds(3));
    }

    public function testFillCurrentUserCaddieScopesToWhicheverUserIsCurrent(): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 1,
        ]));
        CaddieService::fillCurrentUserCaddie([5], CurrentUserTestFactory::get(), EntityManagerFactory::build($this->conn));

        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 4,
        ]));
        CaddieService::fillCurrentUserCaddie([2, 3], CurrentUserTestFactory::get(), EntityManagerFactory::build($this->conn));

        self::assertSame([5], $this->fetchElementIds(1), 'user 1 must only have its own element');
        self::assertSame([2, 3], $this->fetchElementIds(4), 'user 4 must only have its own elements');
    }

    public function testFillCurrentUserCaddieDoesNothingForAnEmptyElementList(): void
    {
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 3,
        ]));

        CaddieService::fillCurrentUserCaddie([], CurrentUserTestFactory::get(), EntityManagerFactory::build($this->conn));

        self::assertSame([], $this->fetchElementIds(3));
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
