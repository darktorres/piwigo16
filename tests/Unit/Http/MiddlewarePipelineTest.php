<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\MiddlewarePipeline;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @param ArrayObject<int, string> $trace
 */
function tracingMiddleware(string $label, ArrayObject $trace): MiddlewareInterface
{
    return new readonly class ($label, $trace) implements MiddlewareInterface {
        /**
         * @param ArrayObject<int, string> $trace
         */
        public function __construct(private string $label, private ArrayObject $trace)
        {
        }

        #[Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $this->trace[] = "before:{$this->label}";
            $response = $handler->handle($request);
            $this->trace[] = "after:{$this->label}";

            return $response;
        }
    };
}

/**
 * @param ArrayObject<int, string> $trace
 */
function terminalHandler(ArrayObject $trace): RequestHandlerInterface
{
    return new readonly class ($trace) implements RequestHandlerInterface {
        /**
         * @param ArrayObject<int, string> $trace
         */
        public function __construct(private ArrayObject $trace)
        {
        }

        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->trace[] = 'fallback';

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

    expect((string) $response->getBody())->toBe('terminal');
    expect((array) $trace)->toBe(['before:a', 'before:b', 'fallback', 'after:b', 'after:a']);
});

test('calls the fallback directly when there is no middleware', function (): void {
    $trace = new ArrayObject();
    $pipeline = new MiddlewarePipeline([], terminalHandler($trace));

    $response = $pipeline->handle(new ServerRequest('GET', '/'));

    expect((string) $response->getBody())->toBe('terminal');
    expect((array) $trace)->toBe(['fallback']);
});
