<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Override;
use Piwigo\Core\Env;
use Piwigo\Core\ErrorCollector;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Test-mode-only error-drain route (`GET /__test/errors`).
 * Drains Piwigo\Core\ErrorCollector's buffer -- per-request, not static, so
 * a separate follow-up request can never see a prior request's errors; use
 * X-PHP-Error-N response headers on the SAME response instead (see
 * ContractTestCase::assertNoPhpErrorHeaders()). Exercised directly by
 * tests/Browser/TestErrorsControllerTest.php.
 *
 * 404s outside test mode -- this route must never be reachable in
 * production (it would let anyone read recent server-side error text).
 */
final readonly class TestErrorsController implements ControllerInterface
{
    public function __construct(
        private ErrorCollector $errorCollector,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (! Env::testModeIsActive()) {
            return ResponseFactory::text('', 404);
        }

        return ResponseFactory::json([
            'errors' => $this->errorCollector->drain(),
        ]);
    }
}
