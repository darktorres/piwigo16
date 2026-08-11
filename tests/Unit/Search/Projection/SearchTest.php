<?php

declare(strict_types=1);

use Piwigo\Search\Projection\Search;

/**
 * @return array<string, mixed>
 */
function fullSearchRow(): array
{
    return [
        'id' => '7',
        'search_uuid' => 'psk-20260712-abcdefghij',
        'created_on' => '2026-07-12 00:00:00',
        'created_by' => '3',
        'forked_from' => '2',
        'rules' => json_encode([
            'q' => 'nature',
            0 => 'stray-int-key',
        ]),
    ];
}

test('fromRow decodes a JSON rules string, keeping only string-keyed entries', function (): void {
    $search = Search::fromRow(fullSearchRow());

    expect($search->id)
        ->toBe(7)
        ->and($search->searchUuid)
        ->toBe('psk-20260712-abcdefghij')
        ->and($search->createdOn)
        ->toBe('2026-07-12 00:00:00')
        ->and($search->createdBy)
        ->toBe(3)
        ->and($search->forkedFrom)
        ->toBe(2)
        // the encoded row mixes a string key ('q') with a numeric key (0) --
        // json_decode(...,true) turns the numeric JSON member back into an
        // int array key, and decodeRules() must drop it, keeping only 'q'.
        ->and($search->rules)
        ->toBe([
            'q' => 'nature',
        ]);
});

test('fromRow defaults every nullable/malformed column to null, and id/rules to their zero value', function (): void {
    $row = fullSearchRow();
    foreach (['id', 'search_uuid', 'created_on', 'created_by', 'forked_from', 'rules'] as $key) {
        $row[$key] = null;
    }

    $search = Search::fromRow($row);

    expect($search->id)
        ->toBe(0)
        ->and($search->searchUuid)
        ->toBeNull()
        ->and($search->createdOn)
        ->toBeNull()
        ->and($search->createdBy)
        ->toBeNull()
        ->and($search->forkedFrom)
        ->toBeNull()
        ->and($search->rules)
        ->toBeNull();
});

test('toArray returns all fields keyed by their DB column names', function (): void {
    $roundTripped = Search::fromRow(fullSearchRow())->toArray();

    expect($roundTripped)
        ->toBe([
            'id' => 7,
            'search_uuid' => 'psk-20260712-abcdefghij',
            'created_on' => '2026-07-12 00:00:00',
            'created_by' => 3,
            'forked_from' => 2,
            'rules' => [
                'q' => 'nature',
            ],
        ]);
});
