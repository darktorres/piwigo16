<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Piwigo\Auth\AccessControl;
use Psr\Http\Message\ResponseInterface;

/**
 * The `/api/v1` surface's own admin-only gate -- shared by every
 * admin-only endpoint, the same reasoning as CsrfGuard. Real 401 (no
 * session at all) vs 403 (signed in, not an admin), expressed as RFC
 * 9457 problem+json.
 */
final readonly class AdminGuard
{
    public function __construct(
        private AccessControl $accessControl,
    ) {}

    /**
     * Returns null when the current session is an admin; a ready-to-return
     * RFC 9457 401 or 403 problem+json response otherwise.
     */
    public function check(): ?ResponseInterface
    {
        if ($this->accessControl->isAGuest()) {
            return ResponseFactory::problem('Unauthorized', 401, 'A signed-in admin session is required.');
        }

        if (! $this->accessControl->isAdmin()) {
            return ResponseFactory::problem('Forbidden', 403, 'An admin-level session is required.');
        }

        return null;
    }
}
