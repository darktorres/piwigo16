<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

/**
 * Implemented by a plugin's `main` class alongside `ExtensionInterface`
 * when its manifest declares `hasAdminPages: true` -- kept separate from
 * `ExtensionInterface` itself (interface segregation, same reasoning as
 * `SettingsPageInterface`/`ApiRouteProviderInterface`). `PluginRegistry::
 * install()`/`activate()` validate this contract at manifest-declaration
 * time, same shape as those two siblings.
 *
 * `registerAdminPages()` is called once per request (from
 * `PluginRegistry::adminPages()`, itself read by `Bootstrap\
 * AdminDispatcher::pageMap()`) and returns this one plugin's own
 * `admin.php` `?page=` slug -> handler class-string map, merged onto the
 * static `config/admin_pages.php` map. A returned class-string is
 * expected to be a real `Controller\Admin\AdminSubControllerInterface`
 * implementor, resolvable via plain PHP-DI autowiring exactly like a
 * core admin page -- `AdminDispatcher::dispatch()`'s own existing
 * `instanceof` check (run for every resolved page, core or
 * plugin-contributed alike) is what actually enforces that contract, not
 * this interface itself: `Piwigo\PluginConfig` sits below
 * `Piwigo\Controller` in this project's layered architecture
 * (`deptrac.yaml`), so it may not reference that interface directly.
 *
 * A plugin-contributed admin page's own handler class does not need
 * `ExtensionContext` -- admin.php already gates every page behind
 * `check_status(AccessLevel::Administrator)` before dispatch
 * (`Admin\AdminShell::runDispatch()`), for every page slug, core or
 * plugin-contributed, so the handler gets that access check for free and
 * self-enforces nothing extra.
 */
interface AdminPageProviderInterface
{
    /**
     * @return array<string, class-string>
     */
    public function registerAdminPages(): array;
}
