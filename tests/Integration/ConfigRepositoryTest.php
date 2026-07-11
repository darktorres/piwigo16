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
use Piwigo\Db\DbConnection;
use Piwigo\Db\TablePrefixListener;

final class ConfigRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ConfigRepository $repo;

    private EntityManagerInterface $em;

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

        $conn = DbConnection::build();
        $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Piwigo'], isDevMode: true);
        $ormConfig->enableNativeLazyObjects(true);
        $this->em = new EntityManager($conn, $ormConfig);
        $this->em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener());

        $repo = $this->em->getRepository(ConfigEntry::class);
        self::assertInstanceOf(ConfigRepository::class, $repo);
        $this->repo = $repo;
    }

    public function test_find_returns_a_real_fixture_row(): void
    {
        $entry = $this->repo->find('secret_key');

        self::assertNotNull($entry);
        self::assertSame('secret_key', $entry->param);
        self::assertNotNull($entry->value);
        self::assertNotSame('', $entry->value);
    }

    public function test_find_returns_null_for_a_missing_param(): void
    {
        self::assertNull($this->repo->find('this_param_does_not_exist_anywhere'));
    }

    public function test_findAll_returns_every_fixture_row(): void
    {
        $entries = $this->repo->findAll();

        self::assertGreaterThan(10, count($entries));
        $params = array_map(static fn (ConfigEntry $e): string => $e->param, $entries);
        self::assertContains('secret_key', $params);
        self::assertContains('gallery_title', $params);
    }

    public function test_upsert_creates_a_new_row(): void
    {
        $param = 'p14_test_upsert_new_' . bin2hex(random_bytes(4));

        $this->repo->upsert($param, 'hello');

        $fresh = $this->freshRepo();
        $entry = $fresh->find($param);
        self::assertNotNull($entry);
        self::assertSame('hello', $entry->value);

        $fresh->deleteByParam($param);
    }

    public function test_upsert_updates_an_existing_row(): void
    {
        $param = 'p14_test_upsert_existing_' . bin2hex(random_bytes(4));
        $this->repo->upsert($param, 'first');
        $this->repo->upsert($param, 'second');

        $fresh = $this->freshRepo();
        $entry = $fresh->find($param);
        self::assertNotNull($entry);
        self::assertSame('second', $entry->value);

        $fresh->deleteByParam($param);
    }

    public function test_deleteByParam_removes_the_row(): void
    {
        $param = 'p14_test_delete_' . bin2hex(random_bytes(4));
        $this->repo->upsert($param, 'temp');

        $this->repo->deleteByParam($param);

        $fresh = $this->freshRepo();
        self::assertNull($fresh->find($param));
    }

    /**
     * A fresh EntityManager/repository, bypassing the first one's identity
     * map -- otherwise find() would return the same in-memory object
     * upsert() already mutated, which wouldn't prove the write actually
     * reached the database.
     */
    private function freshRepo(): ConfigRepository
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
