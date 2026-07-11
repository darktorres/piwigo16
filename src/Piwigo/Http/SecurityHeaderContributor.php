<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Lets later phases add header providers without rewriting
 * SecurityHeadersMiddleware: P28 adds nonce-CSP/COOP/COEP/Trusted-Types/
 * Fetch-Metadata/CSP-reporting, P29 adds per-request nonce + SRI, T3-WEB
 * adds 103 Early-Hints Link headers.
 */
interface SecurityHeaderContributor
{
    public function contribute(ResponseInterface $response): ResponseInterface;
}
