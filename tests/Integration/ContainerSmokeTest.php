<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Kernel;
use Psr\Container\ContainerInterface;

/**
 * P7: deliberately trivial -- no config/container.php definitions exist yet
 * (P8's own deliverable), so there is nothing to iterate. Grows with each
 * later phase as real service definitions are added ("always green because
 * it only tests what's wired" -- docs/PLAN-REPLAY.md's Epoch C test-list
 * description). Extends plain TestCase, not IntegrationTestCase -- no DB is
 * touched at this phase.
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
}
