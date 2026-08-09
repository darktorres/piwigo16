<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryCreateOutcome;

test('failure sets error and leaves info/id null', function (): void {
    $outcome = CategoryCreateOutcome::failure('already exists');

    expect($outcome->error)->toBe('already exists')
        ->and($outcome->info)->toBeNull()
        ->and($outcome->id)->toBeNull();
});

test('success sets info/id and leaves error null', function (): void {
    $outcome = CategoryCreateOutcome::success('created', 5);

    expect($outcome->error)->toBeNull()
        ->and($outcome->info)->toBe('created')
        ->and($outcome->id)->toBe(5);
});

test('success accepts a string id, matching its int|string parameter type', function (): void {
    $outcome = CategoryCreateOutcome::success('created', '5');

    expect($outcome->id)->toBe('5');
});
