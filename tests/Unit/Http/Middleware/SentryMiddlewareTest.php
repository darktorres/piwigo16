<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\Middleware\SentryMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\SentrySdk;

function sentryMiddlewarePassthroughHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(200, [], 'ok');
        }
    };
}

beforeEach(function (): void {
    SentrySdk::init(); // fresh hub, no client bound
});

afterEach(function (): void {
    SentrySdk::init();
});

test('passes through unchanged when the SDK is not initialized', function (): void {
    $response = new SentryMiddleware()->process(new ServerRequest('GET', '/'), sentryMiddlewarePassthroughHandler());

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getBody())->toBe('ok');
});

test('wraps the request in a transaction without altering the response when the SDK is initialized', function (): void {
    // default_integrations disabled: this test only cares whether the
    // middleware wraps a transaction correctly once a client is bound, not
    // whether init() also registers global PHP error/exception handlers
    // (SentryBootstrapTest already covers that production behavior). Real
    // default_integrations across a multi-file suite proved sensitive to
    // PHPUnit's own risky-test handler-stack tracking; this sidesteps it
    // rather than chasing exact restore-call parity across files.
    \Sentry\init(['dsn' => 'https://fake@fake.ingest.sentry.io/1', 'default_integrations' => false]);

    $response = new SentryMiddleware()->process(new ServerRequest('GET', '/'), sentryMiddlewarePassthroughHandler());

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getBody())->toBe('ok');
});
