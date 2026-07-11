<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\TablePrefixListener;

final class ConfigServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ConfigService $service;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-16.x.sql');
            self::$fixtureReady = true;
        }

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->service = new ConfigService($this->buildRepo());
    }

    public function test_loadConfFromDb_merges_every_row_with_boolean_coercion(): void
    {
        $this->service->loadConfFromDb();

        // Real fixture row seeded 'true'/'false' string values -- confirm
        // the exact load_conf_from_db() coercion, not JSON decoding.
        self::assertTrue(Config::all()['activate_comments'] ?? null);
        self::assertIsString(Config::all()['secret_key'] ?? null);
        self::assertSame('Fixture Gallery', Config::all()['gallery_title'] ?? null);
    }

    public function test_loadConfFromDb_with_a_param_loads_only_that_row(): void
    {
        // setUp() already calls ConfigLoader::applyDefaults(), which seeds
        // every non-nullable SCHEMA key (including gallery_title) with its
        // compiled-in default -- Config::has('gallery_title') is true
        // regardless of loadConfFromDb(). The real assertion is that a
        // single-param load doesn't overwrite gallery_title with the
        // fixture's actual DB value ('Fixture Gallery').
        $this->service->loadConfFromDb('secret_key');

        self::assertTrue(Config::has('secret_key'));
        self::assertSame('Piwigo', Config::all()['gallery_title'] ?? null);
    }

    public function test_loadConfFromDb_throws_when_param_not_found_and_dieIfNotFound_is_true(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->loadConfFromDb('this_param_does_not_exist_anywhere');
    }

    public function test_loadConfFromDb_returns_quietly_when_param_not_found_and_dieIfNotFound_is_false(): void
    {
        $this->service->loadConfFromDb('this_param_does_not_exist_anywhere', false);

        self::assertFalse(Config::has('this_param_does_not_exist_anywhere'));
    }

    public function test_confUpdateParam_then_confDeleteParam_round_trips(): void
    {
        $param = 'p14_service_test_' . bin2hex(random_bytes(4));

        $this->service->confUpdateParam($param, 'a value');
        $this->service->loadConfFromDb($param);
        self::assertSame('a value', Config::all()[$param] ?? null);

        $this->service->confDeleteParam($param);
        self::assertFalse(Config::has($param));

        $freshService = new ConfigService($this->buildRepo());
        $freshService->loadConfFromDb($param, false);
        self::assertFalse(Config::has($param));
    }

    public function test_confUpdateParam_encodes_arrays_via_serialize(): void
    {
        $param = 'p14_service_array_' . bin2hex(random_bytes(4));

        $this->service->confUpdateParam($param, ['a', 'b', 'c']);

        $repo = $this->buildRepo();
        $entry = $repo->find($param);
        self::assertNotNull($entry);
        self::assertSame(serialize(['a', 'b', 'c']), $entry->value);

        $repo->deleteByParam($param);
    }

    public function test_confUpdateParam_encodes_bools_as_true_false_strings(): void
    {
        $param = 'p14_service_bool_' . bin2hex(random_bytes(4));

        $this->service->confUpdateParam($param, false);

        $repo = $this->buildRepo();
        $entry = $repo->find($param);
        self::assertNotNull($entry);
        self::assertSame('false', $entry->value);

        $repo->deleteByParam($param);
    }

    public function test_pwgIsDbconfWriteable_returns_true_against_a_real_writable_db(): void
    {
        self::assertTrue($this->service->pwgIsDbconfWriteable());
    }

    private function buildRepo(): ConfigRepository
    {
        $conn = DbConnection::build();
        $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Piwigo'], isDevMode: true);
        $ormConfig->enableNativeLazyObjects(true);
        $em = new EntityManager($conn, $ormConfig);
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener());

        $repo = $em->getRepository(ConfigEntry::class);
        self::assertInstanceOf(ConfigRepository::class, $repo);

        return $repo;
    }
}
