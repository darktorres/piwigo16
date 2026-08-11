<?php

declare(strict_types=1);

use Piwigo\Category\Projection\ParentCategoryForCreate;

test('constructs with distinct values for every property', function (): void {
    $row = new ParentCategoryForCreate(1, '1', '1', true, 'public');

    expect($row->id)
        ->toBe(1)
        ->and($row->uppercats)
        ->toBe('1')
        ->and($row->globalRank)
        ->toBe('1')
        ->and($row->visible)
        ->toBeTrue()
        ->and($row->status)
        ->toBe('public');
});
