<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Symfony\Component\Routing\RouteCollection;

/**
 * Implemented by a plugin's `main` class alongside `ExtensionInterface`
 * when its manifest declares `hasApiRoutes: true` -- kept separate from
 * `ExtensionInterface` itself (interface segregation: most plugins have
 * no `/api/v1` surface of their own). `PluginRegistry::install()`/
 * `activate()` validate this contract at manifest-declaration time: a
 * `hasApiRoutes` manifest whose class doesn't implement this interface
 * throws `PluginValidationException` there, not confusingly deep inside
 * routing/controller resolution.
 *
 * This is the real replacement for the deleted legacy `ws_add_methods`
 * hook (`Piwigo\Ws\*`, including the `Server::addMethod()` a plugin used
 * to call, was removed outright by P27) -- see
 * `docs/events-legacy-map.md`'s own "removed outright" entry. Unlike
 * every other legacy-hook-to-typed-event mapping, this one is not a
 * mechanical 1:1 translation: the old `addMethod('plugin.customMethod',
 * ...)` method-name shape has no exact URL-path equivalent, so a porter
 * must pick a real REST verb + path.
 *
 * `registerApiRoutes()` is called once per request, before routing, by
 * `PluginRegistry::registerApiRoutes()` (itself called from
 * `config/container.php`'s `Router::class` factory) -- add real
 * `Symfony\Component\Routing\Route` entries to the given, live
 * `$routes` collection directly (mutate in place; the return value,
 * if any, is never read). Every route this method registers MUST live
 * under URL prefix `/api/v1/plugin-routes/{pluginId}/...` and route
 * name prefix `api_v1_plugin_routes_{pluginId}_...` (deliberately not
 * `/api/v1/ext/...` -- too close to the existing, differently-purposed
 * `/api/v1/extensions/*`/`/api/v1/plugins/*` core prefixes) -- this
 * reserved namespace is what keeps a plugin's own routes from ever
 * colliding with a core route or another plugin's routes, as long as
 * each plugin prefixes with its own manifest `id`.
 *
 * A route's own `_controller` resolves via plain PHP-DI autowiring
 * (`Http\Middleware\ControllerInvokerMiddleware`), exactly like a core
 * controller -- it constructor-injects `Auth\AccessControl`/
 * `Http\AdminGuard`/`Csrf\CsrfService` directly to self-enforce its own
 * access level, never `ExtensionContext` (which needs a specific
 * `PluginId`/`ThemeId` only `PluginRegistry::bootActive()`'s own
 * `ExtensionContextFactory::build()` call can produce -- plain
 * container autowiring has no way to construct one).
 */
interface ApiRouteProviderInterface
{
    public function registerApiRoutes(RouteCollection $routes): void;
}
