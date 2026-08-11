<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategorySelectOptions;

test('constructs with distinct values for options and selected', function (): void {
    $result = new CategorySelectOptions(options: [
        1 => 'Holidays',
        2 => 'Travel',
    ], selected: [2]);

    expect($result->options)
        ->toBe([
            1 => 'Holidays',
            2 => 'Travel',
        ])
        ->and($result->selected)
        ->toBe([2]);
});
