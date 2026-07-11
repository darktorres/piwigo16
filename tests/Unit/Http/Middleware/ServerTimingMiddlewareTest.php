<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Core\ServerTiming;
use Piwigo\Http\Middleware\ServerTimingMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

function serverTimingPassthroughHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(200);
        }
    };
}

beforeEach(function (): void {
    ServerTiming::reset();
    putenv('SERVER_TIMING_ENABLED');
});

afterEach(function (): void {
    ServerTiming::reset();
    putenv('SERVER_TIMING_ENABLED');
});

test('no header when disabled', function (): void {
    ServerTiming::start('boot');
    ServerTiming::stop('boot');

    $response = new ServerTimingMiddleware()->process(new ServerRequest('GET', '/'), serverTimingPassthroughHandler());

    expect($response->hasHeader('Server-Timing'))->toBeFalse();
});

test('no header when enabled but nothing was recorded', function (): void {
    putenv('SERVER_TIMING_ENABLED=1');

    $response = new ServerTimingMiddleware()->process(new ServerRequest('GET', '/'), serverTimingPassthroughHandler());

    expect($response->hasHeader('Server-Timing'))->toBeFalse();
});

test('header lists recorded timings when enabled', function (): void {
    putenv('SERVER_TIMING_ENABLED=1');
    ServerTiming::start('boot');
    ServerTiming::stop('boot');

    $response = new ServerTimingMiddleware()->process(new ServerRequest('GET', '/'), serverTimingPassthroughHandler());

    expect($response->getHeaderLine('Server-Timing'))->toStartWith('boot;dur=');
});
