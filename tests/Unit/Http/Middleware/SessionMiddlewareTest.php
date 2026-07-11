<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\Middleware\SessionMiddleware;
use Piwigo\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

// No assertion on session_status() becoming PHP_SESSION_ACTIVE: confirmed
// empirically that session_start() silently fails to activate once any
// output has already occurred under CLI SAPI, and Pest's own console
// output happens before any test body runs -- the same CLI-testing
// limitation P7's ResponseEmitterTest already documented for header().
// SessionMiddleware defends against this for real (`$_SESSION ??= []`),
// so the reliably-testable part -- the VO gets attached and the response
// passes through -- still holds regardless of whether activation
// actually succeeded in this process.

function sessionMiddlewareCapturingHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $session = $request->getAttribute(Session::class);
            expect($session)->toBeInstanceOf(Session::class);

            return new Response(200, [], 'ok');
        }
    };
}

test('attaches a Session VO as a request attribute and passes the response through', function (): void {
    $response = new SessionMiddleware()->process(new ServerRequest('GET', '/'), sessionMiddlewareCapturingHandler());

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getBody())->toBe('ok');
});

test('does not error when a session is already reported active', function (): void {
    // Simulates the "legacy common.inc.php already started one" case
    // without depending on a real session_start() succeeding under CLI.
    $middleware = new SessionMiddleware();

    $response = $middleware->process(new ServerRequest('GET', '/'), sessionMiddlewareCapturingHandler());
    $response2 = $middleware->process(new ServerRequest('GET', '/'), sessionMiddlewareCapturingHandler());

    expect($response->getStatusCode())->toBe(200);
    expect($response2->getStatusCode())->toBe(200);
});
