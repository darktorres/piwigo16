<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Static accessor for the current request's `ConfigService` instance --
 * Legacy Coupling Retirement Phase 5. Same shape as this codebase's other
 * per-request singleton facades (`Piwigo\Core\CurrentLogger`,
 * `Piwigo\Template\CurrentTemplate`, `Piwigo\Users\CurrentUser`): a
 * self-managed static instance with `get()`/`set()`, not
 * constructor-injected DI. Exists for classes that are static utilities,
 * constructed throwaway at many sites, or constructed before the DI
 * container exists at at least one real call site -- so a required
 * `ConfigService` constructor param is impossible -- but whose actual
 * config-write call only ever fires post-container. `get()` is read
 * lazily at the call site, not at construction time, so this works even
 * for objects constructed early, as long as the method containing the
 * write isn't itself called (or, worse, isn't the constructor) before
 * `set()` has run.
 *
 * Two construction sites, matching the two independent bootstrap paths
 * that each resolve their own `ConfigService` from the container:
 * `Piwigo\Bootstrap\RequestBootstrap::connect()` (the HTTP request
 * pipeline) and `Piwigo\Bootstrap\CliBootstrap::buildApplication()` (the
 * CLI pipeline, resolve-and-set only -- never followed by
 * `ConfigService::loadConfFromDb()`, which is HTTP-only; see
 * `CliBootstrap`'s own docblock for why).
 */
final class CurrentConfigService
{
    private static ?ConfigService $instance = null;

    public static function get(): ConfigService
    {
        if (! self::$instance instanceof ConfigService) {
            throw new \LogicException('CurrentConfigService not initialised -- call Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint() or Piwigo\Bootstrap\CliBootstrap::buildApplication() first.');
        }

        return self::$instance;
    }

    /**
     * Legacy Coupling Retirement Phase 8, 8d -- lets
     * RequestBootstrap::bootConfigOnly() reuse an instance
     * RequestBootstrap::connect() already resolved earlier in the same
     * request instead of redoing the DB read, while staying callable
     * standalone (tests/Unit/Bootstrap/RequestBootstrapBootConfigOnlyTest.php's own
     * contract) when nothing has set one yet.
     */
    public static function isSet(): bool
    {
        return self::$instance instanceof ConfigService;
    }

    public static function set(ConfigService $configService): void
    {
        self::$instance = $configService;
    }

    /**
     * Test-only, for test-isolation between requests -- mirrors
     * CurrentLogger's/CurrentTemplate's/CurrentUser's own reset()
     * methods.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
