<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: `Piwigo\Core\Lang::load()` (ported from the legacy
 * `load_language()`) needs the DB-configured default language
 * (`Piwigo\Users\UserService::getDefaultLanguage()`, L2aCoreDomain) but is
 * a static method called from dozens of non-DI-managed legacy files —
 * `deptrac.yaml`'s ruleset forbids L1Infrastructure depending upward on
 * L2aCoreDomain regardless of whether the dependency reaches the class via
 * the container or an inline `new`. `UserService` implements this;
 * `include/common.inc.php` (legacy, not subject to deptrac) constructs one
 * and calls `Lang::setDefaultLanguageProvider()` once, at the same point
 * its own former `load_language()` calls already ran — every later
 * `Lang::load()` call in the request reuses it instead of reconstructing
 * `UserService` on every call the way the original free function did.
 */
interface DefaultLanguageProviderInterface
{
    public function getDefaultLanguage(): string;
}
