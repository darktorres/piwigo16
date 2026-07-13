<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Kernel;

/**
 * Confirms RequestPipeline::handle() runs the real pipeline end-to-end.
 * config/routes.php now has real routes (P22) and every root frontend file
 * (about.php first, the rest incrementally) actually calls this for live
 * traffic -- an unmatched path (used throughout this file) still correctly
 * 404s, same as before any routes existed. A real registered route
 * (/about.php) is deliberately *not* exercised here: its controller needs
 * the full legacy include/common.inc.php bootstrap (real $template/$page/
 * $user/$conf globals, check_status()/l10n()/etc. free functions) that
 * only a real HTTP request through Apache -- or CommonBootstrap::run()
 * itself -- provides; live-curl verification against the real instance is
 * the actual end-to-end proof for that (see docs/plan/manifest.yaml's P22
 * wrap-up memory).
 */
final class RequestPipelineTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        Kernel::reset();
    }

    public function test_handle_returns_404_for_an_unmatched_path(): void
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
