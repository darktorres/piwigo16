<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Container-shared instance holding the current request's `ConfigService`
 * reference -- singleton/service-locator elimination campaign, Phase 5.
 * Same shape as `Piwigo\Template\CurrentTemplate`/`Piwigo\Users\CurrentUser`:
 * a nullable, replaceable reference to a collaborator that genuinely can be
 * swapped mid-request (or per-test, see below), not a value object whose own
 * fields get mutated in place (that's `Piwigo\Db\DbCredentials`'s shape,
 * wrong fit here since `ConfigService` is an injected collaborator with its
 * own identity, not a bag of scalars).
 *
 * `current()` is a memoized `@deprecated` transitional bridge for callers
 * not yet converted to constructor injection -- same "load once, read/write
 * many times per request" reasoning as `CurrentTemplate`/`CurrentUser`.
 *
 * Deliberately NOT a lazily-resolving `get()` (an earlier draft of this
 * class considered that, to guarantee `ConfigService`/`Connection` never
 * resolve before `Piwigo\Admin\Install\InstallWizard::boot()`'s own
 * `DbCredentials::seed()` call on the install path) -- unnecessary: a plain
 * nullable-reference wrapper already has that property for free, exactly
 * like `CurrentTemplate`/`CurrentUser` today. Constructor-injecting this
 * class touches nothing until `get()` is actually called; only direct
 * `ConfigService` injection carries the premature-resolution risk (see
 * `Piwigo\Bootstrap\InstallBootstrap`'s own docblock for the concrete
 * bug class this avoids).
 *
 * `isSet()` means exactly "has `set()` been called on this instance" --
 * not "has `loadConfFromDb()` run." `Piwigo\Bootstrap\CliBootstrap::run()`
 * deliberately calls `set()` without a following `loadConfFromDb()` (config
 * table may not exist yet on the CLI path), so the two are not the same
 * thing; `Piwigo\Bootstrap\RequestBootstrap::bootConfigOnly()` is the one
 * real consumer of this exact check, reusing an already-`set()` instance
 * instead of re-resolving and reloading.
 *
 * `set()`'s real value beyond "who is the current instance" -- confirmed by
 * reading ~25 Integration tests that call it directly with a manually-built
 * `ConfigService` (`IntegrationTestCase::buildConfigRepository()`'s own,
 * deliberately separate `EntityManager`) -- is letting a whole subtree of
 * not-yet-container-booted or test-scoped callers see a specific instance,
 * the same job `CurrentTemplate::set()` already does for `Template`.
 */
final class CurrentConfigService
{
    private static ?self $fallback = null;

    private ?ConfigService $configService = null;

    public static function current(): self
    {
        if (\Piwigo\Core\Kernel::isBooted()) {
            $instance = \Piwigo\Core\Kernel::container()->get(self::class);
            if (! $instance instanceof self) {
                throw new \LogicException('Container returned an unexpected type for ' . self::class);
            }

            return $instance;
        }

        return self::$fallback ??= new self();
    }

    public function get(): ConfigService
    {
        if (! $this->configService instanceof ConfigService) {
            throw new \LogicException('CurrentConfigService not initialised -- call Piwigo\Bootstrap\RequestBootstrap::connect()/CliBootstrap::run()/InstallBootstrap::activateConfigService() first.');
        }

        return $this->configService;
    }

    /**
     * Lets `RequestBootstrap::bootConfigOnly()` reuse an instance
     * `RequestBootstrap::connect()` already resolved earlier in the same
     * request instead of redoing the DB read, while staying callable
     * standalone (`tests/Unit/Bootstrap/RequestBootstrapBootConfigOnlyTest.php`'s
     * own contract) when nothing has set one yet.
     */
    public function isSet(): bool
    {
        return $this->configService instanceof ConfigService;
    }

    public function set(ConfigService $configService): void
    {
        $this->configService = $configService;
    }

    /**
     * Test-only -- restricted to tests/ by an arch test, mirroring the
     * equivalent guard on CurrentTemplate's/CurrentUser's own reset()
     * methods.
     */
    public function reset(): void
    {
        $this->configService = null;
    }
}
