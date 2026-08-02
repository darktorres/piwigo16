<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Holds the current request's `Logger` instance -- Legacy Coupling
 * Retirement Track A gap-fill batch G5, replacing the legacy
 * `global $logger;` bridge.
 *
 * Two writers, matching the two independent bootstrap paths that each
 * build their own Logger: `Piwigo\Bootstrap\RequestBootstrap::connect()`
 * (the normal request pipeline) and `Piwigo\Admin\Install\InstallWizard::
 * boot()` (the installer's own no-RequestBootstrap path, which needs a
 * Logger before render() runs).
 *
 * Singleton/service-locator elimination campaign, Phase 2: converted from
 * a self-managed static facade to a container-shared instance. Most real
 * readers take it via constructor injection; `Piwigo\Core\UniqueExecLock`
 * (genuinely static-only) and `Piwigo\Ws\PwgUsers`/`Piwigo\Ws\PwgImages`
 * (Phase 10's still-static dispatch layer) keep calling the
 * `getStatic()` shim below instead.
 */
final class CurrentLogger
{
    private ?Logger $instance = null;

    public function get(): Logger
    {
        if (! $this->instance instanceof Logger) {
            throw new \LogicException('CurrentLogger not initialised -- call Piwigo\Bootstrap\RequestBootstrap::connect() or Piwigo\Controller\ImageDerivativeController::__invoke() first.');
        }

        return $this->instance;
    }

    public function set(Logger $logger): void
    {
        $this->instance = $logger;
    }

    public function isInitialized(): bool
    {
        return $this->instance instanceof Logger;
    }

    public function reset(): void
    {
        $this->instance = null;
    }

    /**
     * @deprecated transitional bridge for callers not yet converted to
     * constructor injection (`Piwigo\Core\UniqueExecLock` -- genuinely
     * static-only; `Piwigo\Ws\PwgUsers`/`Piwigo\Ws\PwgImages` -- Phase 10's
     * still-static dispatch layer) -- PHP forbids an instance method and a
     * static method sharing one name, hence the `Static` suffix. Delete
     * once `grep -rn "CurrentLogger::getStatic("` outside tests/ returns
     * nothing.
     *
     * Falls back to a real, harmless no-op Logger (`severity => OFF` makes
     * every log call an immediate no-op, same as `Logger::OFF`'s own
     * documented behavior and `IntegrationTestCase`'s own established
     * "disabled logger" fixture convention) when `Kernel::boot()` hasn't
     * run -- unlike a boolean flag's `false` default, there's no
     * "uninitialized Logger" sentinel value, but a no-op Logger is exactly
     * as safe: no test relying on this fallback ever wrote through the old
     * static `CurrentLogger::get()` either (a real request always has a
     * booted Kernel with a real seeded Logger by the time these callers
     * run).
     */
    public static function getStatic(): Logger
    {
        if (! Kernel::isBooted()) {
            return new Logger([
                'severity' => Logger::OFF,
            ]);
        }

        $instance = Kernel::container()->get(self::class);
        if (! $instance instanceof self) {
            throw new \LogicException('Container returned an unexpected type for ' . self::class);
        }

        return $instance->get();
    }
}
