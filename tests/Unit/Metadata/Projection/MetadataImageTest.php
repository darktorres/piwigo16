<?php

declare(strict_types=1);

use Piwigo\Metadata\Projection\MetadataImage;

/**
 * @return array<string, mixed>
 */
function fullMetadataImageRow(): array
{
    return [
        'id' => '42',
        'path' => 'upload/2026/08/01/photo.jpg',
        'representative_ext' => 'jpg',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $image = MetadataImage::fromRow(fullMetadataImageRow());

    expect($image->id)->toBe(42)
        ->and($image->path)->toBe('upload/2026/08/01/photo.jpg')
        ->and($image->representativeExt)->toBe('jpg');
});

test('fromRow defaults representative_ext to null when absent', function (): void {
    $row = fullMetadataImageRow();
    $row['representative_ext'] = null;

    $image = MetadataImage::fromRow($row);

    expect($image->representativeExt)->toBeNull();
    // The NOT NULL columns (id/path) fall back to their type's zero value
    // instead -- never actually null for a real fetched row.
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = MetadataImage::fromRow(fullMetadataImageRow())->toArray();

    expect($roundTripped)->toBe([
        'id' => 42,
        'path' => 'upload/2026/08/01/photo.jpg',
        'representative_ext' => 'jpg',
    ]);
});
