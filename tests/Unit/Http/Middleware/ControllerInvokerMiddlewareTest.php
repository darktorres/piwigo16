<?php

declare(strict_types=1);

use Piwigo\Routing\RouteMatchStatus;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\Middleware\ControllerInvokerMiddleware;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Routing\RouteResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

function containerInvokerFakeContainer(mixed $value): ContainerInterface
{
    return new readonly class ($value) implements ContainerInterface {
        public function __construct(private mixed $value)
        {
        }

        #[Override]
        public function get(string $id)
        {
            return $this->value;
        }

        #[Override]
        public function has(string $id): bool
        {
            return true;
        }
    };
}

function containerInvokerNoopHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(200);
        }
    };
}

test('invokes the resolved handler for a found route and passes route args', function (): void {
    $captured = new ArrayObject();
    $controller = new readonly class ($captured) implements ControllerInterface {
        /**
         * @param ArrayObject<string, mixed> $captured
         */
        public function __construct(private ArrayObject $captured)
        {
        }

        #[Override]
        public function __invoke(ServerRequestInterface $request): ResponseInterface
        {
            $this->captured['args'] = $request->getAttribute('route_args');

            return new Response(200, [], 'invoked');
        }
    };

    $middleware = new ControllerInvokerMiddleware(containerInvokerFakeContainer($controller));
    $request = new ServerRequest('GET', '/')->withAttribute(RouteResult::class, RouteResult::found('X', ['id' => '5']));

    $response = $middleware->process($request, containerInvokerNoopHandler());

    expect((string) $response->getBody())->toBe('invoked');
    expect($captured['args'])->toBe(['id' => '5']);
});

test('catches ResponseReadyException from the controller and returns its response normally', function (): void {
    // PHP's exit()/die() skip pending `finally` blocks, so a controller
    // that terminates the process directly would leave every outer
    // middleware's own `finally` (e.g. SentryMiddleware's transaction
    // close) unexecuted. Catching ResponseReadyException here and
    // returning its response like any other Response means the caller
    // (RequestPipeline's own middleware stack) sees a completely normal
    // return -- no different from a controller that never throws at all.
    $controller = new readonly class () implements ControllerInterface {
        #[Override]
        public function __invoke(ServerRequestInterface $request): ResponseInterface
        {
            throw new ResponseReadyException(new Response(302, ['Location' => '/elsewhere']));
        }
    };

    $middleware = new ControllerInvokerMiddleware(containerInvokerFakeContainer($controller));
    $request = new ServerRequest('GET', '/')->withAttribute(RouteResult::class, RouteResult::found('X', []));

    $response = $middleware->process($request, containerInvokerNoopHandler());

    expect($response->getStatusCode())->toBe(302);
    expect($response->getHeaderLine('Location'))->toBe('/elsewhere');
});

test('returns 404 when the route was not found', function (): void {
    $middleware = new ControllerInvokerMiddleware(containerInvokerFakeContainer(null));
    $request = new ServerRequest('GET', '/')->withAttribute(RouteResult::class, RouteResult::notFound());

    $response = $middleware->process($request, containerInvokerNoopHandler());

    expect($response->getStatusCode())->toBe(404);
});

test('returns 404 when the route matched but somehow has no handler', function (): void {
    // Kills line 42's BooleanOrToBooleanAnd (`&&` instead of `||`).
    // RouteResult's public API (found()/notFound()/methodNotAllowed())
    // can never actually produce a Found status paired with a null
    // handler -- this guard exists as defensive code against exactly
    // that invariant being violated, which real callers can't
    // constructively reach. Reflection bypasses the private constructor
    // to build that otherwise-impossible state directly: confirmed live
    // that real code (`||`) still returns 404, while the mutant (`&&`)
    // proceeds to call the container with a null id and throws a
    // TypeError instead.
    $ref = new ReflectionClass(RouteResult::class);
    $result = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('status')->setValue($result, RouteMatchStatus::Found);
    $ref->getProperty('handler')->setValue($result, null);
    $ref->getProperty('args')->setValue($result, []);

    $middleware = new ControllerInvokerMiddleware(containerInvokerFakeContainer(null));
    $request = new ServerRequest('GET', '/')->withAttribute(RouteResult::class, $result);

    $response = $middleware->process($request, containerInvokerNoopHandler());

    expect($response->getStatusCode())->toBe(404);
});

test('returns 404 when the method is not allowed', function (): void {
    $middleware = new ControllerInvokerMiddleware(containerInvokerFakeContainer(null));
    $request = new ServerRequest('GET', '/')->withAttribute(RouteResult::class, RouteResult::methodNotAllowed());

    $response = $middleware->process($request, containerInvokerNoopHandler());

    expect($response->getStatusCode())->toBe(404);
});

test('throws when no RouteResult attribute is present', function (): void {
    $middleware = new ControllerInvokerMiddleware(containerInvokerFakeContainer(null));

    $middleware->process(new ServerRequest('GET', '/'), containerInvokerNoopHandler());
})->throws(LogicException::class);

test('throws when the resolved service does not implement ControllerInterface', function (): void {
    $middleware = new ControllerInvokerMiddleware(containerInvokerFakeContainer(new stdClass()));
    $request = new ServerRequest('GET', '/')->withAttribute(RouteResult::class, RouteResult::found('X', []));

    $middleware->process($request, containerInvokerNoopHandler());
})->throws(LogicException::class);
