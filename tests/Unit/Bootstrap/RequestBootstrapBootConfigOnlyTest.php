<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ServerTiming;
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
