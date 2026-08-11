<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Http\Middleware\SessionMiddleware;
use Piwigo\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

// Correction to an earlier assumption: session_start() does NOT silently
// fail under Pest's own CLI runner the way a raw `php -r` script does --
// confirmed empirically (headers_sent() is false, session_status()
// genuinely transitions from NONE to ACTIVE, no warning) that Pest either
// doesn't flush output the same way or otherwise leaves headers_sent()
// false by the time a test body runs. session_start() DOES real work
// here, including resetting $_SESSION to whatever the session store has
// for the generated id -- tests that care about $_SESSION's prior
// content must ensure a session is already active first (session_start()
// overwrites it) or already closed (session_write_close()) before
// asserting on activation, matching the two dedicated tests below.

function sessionMiddlewareCapturingHandler(): RequestHandlerInterface
{
    return new class() implements RequestHandlerInterface {
        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $session = $request->getAttribute(Session::class);
            expect($session)
                ->toBeInstanceOf(Session::class);

            return new Response(200, [], 'ok');
        }
    };
}

test('attaches a Session VO as a request attribute and passes the response through', function (): void {
    $response = new SessionMiddleware()
        ->process(new ServerRequest('GET', '/'), sessionMiddlewareCapturingHandler());

    expect($response->getStatusCode())
        ->toBe(200);
    expect((string) $response->getBody())
        ->toBe('ok');
});

test('does not error when a session is already reported active', function (): void {
    // Simulates the "legacy common.inc.php already started one" case
    // without depending on a real session_start() succeeding under CLI.
    $middleware = new SessionMiddleware();

    $response = $middleware->process(new ServerRequest('GET', '/'), sessionMiddlewareCapturingHandler());
    $response2 = $middleware->process(new ServerRequest('GET', '/'), sessionMiddlewareCapturingHandler());

    expect($response->getStatusCode())
        ->toBe(200);
    expect($response2->getStatusCode())
        ->toBe(200);
});

test('activates a session when none is active yet', function (): void {
    // Kills line 38's IfNegated/NotIdenticalToIdentical and line 39's
    // RemoveFunctionCall. Forces the "not active" precondition via
    // session_write_close() (genuinely resets session_status() to NONE,
    // confirmed live) regardless of what earlier tests in this shared
    // process left it as.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    new SessionMiddleware()
        ->process(new ServerRequest('GET', '/'), sessionMiddlewareCapturingHandler());

    expect(session_status())
        ->toBe(PHP_SESSION_ACTIVE);
});

test('does not wipe pre-existing $_SESSION data when a session is already active', function (): void {
    // Kills line 41's CoalesceEqualToEqual (`$_SESSION = []` instead of
    // `??=`). session_start() itself resets $_SESSION when it actually
    // runs, so this must force the "already active" precondition first
    // (session_start() skips re-starting, matching the "already
    // reported active" test above) before setting the marker, isolating
    // the ??= vs = distinction to $_SESSION's OWN line. Confirmed live
    // real code preserves the marker; the mutant wipes it.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION = [
        'marker' => 'preexisting-value',
    ];

    new SessionMiddleware()
        ->process(new ServerRequest('GET', '/'), sessionMiddlewareCapturingHandler());

    expect($_SESSION['marker'])->toBe('preexisting-value');
});

/**
 * Confirmed-equivalent: line 47's RemoveMethodCall (dropping
 * `$session->persistInto($_SESSION);`). Session::persistInto()'s own
 * body is currently empty -- "zero typed slots... nothing to write
 * back" per its own docblock -- so calling it has no observable effect
 * at all today. This will become a real, closable gap once a future
 * phase adds the first typed slot; not now.
 */
