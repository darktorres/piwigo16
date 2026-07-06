<?php

declare(strict_types=1);

it('runs on PHP 8.5+', function (): void {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80500);
});

it('Pest is loaded', function (): void {
    expect(class_exists(\Pest\PendingCalls\TestCall::class))->toBeTrue();
});
