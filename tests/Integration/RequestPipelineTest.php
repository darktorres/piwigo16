<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
use Piwigo\Http\Middleware\PluginBootstrapMiddleware;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\KernelContainerOverride;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;

/**
 * Confirms RequestPipeline::handle() runs the real pipeline end-to-end.
 * RouteDefinitions has real routes, and every root frontend file actually
 * calls this for live traffic -- an unmatched path (used throughout this
 * file) still correctly 404s. A real registered route (/about.php) is
 * deliberately *not* exercised here: its controller renders a real Latte
 * template against a real DB connection, heavier than this class's own
 * fast/cheap coverage aims for -- tests/Browser/AboutControllerTest.php is
 * the real end-to-end proof for that, driving the actual route through a
 * real browser against the real app.
 *
 * Extends IntegrationTestCase (workstream C3 Phase 1), not plain TestCase
 * as this class did pre-Phase-1 -- DEFAULT_MIDDLEWARE's own 7 new
 * bootstrap-phase middleware (formerly RequestBootstrap::connect()'s/
 * finalize()'s procedural body, which every real entry point ran
 * *before* ever calling RequestPipeline::handle()) are now resolved
 * eagerly, in order, by handle()'s own array_map() -- including
 * Http\Middleware\ConfigBootstrapMiddleware, which opens a real DB
 * connection. An unmatched path is genuinely no longer cheap to answer:
 * it pays for the same real bootstrap work every matched route already
 * did pre-Phase-1 (just via bootEntryPoint() instead of the pipeline),
 * so this class needs the same real Paths/DB preconditions
 * ConfigBootstrapMiddlewareTest itself establishes.
 *
 * handle()'s own local `$notFound` RequestHandlerInterface (its
 * `->handle()` body returning the literal 'Not Found' 404) is
 * confirmed unreachable through this class's own public API and left
 * uncovered: it's the MiddlewarePipeline's terminal fallback, only ever
 * invoked when the peeled middleware list has been fully exhausted (see
 * MiddlewarePipeline::handle()'s own `$this->middleware === []` branch),
 * but DEFAULT_MIDDLEWARE's own last entry, ControllerInvokerMiddleware,
 * never calls `$handler->handle()` onward -- it always returns a Response
 * directly itself (a 404 Response, a thrown LogicException, or the
 * matched controller's own result/ResponseReadyException), confirmed by
 * reading its own process() body. With every real middleware always
 * terminating the chain itself, the fallback can only run if
 * DEFAULT_MIDDLEWARE were empty or its terminal entry changed to
 * delegate -- neither of which handle() lets a caller control.
 */
