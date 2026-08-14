<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use LogicException;

/**
 * Container-shared instance holding the current request's `PluginRegistry`
 * reference -- same shape/purpose as `Config\CurrentConfigService`:
 * `Bootstrap\RequestBootstrap::connect()` builds `PluginRegistry` itself
 * manually, threading the request's own shared `Connection` through it
 * (see `RequestBootstrap::pluginRegistry()`'s own docblock for why), so a
 * later container-autowired reader -- `Controller\Admin\
 * PluginSubController` (P27.15), resolved fresh per request via the DI
 * container, not through that same manual `$conn`-scoped path -- would
 * otherwise get a *different*, never-`bootActive()`d `PluginRegistry`
 * instance whose `getBootedInstance()` cache is always empty. `set()`
 * here, called right after `bootActive()` runs, is what makes the one
 * real, already-booted instance reachable from that reader instead.
 */
final class CurrentPluginRegistry
{
    private ?PluginRegistry $registry = null;

    public function get(): PluginRegistry
    {
        if (! $this->registry instanceof PluginRegistry) {
            throw new LogicException('CurrentPluginRegistry not initialised -- call Piwigo\Bootstrap\RequestBootstrap::connect() first.');
        }

        return $this->registry;
    }

    public function set(PluginRegistry $registry): void
    {
        $this->registry = $registry;
    }
}
