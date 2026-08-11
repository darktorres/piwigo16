<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
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
        // container -- once destroyed, the instance can no longer be
        // reached.
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

    public function testBootPublishesTheGivenPathsAsTheProcessWideCurrentPaths(): void
    {
        $paths = Paths::fromRoot($this->tempRoot);

        InstallBootstrap::boot($paths);

        self::assertTrue(Kernel::isBooted());
        // Same instance, not merely an equal one -- proves Kernel::boot()
        // really did bind this exact Paths into the container, not some
        // other default it built itself.
        self::assertSame($paths, CurrentPathsTestFactory::get());

        // No local/config/config.php -> default policy -> boot() really did
        // call ErrorCollector::install() as a side effect of this call; undo
        // it so it can't intercept errors raised by any later test.
        restore_error_handler();
    }

    public function testBootIsIdempotentWhenRecalledWithTheSamePathsRootByValue(): void
    {
        $first = Paths::fromRoot($this->tempRoot);
        $second = Paths::fromRoot($this->tempRoot);

        InstallBootstrap::boot($first);
        InstallBootstrap::boot($second);

        self::assertTrue(Kernel::isBooted());

        // No local/config/config.php -> default policy -> boot() really did
        // call ErrorCollector::install() as a side effect of the 1st call;
        // undo it so it can't intercept errors raised by any later test.
        restore_error_handler();
    }

    public function testBootThrowsInsteadOfSilentlyKeepingAStalePathsBindingWhenRecalledWithADifferentRoot(): void
    {
        $first = Paths::fromRoot($this->tempRoot);
        $secondRoot = sys_get_temp_dir() . '/piwigo-installbootstrap-2nd-' . bin2hex(random_bytes(6)) . '/';
        mkdir($secondRoot, 0777, true);
        $second = Paths::fromRoot($secondRoot);

        InstallBootstrap::boot($first);

        try {
            InstallBootstrap::boot($second);
            self::fail('Expected InstallBootstrap::boot() to throw on a mismatched Paths root.');
        } catch (LogicException $logicException) {
            self::assertSame(
                'Kernel already booted with a different Paths root (' . $this->tempRoot
                    . ') -- reset the Kernel (call reset() first) to rebind it (e.g. between tests).',
                $logicException->getMessage()
            );
        }

        // The failed 2nd call never reached Kernel::boot()'s own state
        // mutation -- CurrentPaths must still reflect the *first* Paths.
        self::assertSame($first, CurrentPathsTestFactory::get());

        // The 1st boot() (no local/config/config.php -> default policy)
        // really did call ErrorCollector::install(); undo it so it can't
        // intercept errors raised by any later test.
        restore_error_handler();
        $this->removeDirectory($secondRoot);
    }

    public function testBootInstallsTheErrorCollectorWhenDeploymentPolicyAllowsFrontendErrors(): void
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

    public function testBootDoesNotInstallTheErrorCollectorWhenDeploymentPolicyDisablesFrontendErrors(): void
    {
        $this->writePolicy('return new \\Piwigo\\Config\\DeploymentPolicy(showPhpErrorsOnFrontend: false);');
        $paths = Paths::fromRoot($this->tempRoot);

        InstallBootstrap::boot($paths);

        self::assertFalse($this->errorCollector()->isActive());
    }

    public function testBootSkipsTheErrorReportingIniChangeWhenDeploymentPolicyHasShowPhpErrorsDisabled(): void
    {
        $this->writePolicy('return new \\Piwigo\\Config\\DeploymentPolicy(showPhpErrors: 0);');
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

    public function testActivateConfigServiceWiresTheContainersConfigserviceOntoCurrentconfigservice(): void
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

    public function testActivateConfigServiceThrowsWhenTheKernelWasNeverBooted(): void
    {
        Kernel::reset();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Kernel not booted');

        InstallBootstrap::activateConfigService();
    }

    public function testActivateConfigServiceThrowsWhenTheContainerReturnsAnUnexpectedType(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Container returned an unexpected type for ' . ConfigService::class);

        KernelContainerOverride::withWrongTypeFor(
            ConfigService::class,
            static fn () => InstallBootstrap::activateConfigService()
        );
    }
}
