<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Per-request "this request was authenticated via a WS api_key" signal.
 *
 * Classes under src/Piwigo/ may not call define() (see
 * tests/Arch/StructuralTest.php's "no define() calls" check, part of the
 * SEC-60/worker-mode static-state audit): a real PHP constant can never be
 * un-defined, so it would leak `true` into every later request sharing the
 * same FrankenPHP worker process. This class exists for that reason.
 * UserBootstrap::initialize() calls activate() once per request when a WS
 * api_key authenticates; SessionService (session persistence gate) and
 * Server (api_key-forbidden WS methods) read isActive().
 *
 * `UserBootstrap` (the only writer) constructor-injects this directly, as do
 * `Piwigo\Ws\Session\LoginHandler`/`LogoutHandler`/`Piwigo\Ws\Server`.
 * `Piwigo\Session\SessionService::sessionWrite()` reads this via its own
 * private lazy apiKeyRequestFlag() helper instead: ~27 real construction
 * sites make a required constructor param too high a blast radius for
 * this one internal read.
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
