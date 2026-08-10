<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\Container;
use Piwigo\Core\Kernel;

/**
 * The ORM stack (EntityManager, attribute-mapped entities) is genuinely
 * new and unproven in this codebase -- the reference implementation
 * never built it. This is the "does it actually work, resolved through
 * the real container, not a hand-built EntityManager" proof.
 */
final class EntityManagerSmokeTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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

        if (Kernel::isBooted()) {
            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }
            $currentConfig->reset();
        }
        Kernel::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
    }

    #[Override]
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
        self::assertSame('config', $metadata->getTableName());
        self::assertSame(['param'], $metadata->getIdentifierFieldNames());
    }

    public function test_container_resolved_entity_manager_persist_flush_remove_round_trip(): void
    {
        $container = Container::build();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $param = 'p14_em_smoke_' . bin2hex(random_bytes(4));
        // `value` is a real JSON column (ConfigEntry's own docblock) --
        // a raw un-encoded string is genuinely invalid for it.
        $encodedValue = json_encode('smoke-test-value');
        assert($encodedValue !== false);
        $entry = new ConfigEntry($param, $encodedValue);

        $em->persist($entry);
        $em->flush();
        $em->clear();

        $found = $em->find(ConfigEntry::class, $param);
        self::assertNotNull($found);
        self::assertSame($encodedValue, $found->value);

        $em->remove($found);
        $em->flush();
        $em->clear();

        self::assertNull($em->find(ConfigEntry::class, $param));
    }
}
