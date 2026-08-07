<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use stdClass;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\DbCredentialsTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint() -- the real per-
 * request entry point every root `public/*.php` file calls directly.
 * Never called directly by any Unit/Integration test before this file
 * (only reachable, until now, through a real HTTP request against the
 * live Apache instance) -- its own first statement,
 * Piwigo\Core\CoverageCollector::registerIfActive($paths), is genuinely
 * unreachable that way: CoverageCollector's own pcov instrumentation
 * (used to make the Browser suite's real Apache-process requests
 * measurable at all) only starts *after* that line already ran, so the
 * line that starts it can never appear in its own dump. A plain PHPUnit-
 * process call -- already instrumented by PHPUnit's own coverage driver
 * before this method's first statement even runs -- is the only way to
 * observe it.
 *
 * Reaching a real \LogicException deep inside connect() (the "container
 * returned an unexpected type for ConfigService" guard --
 * RequestBootstrapConnectTest.php doesn't exercise this one directly,
 * since it always calls connect() standalone with a real container)
 * conveniently proves the whole chain -- CoverageCollector,
 * SentryBootstrap::init(), configure() (a real install-sentinel pass,
 * static-setter wiring, Kernel::boot()), InstallationFlag::mark(), and
 * connect() up through its own real DB connect -- actually ran, without
 * needing finalize() (template rendering, language loading, plugin
 * event registration) to also succeed. KernelContainerOverride rebinds
 * just ConfigService::class to a plain stdClass (see its own docblock),
 * the same pattern InstallBootstrapTest/AdminAccessorTest/etc. already
 * use for this exact class of guard.
 */
final class RequestBootstrapBootEntryPointTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    /**
     * @var array<string, string>
     */
    private array $originalDbEnv = [];

    /**
     * Resolved from the container inside the test's own override callback,
     * before KernelContainerOverride::with()'s finally calls Kernel::reset()
     * -- install()'s real set_error_handler() registration is a process-
     * global side effect that outlives the container/instance that made
     * it, so tearDown() needs a direct reference to check/undo it, not a
     * re-resolve from what may by then be a destroyed container (unlike
     * InstallationFlag below, whose own state has no such global side
     * effect and is safe to simply let become unreachable garbage).
     */
    private ?ErrorCollector $errorCollectorUnderTest = null;

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

        // This list must include PIWIGO_DB_DRIVER/PIWIGO_DB_PORT -- omitting
        // either leaks a stale env var into every later Integration test
        // class in the same process (see InstallWizardTest/InstallServiceTest's
        // own docblocks for the concrete failure that causes).
        foreach (['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_PREFIX', 'PIWIGO_DB_DRIVER', 'PIWIGO_DB_PORT'] as $key) {
            $value = getenv($key);
            $this->originalDbEnv[$key] = $value === false ? '' : $value;
        }
        // Defensive: force a fresh derive from the current (correct)
        // process env, immune to any bad credential another Integration
        // test in this shared process seeded and failed to restore.
        DbCredentialsTestFactory::get()->reload();

        unset($_SERVER['PATH_INFO']);
    }

    #[Override]
    protected function tearDown(): void
    {
        DbCredentialsTestFactory::get()->seed($this->originalDbEnv);

        // configure() -> connect()'s own ErrorCollector::installIfConfigured()
        // runs unconditionally before the \LogicException fires -- restore
        // immediately, same discipline as InstallBootstrapTest's own
        // docblock/RequestBootstrapConnectTest's tearDown() above. Checked
        // via the instance captured inside the test's own callback (see
        // that property's own docblock), not a container re-resolve --
        // KernelContainerOverride::with()'s finally has typically already
        // reset the container by the time tearDown() runs.
        if ($this->errorCollectorUnderTest?->isActive() === true) {
            restore_error_handler();
        }
        $this->errorCollectorUnderTest?->reset();
        $this->errorCollectorUnderTest = null;

        // Some tests above reach this point via KernelContainerOverride::
        // with(), which already calls Kernel::reset() internally before
        // returning -- only resolve+reset InstallationFlag when a
        // container actually still exists.
        if (Kernel::isBooted()) {
            $installationFlag = Kernel::container()->get(InstallationFlag::class);
            if ($installationFlag instanceof InstallationFlag) {
                $installationFlag->reset();
            }
        }
        unset($_SERVER['PATH_INFO']);

        parent::tearDown();
    }

    public function test_bootEntryPoint_runs_coverageCollector_and_propagates_a_container_type_error_from_connect(): void
    {
        $paths = Paths::fromRoot(dirname(__DIR__, 2));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Container returned an unexpected type for ' . ConfigService::class);

        // KernelContainerOverride::with()'s own finally already guarantees
        // Kernel::reset() regardless of the exception below, so no
        // additional cleanup is needed here. Paths::class must be bound
        // alongside the deliberately-wrong ConfigService::class override --
        // with() rebuilds the container from scratch with no Paths given by
        // default, and CurrentPaths is a pure shim with no state of its
        // own to survive that rebuild; bootEntryPoint()'s own
        // internal Kernel::boot($paths) call is a genuine no-op here since
        // booted is already forced true by the override.
        KernelContainerOverride::with(
            [
                ConfigService::class => new stdClass(),
                Paths::class => $paths,
            ],
            function () use ($paths): void {
                // Captured now, while the override's own container is still
                // alive, so tearDown() can still check/undo install()'s real
                // set_error_handler() registration after this callback's
                // container is gone (see that property's own docblock).
                $errorCollector = Kernel::container()->get(ErrorCollector::class);
                if ($errorCollector instanceof ErrorCollector) {
                    $this->errorCollectorUnderTest = $errorCollector;
                }

                RequestBootstrap::bootEntryPoint($paths);
            }
        );
    }
}
