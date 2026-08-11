<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryIdImageOrder;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryIdImageOrder(1, 'file ASC');

    expect($row->id)
        ->toBe(1)
        ->and($row->imageOrder)
        ->toBe('file ASC');
});

test('accepts a null imageOrder', function (): void {
    $row = new CategoryIdImageOrder(1, null);

    expect($row->imageOrder)
        ->toBeNull();
});
