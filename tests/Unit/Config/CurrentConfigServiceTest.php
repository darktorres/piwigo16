<?php

declare(strict_types=1);

use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\PluginConfig\EventDispatcher;

/**
 * Piwigo\Config\CurrentConfigService -- container-shared instance
 * (singleton/service-locator elimination campaign, Phase 5); each test
 * constructs its own fresh instance directly, no reset() needed. get()'s
 * own "not initialised" \LogicException guard is the only red line.
 *
 * A throwaway ConfigService never needs Kernel::boot() to construct --
 * EntityManagerFactory::build() only builds objects, it never opens a real
 * connection (same reasoning PiwigoInfosSenderTest.php's own docblock
 * gives for the identical construction).
 */
function current_config_service_test_config_service(): ConfigService
{
    return new ConfigService(EntityManagerFactory::build()->getRepository(ConfigEntry::class), new EventDispatcher(), new CurrentConfig());
}

afterEach(function (): void {
    Kernel::reset();
});

test('get throws when no ConfigService has ever been set', function (): void {
    $currentConfigService = new CurrentConfigService();

    expect($currentConfigService->isSet())->toBeFalse();

    $currentConfigService->get();
})->throws(LogicException::class, 'CurrentConfigService not initialised -- call Piwigo\Bootstrap\RequestBootstrap::connect()/CliBootstrap::run()/InstallBootstrap::activateConfigService() first.');

test('set publishes a ConfigService instance that get returns and isSet reports true', function (): void {
    $currentConfigService = new CurrentConfigService();
    $configService = current_config_service_test_config_service();

    $currentConfigService->set($configService);

    expect($currentConfigService->isSet())->toBeTrue()
        ->and($currentConfigService->get())->toBe($configService);
});

test('reset clears the published instance so get throws again', function (): void {
    $currentConfigService = new CurrentConfigService();
    $currentConfigService->set(current_config_service_test_config_service());
    expect($currentConfigService->isSet())->toBeTrue();

    $currentConfigService->reset();

    expect($currentConfigService->isSet())->toBeFalse();
    $currentConfigService->get();
})->throws(LogicException::class);

test('CurrentConfigServiceTestFactory::get falls back to a memoized instance when Kernel is not booted', function (): void {
    // Memoized (not fresh-per-call), same reasoning as
    // CurrentTemplate::current() (and formerly EventDispatcher::get()/
    // Translator::get(), closed in sub-phases 12F-6/12F-9): a caller that
    // writes via current() in one call and reads via current() in a later
    // call must see the same instance, or the write would be lost.
    $configService = current_config_service_test_config_service();

    $first = CurrentConfigServiceTestFactory::get();
    $first->set($configService);

    $second = CurrentConfigServiceTestFactory::get();

    expect($second)->toBe($first)
        ->and($second->isSet())->toBeTrue();
});

test('CurrentConfigServiceTestFactory::get resolves the container-shared instance once Kernel is booted', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    $instance = Kernel::container()->get(CurrentConfigService::class);

    expect(CurrentConfigServiceTestFactory::get())->toBe($instance);
});
