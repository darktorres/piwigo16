<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Override;
use Psr\Http\Message\ResponseInterface;

/**
 * The unconditional baseline set -- headers safe to add regardless of
 * scheme/nonce state. CSP/HSTS/Permissions-Policy need nonce and
 * HTTPS-detection infrastructure that doesn't exist yet (P27/P32).
 */
final class BaselineSecurityHeaders implements SecurityHeaderContributor
{
    #[Override]
    public function contribute(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
