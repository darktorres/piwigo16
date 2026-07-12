<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Auth\AuthRepository;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

final class AuthRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private AuthRepository $repo;

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
        $this->repo = new AuthRepository($this->conn);
    }

    public function test_find_username_and_password_returns_a_fixture_user(): void
    {
        $found = $this->repo->findUsernameAndPassword(1, 'id', 'username', 'password');

        self::assertNotNull($found);
        self::assertSame('fixture_admin', $found['username']);
        self::assertStringStartsWith('$2y$', $found['password']);
    }

    public function test_find_username_and_password_returns_null_for_a_missing_user(): void
    {
        self::assertNull($this->repo->findUsernameAndPassword(999999, 'id', 'username', 'password'));
    }

    public function test_update_language_persists_the_new_value(): void
    {
        $this->repo->updateLanguage(1, 'fr_FR');

        $value = $this->conn->createQueryBuilder()
            ->select('language')
            ->from(Tables::userInfos())
            ->where('user_id = :id')
            ->setParameter('id', 1)
            ->executeQuery()
            ->fetchOne();

        self::assertSame('fr_FR', $value);

        $this->conn->executeStatement(
            'UPDATE ' . Tables::userInfos() . " SET language = 'en_UK' WHERE user_id = 1"
        );
    }
}
