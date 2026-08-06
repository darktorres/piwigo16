<?php

declare(strict_types=1);

namespace Piwigo\Core;

use LogicException;

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
 * a self-managed static facade to a container-shared instance -- every
 * real reader takes it via constructor injection now, including
 * `Piwigo\Ws\PwgUsers`/`Piwigo\Ws\PwgImages` (`$this->currentLogger->get()`)
 * and `Piwigo\Core\UniqueExecLock` (a real `Logger` parameter, NOCTOR).
 * The former `getStatic()` transitional bridge (for
 * `Piwigo\Admin\Upload\UploadService`'s static event handlers, which
 * can't take constructor injection) was closed in sub-phase 12F-1 -- see
 * `UploadService::currentLogger()`'s own private static resolver.
 */
final class CurrentLogger
{
    private ?Logger $instance = null;

    public function get(): Logger
    {
        if (! $this->instance instanceof Logger) {
            throw new LogicException('CurrentLogger not initialised -- call Piwigo\Bootstrap\RequestBootstrap::connect() or Piwigo\Controller\ImageDerivativeController::__invoke() first.');
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
}
