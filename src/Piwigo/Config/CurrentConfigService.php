<?php

declare(strict_types=1);

namespace Piwigo\Config;

use LogicException;

/**
 * Container-shared instance holding the current request's `ConfigService`
 * reference. Same shape as `Piwigo\Template\CurrentTemplate`/
 * `Piwigo\Users\CurrentUser`: a nullable, replaceable reference to a
 * collaborator that genuinely can be swapped mid-request (or per-test, see
 * below), not a value object whose own fields get mutated in place (that's
 * `Piwigo\Db\DbCredentials`'s shape, wrong fit here since `ConfigService`
 * is an injected collaborator with its own identity, not a bag of
 * scalars). Every real caller takes this via constructor injection.
 *
 * This class does not eagerly resolve `ConfigService`; nothing happens
 * until `get()` is called. Constructor-injecting `CurrentConfigService` is
 * therefore safe wherever `ConfigService`/`Connection` must not resolve
 * before `Piwigo\Admin\Install\InstallWizard::boot()`'s own
 * `DbCredentials::seed()` call on the install path -- injecting
 * `ConfigService` directly there would carry that premature-resolution
 * risk (see `Piwigo\Bootstrap\InstallBootstrap`'s own docblock for the
 * concrete bug class this avoids).
 *
 * `isSet()` means exactly "has `set()` been called on this instance" --
 * not "has `loadConfFromDb()` run." `Piwigo\Bootstrap\CliBootstrap::run()`
 * deliberately calls `set()` without a following `loadConfFromDb()` (config
 * table may not exist yet on the CLI path), so the two are not the same
 * thing; `Piwigo\Bootstrap\RequestBootstrap::bootConfigOnly()` is the one
 * real consumer of this exact check, reusing an already-`set()` instance
 * instead of re-resolving and reloading.
 *
 * `set()`'s real value beyond "who is the current instance" is letting
 * a whole subtree of not-yet-container-booted or test-scoped callers
 * see a specific instance, the same job `CurrentTemplate::set()`
 * already does for `Template`. ~25 Integration tests rely on this,
 * calling it directly with a manually-built `ConfigService`
 * (`IntegrationTestCase::buildConfigRepository()`'s own, deliberately
 * separate `EntityManager`).
 */
final class CurrentConfigService
{
    private ?ConfigService $configService = null;

    public function get(): ConfigService
    {
        if (! $this->configService instanceof ConfigService) {
            throw new LogicException('CurrentConfigService not initialised -- call Piwigo\Bootstrap\RequestBootstrap::connect()/CliBootstrap::run()/InstallBootstrap::activateConfigService() first.');
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
