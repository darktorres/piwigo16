<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Lets future work add header providers without rewriting
 * SecurityHeadersMiddleware: planned additions include
 * nonce-CSP/COOP/COEP/Trusted-Types/Fetch-Metadata/CSP-reporting,
 * per-request nonce + SRI, and 103 Early-Hints Link headers.
 */
interface SecurityHeaderContributor
{
    public function contribute(ResponseInterface $response): ResponseInterface;
}
