<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Core\Kernel;

beforeEach(function (): void {
    Kernel::reset();
});

afterEach(function (): void {
    Kernel::reset();
});

test('run boots the Kernel', function (): void {
    CommonBootstrap::run();
    expect(Kernel::isBooted())->toBeTrue();
});
