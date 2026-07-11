<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\ServerTiming;
use Piwigo\Users\CurrentUser;

beforeEach(function (): void {
    Kernel::reset();
    ServerTiming::reset();
    Config::reset();
    CurrentUser::reset();
});

afterEach(function (): void {
    Kernel::reset();
    ServerTiming::reset();
    Config::reset();
    CurrentUser::reset();
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

    expect(Config::has('gallery_title'))->toBeTrue()
        ->and(Config::galleryTitle())->toBe('Piwigo');
});

test('run attaches a guest CurrentUser', function (): void {
    CommonBootstrap::run(Paths::fromRoot(sys_get_temp_dir()));

    expect(CurrentUser::isInitialized())->toBeTrue();
});
