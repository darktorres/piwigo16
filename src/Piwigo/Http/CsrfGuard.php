<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Piwigo\Csrf\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The `/api/v1` surface's own CSRF check -- every real mutating endpoint
 * needs this, so it's shared infrastructure the same way ResponseFactory
 * is, not a one-off. Reads the `X-CSRF-Token` header rather than a
 * `pwg_token` body/query field, so the token never has to be threaded
 * into a REST resource's own JSON body shape.
 */
final readonly class CsrfGuard
{
    public function __construct(
        private CsrfService $csrfService,
    ) {}

    /**
     * Returns null when the token is present and valid; a ready-to-return
     * RFC 9457 403 problem+json response otherwise.
     */
    public function check(ServerRequestInterface $request): ?ResponseInterface
    {
        $submitted = $request->getHeaderLine('X-CSRF-Token');
        if ($submitted === '') {
            return ResponseFactory::problem('Forbidden', 403, 'Missing X-CSRF-Token header.');
        }

        if (! hash_equals($this->csrfService->getToken(), $submitted)) {
            return ResponseFactory::problem('Forbidden', 403, 'Invalid CSRF token.');
        }

        return null;
    }
}
