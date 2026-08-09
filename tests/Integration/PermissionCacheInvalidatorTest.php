<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Cache\CachePools;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * confDeleteParam('count_orphans') is a real DB write (ConfigRepository::
 * deleteByParam()), so this needs the full IntegrationTestCase fixture --
 * same reasoning as ConfigServiceTest's own DB-touching methods.
 */
final class PermissionCacheInvalidatorTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private ConfigService $configService;

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

        // invalidate() unconditionally ends with confDeleteParam() on the
        // container-shared CurrentConfigService singleton (see its own
        // docblock) -- every test below reaches that call, so every test
        // needs it primed, not just the one that asserts on its effect.
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $this->configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), $currentConfig);
        CurrentConfigServiceTestFactory::get()->set($this->configService);
    }

    public function test_invalidate_clears_the_permissions_pool(): void
    {
        $item = CachePools::permissions()->getItem('p14_invalidator_test_permissions');
        $item->set('cached_value');
        CachePools::permissions()->save($item);

        PermissionCacheInvalidator::invalidate();

        self::assertFalse(CachePools::permissions()->getItem('p14_invalidator_test_permissions')->isHit());
    }

    public function test_invalidate_clears_the_effective_permissions_pool(): void
    {
        $item = CachePools::effectivePermissions()->getItem('p14_invalidator_test_effective');
        $item->set('cached_value');
        CachePools::effectivePermissions()->save($item);

        PermissionCacheInvalidator::invalidate();

        self::assertFalse(CachePools::effectivePermissions()->getItem('p14_invalidator_test_effective')->isHit());
    }

    public function test_invalidate_deletes_the_count_orphans_config_param(): void
    {
        try {
            // 'count_orphans' is property-backed (KEY_TO_PROPERTY maps it to
            // CurrentConfig::countOrphans()), so confGetParam() would read
            // that in-memory property instead of the DB row -- findRawValue()
            // bypasses that shortcut and reads straight through the repo,
            // matching ImageServiceTest's own emptyLounge() coverage of this
            // exact param. findRawValue() json_decode()s the stored value
            // and only returns it when the decode yields a string (matching
            // insertIgnoreRawValue()'s own string-only contract) -- a bare
            // int here would json_encode() to an unquoted JSON number,
            // decode back to an int, and always read back as false.
            $this->configService->confUpdateParam('count_orphans', '3');
            self::assertSame('3', $this->configService->findRawValue('count_orphans'));

            PermissionCacheInvalidator::invalidate();

            self::assertFalse($this->configService->findRawValue('count_orphans'));
        } finally {
            $this->configService->confDeleteParam('count_orphans');
        }
    }

    public function test_invalidate_throws_when_the_container_returns_an_unexpected_type_for_current_config_service(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Container returned an unexpected type for ' . CurrentConfigService::class);

        KernelContainerOverride::withWrongTypeFor(
            CurrentConfigService::class,
            static function (): void {
                PermissionCacheInvalidator::invalidate();
            },
        );
    }
}
