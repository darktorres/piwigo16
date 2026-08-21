<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Symfony\Component\Routing\RouteCollection;

/**
 * Implemented by a plugin's `main` class alongside `ExtensionInterface`
 * when its manifest declares `hasPageRoutes: true` -- kept separate from
 * `ApiRouteProviderInterface` (interface segregation, same reasoning as
 * that interface's own docblock): unlike an `/api/v1/plugin-routes/*`
 * JSON endpoint, this is for a real, public-facing page a plugin owns
 * outright -- the real-world shape legacy plugins like `tag_groups`/
 * `piwigo_masonry_grid`/`PWG_Stuffs` need (their own root-level clean-URL
 * entry point, e.g. `/tag_groups.php`), which
 * `Bootstrap\RouteDefinitions::all()`'s static, compiled-in
 * `RouteCollection` has no way to accommodate.
 *
 * `registerPageRoutes()` is called once per request, from the same
 * `Http\Middleware\RoutingMiddleware::process()` call site as
 * `ApiRouteProviderInterface::registerApiRoutes()` (via
 * `PluginRegistry::registerPageRoutes()`) -- add real
 * `Symfony\Component\Routing\Route` entries to the given, live `$routes`
 * collection directly (mutate in place; the return value, if any, is
 * never read).
 *
 * Deliberately no reserved URL-prefix/route-name namespace here (unlike
 * `ApiRouteProviderInterface`'s mandatory `/api/v1/plugin-routes/{id}/`
 * prefix): a real clean-URL page route needs to look like an ordinary
 * root-level entry point, not a namespaced sub-path. This stays safe
 * without one because `Router::dispatch()`'s underlying `UrlMatcher`
 * tries routes in registration order and `RoutingMiddleware::process()`
 * always appends plugin routes after `Bootstrap\RouteDefinitions::all()`'s
 * own core routes -- a plugin can add a route, but can never shadow an
 * existing core path, only add a genuinely new one. A route name
 * collision between two plugins (or a plugin and core) is still a real,
 * if narrower, possibility -- `Symfony\Component\Routing\RouteCollection::add()`
 * silently overwrites an existing entry with the same *name* (not path),
 * so a plugin author still needs to pick a name unlikely to collide,
 * same as any other manifest-declared identifier in this system.
 *
 * A route's own `_controller` resolves via plain PHP-DI autowiring
 * (`Http\Middleware\ControllerInvokerMiddleware`), exactly like a core
 * controller -- it constructor-injects `Auth\AccessControl`/
 * `Http\AdminGuard`/`Csrf\CsrfService` directly to self-enforce its own
 * access level if it needs one, same self-enforcement burden
 * `ApiRouteProviderInterface`'s own docblock already documents (a page
 * route bypasses `Admin\AdminShell`'s shared
 * `check_status(AccessLevel::Administrator)` gate entirely -- that gate
 * only runs for `admin.php` `?page=` slugs, never for a route dispatched
 * through `Routing\Router`).
 */
interface PageRouteProviderInterface
{
    public function registerPageRoutes(RouteCollection $routes): void;
}
