<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Piwigo\Db\TablePrefixListener;
use Piwigo\Tests\Support\DbCredentialsTestFactory;
use Piwigo\Config\ConfigEntry;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\ServerTiming;
use Piwigo\Lang\Translator;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Users\CurrentUser;
use Sentry\SentrySdk;

/**
 * A mutation-testing sweep found 2 confirmed-equivalent RemoveMethodCall
 * mutants in bootConfigOnly() itself, neither worth a dedicated test:
 * - `ConfigLoader::applyDefaults()`/`applyEnvOverrides()` are genuine
 *   no-op method bodies today (`public static function applyDefaults():
 *   void {}` -- confirmed via direct source read, same finding already
 *   documented in Piwigo\Bootstrap\CliBootstrap's own source comment).
 * - `PageState::attachGlobals()` is `self::$instance ??= new self();` --
 *   byte-identical to what `PageState::current()` already does on its
 *   own on first real access, so removing the eager call here changes
 *   nothing any consumer can observe (unlike Lang::attachGlobals() just
 *   below, which does a real, non-idempotent snapshot copy).
 */
beforeEach(function (): void {
    Kernel::reset();
    CurrentConfig::current()->reset();
    CurrentUser::current()->reset();
    // Legacy Coupling Retirement Phase 8, 8d: bootConfigOnly() now reuses
    // an already-set CurrentConfigService instead of always resolving+
    // loading its own -- without this reset, a set() left over from an
    // earlier test would make these tests skip that resolve-and-load path
    // entirely and silently pass/fail on stale state.
    CurrentConfigServiceTestFactory::get()->reset();
    // No Lang::current()->reset() here (unlike afterEach below): Lang, since
    // the singleton/service-locator elimination campaign's Phase 8, has no
    // memoized pre-boot fallback the way CurrentUser::current()/
    // CurrentConfigServiceTestFactory::get() do -- see its own docblock -- and
    // Kernel::reset() just above already tore down whatever container (and
    // whatever Lang instance lived in it) the previous test left behind.
    // There's nothing left to reset here; a fresh Kernel::boot() a few lines
    // into each test body below hands out a brand new, already-empty
    // instance from a brand new container.
    TranslatorTestFactory::get()->reset();
    SentrySdk::init();
    putenv('SENTRY_DSN');
});

afterEach(function (): void {
    CurrentConfig::current()->reset();
    CurrentUser::current()->reset();
    CurrentConfigServiceTestFactory::get()->reset();
    // Lang::current() has no memoized pre-boot fallback (see beforeEach's
    // own comment above), so it must resolve -- and get reset -- while the
    // container is still up, before Kernel::reset() below tears it down;
    // same ordering ExtensionScannerTest.php's own afterEach() already
    // established. Not every test in this file boots the Kernel at all
    // ("CurrentConfigService::get throws when nothing has ever been set"
    // never does, and KernelContainerOverride::with() -- used by the
    // wrong-type test -- already tears its own override container back down
    // in its own finally block before this hook ever runs), hence the guard.
    if (Kernel::isBooted()) {
        Lang::current()->reset();
    }
    Kernel::reset();
    TranslatorTestFactory::get()->reset();
    SentrySdk::init();
    putenv('SENTRY_DSN');
});

test('bootConfigOnly boots the Kernel', function (): void {
    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));
    expect(Kernel::isBooted())->toBeTrue();
});

test('bootConfigOnly records a boot timing', function (): void {
    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));

    $timing = Kernel::container()->get(ServerTiming::class);
    if (! $timing instanceof ServerTiming) {
        throw new LogicException('Container returned an unexpected type for ' . ServerTiming::class);
    }

    expect($timing->all())->toHaveKey('boot');
    expect($timing->all()['boot'])->toBeGreaterThanOrEqual(0.0);
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
    expect(CurrentConfig::current()->galleryTitle())->not->toBe('');
});

test('bootConfigOnly merges DB-persisted config overrides into CurrentConfig (P23 batch 1)', function (): void {
    // tests/Fixtures/piwigo-17.0.sql overrides gallery_title away from its
    // own property default ('Piwigo') to prove loadConfFromDb() actually
    // ran, not just that the property exists.
    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));

    expect(CurrentConfig::current()->galleryTitle())->toBe('Fixture Gallery');
});

test('bootConfigOnly attaches a guest CurrentUser', function (): void {
    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));

    expect(CurrentUser::current()->isInitialized())->toBeTrue();
});

test('bootConfigOnly initializes Sentry when SENTRY_DSN is set', function (): void {
    putenv('SENTRY_DSN=https://fake@fake.ingest.sentry.io/1');

    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));

    expect(SentrySdk::getCurrentHub()->getClient())->not->toBeNull();

    // Same reasoning as SentryBootstrapTest's own "binds a client" test --
    // a real DSN registers real global PHP error/exception handlers.
    restore_error_handler();
    restore_exception_handler();
});

