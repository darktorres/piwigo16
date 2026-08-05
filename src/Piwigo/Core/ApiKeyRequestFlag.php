<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Per-request "this request was authenticated via a WS api_key" signal.
 *
 * Replaces a raw `define('PWG_API_KEY_REQUEST', true)` that lived in
 * Piwigo\Bootstrap\UserBootstrap (a mechanical port of user.inc.php's own
 * define()) -- classes under src/Piwigo/ may not call define() (see
 * tests/Arch/StructuralTest.php's "no define() calls" check, part of the
 * SEC-60/worker-mode static-state audit): a real PHP constant can never be
 * un-defined, so it would leak `true` into every later request sharing the
 * same future FrankenPHP worker process. UserBootstrap::initialize() calls
 * activate() once per request when a WS api_key authenticates; SessionService
 * (session persistence gate) and PwgServer (api_key-forbidden WS methods)
 * read isActive() instead of defined('PWG_API_KEY_REQUEST').
 *
 * Singleton/service-locator elimination campaign, Phase 1: converted from a
 * self-managed static facade to a container-shared instance. `UserBootstrap`
 * (the only writer) constructor-injects this directly, as do
 * `Piwigo\Ws\PwgCore`/`Piwigo\Ws\PwgServer` (Phase 10) now. `Piwigo\Session\
 * SessionService::sessionWrite()` reads this via its own private lazy
 * apiKeyRequestFlag() helper instead (Phase 11 sub-phase 11G: ~27 real
 * construction sites made a required constructor param too high a blast
 * radius for this one internal read) -- the `isActiveStatic()` transitional
 * shim this class used to host is gone now that every real caller converted.
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
