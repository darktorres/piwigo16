<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategoryIdNameUppercat;

test('constructs with distinct values for every property', function (): void {
    $row = new CategoryIdNameUppercat(2, 'Nested Sub Album', 1);

    expect($row->id)
        ->toBe(2)
        ->and($row->name)
        ->toBe('Nested Sub Album')
        ->and($row->idUppercat)
        ->toBe(1);
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new CategoryIdNameUppercat(2, 'Nested Sub Album', null);

    expect($row->toArray())
        ->toBe([
            'id' => 2,
            'name' => 'Nested Sub Album',
            'id_uppercat' => null,
        ]);
});
