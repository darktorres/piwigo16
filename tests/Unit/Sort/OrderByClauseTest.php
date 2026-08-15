<?php

declare(strict_types=1);

use Piwigo\Sort\OrderByClause;

test('constructs with distinct values for every property', function (): void {
    $clause = new OrderByClause('i.dateAvailable', 'DESC');

    expect($clause->property)
        ->toBe('i.dateAvailable')
        ->and($clause->dir)
        ->toBe('DESC');
});
