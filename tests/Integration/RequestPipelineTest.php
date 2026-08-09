<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Kernel;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Confirms RequestPipeline::handle() runs the real pipeline end-to-end.
 * config/routes.php now has real routes and every root frontend file
 * (about.php first, the rest incrementally) actually calls this for live
 * traffic -- an unmatched path (used throughout this file) still correctly
 * 404s, same as before any routes existed. A real registered route
 * (/about.php) is deliberately *not* exercised here: its controller needs
 * the full legacy include/common.inc.php bootstrap (real $template/$page/
 * $user/$conf globals, check_status()/l10n()/etc. free functions) that
 * only a real HTTP request through Apache -- or
 * RequestBootstrap::bootEntryPoint() itself -- provides; live-curl
 * verification against the real instance is
 * the actual end-to-end proof for that.
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
final class RequestPipelineTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        Kernel::reset();
    }

    public function test_handle_returns_404_for_an_unmatched_path(): void
    {
        Kernel::boot();

        $response = RequestPipeline::handle(new ServerRequest('GET', '/anything'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_handle_response_carries_baseline_security_headers(): void
    {
        Kernel::boot();

        $response = RequestPipeline::handle(new ServerRequest('GET', '/anything'));

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function test_handle_throws_when_the_container_returns_an_unexpected_type_for_a_middleware(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            "Container returned an unexpected type for '" . ExceptionHandlerMiddleware::class . "'."
        );

        // Every one of DEFAULT_MIDDLEWARE's 7 entries is resolved eagerly,
        // in order, by the array_map() inside handle() itself -- rebinding
        // the very first one (ExceptionHandlerMiddleware) means no other
        // real middleware needs to resolve at all before the guard fires.
        KernelContainerOverride::withWrongTypeFor(
            ExceptionHandlerMiddleware::class,
            static fn () => RequestPipeline::handle(new ServerRequest('GET', '/anything'))
        );
    }
}
