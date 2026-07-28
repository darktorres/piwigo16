<?php

declare(strict_types=1);

use Piwigo\Core\ApiKeyRequestFlag;

beforeEach(function (): void {
    ApiKeyRequestFlag::reset();
});

afterEach(function (): void {
    ApiKeyRequestFlag::reset();
});

test('activate sets the flag, reset clears it back', function (): void {
    expect(ApiKeyRequestFlag::isActive())->toBeFalse();

    ApiKeyRequestFlag::activate();

    expect(ApiKeyRequestFlag::isActive())->toBeTrue();

    ApiKeyRequestFlag::reset();

    expect(ApiKeyRequestFlag::isActive())->toBeFalse();
});
