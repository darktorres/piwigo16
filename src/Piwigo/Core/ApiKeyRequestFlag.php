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
 * (the only writer) constructor-injects this directly. `Piwigo\Ws\PwgCore`/
 * `Piwigo\Ws\PwgServer` (Phase 10) and `Piwigo\Session\SessionService`
 * (Phase 4) aren't converted yet, so they keep calling the `isActiveStatic()`
 * shim below instead of `isActive()` -- see that method's own docblock.
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

    /**
     * @deprecated transitional bridge for callers not yet converted to
     * constructor injection (Piwigo\Ws\PwgCore::sessionLogin()/
     * sessionLogout(), Piwigo\Ws\PwgServer::isInvokeAllowed(), and
     * Piwigo\Session\SessionService::sessionWrite()) -- PHP forbids an
     * instance method and a static method sharing one name, hence the
     * `Static` suffix (not a rename of the real API -- `isActive()` above
     * is the real one; this is scaffolding only). Delete once
     * `grep -rn "ApiKeyRequestFlag::isActiveStatic("` outside tests/
     * returns nothing.
     */
    public static function isActiveStatic(): bool
    {
        $instance = Kernel::container()->get(self::class);
        if (! $instance instanceof self) {
            throw new \LogicException('Container returned an unexpected type for ' . self::class);
        }

        return $instance->isActive();
    }
}
