<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * `Piwigo\Core\Lang::load()` needs the DB-configured default language
 * (`Piwigo\Users\UserService::getDefaultLanguage()`, L2aCoreDomain) but is
 * a static method called from dozens of non-DI-managed legacy files —
 * `deptrac.yaml`'s ruleset forbids L1Infrastructure depending upward on
 * L2aCoreDomain regardless of whether the dependency reaches the class via
 * the container or an inline `new`. `UserService` implements this;
 * `Piwigo\Bootstrap\RequestBootstrap::finalize()` constructs one
 * and calls `Lang::setDefaultLanguageProvider()` once, and every later
 * `Lang::load()` call in the request reuses it instead of reconstructing
 * `UserService` on every call.
 *
 * `Lang::load()` also needs the *current* (logged-in or guest) user's
 * language preference, obtained via `Piwigo\Users\CurrentUser` (also
 * L2aCoreDomain, same deptrac barrier). `getCurrentLanguage()` reuses
 * this same provider/setter plumbing rather than standing up a second
 * interface+setter pair for what is, from `Lang`'s perspective, the same
 * "ask something in the Users domain about a language" responsibility.
 */
interface DefaultLanguageProviderInterface
{
    public function getDefaultLanguage(): string;

    /**
     * Null when no current-user language preference is available (e.g. no
     * provider has been set yet).
     */
    public function getCurrentLanguage(): ?string;
}
