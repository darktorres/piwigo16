<?php

declare(strict_types=1);

use Piwigo\Category\Projection\CategorySyncCandidateRow;

test('constructs with distinct values for every property', function (): void {
    $row = new CategorySyncCandidateRow(1, '1', '1', 'public', true);

    expect($row->id)->toBe(1)
        ->and($row->uppercats)->toBe('1')
        ->and($row->globalRank)->toBe('1')
        ->and($row->status)->toBe('public')
        ->and($row->visible)->toBeTrue();
});

test('toArray round-trips the exact same shape', function (): void {
    $row = new CategorySyncCandidateRow(1, '1', null, 'public', false);

    expect($row->toArray())->toBe([
        'id' => 1,
        'uppercats' => '1',
        'global_rank' => null,
        'status' => 'public',
        'visible' => false,
    ]);
});
