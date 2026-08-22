<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\Middleware\SecurityHeadersMiddleware;
use Piwigo\Http\SecurityHeaderContributor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

function securityHeadersPassthroughHandler(): RequestHandlerInterface
{
    return new class() implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(200);
        }
    };
}

test('the default baseline contributor adds the unconditional header set', function (): void {
    $response = new SecurityHeadersMiddleware()
        ->process(
            new ServerRequest('GET', '/'),
            securityHeadersPassthroughHandler()
        );

    expect($response->getHeaderLine('X-Content-Type-Options'))
        ->toBe('nosniff');
    expect($response->getHeaderLine('X-Frame-Options'))
        ->toBe('SAMEORIGIN');
    expect($response->getHeaderLine('Referrer-Policy'))
        ->toBe('strict-origin-when-cross-origin');
    expect($response->getHeaderLine('Strict-Transport-Security'))
        ->toBe('max-age=31536000');
});

test('a custom contributor list can add its own headers', function (): void {
    $extra = new class() implements SecurityHeaderContributor {
        #[Override]
        public function contribute(ResponseInterface $response): ResponseInterface
        {
            return $response->withHeader('X-Test-Contributor', 'yes');
        }
    };

    $response = new SecurityHeadersMiddleware([$extra])->process(
        new ServerRequest('GET', '/'),
        securityHeadersPassthroughHandler()
    );

    expect($response->getHeaderLine('X-Test-Contributor'))
        ->toBe('yes');
});
