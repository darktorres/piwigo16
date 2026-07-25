<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;

final class PreferencesServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PreferencesService $service;

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
        $this->service = new PreferencesService(new UserRepository($this->conn));

        CurrentUser::set(User::fromUserArray(['id' => 1, 'preferences' => []]));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . ' SET preferences = NULL WHERE user_id = 1');
        parent::tearDown();
    }

    public function test_update_param_then_get_param_round_trips(): void
    {
        $this->service->updateParam('theme', 'dark');

        self::assertSame('dark', $this->service->getParam('theme'));

        self::assertSame('dark', CurrentUser::get()->preferences['theme']);
    }

    public function test_update_param_persists_to_the_database(): void
    {
        $this->service->updateParam('language', 'fr_FR');

        $value = $this->conn->createQueryBuilder()
            ->select('preferences')
            ->from(Tables::userInfos())
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($value);
        self::assertSame(['language' => 'fr_FR'], json_decode($value, true));
    }

    public function test_update_param_converts_the_string_true_and_false_to_bool(): void
    {
        $this->service->updateParam('flag_a', 'true');
        $this->service->updateParam('flag_b', 'false');

        self::assertTrue($this->service->getParam('flag_a'));
        self::assertFalse($this->service->getParam('flag_b'));
    }

    public function test_delete_param_removes_a_single_key(): void
    {
        $this->service->updateParam('a', 1);
        $this->service->updateParam('b', 2);

        $this->service->deleteParam('a');

        self::assertNull($this->service->getParam('a'));
        self::assertSame(2, $this->service->getParam('b'));
    }

    public function test_delete_param_accepts_a_list_of_keys(): void
    {
        $this->service->updateParam('a', 1);
        $this->service->updateParam('b', 2);
        $this->service->updateParam('c', 3);

        $this->service->deleteParam(['a', 'b']);

        self::assertNull($this->service->getParam('a'));
        self::assertNull($this->service->getParam('b'));
        self::assertSame(3, $this->service->getParam('c'));
    }

    public function test_get_param_returns_the_default_when_unset(): void
    {
        self::assertSame('fallback', $this->service->getParam('never-set', 'fallback'));
    }
}
