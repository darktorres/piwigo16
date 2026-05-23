<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Http\SecurityHeaders;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Defense-in-depth response headers — CSP, X-Frame-Options,
 * X-Content-Type-Options, Referrer-Policy, Permissions-Policy, and
 * (HTTPS only) HSTS. The exact header shapes live in
 * {@see SecurityHeaders}; this middleware just applies them to PSR-7
 * responses on the main pipeline.
 *
 * Sits at position 0 of the pipeline (outermost), so headers also
 * attach to the error responses produced by ExceptionHandlerMiddleware.
 * The four fast-path branches in `index.php` (install, upgrade,
 * upgrade_feed, i/) short-circuit before the pipeline runs, so they
 * call {@see SecurityHeaders::emitDirect()} themselves.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        foreach (SecurityHeaders::headerMap() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
