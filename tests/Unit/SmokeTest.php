<?php

declare(strict_types=1);

use Pest\PendingCalls\TestCall;

it('runs on PHP 8.5+', function (): void {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80500);
});

it('Pest is loaded', function (): void {
    expect(class_exists(TestCall::class))->toBeTrue();
});
