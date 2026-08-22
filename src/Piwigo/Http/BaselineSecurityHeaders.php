<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Override;
use Psr\Http\Message\ResponseInterface;

/**
 * The unconditional baseline set -- headers safe to add regardless of
 * scheme/nonce state. CSP/Permissions-Policy still need nonce
 * infrastructure that doesn't exist yet. Strict-Transport-Security
 * (P44-M) doesn't need that same gating -- browsers ignore it entirely
 * on a plain-HTTP response, so it's safe to send unconditionally rather
 * than threading HTTPS-detection through this contributor.
 */
final class BaselineSecurityHeaders implements SecurityHeaderContributor
{
    #[Override]
    public function contribute(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
