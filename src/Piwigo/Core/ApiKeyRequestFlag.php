<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Per-request "this request was authenticated via a stateless api_key
 * header, not a session" signal.
 *
 * Classes under src/Piwigo/ may not call define() (see
 * tests/Arch/StructuralTest.php's "no define() calls" check, part of the
 * SEC-60/worker-mode static-state audit): a real PHP constant can never be
 * un-defined, so it would leak `true` into every later request sharing the
 * same FrankenPHP worker process. This class exists for that reason.
 * `Piwigo\Session\SessionService::sessionWrite()` reads this via its own
 * private lazy apiKeyRequestFlag() helper (session persistence gate);
 * `Controller\Api\SessionLoginController`/`SessionLogoutController` read
 * isActive() to reject login/logout on an already-api_key-authenticated
 * request, matching WS's own `apiKeyForbiddenMethods` restriction.
 *
 * activate() currently has no caller: the old WS-only mechanism that used
 * to set it (`UserBootstrap`'s `HTTP_X_PIWIGO_API` stateless-header
 * branch, gated on the legacy `ws.php?method=...` convention) was deleted
 * along with the WS layer itself (P27) rather than ported, since it keyed
 * off a request shape (`$_REQUEST['method']`) `/api/v1` never sends and was
 * consequently already unreachable for REST callers before this deletion.
 * `/api/v1`'s own api_key login path (`SessionLoginController`'s `pkid-...`
 * username branch) creates a real persistent session instead, so it never
 * needs this flag either. A stateless per-request-header auth mode for
 * `/api/v1`, if wanted, is unbuilt new REST work, not a WS port.
 */
final class ApiKeyRequestFlag
{
    private bool $active = false;

    public function activate(): void
    {
        $this->active = true;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