final class RequestPipelineTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();
    }

    #[Override]
    protected function tearDown(): void
    {
        // ConfigBootstrapMiddleware's own first statement is
        // ErrorCollector::installIfConfigured() -- every real handle() call
        // above reaches it, leaving a real set_error_handler() active
        // unless undone here (same discipline as ConfigBootstrapMiddlewareTest's
        // own tearDown). Guarded on isBooted(): the third test's own
        // KernelContainerOverride::withWrongTypeFor() call already resets
        // the Kernel itself in its own finally block before this runs.
        if (Kernel::isBooted()) {
            $errorCollector = Kernel::container()->get(ErrorCollector::class);
            if ($errorCollector instanceof ErrorCollector) {
                if ($errorCollector->isActive()) {
                    restore_error_handler();
                }
                $errorCollector->reset();
            }
        }

        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testHandleReturns404ForAnUnmatchedPath(): void
    {
        $response = RequestPipeline::handle(new ServerRequest('GET', '/anything'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', (string) $response->getBody());
    }

    public function testHandleResponseCarriesBaselineSecurityHeaders(): void
    {
        $response = RequestPipeline::handle(new ServerRequest('GET', '/anything'));

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testHandleThrowsWhenTheContainerReturnsAnUnexpectedTypeForAMiddleware(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            "Container returned an unexpected type for '" . ExceptionHandlerMiddleware::class . "'."
        );

        // Every one of DEFAULT_MIDDLEWARE's entries is resolved eagerly,
        // in order, by the array_map() inside handle() itself -- rebinding
        // the very first one (ExceptionHandlerMiddleware) means no other
        // real middleware needs to resolve at all before the guard fires,
        // so this test alone still doesn't touch the DB despite this
        // class's own heavier setUp() now doing so for its siblings above.
        KernelContainerOverride::withWrongTypeFor(
            ExceptionHandlerMiddleware::class,
            static fn (): ResponseInterface => RequestPipeline::handle(new ServerRequest('GET', '/anything'))
        );
    }

    /**
     * End-to-end proof for P29.6's `ApiRouteProviderInterface`: a real
     * plugin, installed and activated for real against the test DB and
     * present in the real `plugins/` directory (this class's own
     * `Kernel::boot()` call in `IntegrationTestCase::setUp()` uses the
     * real repo root, so `PluginRegistry` scans the real `plugins/`
     * dir -- not a swappable temp one like `PluginRegistryTest::
     * buildRegistry()` uses), gets its own `registerApiRoutes()` called
     * during a real `RequestPipeline::handle()` call, and its route's
     * controller actually runs -- not just that routing matched.
     */
    public function testAnActivatedPluginsApiRouteControllerActuallyRuns(): void
    {
        $id = 'zz-request-pipeline-api-route-' . uniqid('', false);
        $this->writeApiRouteFixturePlugin($id);

        $conn = DbConnection::build();
        $registry = $this->pluginRegistryFromRealContainer($conn);

        try {
            $registry->install($id);
            $registry->activate($id);

            $response = RequestPipeline::handle(new ServerRequest('GET', '/api/v1/plugin-routes/' . $id . '/ping'));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('pong from ' . $id, (string) $response->getBody());
        } finally {
            $registry->deactivate($id);
            $registry->uninstall($id);
            $this->removeFixturePlugin($id);
        }
    }

    private function writeApiRouteFixturePlugin(string $id): void
    {
        $dir = dirname(__DIR__, 2) . '/plugins/' . $id;
        mkdir($dir . '/src', 0o777, true);

        $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

        file_put_contents($dir . '/plugin.json', json_encode([
            'id' => $id,
            'name' => $id,
            'version' => '1.0.0',
            'description' => 'Test-only fixture plugin (tests/Integration/RequestPipelineTest.php).',
            'license' => 'MIT',
            'minPiwigo' => '16.3.0',
            'main' => $namespace . '\\Plugin',
            'hasApiRoutes' => true,
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        file_put_contents($dir . '/src/Plugin.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Piwigo\\PluginConfig\\ApiRouteProviderInterface;
            use Piwigo\\PluginConfig\\ExtensionContext;
            use Piwigo\\PluginConfig\\ExtensionInterface;
            use Symfony\\Component\\Routing\\Route;
            use Symfony\\Component\\Routing\\RouteCollection;

            final class Plugin implements ExtensionInterface, ApiRouteProviderInterface
            {
                public function boot(ExtensionContext \$context): void {}
                public function install(): void {}
                public function activate(): void {}
                public function deactivate(): void {}
                public function uninstall(): void {}
                public function update(string \$oldVersion, string \$newVersion): void {}
                public function subscribedEvents(): array { return []; }

                public function registerApiRoutes(RouteCollection \$routes): void
                {
                    \$routes->add('api_v1_plugin_routes_{$id}_ping', new Route(
                        '/api/v1/plugin-routes/{$id}/ping',
                        defaults: ['_controller' => PingController::class],
                        methods: ['GET'],
                    ));
                }
            }

            PHP);

        file_put_contents($dir . '/src/PingController.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Piwigo\\Http\\ControllerInterface;
            use Piwigo\\Http\\ResponseFactory;
            use Psr\\Http\\Message\\ResponseInterface;
            use Psr\\Http\\Message\\ServerRequestInterface;

            final class PingController implements ControllerInterface
            {
                public function __invoke(ServerRequestInterface \$request): ResponseInterface
                {
                    return ResponseFactory::text('pong from {$id}');
                }
            }

            PHP);
    }

    private function removeFixturePlugin(string $id): void
    {
        $this->removeDirRecursively(dirname(__DIR__, 2) . '/plugins/' . $id);
    }

    private function removeDirRecursively(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirRecursively($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Builds a real `PluginRegistry` via `PluginBootstrapMiddleware`'s
     * own private `pluginRegistry()` construction (reflection, not
     * duplicated by hand) -- `ExtensionContextFactory`/the plugin-scoped
     * read facades have no container binding of their own (only ever
     * built inside that middleware, `$conn`-scoped), so this is the one
     * way to get a real, correctly-wired `PluginRegistry` outside a real
     * request without re-deriving that construction from scratch and
     * risking a connection mismatch against the container's own.
     */
    private function pluginRegistryFromRealContainer(Connection $conn): PluginRegistry
    {
        $middleware = Kernel::container()->get(PluginBootstrapMiddleware::class);
        if (! $middleware instanceof PluginBootstrapMiddleware) {
            throw new LogicException('Container returned an unexpected type for ' . PluginBootstrapMiddleware::class);
        }

        $registry = new ReflectionMethod($middleware, 'pluginRegistry')
            ->invoke($middleware, $conn);
        if (! $registry instanceof PluginRegistry) {
            throw new LogicException('PluginBootstrapMiddleware::pluginRegistry() returned an unexpected type.');
        }

        return $registry;
    }
}
