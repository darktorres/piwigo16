<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\Kernel;

/**
 * Boots the DI container against the test database and resolves every entry
 * defined in config/container.php. A failure here means a service is
 * un-wirable — wrong type hint, missing binding, or a circular dependency
 * not covered by a lazy proxy — and would cause a runtime 500 the first
 * time that service is requested.
 */
final class ContainerSmokeTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../dev/fixtures/piwigo-16.x.sql';

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        $this->markTestInstalled();

        // Globals required by container factories (PageState, CurrentUser, Lang).
        $GLOBALS['page']   = ['infos' => [], 'errors' => [], 'warnings' => [], 'messages' => []];
        $GLOBALS['user']   = [];
        $GLOBALS['lang']   = [];
        $GLOBALS['filter'] = [];

        // Populate Config::$data with defaults + test-env credentials so
        // DbConnection::build() can connect when the container resolves Connection.
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        $GLOBALS['prefixeTable'] = Config::dbPrefix();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Kernel::reset();
    }

    public function test_every_container_entry_resolves(): void
    {
        Kernel::boot();
        $container   = Kernel::container();
        $definitions = require PHPWG_ROOT_PATH . 'config/container.php';

        if (!is_array($definitions)) {
            self::fail('config/container.php did not return an array');
        }

        $failures = [];
        foreach ($definitions as $id => $definition) {
            if (!is_string($id)) {
                continue;
            }
            try {
                $container->get($id);
            } catch (\Throwable $e) {
                $failures[$id] = get_class($e) . ': ' . $e->getMessage();
            }
        }

        $lines = [];
        foreach ($failures as $serviceId => $err) {
            $lines[] = "  [$serviceId]\n    $err";
        }
        $count = count($failures);
        self::assertSame([], $failures,
            "$count container " . ($count === 1 ? 'entry' : 'entries') . " failed to resolve:\n"
            . implode("\n", $lines)
        );
    }
}
