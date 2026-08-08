<?php

declare(strict_types=1);

use Piwigo\Image\Projection\FormatCountSum;

test('constructs with distinct values for every property', function (): void {
    $row = new FormatCountSum(3, 4_096);

    expect($row->count)->toBe(3)
        ->and($row->sum)->toBe(4_096);
});
