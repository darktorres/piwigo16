<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ServerTiming;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Users\CurrentUser;

beforeEach(function (): void {
    Kernel::reset();
    ServerTiming::reset();
    CurrentConfig::reset();
    CurrentUser::reset();
    // Legacy Coupling Retirement Phase 8, 8d: bootConfigOnly() now reuses
    // an already-set CurrentConfigService instead of always resolving+
    // loading its own -- without this reset, a set() left over from an
    // earlier test would make these tests skip that resolve-and-load path
    // entirely and silently pass/fail on stale state.
    CurrentConfigService::reset();
});

afterEach(function (): void {
    Kernel::reset();
    ServerTiming::reset();
    CurrentConfig::reset();
    CurrentUser::reset();
    CurrentConfigService::reset();
});

test('bootConfigOnly boots the Kernel', function (): void {
    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));
    expect(Kernel::isBooted())->toBeTrue();
});

test('bootConfigOnly records a boot timing', function (): void {
    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));

    expect(ServerTiming::all())->toHaveKey('boot');
    expect(ServerTiming::all()['boot'])->toBeGreaterThanOrEqual(0.0);
});

test('bootConfigOnly seeds CurrentConfig from its own property defaults (P13)', function (): void {
    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));

    // Same DB-state-independent claim the original assertion made
    // (CurrentConfig::has('gallery_title')->toBeTrue(), back when P13 first
    // wrote this test, before P23 batch 1 added the loadConfFromDb() call
    // this same method now also makes) -- not the literal default value,
    // since a real config-table row (this test's own DB connection, shared
    // with whatever fixture state other suites left behind) can legitimately
    // override it.
    expect(CurrentConfig::galleryTitle())->not->toBe('');
});

test('bootConfigOnly merges DB-persisted config overrides into CurrentConfig (P23 batch 1)', function (): void {
    // tests/Fixtures/piwigo-17.0.sql overrides gallery_title away from its
    // own property default ('Piwigo') to prove loadConfFromDb() actually
    // ran, not just that the property exists.
    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));

    expect(CurrentConfig::galleryTitle())->toBe('Fixture Gallery');
});

test('bootConfigOnly attaches a guest CurrentUser', function (): void {
    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));

    expect(CurrentUser::isInitialized())->toBeTrue();
});

test('bootConfigOnly reuses an already-set CurrentConfigService instead of resolving a new one', function (): void {
    $conn = \Piwigo\Db\DbConnection::build();
    $ormConfig = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Piwigo'], isDevMode: true);
    $ormConfig->enableNativeLazyObjects(true);
    $em = new \Doctrine\ORM\EntityManager($conn, $ormConfig);
    $em->getEventManager()->addEventListener(\Doctrine\ORM\Events::loadClassMetadata, new \Piwigo\Db\TablePrefixListener());
    $preSetService = new \Piwigo\Config\ConfigService($em->getRepository(\Piwigo\Config\ConfigEntry::class));
    CurrentConfigService::set($preSetService);

    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));

    // Same instance as the one set beforehand -- proves the isSet() branch
    // really did reuse it instead of resolving (and loadConfFromDb()-ing) a
    // fresh one from the container.
    expect(CurrentConfigService::get())->toBe($preSetService);
});

test('CurrentConfigService::get throws when nothing has ever been set', function (): void {
    expect(CurrentConfigService::isSet())->toBeFalse();

    CurrentConfigService::get();
})->throws(LogicException::class, 'CurrentConfigService not initialised -- call Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint() or Piwigo\Bootstrap\CliBootstrap::buildApplication() first.');

test('bootConfigOnly throws when the container returns an unexpected type for ConfigService', function (): void {
    // CurrentConfigService::isSet() is false (reset in beforeEach above), so
    // bootConfigOnly() takes the "resolve from the container" branch instead
    // of reusing an already-set instance -- the real container always
    // autowires a genuine ConfigService for this class-string, so this
    // \LogicException guard is otherwise unreachable through the public
    // API. KernelContainerOverride rebinds just this one class to a plain
    // stdClass (see its own docblock), matching the identical pattern
    // tests/Integration/InstallBootstrapTest.php's own
    // test_activateConfigService_throws_when_the_container_returns_an_unexpected_type
    // uses for InstallBootstrap's sibling guard.
    KernelContainerOverride::withWrongTypeFor(
        ConfigService::class,
        static fn () => RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()))
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ConfigService::class);
