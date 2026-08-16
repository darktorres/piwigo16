<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Piwigo\Core\SubscriberInterface;

/**
 * Contract implemented by a plugin's or theme's `main` class (the
 * fully-qualified class name declared in its own `plugin.json`/
 * `theme.json` manifest). One shared interface for both -- unlike the
 * reference fork's `PluginInterface`/`ThemeInterface` split, which turned
 * out to be dead weight: every `getId()`/`getVersion()`/`getName()`/
 * `getParentId()`/etc. accessor it put on the interface has zero real
 * callers there, since `PluginManifest`/`ThemeManifest` already carry
 * that data. Theme-only facts (`parent`, `loadParentCss`, `assets`, ...)
 * live on `ThemeManifest` here for the same reason, never on this
 * interface.
 *
 * `boot(ExtensionContext $context)` is called once per request for every
 * active plugin (`PluginRegistry::bootActive()`) and for the current
 * theme's own parent-resolution chain (`ThemeRegistry::bootCurrent()`) --
 * see both classes' own docblocks for the exact two-pass ordering this
 * requires. `install()`/`activate()`/`deactivate()`/`uninstall()`/
 * `update()` are lifecycle hooks called only from the admin UI
 * (`Admin\Extensions\ExtensionLifecycle`), never from a real request's
 * boot path.
 *
 * `subscribedEvents()` (P32 Stage A6: inherited from `Core\
 * SubscriberInterface`, not redeclared here -- see that interface's own
 * docblock for the shared shape/rationale) must declare the full,
 * unconditional union of every event this extension might ever care
 * about -- unlike `Listener\*`'s own `SubscriberInterface` implementors,
 * this runs on a bare `new $class()` instance
 * (`PluginRegistry`/`ThemeRegistry`'s own `bootInstance()`), before
 * `boot()` ever assigns anything to `$this`, so there is no
 * constructor-injected state to condition on here. Runtime decisions
 * (e.g. "only register this handler when
 * `ExtensionContext::isAdminContext()` is true") belong inside the
 * handler method itself instead.
 */
interface ExtensionInterface extends SubscriberInterface
{
    public function boot(ExtensionContext $context): void;

    public function install(): void;

    public function activate(): void;

    public function deactivate(): void;

    public function uninstall(): void;

    public function update(string $oldVersion, string $newVersion): void;
}
