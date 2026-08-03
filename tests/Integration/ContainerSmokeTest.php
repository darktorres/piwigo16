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
 * config/container.php is now fully populated, so the resolution loop
 * below exercises every real service definition wired so far. Extends
 * plain TestCase, not IntegrationTestCase -- no DB is touched at this
 * phase.
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

    /**
     * Piwigo\Core\TemplateInterface's binding is a factory resolving
     * Piwigo\Template\CurrentTemplate::get() (Legacy Coupling Retirement
     * Track A) -- the current REQUEST's Template instance, constructed
     * dynamically per-request with runtime theme/path parameters and
     * genuinely unavailable until Piwigo\Bootstrap\RequestBootstrap::
     * finalize() has run. This is not a wiring bug the way an unresolvable
     * MailerInterface/HtmlRenderingInterface/etc. entry would be (those
     * bind to trivially-autowireable concrete classes with no request-scoped
     * prerequisite) -- it's the correct, by-design behavior of a per-request
     * singleton facade, same category as the other request-scoped facades
     * this codebase already has (CurrentUser::get()/PageState::current()
     * throw the identical way before their own attachGlobals() runs).
     * Constructing a real Template here to make this resolve would need
     * PHPWG_ROOT_PATH + a full $conf + real filesystem writes to a compile
     * dir -- disproportionate weight for a smoke test, and order-dependent
     * across the whole Integration run (see ExtensionLifecycleTest's own
     * PHPWG_ROOT_PATH guard comment for exactly this class of hazard).
     * Covered instead by real production traffic (every request reaches
     * this binding) and each Track A batch's own live curl smoke.
     */
    private const array REQUEST_SCOPED_ONLY_ENTRIES = [
        \Piwigo\Core\TemplateInterface::class,
        // Piwigo\Cache\PersistentCache's binding (singleton/service-locator
        // elimination campaign, Phase 0) resolves to PersistentFileCache,
        // whose constructor reads Piwigo\Core\CurrentPaths::get()->root --
        // genuinely unavailable here, same "Kernel booted with no real
        // Paths" reasoning as TemplateInterface above, not a wiring bug.
        \Piwigo\Cache\PersistentCache::class,
        // Piwigo\Storage\StorageRegistry's own factory binding requires
        // config/storage.php, whose own top-level `$paths =
        // CurrentPaths::get();` (config/storage.php:25) is unconditional --
        // same "Kernel booted with no real Paths" reasoning as
        // PersistentCache above, not a wiring bug.
        \Piwigo\Storage\StorageRegistry::class,
    ];

    public function test_every_container_entry_resolves(): void
    {
        Kernel::boot();
        $container = Kernel::container();

        $definitions = require dirname(__DIR__, 2) . '/config/container.php';
        self::assertIsArray($definitions);

        $failures = [];
        foreach (array_keys($definitions) as $id) {
            self::assertIsString($id, 'Non-string key in config/container.php: ' . var_export($id, true));
            if (in_array($id, self::REQUEST_SCOPED_ONLY_ENTRIES, true)) {
                continue;
            }
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
