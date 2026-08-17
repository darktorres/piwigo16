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
 * request.
 *
 * activate() currently has no caller. `/api/v1`'s own api_key login path
 * (`SessionLoginController`'s `pkid-...` username branch) creates a real
 * persistent session instead, so it never needs this flag. A stateless
 * per-request-header auth mode for `/api/v1`, if wanted, is unbuilt.
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
