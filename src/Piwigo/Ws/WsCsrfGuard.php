<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Csrf\CsrfService;

/**
 * The WS layer's own CSRF check, split out of the former WsHelper
 * god-class (P25 Stage 1 step 6). Called from 41 handlers.
 */
final readonly class WsCsrfGuard
{
    public function __construct(
        private CsrfService $csrfService,
    ) {}

    /**
     * Checks a WS method's submitted `pwg_token` against the current
     * session's real CSRF token. `$required` mirrors how the calling
     * Handler registered `pwg_token`: `true` (default) for a
     * mandatory param -- a missing/empty/mismatched token is always
     * rejected; `false` for a genuinely optional one -- a `null`
     * $submittedToken (no token submitted at all) is allowed through,
     * only a present-but-wrong token is rejected.
     *
     * `$message` defaults to the plain, untranslated string every real
     * call site but 4 (Comments\DeleteHandler/ValidateHandler,
     * Users\EditApiKeyHandler/RevokeApiKeyHandler) already used -- those
     * 4 pass their own `$this->lang->t('Invalid security token')`
     * instead, a pre-existing inconsistency preserved as-is here, not
     * silently dropped or extended to every other call site.
     */
    public function checkSecurityToken(?string $submittedToken, bool $required = true, ?string $message = null): ?WsErrorResponse
    {
        $message ??= 'Invalid security token';

        if ($submittedToken === null) {
            return $required ? new WsErrorResponse(403, $message) : null;
        }

        return hash_equals($this->csrfService->getToken(), $submittedToken)
            ? null
            : new WsErrorResponse(403, $message);
    }
}
