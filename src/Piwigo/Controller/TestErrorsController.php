<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\Env;
use Piwigo\Core\ErrorCollector;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Test-mode-only error-drain route (`GET /__test/errors`), per
 * docs/PLAN-REPLAY.md's own "test-mode error-drain assertion per request"
 * design (Risk register, P17-P23 mitigation) -- never wired until now
 * (docs/PLAN-REPLAY-AUDIT.md finding #7). Drains Piwigo\Core\ErrorCollector's
 * buffer so IntegrationTestCase::assertNoPhpErrors() can assert a real HTTP
 * request produced no PHP errors/warnings/deprecations, instead of relying
 * only on the X-PHP-Error-N response headers (which a failed/redirected
 * request may never surface to the test client).
 *
 * 404s outside test mode -- this route must never be reachable in
 * production (it would let anyone read recent server-side error text).
 */
final class TestErrorsController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (! Env::testModeIsActive()) {
            return ResponseFactory::text('', 404);
        }

        return ResponseFactory::json([
            'errors' => ErrorCollector::drain(),
        ]);
    }
}
