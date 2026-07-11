<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Kernel;
use Psr\Container\ContainerInterface;

/**
 * Boots the Kernel, then iterates and resolves every entry defined in
 * config/container.php. A failure here means a service is un-wirable --
 * wrong type hint, missing binding, or a circular dependency -- and would
 * cause a runtime 500 the first time that service is requested.
 *
 * P8: config/container.php is still empty, so the resolution loop below
 * runs zero iterations -- "always green because it only tests what's wired"
 * (docs/PLAN-REPLAY.md's Epoch C test-list description). Grows with each
 * later phase as real service definitions are added. Extends plain
 * TestCase, not IntegrationTestCase -- no DB is touched at this phase.
 */
final class ContainerSmokeTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        Kernel::reset();
    }

    public function test_boot_produces_a_working_container(): void
    {
        Kernel::boot();
        self::assertInstanceOf(ContainerInterface::class, Kernel::container());
    }

    public function test_every_container_entry_resolves(): void
    {
        Kernel::boot();
        $container = Kernel::container();

        $definitions = require dirname(__DIR__, 2) . '/config/container.php';
        self::assertIsArray($definitions);

        $failures = [];
        foreach (array_keys($definitions) as $id) {
            self::assertIsString($id, 'Non-string key in config/container.php: ' . var_export($id, true));
            try {
                $container->get($id);
            } catch (\Throwable $e) {
                $failures[$id] = $e::class . ': ' . $e->getMessage();
            }
        }

        $lines = [];
        foreach ($failures as $serviceId => $err) {
            $lines[] = "  [$serviceId]\n    $err";
        }
        $count = count($failures);
        self::assertSame(
            [],
            $failures,
            "$count container " . ($count === 1 ? 'entry' : 'entries') . " failed to resolve:\n"
            . implode("\n", $lines)
        );
    }
}
