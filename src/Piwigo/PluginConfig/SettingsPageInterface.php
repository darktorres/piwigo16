<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Piwigo\Core\View;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Implemented by a plugin's/theme's `main` class alongside
 * `ExtensionInterface` when its manifest declares `hasSettings` (`true`
 * or `'webmaster'`) -- kept separate from `ExtensionInterface` itself
 * (interface segregation: most plugins/themes have no settings page at
 * all). `PluginRegistry::install()`/`activate()` and `ThemeRegistry`'s
 * equivalents validate this contract at manifest-declaration time: a
 * `hasSettings` manifest whose class doesn't implement this interface
 * throws `PluginValidationException`/`ThemeValidationException` there,
 * not confusingly deep inside `Controller\Admin\PluginSubController`/
 * `ThemeSubController`.
 *
 * `handleSettingsRequest()` reads input from the given PSR-7 request
 * (`getParsedBody()`/`getQueryParams()`), matching
 * `AdminSubControllerInterface`'s own SEC-19 "never $_GET/$_POST
 * directly" contract, and returns the `View` to render as this page's
 * own admin content -- its 2 real callers (`Controller\Admin\
 * PluginSubController`/`ThemeSubController`) render it via
 * `ExtensionContext::render()`/`Renderer::render()` and assign it into
 * `AdminContentPageContext` themselves, the same shape every other
 * admin sub-controller already uses (P43-D, docs/PLAN.md).
 */
interface SettingsPageInterface
{
    public function handleSettingsRequest(ServerRequestInterface $request): View;
}
