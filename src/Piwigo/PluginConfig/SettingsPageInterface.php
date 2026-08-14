<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

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
 * directly" contract, and is expected to render its own output via the
 * already-booted `ExtensionContext::template()` --
 * `assignContext()`/`assignVarFromTemplate('ADMIN_CONTENT', ...)`, the
 * same real mechanism `Controller\Admin\ConfigurationSubController`
 * itself uses.
 */
interface SettingsPageInterface
{
    public function handleSettingsRequest(ServerRequestInterface $request): void;
}
