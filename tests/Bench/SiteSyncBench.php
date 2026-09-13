<?php

declare(strict_types=1);

namespace Piwigo\Tests\Bench;

use Nyholm\Psr7\ServerRequest;
use PhpBench\Attributes\AfterMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\Event\TabsheetBeforeSelect;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Controller\Admin\SiteUpdateSubController;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Bench\Support\SyncBenchEnvironment;
use Piwigo\Tests\Bench\Support\SyncScenarioBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Full "Synchronize" (admin.php?page=site_update) pass timing at growing
 * gallery scale -- one forced *full* sync per scale, against a scratch
 * DB+filesystem that starts completely empty. No DB seeding:
 * SiteUpdateSubController::handle()'s own dirs/files-scanning blocks
 * create every category/image in the one call being timed.
 *
 * Full sync, not the "Quick Local Synchronization" shortcut: that
 * shortcut leaves `meta_all` unset, so MetadataService::getFilelist()'s
 * `only_new` filter would (on a DB that already carries synced-looking
 * rows) skip almost every file regardless of scale, hiding any scaling
 * problem in the metadata-sync stage. `meta_all=1` forces every image to
 * be reprocessed every time, at every scale.
 *
 * Bootstrap: `public/admin.php` itself calls
 * `RequestPipeline::runBootstrapPhase()` (ConfigBootstrapMiddleware,
 * SessionMiddleware, PluginBootstrapMiddleware, LoadedPluginsMiddleware,
 * UserResolutionMiddleware, LanguageMiddleware, FinalizeBridgeMiddleware --
 * see RequestPipeline::BOOTSTRAP_MIDDLEWARE) before ever building
 * `Admin\AdminShell`, so this calls the exact same real entry point
 * rather than hand-replicating pieces of it -- an earlier version of this
 * class tried seeding CurrentLogger/CurrentConfigService/CurrentTemplate
 * individually and kept hitting one more "not initialised" container
 * singleton each time (session handling, event-listener registration for
 * admin tab rendering, ...); running the real bootstrap phase is both
 * more faithful to production and far less fragile than chasing each one
 * by hand. `AdminShell` itself (which gates every page on
 * `AccessLevel::Administrator`) is deliberately skipped -- this calls
 * `SiteUpdateSubController` directly instead, same as
 * `tests/Unit/Controller/Admin/SiteUpdateSubControllerTest.php` already
 * does for this identical controller, since admin.php (not this
 * sub-controller) owns that access check.
 *
 * SiteUpdateSubController reads its site id/POST fields from raw
 * $_GET/$_POST/$_REQUEST superglobals (SiteUpdateRequest::fromGlobals(),
 * CsrfTokenRequest::fromGlobals()), not from the PSR-7 $request
 * argument -- a bare ServerRequest satisfies the method signature only.
 *
 * PHPBench relaunches this class in a fresh PHP subprocess per measured
 * iteration (its own remote.template runs one before/subject/after
 * sequence per subprocess), so beforeSync()/afterSync() genuinely run
 * once per iteration, not once per `count` param overall -- each
 * iteration gets its own scratch DB and photo tree from scratch.
 *
 * Image count comes from the PIWIGO_SYNC_BENCH_COUNT environment
 * variable, not a hardcoded list -- one invocation benchmarks exactly
 * one scale. Compare scales by running this multiple times with
 * different values, e.g.:
 *
 *   for n in 1 10 100 1000 10000; do
 *     PIWIGO_SYNC_BENCH_COUNT=$n vendor/bin/phpbench run tests/Bench/SiteSyncBench.php --filter=benchFullSync
 *   done
 */
final class SiteSyncBench
{
    private ?SyncBenchEnvironment $environment = null;

    private ?SiteUpdateSubController $controller = null;

    private ?ServerRequestInterface $request = null;

    /**
     * Yields nothing (this benchmark's subject then simply doesn't run --
     * no error) unless PIWIGO_SYNC_BENCH_COUNT is set to a positive
     * integer, so a blanket `composer bench` scan of tests/Bench/ (which
     * also runs KernelBootBench) is unaffected by default -- only an
     * explicit `PIWIGO_SYNC_BENCH_COUNT=<n> composer bench` (or a direct
     * `vendor/bin/phpbench run tests/Bench/SiteSyncBench.php`) opts in.
     *
     * @return iterable<string, array{count: int}>
     */
    public function scales(): iterable
    {
        $raw = getenv('PIWIGO_SYNC_BENCH_COUNT');
        if ($raw === false || ! ctype_digit($raw)) {
            return;
        }
        $count = (int) $raw;
        if ($count < 1) {
            return;
        }

        yield "n={$count}" => [
            'count' => $count,
        ];
    }

