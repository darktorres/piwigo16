<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Config\Config;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ServerTiming;
use Piwigo\Users\CurrentUser;

beforeEach(function (): void {
    Kernel::reset();
    ServerTiming::reset();
    Config::reset();
    CurrentUser::reset();
    // Legacy Coupling Retirement Phase 8, 8d: CommonBootstrap::run() now
    // reuses an already-set CurrentConfigService instead of always
    // resolving+loading its own -- without this reset, a set() left over
    // from an earlier test would make these tests skip that resolve-and-load
    // path entirely and silently pass/fail on stale state.
    CurrentConfigService::reset();
});

afterEach(function (): void {
    Kernel::reset();
    ServerTiming::reset();
    Config::reset();
    CurrentUser::reset();
    CurrentConfigService::reset();
});

test('run boots the Kernel', function (): void {
    CommonBootstrap::run(Paths::fromRoot(sys_get_temp_dir()));
    expect(Kernel::isBooted())->toBeTrue();
});

test('run records a boot timing', function (): void {
    CommonBootstrap::run(Paths::fromRoot(sys_get_temp_dir()));

    expect(ServerTiming::all())->toHaveKey('boot');
    expect(ServerTiming::all()['boot'])->toBeGreaterThanOrEqual(0.0);
});

test('run seeds Config from SCHEMA defaults (P13)', function (): void {
    CommonBootstrap::run(Paths::fromRoot(sys_get_temp_dir()));

    expect(Config::has('gallery_title'))->toBeTrue();
});

test('run merges DB-persisted config overrides into Config (P23 batch 1)', function (): void {
    // tests/Fixtures/piwigo-17.0.sql overrides gallery_title away from its
    // SCHEMA default ('Piwigo') to prove loadConfFromDb() actually ran,
    // not just that the key exists (SCHEMA defaults alone would also make
    // has() true).
    CommonBootstrap::run(Paths::fromRoot(sys_get_temp_dir()));

    expect(Config::galleryTitle())->toBe('Fixture Gallery');
});

test('run attaches a guest CurrentUser', function (): void {
    CommonBootstrap::run(Paths::fromRoot(sys_get_temp_dir()));

    expect(CurrentUser::isInitialized())->toBeTrue();
});
