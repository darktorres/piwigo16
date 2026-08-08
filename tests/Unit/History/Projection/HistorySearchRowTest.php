<?php

declare(strict_types=1);

use Piwigo\History\Projection\HistorySearchRow;

/**
 * @return array{date: ?string, time: string, userId: int, ip: string, section: ?string, categoryId: ?int, searchId: ?int, tagIds: ?string, imageId: ?int, imageType: ?string}
 */
function fullHistorySearchRowArgs(): array
{
    return [
        'date' => '2026-07-12',
        'time' => '03:00:00',
        'userId' => 1,
        'ip' => '127.0.0.1',
        'section' => 'categories',
        'categoryId' => 2,
        'searchId' => null,
        'tagIds' => '1,2',
        'imageId' => 5,
        'imageType' => 'picture',
    ];
}

test('constructs with distinct values for every property', function (): void {
    $row = new HistorySearchRow(...fullHistorySearchRowArgs());

    expect($row->date)->toBe('2026-07-12')
        ->and($row->time)->toBe('03:00:00')
        ->and($row->userId)->toBe(1)
        ->and($row->ip)->toBe('127.0.0.1')
        ->and($row->section)->toBe('categories')
        ->and($row->categoryId)->toBe(2)
        ->and($row->searchId)->toBeNull()
        ->and($row->tagIds)->toBe('1,2')
        ->and($row->imageId)->toBe(5)
        ->and($row->imageType)->toBe('picture');
});

test('toArray round-trips the exact same shape the constructor accepted, using the raw snake_case/IP keys', function (): void {
    $roundTripped = new HistorySearchRow(...fullHistorySearchRowArgs())->toArray();

    expect($roundTripped)->toBe([
        'date' => '2026-07-12',
        'time' => '03:00:00',
        'user_id' => 1,
        'IP' => '127.0.0.1',
        'section' => 'categories',
        'category_id' => 2,
        'search_id' => null,
        'tag_ids' => '1,2',
        'image_id' => 5,
        'image_type' => 'picture',
    ]);
});
