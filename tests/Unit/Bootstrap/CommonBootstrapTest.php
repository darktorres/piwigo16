<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Core\Kernel;
use Piwigo\Core\ServerTiming;

beforeEach(function (): void {
    Kernel::reset();
    ServerTiming::reset();
});

afterEach(function (): void {
    Kernel::reset();
    ServerTiming::reset();
});

test('run boots the Kernel', function (): void {
    CommonBootstrap::run();
    expect(Kernel::isBooted())->toBeTrue();
});

test('run records a boot timing', function (): void {
    CommonBootstrap::run();

    expect(ServerTiming::all())->toHaveKey('boot');
    expect(ServerTiming::all()['boot'])->toBeGreaterThanOrEqual(0.0);
});
