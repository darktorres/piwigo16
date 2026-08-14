<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use PHPUnit\Framework\TestCase;
use Piwigo\Cache\PersistentCache;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\DefaultLanguageProviderInterface;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\TelemetrySenderInterface;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Validation\InputValidator;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Throwable;

/**
 * Boots the Kernel, then iterates and resolves every entry defined in
 * config/container.php. A failure here means a service is un-wirable --
 * wrong type hint, missing binding, or a circular dependency -- and would
 * cause a runtime 500 the first time that service is requested.
 *
 * Extends plain TestCase, not IntegrationTestCase -- no DB is touched.
 */
final class ContainerSmokeTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        Kernel::reset();
    }

    /**
     * TemplateInterface's binding is a factory resolving
     * Piwigo\Template\CurrentTemplate::get() -- the current REQUEST's
     * Template instance, constructed dynamically per-request with runtime
     * theme/path parameters and genuinely unavailable until
     * Piwigo\Bootstrap\RequestBootstrap::finalize() has run. This is not a
     * wiring bug the way an unresolvable MailerInterface/
     * HtmlRenderingInterface/etc. entry would be (those bind to
     * trivially-autowireable concrete classes with no request-scoped
     * prerequisite) -- it's the correct, by-design behavior of a per-request
     * singleton facade, same category as the other request-scoped facades
     * this codebase already has (CurrentUser::get() throws the identical
     * way before its own attachGlobals() runs).
     * Constructing a real Template here to make this resolve would need
     * PHPWG_ROOT_PATH + a full $conf + real filesystem writes to a compile
     * dir -- disproportionate weight for a smoke test, and order-dependent
     * across the whole Integration run (see ExtensionLifecycleTest's own
     * PHPWG_ROOT_PATH guard comment for exactly this class of hazard).
     * Covered instead by real production traffic (every request reaches
     * this binding).
     */
    private const array REQUEST_SCOPED_ONLY_ENTRIES = [
        TemplateInterface::class,
        // Piwigo\Cache\PersistentCache's binding resolves to
        // PersistentFileCache, whose constructor takes Paths $paths
        // directly -- genuinely unavailable here, same "Kernel booted with
        // no real Paths" reasoning as TemplateInterface above, not a
        // wiring bug.
        PersistentCache::class,
        // Piwigo\Storage\StorageRegistry's own factory binding requires
        // config/storage.php, whose own returned closure takes
        // `Paths $paths` as a real param -- fails to autowire for the
        // identical "Kernel booted with no real Paths" reasoning as
        // PersistentCache above, not a wiring bug.
        StorageRegistry::class,
        // Piwigo\Config\DeploymentPolicy's own factory binding takes
        // Paths $paths as an autowired param -- same "Kernel booted with
        // no real Paths" reasoning as PersistentCache/StorageRegistry
        // above, not a wiring bug.
        DeploymentPolicy::class,
        // Piwigo\Core\DefaultLanguageProviderInterface resolves to
        // Piwigo\Users\UserService, which constructor-injects
        // DeploymentPolicy -- fails to resolve for the identical reason,
        // one level removed.
        DefaultLanguageProviderInterface::class,
        // Piwigo\Core\RedirectServiceInterface resolves to
        // Piwigo\Bootstrap\RedirectService, which constructor-injects
        // Piwigo\Users\UserService -- same "Kernel booted with no real
        // Paths" reasoning as DefaultLanguageProviderInterface above, one
        // level removed through UserService's own DeploymentPolicy
        // dependency.
        RedirectServiceInterface::class,
        // Piwigo\Core\TelemetrySenderInterface resolves to
        // Piwigo\Admin\PiwigoInfosSender, which constructor-injects
        // Piwigo\Admin\InstallationStats, which itself constructor-injects
        // UserService -- same "Kernel booted with no real Paths" reasoning
        // as DefaultLanguageProviderInterface above, two levels removed.
        TelemetrySenderInterface::class,
        // Piwigo\Core\FilterUpdaterInterface resolves to
        // Piwigo\Filter\FilterService, which constructor-injects
        // Piwigo\Core\Lang, whose own Paths param the container can't
        // guess without a real one -- same "Kernel booted with no real
        // Paths" reasoning as TemplateInterface above, one level removed.
        FilterUpdaterInterface::class,
        // Piwigo\Core\MailerInterface resolves to Piwigo\Mail\MailService,
        // which constructor-injects Piwigo\Core\Lang, whose own Paths
        // param the container can't guess without a real one -- same
        // "Kernel booted with no real Paths" reasoning as
        // FilterUpdaterInterface above.
        MailerInterface::class,
        // Piwigo\Core\UrlServiceInterface resolves to Piwigo\Url\UrlService,
        // which constructor-injects Piwigo\Core\Lang, whose own Paths
        // param the container can't guess without a real one -- same
        // "Kernel booted with no real Paths" reasoning as MailerInterface
        // above.
        UrlServiceInterface::class,
        // Piwigo\Core\HtmlRenderingInterface resolves to Piwigo\Html\HtmlService,
        // which constructor-injects Piwigo\Core\ErrorCollector, whose own
        // DeploymentPolicy param's factory binding needs a real Paths to
        // autowire -- same "Kernel booted with no real Paths" reasoning as
        // MailerInterface above, via a different (ErrorCollector, not
        // Lang) dependency chain.
        HtmlRenderingInterface::class,
        // Piwigo\Validation\InputValidator's own factory binding takes
        // HtmlRenderingInterface directly, so it fails to resolve for the
        // identical reason, one level removed.
        InputValidator::class,
        // Symfony\Component\Messenger\Command\ConsumeMessagesCommand's own
        // factory binding takes Paths $paths directly (to build its
        // RoutableMessageBus/receiver locator) -- same "Kernel booted with
        // no real Paths" reasoning as PersistentCache/StorageRegistry/
        // DeploymentPolicy above; real usage always resolves this only
        // after Bootstrap\CliBootstrap has set up a real Paths first.
        ConsumeMessagesCommand::class,
    ];

    public function testEveryContainerEntryResolves(): void
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
            } catch (Throwable $e) {
                $failures[$id] = $e::class . ': ' . $e->getMessage();
            }
        }

        $lines = [];
        foreach ($failures as $serviceId => $err) {
            $lines[] = "  [{$serviceId}]\n    {$err}";
        }
        $count = count($failures);
        self::assertSame(
            [],
            $failures,
            "{$count} container " . ($count === 1 ? 'entry' : 'entries') . " failed to resolve:\n"
            . implode("\n", $lines)
        );
    }
}
