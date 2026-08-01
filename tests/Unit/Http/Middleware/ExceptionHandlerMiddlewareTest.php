<?php

declare(strict_types=1);

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

function okHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(200, [], 'ok');
        }
    };
}

function throwingHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            throw new RuntimeException('boom');
        }
    };
}

test('passes through a successful response unchanged', function (): void {
    $logger = new Logger('test', [new TestHandler()]);

    $response = new ExceptionHandlerMiddleware($logger)->process(new ServerRequest('GET', '/'), okHandler());

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getBody())->toBe('ok');
});

test('catches a downstream throwable and returns a generic 500', function (): void {
    $logger = new Logger('test', [new TestHandler()]);

    $response = new ExceptionHandlerMiddleware($logger)->process(new ServerRequest('GET', '/'), throwingHandler());

    expect($response->getStatusCode())->toBe(500);
    expect((string) $response->getBody())->toBe('Internal Server Error');
});

test('logs the caught throwable with structured context', function (): void {
    $handler = new TestHandler();
    $logger = new Logger('test', [$handler]);

    new ExceptionHandlerMiddleware($logger)->process(new ServerRequest('GET', '/some/path'), throwingHandler());

    expect($handler->hasRecordThatContains('Unhandled exception', Level::Error))->toBeTrue();

    $record = $handler->getRecords()[0];
    expect($record->context)->toBe([
        'exception' => RuntimeException::class,
        'message' => 'boom',
        'path' => '/some/path',
    ]);
});

test('reports the caught throwable to Sentry', function (): void {
    // Kills line 39's RemoveFunctionCall (dropping the
    // \Sentry\captureException($e) call entirely). captureException()
    // is a safe no-op with no observable effect when the SDK isn't
    // initialized, so this needs a real, initialized SDK client to
    // detect at all -- a spy Transport (real Sentry\init() option,
    // same 'transport' mechanism SentryMiddlewareTest's own precedent
    // uses a fake DSN for) captures every Event actually sent, letting
    // this assert the exception was genuinely reported, not just that
    // captureException() exists in the source.
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
        'transport' => $spyTransport,
    ]);

    $logger = new Logger('test', [new TestHandler()]);
    new ExceptionHandlerMiddleware($logger)->process(new ServerRequest('GET', '/'), throwingHandler());

    SentrySdk::init();

    expect($spyTransport->captured)->toHaveCount(1);
    $exceptions = $spyTransport->captured[0]->getExceptions();
    expect($exceptions)->toHaveCount(1)
        ->and($exceptions[0]->getValue())->toBe('boom');
});
