<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\Middleware\SentryMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

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

test('never opens a transaction when the SDK is not initialized', function (): void {
    // Kills line 27's InstanceOfToTrue (`if (!true)`, i.e. never taking
    // the early return) and line 28's RemoveEarlyReturn (the early-return
    // branch becomes a no-op, falling through to the same transaction-
    // building code either way). Both leave the current scope's span set
    // even though no client is bound. configureScope()/getSpan() operate
    // on the Hub's own scope stack independent of whether a client is
    // bound (confirmed live in the SDK source), so this is directly
    // observable without needing a real client.
    expect(SentrySdk::getCurrentHub()->getSpan())->toBeNull();

    new SentryMiddleware()->process(new ServerRequest('GET', '/'), sentryMiddlewarePassthroughHandler());

    expect(SentrySdk::getCurrentHub()->getSpan())->toBeNull();
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

test('sends exactly one correctly-named, correctly-tagged transaction event when the SDK is initialized', function (): void {
    // Kills line 27's IfNegated/RemoveNot (both reduce the guard to
    // `if ($isClient)`, taking the early return -- skipping the
    // transaction entirely -- exactly when a client IS bound) and
    // InstanceOfToFalse (`if (!false)`, always taking the early return
    // regardless), plus line 34's RemoveMethodCall (dropping
    // setOp('http.server')) and line 44's RemoveMethodCall (dropping
    // transaction->finish(), which is what actually builds and sends the
    // event -- without it nothing reaches the transport at all). A spy
    // Transport (same real Sentry\init() 'transport' + fake-DSN
    // mechanism as ExceptionHandlerMiddlewareTest's own precedent, plus
    // a real traces_sample_rate so the transaction is actually sampled
    // and sent) directly observes all of these at once.
    $spyTransport = new class implements TransportInterface {
        /** @var list<Event> */
        public array $captured = [];

        #[Override]
        public function send(Event $event): Result
        {
            $this->captured[] = $event;

            return new Result(ResultStatus::success(), $event);
        }

        #[Override]
        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };
    \Sentry\init([
        'dsn' => 'https://fake@fake.ingest.sentry.io/1',
        'default_integrations' => false,
        'traces_sample_rate' => 1.0,
        'transport' => $spyTransport,
    ]);

    new SentryMiddleware()->process(new ServerRequest('GET', '/some/path'), sentryMiddlewarePassthroughHandler());

    expect($spyTransport->captured)->toHaveCount(1);
    $event = $spyTransport->captured[0];
    expect($event->getTransaction())->toBe('GET /some/path')
        ->and($event->getContexts()['trace']['op'] ?? null)->toBe('http.server');
});

test('makes the transaction the active span visible to the downstream handler', function (): void {
    // Kills line 37's RemoveFunctionCall (dropping the whole
    // configureScope() call) and line 38's RemoveMethodCall (dropping
    // just the inner $scope->setSpan($transaction) call, leaving an
    // empty closure). Both leave the current scope's span null while the
    // downstream handler runs, instead of the real transaction.
    \Sentry\init(['dsn' => 'https://fake@fake.ingest.sentry.io/1', 'default_integrations' => false, 'traces_sample_rate' => 1.0]);

    $handler = new class implements RequestHandlerInterface {
        public mixed $capturedSpan = 'not-set';

        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->capturedSpan = SentrySdk::getCurrentHub()->getSpan();

            return new Response(200);
        }
    };

    new SentryMiddleware()->process(new ServerRequest('GET', '/'), $handler);

    expect($handler->capturedSpan)->not->toBeNull();
});
