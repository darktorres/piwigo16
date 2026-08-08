<?php

declare(strict_types=1);

use Piwigo\Image\Projection\MostRecentCategoryInfo;

test('constructs with distinct values for every property', function (): void {
    $row = new MostRecentCategoryInfo(2, '1,2');

    expect($row->categoryId)->toBe(2)
        ->and($row->uppercats)->toBe('1,2');
});
