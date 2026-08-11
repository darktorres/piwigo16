<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * Piwigo\Bootstrap\RequestPipeline -- runs the real 7-middleware pipeline
 * every root entry point calls. No dedicated Integration/Browser spec of
 * its own (every real HTTP request through the app goes through it, but
 * nothing imports the class name directly in an existing test).
 *
 * A request path that matches no real route in config/routes.php is a
 * real, deterministic, cheap branch through the *entire* pipeline:
 * ExceptionHandlerMiddleware/SecurityHeadersMiddleware/SessionMiddleware/
 * ServerTimingMiddleware/SentryMiddleware all just wrap the response:
 * RoutingMiddleware's real Router::dispatch() (real Symfony UrlMatcher
 * against the real routes file, already container-bound) returns
 * RouteResult::notFound(), and ControllerInvokerMiddleware's own first
 * check (`$result->status !== RouteMatchStatus::Found`) returns a plain
 * 404 text response immediately -- no controller is ever resolved or
 * invoked.
 *
 * `SessionMiddleware` really does call `session_start()` here (see
 * `SessionMiddlewareTest.php`'s own docblock -- it genuinely activates
 * under Pest's CLI runner, not a silent no-op), so this closes the
 * session in `finally` via `session_write_close()` -- confirmed live
 * that leaving it active breaks `CsrfServiceTest.php`'s own
 * `session_id()` calls whenever both files land in the same worker.
 */
function requestPipelineTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? requestPipelineTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

test('handle returns a real 404 for a path matching no real route, without invoking any controller', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-request-pipeline-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    try {
        $response = RequestPipeline::handle(new ServerRequest('GET', '/this-route-does-not-exist-xyz'));

        expect($response->getStatusCode())
            ->toBe(404)
            ->and((string) $response->getBody())
            ->toBe('Not Found');
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        Kernel::reset();
        requestPipelineTestRrmdir($root);
    }
});
