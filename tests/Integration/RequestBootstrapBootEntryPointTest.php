<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\ConfigService;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbCredentials;
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

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        foreach (['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE', 'PIWIGO_DB_PREFIX'] as $key) {
            $value = getenv($key);
            $this->originalDbEnv[$key] = $value === false ? '' : $value;
        }
        // Defensive: force a fresh derive from the current (correct)
        // process env, immune to any bad credential another Integration
        // test in this shared process seeded and failed to restore.
        DbCredentials::reset();

        unset($_SERVER['PATH_INFO']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        DbCredentials::seed($this->originalDbEnv);
        DbCredentials::reset();

        // configure() -> connect()'s own ErrorCollector::installIfConfigured()
        // runs unconditionally before the \LogicException fires -- restore
        // immediately, same discipline as InstallBootstrapTest's own
        // docblock/RequestBootstrapConnectTest's tearDown() above.
        if (ErrorCollector::isActive()) {
            restore_error_handler();
        }
        ErrorCollector::reset();

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

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Container returned an unexpected type for ' . ConfigService::class);

        // KernelContainerOverride::with()'s own finally already guarantees
        // Kernel::reset() regardless of the exception below, so no
        // additional cleanup is needed here.
        KernelContainerOverride::withWrongTypeFor(
            ConfigService::class,
            static function () use ($paths): void {
                // with() calls Kernel::reset() internally, which also
                // clears CurrentPaths -- restore it before bootEntryPoint()
                // runs anything that needs it (Kernel::boot($paths) itself
                // is a no-op here since booted is already forced true by
                // the override).
                CurrentPaths::set($paths);
                RequestBootstrap::bootEntryPoint($paths);
            }
        );
    }
}
