<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\MiddlewarePipeline;
use Piwigo\Http\ResponseReadyException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @param ArrayObject<int, string> $trace
 */
function tracingMiddleware(string $label, ArrayObject $trace): MiddlewareInterface
{
    return new readonly class($label, $trace) implements MiddlewareInterface {
        /**
         * @param ArrayObject<int, string> $trace
         */
        public function __construct(
            private string $label,
            private ArrayObject $trace
        ) {}

        #[Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $this->trace->append("before:{$this->label}");
            $response = $handler->handle($request);
            $this->trace->append("after:{$this->label}");

            return $response;
        }
    };
}

/**
 * A middleware that never calls $handler->handle() at all -- throws
 * ResponseReadyException immediately instead, the same shape a real
 * bootstrap-phase short-circuit (install-sentinel redirect, gallery-locked
 * 503) takes once ported to a real middleware (workstream C3 Phase 1).
 */
function shortCircuitingMiddleware(ResponseInterface $response): MiddlewareInterface
{
    return new readonly class($response) implements MiddlewareInterface {
        public function __construct(
            private ResponseInterface $response
        ) {}

        #[Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            throw new ResponseReadyException($this->response);
        }
    };
}

/**
 * @param ArrayObject<int, string> $trace
 */
function terminalHandler(ArrayObject $trace): RequestHandlerInterface
{
    return new readonly class($trace) implements RequestHandlerInterface {
        /**
         * @param ArrayObject<int, string> $trace
         */
        public function __construct(
            private ArrayObject $trace
        ) {}

        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->trace->append('fallback');

            return new Response(200, [], 'terminal');
        }
    };
}

test('runs middleware in order, then the fallback, then unwinds in reverse', function (): void {
    $trace = new ArrayObject();
    $pipeline = new MiddlewarePipeline(
        [tracingMiddleware('a', $trace), tracingMiddleware('b', $trace)],
        terminalHandler($trace),
    );

    $response = $pipeline->handle(new ServerRequest('GET', '/'));

    expect((string) $response->getBody())
        ->toBe('terminal');
    expect((array) $trace)
        ->toBe(['before:a', 'before:b', 'fallback', 'after:b', 'after:a']);
});

test('calls the fallback directly when there is no middleware', function (): void {
    $trace = new ArrayObject();
    $pipeline = new MiddlewarePipeline([], terminalHandler($trace));

    $response = $pipeline->handle(new ServerRequest('GET', '/'));

    expect((string) $response->getBody())
        ->toBe('terminal');
    expect((array) $trace)
        ->toBe(['fallback']);
});

test('a ResponseReadyException thrown by a middleware in the middle of the chain looks like a normal return to every outer middleware', function (): void {
    $trace = new ArrayObject();
    $shortCircuitResponse = new Response(503, [], 'gallery locked');
    $pipeline = new MiddlewarePipeline(
        [tracingMiddleware('a', $trace), shortCircuitingMiddleware($shortCircuitResponse), tracingMiddleware('b', $trace)],
        terminalHandler($trace),
    );

    $response = $pipeline->handle(new ServerRequest('GET', '/'));

    expect($response)
        ->toBe($shortCircuitResponse);
    expect((string) $response->getBody())
        ->toBe('gallery locked');
    // 'b' and 'fallback' never run (the short-circuit sits between 'a' and
    // 'b'), but 'a' sees a normal return, not a propagating exception --
    // both its before: and after: trace entries are recorded.
    expect((array) $trace)
        ->toBe(['before:a', 'after:a']);
});

test('a ResponseReadyException thrown by the innermost middleware is caught at that same level', function (): void {
    $trace = new ArrayObject();
    $shortCircuitResponse = new Response(302, [], 'redirect');
    $pipeline = new MiddlewarePipeline(
        [tracingMiddleware('a', $trace), shortCircuitingMiddleware($shortCircuitResponse)],
        terminalHandler($trace),
    );

    $response = $pipeline->handle(new ServerRequest('GET', '/'));

    expect($response)
        ->toBe($shortCircuitResponse);
    expect((array) $trace)
        ->toBe(['before:a', 'after:a']);
});