    /**
     * @param array{count?: int} $params
     */
    public function beforeSync(array $params): void
    {
        // scales() yields nothing when PIWIGO_SYNC_BENCH_COUNT isn't set --
        // PHPBench still invokes before/subject/afterMethods once in that
        // case, with an empty $params, rather than skipping this
        // benchmark's subject entirely. No-op here (and in
        // benchFullSync()/afterSync() below) so a blanket `composer bench`
        // scan stays a clean, error-free no-op for this class instead of
        // crashing on a missing 'count' key.
        if (! isset($params['count'])) {
            return;
        }

        $environment = new SyncBenchEnvironment($params['count']);
        $environment->setUp();
        SyncScenarioBuilder::build($environment->galleriesDir, $params['count']);
        $this->environment = $environment;

        $_GET = [
            'site' => '1',
        ];
        $_POST = [
            'sync' => 'files',
            'sync_meta' => '1',
            'meta_all' => '1',
            'subcats-included' => '1',
            'simulate' => '0',
            'submit' => 'Synchronize',
        ];
        $_REQUEST = $_POST + $_GET;

        $request = new ServerRequest('POST', '/admin.php?page=site_update&site=1');
        $bootstrapResult = RequestPipeline::runBootstrapPhase($request);
        if ($bootstrapResult instanceof ResponseInterface) {
            throw new RuntimeException(
                'RequestPipeline::runBootstrapPhase() short-circuited instead of completing: ' . (string) $bootstrapResult->getBody(),
            );
        }

        // RequestPipeline::BOOTSTRAP_MIDDLEWARE's own ConfigBootstrapMiddleware
        // just pointed CurrentLogger at a real, on-disk log file under the
        // repo's own _data/logs/ -- overridden back to a no-op instance,
        // same as every IntegrationTestCase subclass's own setUp(), so a
        // benchmark run never writes there.
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if ($currentLogger instanceof CurrentLogger) {
            $currentLogger->set(new Logger([
                'severity' => Logger::OFF,
            ]));
        }

        // SessionMiddleware (part of the bootstrap phase above) already
        // called session_start() -- CsrfService::getToken() needs that
        // active session's id.
        $csrfService = Kernel::container()->get(CsrfService::class);
        if (! $csrfService instanceof CsrfService) {
            throw new RuntimeException('Container returned an unexpected type for ' . CsrfService::class);
        }
        $_REQUEST['pwg_token'] = $csrfService->getToken();

        // AdminShell::runDispatch() registers this listener (which feeds
        // Tabsheet::select()'s own admin-nav-tab rendering) before its own
        // access-level gate -- independent of authentication, so it's
        // reproduced directly here rather than pulling in the rest of
        // AdminShell (which this class deliberately bypasses, see this
        // class's own docblock). Without it, SiteUpdateSubController's
        // own Tabsheet::select('synchronization', ...) call finds no
        // registered tabs at all and fails converting a null tab id.
        $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
        $coreTabs = Kernel::container()->get(CoreTabs::class);
        if (! $eventDispatcher instanceof EventDispatcher || ! $coreTabs instanceof CoreTabs) {
            throw new RuntimeException('Container returned an unexpected type for EventDispatcher/CoreTabs');
        }
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        $controller = Kernel::container()->get(SiteUpdateSubController::class);
        if (! $controller instanceof SiteUpdateSubController) {
            throw new RuntimeException('Container returned an unexpected type for ' . SiteUpdateSubController::class);
        }
        $this->controller = $controller;
        $this->request = $request;
    }

    /**
     * `$params` defaults to `[]` -- PHPBench's own remote.template only
     * passes parameters to the *subject* method (unlike before/after
     * methods, which always receive them) when at least one was yielded;
     * scales() yielding nothing means this gets called with zero
     * arguments.
     *
     * @param array{count?: int} $params
     */
    #[ParamProviders('scales')]
    #[BeforeMethods('beforeSync')]
    #[AfterMethods('afterSync')]
    #[Revs(1)]
    #[Iterations(3)]
    public function benchFullSync(array $params = []): void
    {
        if (! isset($params['count'])) {
            return;
        }
        if (! $this->controller instanceof SiteUpdateSubController || ! $this->request instanceof ServerRequestInterface) {
            throw new RuntimeException('beforeSync() did not run before benchFullSync()');
        }

        $this->controller->handle($this->request);
    }

    /**
     * @param array{count?: int} $params
     */
    public function afterSync(array $params): void
    {
        if (! isset($params['count'])) {
            return;
        }

        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->environment?->tearDown();
        $this->environment = null;
        $this->controller = null;
        $this->request = null;
    }
}
