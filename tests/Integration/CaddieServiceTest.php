<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Caddie\CaddieService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Users\CurrentUser;
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

        $currentConfig = \Piwigo\Core\Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->clearCaddie(1);
        $this->clearCaddie(3);
        $this->clearCaddie(4);
        parent::tearDown();
    }

    public function test_fill_current_user_caddie_inserts_rows_for_the_current_user_id(): void
    {
        CurrentUser::current()->set(User::fromUserArray(['id' => 3]));

        CaddieService::fillCurrentUserCaddie([2, 4, 1]);

        self::assertSame([1, 2, 4], $this->fetchElementIds(3));
    }

    public function test_fill_current_user_caddie_scopes_to_whichever_user_is_current(): void
    {
        CurrentUser::current()->set(User::fromUserArray(['id' => 1]));
        CaddieService::fillCurrentUserCaddie([5]);

        CurrentUser::current()->set(User::fromUserArray(['id' => 4]));
        CaddieService::fillCurrentUserCaddie([2, 3]);

        self::assertSame([5], $this->fetchElementIds(1), 'user 1 must only have its own element');
        self::assertSame([2, 3], $this->fetchElementIds(4), 'user 4 must only have its own elements');
    }

    public function test_fill_current_user_caddie_does_nothing_for_an_empty_element_list(): void
    {
        CurrentUser::current()->set(User::fromUserArray(['id' => 3]));

        CaddieService::fillCurrentUserCaddie([]);

        self::assertSame([], $this->fetchElementIds(3));
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
