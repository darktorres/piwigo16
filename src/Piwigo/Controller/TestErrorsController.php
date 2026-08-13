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
 * Drains Piwigo\Core\ErrorCollector's buffer. A prior IntegrationTestCase::
 * assertNoPhpErrors() helper called this route via a separate follow-up
 * request after the one under test, meant to catch PHP errors/warnings/
 * deprecations that a failed/redirected request may never surface via the
 * X-PHP-Error-N response headers -- deleted as dead code once
 * ContractTestCase::assertNoPhpErrorHeaders() proved that approach
 * structurally can't work: ErrorCollector's buffer is per-request, so a
 * second, separate request can never see the first request's errors. See
 * ContractTestCase's own docblock for the working alternative, which reads
 * X-PHP-Error-N headers off the SAME response instead. This route itself
 * stays -- exercised directly by tests/Browser/TestErrorsControllerTest.php
 * -- as a real, still-correct primitive a future caller could build on.
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
