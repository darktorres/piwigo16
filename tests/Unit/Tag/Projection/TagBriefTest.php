<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\TagId;
use Piwigo\Tag\Projection\TagBrief;

/**
 * @return array<string, mixed>
 */
function fullTagBriefRow(): array
{
    return [
        'id' => '7',
        'name' => 'Sunset',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $tag = TagBrief::fromRow(fullTagBriefRow());

    expect($tag->id)->toEqual(TagId::from(7))
        ->and($tag->name)->toBe('Sunset');
});

test('fromRow defaults name to empty string when absent, given a valid id', function (): void {
    $row = fullTagBriefRow();
    $row['name'] = null;

    $tag = TagBrief::fromRow($row);

    expect($tag->id)->toEqual(TagId::from(7))
        ->and($tag->name)->toBe('');
});

test('fromRow throws when id is missing', function (): void {
    $row = fullTagBriefRow();
    $row['id'] = null;

    TagBrief::fromRow($row);
})->throws(InvalidArgumentException::class);

test('fromRow throws with the real debug type of a non-null but invalid id', function (): void {
    // The test above sets id to null itself, so `$row['id'] ?? null`
    // resolves to null whether or not that coalesce actually reads
    // $row['id'] -- can't tell it apart from a mutated bare `null`. A
    // non-null-but-still-invalid id (TagId::tryFrom() rejects any
    // non-positive-integer-string) forces the exception message's own
    // get_debug_type($row['id'] ?? null) call to reflect the real value.
    $row = fullTagBriefRow();
    $row['id'] = 'not-a-number';

    expect(fn () => TagBrief::fromRow($row))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive tag id, got string');
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = TagBrief::fromRow(fullTagBriefRow())->toArray();

    expect($roundTripped)->toBe([
        'id' => 7,
        'name' => 'Sunset',
    ]);
});