test('bootConfigOnly sets CurrentConfigService when resolving a fresh instance from the container', function (): void {
    // The "seeds CurrentConfig..." and "merges DB-persisted..." tests
    // above both call bootConfigOnly() fresh (CurrentConfigService reset
    // in beforeEach) too, but only ever assert on CurrentConfig's own
    // static state, populated by $configService->loadConfFromDb() acting
    // directly on the local variable -- neither can tell whether
    // CurrentConfigServiceTestFactory::get()->set($configService) itself actually ran.
    expect(CurrentConfigServiceTestFactory::get()->isSet())->toBeFalse();

    RequestBootstrap::bootConfigOnly(Paths::fromRoot(sys_get_temp_dir()));

    expect(fn () => CurrentConfigServiceTestFactory::get()->get())->not->toThrow(LogicException::class);
    expect(CurrentConfigServiceTestFactory::get()->get())->toBeInstanceOf(ConfigService::class);
});

test('bootConfigOnly attaches Lang globals from whatever the Translator has loaded', function (): void {
    // Lang::attachGlobals() snapshots TranslatorTestFactory::get()->mirroredStrings()
    // into Lang's own static $data -- a real, non-idempotent copy (unlike
    // PageState::attachGlobals()'s own `??=` lazy-init, which current()
    // already performs identically on its own, making that one call
    // genuinely unobservable and not chased here). loadArray() seeds a
    // known string directly, matching Translator's own documented
    // test-helper shape.
    //
    // Kernel is booted here, BEFORE bootConfigOnly(), specifically so the
    // Translator instance seeded below is the SAME one bootConfigOnly()'s
    // own internal Kernel::boot() call resolves (a no-op against the
    // idempotency guard once already booted) -- seeding the pre-boot
    // get() fallback instead would be invisible to Lang::attachGlobals()'s
    // later read, which always sees the real container-shared instance
    // once a container exists.
    $paths = Paths::fromRoot(sys_get_temp_dir());
    Kernel::boot($paths);
    $translator = Kernel::container()->get(Translator::class);
    if (! $translator instanceof Translator) {
        throw new LogicException('Container returned an unexpected type for ' . Translator::class);
    }
    $translator->loadArray(['bootconfigonly_probe' => 'probe-value']);

    RequestBootstrap::bootConfigOnly($paths);

    expect(Lang::current()->has('bootconfigonly_probe'))->toBeTrue()
        ->and(Lang::current()->snapshot()['bootconfigonly_probe'])->toBe('probe-value');
});

test('bootConfigOnly reuses an already-set CurrentConfigService instead of resolving a new one', function (): void {
    // Kernel::boot() first, then seed the *container-resolved* instance --
    // current()'s pre-boot fallback is a different, memoized object from
    // the one bootConfigOnly()'s own Kernel::boot() call (idempotent,
    // already booted) would later resolve via the container. Same
    // "seed after boot, not before" fix shape as every other Current*
    // wrapper in this campaign.
    $paths = Paths::fromRoot(sys_get_temp_dir());
    Kernel::boot($paths);

    $conn = DbConnection::build();
    $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Piwigo'], isDevMode: true);
    $ormConfig->enableNativeLazyObjects(true);
    $em = new EntityManager($conn, $ormConfig);
    $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener(DbCredentialsTestFactory::get()));
    $preSetService = new ConfigService($em->getRepository(ConfigEntry::class), new EventDispatcher(), CurrentConfig::current());
    CurrentConfigServiceTestFactory::get()->set($preSetService);

    RequestBootstrap::bootConfigOnly($paths);

    // Same instance as the one set beforehand -- proves the isSet() branch
    // really did reuse it instead of resolving (and loadConfFromDb()-ing) a
    // fresh one from the container.
    expect(CurrentConfigServiceTestFactory::get()->get())->toBe($preSetService);
});

test('CurrentConfigService::get throws when nothing has ever been set', function (): void {
    expect(CurrentConfigServiceTestFactory::get()->isSet())->toBeFalse();

    CurrentConfigServiceTestFactory::get()->get();
})->throws(LogicException::class, 'CurrentConfigService not initialised -- call Piwigo\Bootstrap\RequestBootstrap::connect()/CliBootstrap::run()/InstallBootstrap::activateConfigService() first.');

test('bootConfigOnly throws when the container returns an unexpected type for ConfigService', function (): void {
    // CurrentConfigServiceTestFactory::get()->isSet() is false (reset in beforeEach above), so
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
