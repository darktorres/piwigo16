<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryIdStatus;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryIdStatus(1, 'private');

    expect($row->id)->toBe(1)
        ->and($row->status)->toBe('private');
});
