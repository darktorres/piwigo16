<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use LogicException;

/**
 * Container-shared instance holding the current request's `PluginRegistry`
 * reference -- same shape/purpose as `Config\CurrentConfigService`:
 * `Http\Middleware\PluginBootstrapMiddleware` builds `PluginRegistry` itself
 * manually, threading the request's own shared `Connection` through it
 * (see that class's own private `pluginRegistry()` method for why), so a
 * later container-autowired reader -- `Controller\Admin\
 * PluginSubController`, resolved fresh per request via the DI
 * container, not through that same manual `$conn`-scoped path -- would
 * otherwise get a *different*, never-`bootActive()`d `PluginRegistry`
 * instance whose `getBootedInstance()` cache is always empty. `set()`
 * here, called right after `bootActive()` runs, is what makes the one
 * real, already-booted instance reachable from that reader instead
 * (`Admin\LoadedPluginsMiddleware`, immediately after `PluginBootstrap
 * Middleware` in the real pipeline, is the other real reader).
 */
final class CurrentPluginRegistry
{
    private ?PluginRegistry $registry = null;

    public function get(): PluginRegistry
    {
        if (! $this->registry instanceof PluginRegistry) {
            throw new LogicException('CurrentPluginRegistry not initialised -- call Piwigo\Http\Middleware\PluginBootstrapMiddleware::process() first.');
        }

        return $this->registry;
    }

    public function set(PluginRegistry $registry): void
    {
        $this->registry = $registry;
    }
}
