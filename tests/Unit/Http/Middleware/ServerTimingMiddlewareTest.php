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
    putenv('SERVER_TIMING_ENABLED');
});

afterEach(function (): void {
    putenv('SERVER_TIMING_ENABLED');
});

test('no header when disabled', function (): void {
    $timing = new ServerTiming();
    $timing->start('boot');
    $timing->stop('boot');

    $response = new ServerTimingMiddleware($timing)->process(new ServerRequest('GET', '/'), serverTimingPassthroughHandler());

    expect($response->hasHeader('Server-Timing'))->toBeFalse();
});

test('no header when explicitly set to an empty string, not just when unset', function (): void {
    // Kills line 28's EmptyStringToNotEmpty: the existing "no header
    // when disabled" test above only reaches the $enabled === false
    // branch (a genuinely unset env var) -- an explicitly empty (but
    // set) value is a different code path through the same guard.
    putenv('SERVER_TIMING_ENABLED=');
    $timing = new ServerTiming();
    $timing->start('boot');
    $timing->stop('boot');

    $response = new ServerTimingMiddleware($timing)->process(new ServerRequest('GET', '/'), serverTimingPassthroughHandler());

    expect($response->hasHeader('Server-Timing'))->toBeFalse();
});

test('no header when enabled but nothing was recorded', function (): void {
    putenv('SERVER_TIMING_ENABLED=1');

    $response = new ServerTimingMiddleware(new ServerTiming())->process(new ServerRequest('GET', '/'), serverTimingPassthroughHandler());

    expect($response->hasHeader('Server-Timing'))->toBeFalse();
});

test('header lists recorded timings when enabled', function (): void {
    putenv('SERVER_TIMING_ENABLED=1');
    $timing = new ServerTiming();
    $timing->start('boot');
    $timing->stop('boot');

    $response = new ServerTimingMiddleware($timing)->process(new ServerRequest('GET', '/'), serverTimingPassthroughHandler());

    expect($response->getHeaderLine('Server-Timing'))->toStartWith('boot;dur=');
});
