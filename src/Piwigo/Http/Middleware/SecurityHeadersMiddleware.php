<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Defense-in-depth response headers — CSP, X-Frame-Options,
 * X-Content-Type-Options, Referrer-Policy, Permissions-Policy, and
 * (HTTPS only) HSTS.
 *
 * Sits at position 0 of the pipeline (outermost), so headers also
 * attach to the error responses produced by ExceptionHandlerMiddleware.
 *
 * CSP shape notes:
 *   - `script-src 'self'` — no nonce expression. Every `<script>` tag
 *     in tree is `type="application/json"` (data island), exempt from
 *     `script-src` per CSP3 §6.1.5. A separate CI guard
 *     (`tools/check-no-executable-inline-scripts.php`) blocks
 *     executable inline scripts from regressing into templates.
 *   - `style-src-attr 'unsafe-inline'` — covers the inline
 *     `style="--var:value"` CSS-variable bridges on a handful of
 *     templates (thumbnails, calendars, format cards, …).
 *   - HSTS only set under HTTPS; browsers ignore it over plain HTTP
 *     anyway, and emitting it there is just noise.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const string CSP = "default-src 'self'; "
        . "img-src 'self' data: blob:; "
        . "style-src 'self'; "
        . "style-src-elem 'self'; "
        . "style-src-attr 'unsafe-inline'; "
        . "script-src 'self'; "
        . "frame-ancestors 'self'; "
        . "form-action 'self'";

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && $https !== 'off') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
