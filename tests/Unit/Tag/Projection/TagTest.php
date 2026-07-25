<?php

declare(strict_types=1);

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

    expect($tag->id)->toBe(4)
        ->and($tag->name)->toBe('Landscape')
        ->and($tag->urlName)->toBe('landscape')
        ->and($tag->lastmodified)->toBe('2026-07-24 10:00:00');
});

test('fromRow defaults every column to its zero value when absent', function (): void {
    $row = fullTagRow();
    $row['id'] = null;
    $row['name'] = null;
    $row['url_name'] = null;
    $row['lastmodified'] = null;

    $tag = Tag::fromRow($row);

    expect($tag->id)->toBe(0)
        ->and($tag->name)->toBe('')
        ->and($tag->urlName)->toBe('')
        ->and($tag->lastmodified)->toBe('');
});

test('fromRow tolerates an extra counter key without reading it', function (): void {
    $row = fullTagRow();
    $row['counter'] = '17';

    $tag = Tag::fromRow($row);

    expect($tag->id)->toBe(4)
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
