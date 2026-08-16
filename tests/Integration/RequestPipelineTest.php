<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
use Piwigo\Tests\Support\KernelContainerOverride;
use Psr\Http\Message\ResponseInterface;

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
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }
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
}
