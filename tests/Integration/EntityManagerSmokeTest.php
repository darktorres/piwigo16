<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Container;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\DbTransactionTestOverride;

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
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

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
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testContainerResolvedEntityManagerMapsConfigEntryCorrectly(): void
    {
        $container = Container::build();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $metadata = $em->getClassMetadata(ConfigEntry::class);
        self::assertSame('config', $metadata->getTableName());
        self::assertSame(['param'], $metadata->getIdentifierFieldNames());
    }

    public function testContainerResolvedEntityManagerPersistFlushRemoveRoundTrip(): void
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
