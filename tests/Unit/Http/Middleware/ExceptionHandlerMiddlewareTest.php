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
