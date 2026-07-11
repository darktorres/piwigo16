<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Piwigo\Core\Lang;
use Piwigo\Core\Paths;

/**
 * Thin object-oriented facade over Lang/Translator for constructor
 * injection -- t()/l10n() delegate straight to Lang::t(), matching l10n()'s
 * existing free-function contract exactly (both accept a possibly-null key
 * for parity with legacy call sites that pass an unchecked array value).
 *
 * loadLanguageForPlugin() is new: discovers and loads a plugin's own PO
 * file (`<pluginDir>/language/<locale>/plugin.po`). `$locale` is an
 * explicit parameter rather than resolved internally from
 * `Piwigo\Users\CurrentUser` -- `Piwigo\Lang\` is L1Infrastructure, and
 * deptrac's ruleset only lets L1Infrastructure depend on L0Data, not
 * upward on `Users`' L2aCoreDomain (confirmed via a real `deptrac analyse`
 * violation caught while wiring this in, same layer shape as `Kernel`'s
 * own `CurrentUser::attachGlobals()` exclusion). Callers resolve the
 * active locale themselves (typically `CurrentUser::get()->language`) and
 * pass it in.
 *
 * [SEC-26] `$locale` in real deployments is ultimately user/DB-controlled
 * (a registered user's stored language preference); validated against the
 * real, filesystem-verifiable set of installed `language/<locale>/`
 * directories under the core `language/` tree before it's composed into
 * any path, blocking path traversal (`../../etc/passwd`) or reads of
 * arbitrary files outside a plugin's language directory. There is no
 * `Config::availableLanguages()` accessor to validate against (checked:
 * not in the 277-key SCHEMA) -- the filesystem check is both simpler and
 * authoritative (a "locale" with no matching core directory isn't a real,
 * loadable locale regardless of what a DB row claims).
 */
final class LangService
{
    public function __construct(
        private readonly Paths $paths,
    ) {}

    public function t(?string $key, mixed ...$args): string
    {
        return Lang::t($key ?? '', ...$args);
    }

    public function l10n(?string $key, mixed ...$args): string
    {
        return $this->t($key, ...$args);
    }

    public function loadLanguageForPlugin(string $pluginDir, string $locale): bool
    {
        if (! $this->isInstalledLocale($locale)) {
            return false;
        }

        $poFile = rtrim($pluginDir, '/') . '/language/' . $locale . '/plugin.po';
        if (! is_readable($poFile)) {
            return false;
        }

        Translator::get()->load($locale, $poFile);

        return true;
    }

    private function isInstalledLocale(string $locale): bool
    {
        if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale) !== 1) {
            return false;
        }

        return is_dir($this->paths->root . 'language/' . $locale);
    }
}
