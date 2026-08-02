<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\TagId;
use Piwigo\Tag\Projection\Tag;

/**
 * @return array<string, mixed>
 */
function fullTagRow(): array
{
    return [
        'id' => '4',
        'name' => 'Landscape',
        'url_name' => 'landscape',
        'lastmodified' => '2026-07-24 10:00:00',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $tag = Tag::fromRow(fullTagRow());

    expect($tag->id)->toEqual(TagId::from(4))
        ->and($tag->name)->toBe('Landscape')
        ->and($tag->urlName)->toBe('landscape')
        ->and($tag->lastmodified)->toBe('2026-07-24 10:00:00');
});

test('fromRow defaults name/url_name/lastmodified to their zero value when absent, given a valid id', function (): void {
    $row = fullTagRow();
    $row['name'] = null;
    $row['url_name'] = null;
    $row['lastmodified'] = null;

    $tag = Tag::fromRow($row);

    expect($tag->id)->toEqual(TagId::from(4))
        ->and($tag->name)->toBe('')
        ->and($tag->urlName)->toBe('')
        ->and($tag->lastmodified)->toBe('');
});

test('fromRow throws when id is missing', function (): void {
    // Behavior change, deliberate: id is a TagId now, and TagId::from(0)
    // always throws (0 was never a valid id) -- fromRow() itself has no
    // real caller (every real caller goes through TagRepository's own
    // toProjection(), which builds this straight off a typed TagEntity),
    // same "throw on a structurally-impossible missing id" shape as
    // Group\Projection\Group's own fromRow().
    $row = fullTagRow();
    $row['id'] = null;

    Tag::fromRow($row);
})->throws(InvalidArgumentException::class);

test('fromRow throws with the real debug type of a non-null but invalid id', function (): void {
    // The test above sets id to null itself, so `$row['id'] ?? null`
    // resolves to null whether or not that coalesce actually reads
    // $row['id'] -- can't tell it apart from a mutated bare `null`. A
    // non-null-but-still-invalid id (TagId::tryFrom() rejects any
    // non-positive-integer-string) forces the exception message's own
    // get_debug_type($row['id'] ?? null) call to reflect the real value.
    $row = fullTagRow();
    $row['id'] = 'not-a-number';

    expect(fn () => Tag::fromRow($row))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive tag id, got string');
});

test('fromRow tolerates an extra counter key without reading it', function (): void {
    $row = fullTagRow();
    $row['counter'] = '17';

    $tag = Tag::fromRow($row);

    expect($tag->id)->toEqual(TagId::from(4))
        ->and($tag->name)->toBe('Landscape');
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = Tag::fromRow(fullTagRow())->toArray();

    expect($roundTripped)->toBe([
        'id' => 4,
        'name' => 'Landscape',
        'url_name' => 'landscape',
        'lastmodified' => '2026-07-24 10:00:00',
    ]);
});
