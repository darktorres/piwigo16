<?php

declare(strict_types=1);

use Piwigo\Core\AdminContext;

beforeEach(function (): void {
    AdminContext::reset();
});

afterEach(function (): void {
    AdminContext::reset();
});

test('mark activates the flag, reset clears it back', function (): void {
    expect(AdminContext::isActive())->toBeFalse();

    AdminContext::mark();

    expect(AdminContext::isActive())->toBeTrue();

    AdminContext::reset();

    expect(AdminContext::isActive())->toBeFalse();
});
