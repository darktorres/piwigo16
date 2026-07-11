<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Kernel;

/**
 * Confirms RequestPipeline::handle() runs the real pipeline end-to-end.
 * Nothing in production calls this yet (index.php still only calls
 * CommonBootstrap::run(), P7) -- config/routes.php is empty, so every
 * request correctly 404s. This is the honest, currently-true behavior; it
 * changes once P22 registers real routes.
 */
final class RequestPipelineTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        Kernel::reset();
    }

    public function test_handle_returns_404_when_no_routes_are_registered(): void
    {
        Kernel::boot();

        $response = RequestPipeline::handle(new ServerRequest('GET', '/anything'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_handle_response_carries_baseline_security_headers(): void
    {
        Kernel::boot();

        $response = RequestPipeline::handle(new ServerRequest('GET', '/anything'));

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }
}
