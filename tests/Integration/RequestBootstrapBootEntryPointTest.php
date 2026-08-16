<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentPathsTestFactory;

/**
 * Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint() -- the real per-
 * request entry point every root `public/*.php` file calls directly.
 *
 * Workstream C3 Phase 1 shrank this method's own body dramatically: it no
 * longer runs connect()/finalize() (now real PSR-15 middleware inside
 * `RequestPipeline::handle()`, called separately by every real entry
 * point right after this method returns) -- per Plan 3's own D1 decision,
 * "bootEntryPoint() does not disappear, it shrinks to that one
 * [configure()] call plus the install-sentinel redirect check [and
 * InstallationFlag::mark()]." This file replaces the pre-Phase-1
 * `testBootEntryPointRunsCoverageCollectorAndPropagatesAContainerTypeErrorFromConnect()`,
 * whose whole premise -- proving bootEntryPoint() reaches a real DB
 * connect deep inside connect() -- is no longer even possible to
 * exercise this way: bootEntryPoint() genuinely does not reach
 * ConfigService at all anymore.
 *
 * `CoverageCollector::registerIfActive($paths)`'s own "unreachable via
 * pcov instrumentation" reasoning (a plain PHPUnit-process call is the
 * only way to observe it) still applies here, same as the file this
 * replaces.
 */
final class RequestBootstrapBootEntryPointTest extends IntegrationTestCase
{
    #[Override]
    protected function tearDown(): void
    {
        unset($_SERVER['PATH_INFO']);
        if (Kernel::isBooted()) {
            $errorCollector = Kernel::container()->get(ErrorCollector::class);
            if ($errorCollector instanceof ErrorCollector) {
                if ($errorCollector->isActive()) {
                    restore_error_handler();
                }
                $errorCollector->reset();
            }
            $installationFlag = Kernel::container()->get(InstallationFlag::class);
            if ($installationFlag instanceof InstallationFlag) {
                $installationFlag->reset();
            }
        }
        Kernel::reset();

        parent::tearDown();
    }

    /**
     * The two real, observable effects of bootEntryPoint()'s own shrunken
     * body: configure() boots the Kernel against the exact Paths passed
     * in, and InstallationFlag::mark() runs right after it (proven via
     * isActive(), the only externally-observable side effect mark() has --
     * see InstallationFlag's own docblock).
     */
    public function testBootEntryPointBootsTheKernelAndMarksTheInstallationFlag(): void
    {
        Kernel::reset();
        $paths = Paths::fromRoot(dirname(__DIR__, 2));

        RequestBootstrap::bootEntryPoint($paths);

        self::assertTrue(Kernel::isBooted());
        self::assertSame($paths->root, CurrentPathsTestFactory::get()->root);
        $installationFlag = Kernel::container()->get(InstallationFlag::class);
        self::assertInstanceOf(InstallationFlag::class, $installationFlag);
        self::assertTrue($installationFlag->isActive());
    }

    /**
     * configure()'s own install-sentinel redirect throws
     * ResponseReadyException, but bootEntryPoint()'s own try/catch around
     * configure() catches it, emits the response directly, and calls
     * exit -- terminating the whole PHP process, not returning or
     * re-throwing. That makes this path genuinely untestable in-process
     * through bootEntryPoint() itself (confirmed empirically: catching it
     * here crashes the test runner with "Premature end of PHP process").
     * RequestBootstrapConfigureTest::
     * testConfigureRedirectResponseHasA302StatusAndInstallphpLocation()
     * already covers the exception's own shape by calling configure()
     * directly, one layer below bootEntryPoint()'s own catch -- the
     * correct, already-established boundary for this, not duplicated
     * here.
     */
}
