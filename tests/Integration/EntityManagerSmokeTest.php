<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\Container;
use Piwigo\Core\Kernel;

/**
 * The ORM stack (EntityManager, attribute-mapped entities,
 * TablePrefixListener) is genuinely new and unproven in this codebase --
 * the reference implementation never built it (see
 * docs/plan/manifest.yaml's P14 commit message). This is the phase's own
 * "does it actually work, resolved through the real container, not a
 * hand-built EntityManager" proof.
 */
final class EntityManagerSmokeTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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

        Kernel::reset();
        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Kernel::reset();
        parent::tearDown();
    }

    public function test_container_resolved_entity_manager_maps_config_entry_correctly(): void
    {
        $container = Container::build();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $metadata = $em->getClassMetadata(ConfigEntry::class);
        self::assertSame('piwigo_config', $metadata->getTableName());
        self::assertSame(['param'], $metadata->getIdentifierFieldNames());
    }

    public function test_container_resolved_entity_manager_persist_flush_remove_round_trip(): void
    {
        $container = Container::build();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $param = 'p14_em_smoke_' . bin2hex(random_bytes(4));
        $entry = new ConfigEntry($param, 'smoke-test-value');

        $em->persist($entry);
        $em->flush();
        $em->clear();

        $found = $em->find(ConfigEntry::class, $param);
        self::assertNotNull($found);
        self::assertSame('smoke-test-value', $found->value);

        $em->remove($found);
        $em->flush();
        $em->clear();

        self::assertNull($em->find(ConfigEntry::class, $param));
    }
}
