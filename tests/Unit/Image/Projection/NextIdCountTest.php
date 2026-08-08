<?php

declare(strict_types=1);

use Piwigo\Image\Projection\NextIdCount;

test('constructs with distinct values for every property', function (): void {
    $row = new NextIdCount(5, 3);

    expect($row->nextId)->toBe(5)
        ->and($row->count)->toBe(3);
});
