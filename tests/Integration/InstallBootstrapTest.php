<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * InstallBootstrap is install.php's counterpart to
 * RequestBootstrap::bootEntryPoint()/CliBootstrap::buildApplication() --
 * see its own docblock. boot() itself is a thin 3-step orchestrator
 * (ConfigLoader's 2 no-op steps, Kernel::boot($paths), then
 * ErrorCollector::installIfConfigured()); the real observable behavior
 * worth asserting is (a) Kernel/CurrentPaths really do end up booted with
 * the given Paths, and (b) ErrorCollector really is (or isn't) installed
 * depending on the DeploymentPolicy resolved from that Paths' own
 * local/config/config.php.
 *
 * Deliberately does NOT leave a real ErrorCollector::install() active
 * across tests: this test suite's own established convention (see
 * tests/Unit/Core/ErrorCollectorTest.php's docblock) is to avoid a
 * lingering set_error_handler()/register_shutdown_function() pair leaking
 * into later tests. The one test that does exercise the real
 * self::install() call restores the previous error handler immediately
 * afterwards (register_shutdown_function() itself cannot be undone, but
 * ErrorCollector::flush() is a real no-op once its own buffer is cleared
 * by reset(), so the permanently-registered shutdown callback is harmless).
 */
final class InstallBootstrapTest extends IntegrationTestCase
{
    private string $tempRoot;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        // parent::setUp()'s own conditional default boot (real repo root)
        // would otherwise defeat every test below's own
        // InstallBootstrap::boot($paths) call via Kernel::boot()'s
        // idempotency guard -- reset back to a genuinely unbooted baseline
        // so each test's own boot() call is a real first boot, not a
        // silent no-op against an already-live container.
        Kernel::reset();
        $this->tempRoot = sys_get_temp_dir() . '/piwigo-installbootstrap-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->tempRoot . 'local/config', 0777, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        // Resolved (and reset) before Kernel::reset() destroys the
        // container -- unlike the old static API, an instance can no
        // longer be reached afterwards (singleton/service-locator
        // elimination campaign, Phase 2).
        if (Kernel::isBooted()) {
            $errorCollector = Kernel::container()->get(ErrorCollector::class);
            if ($errorCollector instanceof ErrorCollector) {
                $errorCollector->reset();
            }
        }
        Kernel::reset();
        $this->removeDirectory($this->tempRoot);
        parent::tearDown();
    }

    private function errorCollector(): ErrorCollector
    {
        $errorCollector = Kernel::container()->get(ErrorCollector::class);
        if (! $errorCollector instanceof ErrorCollector) {
            throw new LogicException('Container returned an unexpected type for ' . ErrorCollector::class);
        }

        return $errorCollector;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        self::assertIsArray($items);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writePolicy(string $body): void
    {
        file_put_contents($this->tempRoot . 'local/config/config.php', "<?php\n" . $body . "\n");
    }

    public function test_boot_publishes_the_given_paths_as_the_process_wide_current_paths(): void
    {
        $paths = Paths::fromRoot($this->tempRoot);

        InstallBootstrap::boot($paths);

        self::assertTrue(Kernel::isBooted());
        // Same instance, not merely an equal one -- proves Kernel::boot()
        // really did bind this exact Paths into the container, not some
        // other default it built itself.
        self::assertSame($paths, CurrentPaths::get());

        // No local/config/config.php -> default policy -> boot() really did
        // call ErrorCollector::install() as a side effect of this call; undo
        // it so it can't intercept errors raised by any later test.
        restore_error_handler();
    }

    public function test_boot_is_idempotent_and_keeps_the_first_paths_on_a_second_call(): void
    {
        $first = Paths::fromRoot($this->tempRoot);
        $secondRoot = sys_get_temp_dir() . '/piwigo-installbootstrap-2nd-' . bin2hex(random_bytes(6)) . '/';
        mkdir($secondRoot, 0777, true);
        $second = Paths::fromRoot($secondRoot);

        InstallBootstrap::boot($first);
        InstallBootstrap::boot($second);

        // Kernel::boot()'s own `self::$booted` guard makes the 2nd call a
        // no-op -- CurrentPaths must still reflect the *first* Paths, not
        // silently swap to the second.
        self::assertSame($first, CurrentPaths::get());

        // The 1st boot() (no local/config/config.php -> default policy)
        // really did call ErrorCollector::install(); the 2nd was a no-op
        // (Kernel::$booted guard). Undo the one real registration.
        restore_error_handler();
        $this->removeDirectory($secondRoot);
    }

    public function test_boot_installs_the_error_collector_when_deployment_policy_allows_frontend_errors(): void
    {
        // No local/config/config.php at all -> DeploymentPolicy::load()
        // falls back to all-defaults, whose showPhpErrorsOnFrontend is true.
        $paths = Paths::fromRoot($this->tempRoot);

        InstallBootstrap::boot($paths);

        self::assertTrue($this->errorCollector()->isActive());

        // Real cleanup: undo the real set_error_handler() this just
        // performed so it can't intercept errors raised by any later test
        // in this shared process.
        restore_error_handler();
    }

    public function test_boot_does_not_install_the_error_collector_when_deployment_policy_disables_frontend_errors(): void
    {
        $this->writePolicy("return new \\Piwigo\\Config\\DeploymentPolicy(showPhpErrorsOnFrontend: false);");
        $paths = Paths::fromRoot($this->tempRoot);

        InstallBootstrap::boot($paths);

        self::assertFalse($this->errorCollector()->isActive());
    }

    public function test_boot_skips_the_error_reporting_ini_change_when_deployment_policy_has_show_php_errors_disabled(): void
    {
        $this->writePolicy("return new \\Piwigo\\Config\\DeploymentPolicy(showPhpErrors: 0);");
        $paths = Paths::fromRoot($this->tempRoot);
        $sentinelLevel = E_ALL & ~E_DEPRECATED;
        $previous = ini_set('error_reporting', (string) $sentinelLevel);
        self::assertNotFalse($previous, 'ini_set(error_reporting) must succeed to run this test');

        InstallBootstrap::boot($paths);

        // installIfConfigured()'s own `if ($policy->showPhpErrors !== 0)`
        // guard short-circuits before ever touching error_reporting ini or
        // calling self::install() -- the sentinel value set above must
        // survive untouched, and the collector must stay inactive.
        self::assertSame((string) $sentinelLevel, ini_get('error_reporting'));
        self::assertFalse($this->errorCollector()->isActive());

        ini_set('error_reporting', $previous);
    }

    public function test_activateConfigService_wires_the_containers_configservice_onto_currentconfigservice(): void
    {
        $paths = Paths::fromRoot($this->tempRoot);
        InstallBootstrap::boot($paths);

        InstallBootstrap::activateConfigService();

        self::assertTrue(CurrentConfigServiceTestFactory::get()->isSet());
        // Same instance as the container would hand out itself -- proves
        // this really pulled the container's own (implicitly singleton)
        // ConfigService rather than building a detached one.
        self::assertSame(Kernel::container()->get(ConfigService::class), CurrentConfigServiceTestFactory::get()->get());

        restore_error_handler();
    }

    public function test_activateConfigService_throws_when_the_kernel_was_never_booted(): void
    {
        Kernel::reset();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Kernel not booted');

        InstallBootstrap::activateConfigService();
    }

    public function test_activateConfigService_throws_when_the_container_returns_an_unexpected_type(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Container returned an unexpected type for ' . ConfigService::class);

        KernelContainerOverride::withWrongTypeFor(
            ConfigService::class,
            static fn () => InstallBootstrap::activateConfigService()
        );
    }
}
