<?php

declare(strict_types=1);

use Piwigo\Config\DeploymentPolicy;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Controller\TestErrorsController;
use Piwigo\Core\ErrorCollector;

/**
 * Piwigo\Controller\TestErrorsController's own "outside test mode" 404
 * branch (`! Env::testModeIsActive()`, ~line 33) -- NOT exercised by
 * tests/Browser/TestErrorsControllerTest.php (see that file's own
 * docblock): omitting the X-Piwigo-Env header on a real HTTP request makes
 * Bootstrap\RequestBootstrap load the real (non-test) `.env` instead of
 * `.env.test`, and this dev sandbox has no `.env` file at all, so a
 * header-less `curl` 500s from RequestBootstrap::configure()'s own
 * DB-connection fatal well before this controller's body ever runs --
 * untestable via any real HTTP route in this environment.
 *
 * Env::testModeIsActive() reads $_SERVER directly though, not anything
 * derived from the ServerRequestInterface argument (this controller never
 * reads $request at all -- see its own __invoke() body), so a direct,
 * no-HTTP Unit invocation reaches the real branch: temporarily clear
 * tests/bootstrap.php's own `HTTP_X_PIWIGO_ENV` header (set once, globally,
 * for the whole CLI test-suite process) the same way
 * tests/Unit/Core/ErrorCollectorTest.php and
 * tests/Unit/Core/CoverageCollectorTest.php already do, restored
 * immediately after.
 */
test('returns an empty 404 response when test mode is not active', function (): void {
    $original = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    unset($_SERVER['HTTP_X_PIWIGO_ENV']);

    try {
        $controller = new TestErrorsController(new ErrorCollector(new DeploymentPolicy()));
        $response = $controller(new ServerRequest('GET', '/__test/errors'));
    } finally {
        if ($original !== null) {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $original;
        }
    }

    expect($response->getStatusCode())->toBe(404);
    expect((string) $response->getBody())->toBe('');
});
