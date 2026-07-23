<?php

declare(strict_types=1);

use Piwigo\Image\Projection\Image;

/**
 * @return array<string, mixed>
 */
function fullImageRow(): array
{
    return [
        'id' => '42',
        'file' => 'photo.jpg',
        'date_available' => '2026-08-01 12:00:00',
        'date_creation' => '2026-07-30 08:00:00',
        'name' => 'A photo',
        'comment' => 'A comment',
        'author' => 'torres',
        'hit' => '7',
        'filesize' => '123456',
        'width' => '800',
        'height' => '600',
        'coi' => 'TLBR',
        'representative_ext' => 'jpg',
        'date_metadata_update' => '2026-07-31',
        'rating_score' => '4.5',
        'path' => 'upload/2026/08/01/photo.jpg',
        'storage_category_id' => '3',
        'level' => '1',
        'md5sum' => 'abc123',
        'added_by' => '5',
        'rotation' => '2',
        'latitude' => '48.8566',
        'longitude' => '2.3522',
        'lastmodified' => '2026-08-01 12:00:00',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $image = Image::fromRow(fullImageRow());

    expect($image->id)->toBe(42)
        ->and($image->file)->toBe('photo.jpg')
        ->and($image->dateAvailable)->toBe('2026-08-01 12:00:00')
        ->and($image->dateCreation)->toBe('2026-07-30 08:00:00')
        ->and($image->name)->toBe('A photo')
        ->and($image->comment)->toBe('A comment')
        ->and($image->author)->toBe('torres')
        ->and($image->hit)->toBe(7)
        ->and($image->filesize)->toBe(123456)
        ->and($image->width)->toBe(800)
        ->and($image->height)->toBe(600)
        ->and($image->coi)->toBe('TLBR')
        ->and($image->representativeExt)->toBe('jpg')
        ->and($image->dateMetadataUpdate)->toBe('2026-07-31')
        ->and($image->ratingScore)->toBe(4.5)
        ->and($image->path)->toBe('upload/2026/08/01/photo.jpg')
        ->and($image->storageCategoryId)->toBe(3)
        ->and($image->level)->toBe(1)
        ->and($image->md5sum)->toBe('abc123')
        ->and($image->addedBy)->toBe(5)
        ->and($image->rotation)->toBe(2)
        ->and($image->latitude)->toBe(48.8566)
        ->and($image->longitude)->toBe(2.3522)
        ->and($image->lastmodified)->toBe('2026-08-01 12:00:00');
});

test('fromRow defaults every nullable column to null when absent', function (): void {
    $row = fullImageRow();
    foreach (['date_available', 'date_creation', 'name', 'comment', 'author', 'filesize',
        'width', 'height', 'coi', 'representative_ext', 'date_metadata_update', 'rating_score',
        'storage_category_id', 'md5sum', 'added_by', 'rotation', 'latitude', 'longitude'] as $key) {
        $row[$key] = null;
    }

    $image = Image::fromRow($row);

    expect($image->dateAvailable)->toBeNull()
        ->and($image->dateCreation)->toBeNull()
        ->and($image->name)->toBeNull()
        ->and($image->comment)->toBeNull()
        ->and($image->author)->toBeNull()
        ->and($image->filesize)->toBeNull()
        ->and($image->width)->toBeNull()
        ->and($image->height)->toBeNull()
        ->and($image->coi)->toBeNull()
        ->and($image->representativeExt)->toBeNull()
        ->and($image->dateMetadataUpdate)->toBeNull()
        ->and($image->ratingScore)->toBeNull()
        ->and($image->storageCategoryId)->toBeNull()
        ->and($image->md5sum)->toBeNull()
        ->and($image->addedBy)->toBeNull()
        ->and($image->rotation)->toBeNull()
        ->and($image->latitude)->toBeNull()
        ->and($image->longitude)->toBeNull();
    // The NOT NULL columns (id/file/hit/path/level/lastmodified) fall back
    // to their type's zero value instead, matching every other narrowing
    // helper in this codebase (is_numeric(...) ? (int) ... : 0, etc.) --
    // never actually null for a real fetched row, since these are NOT NULL
    // DB columns; this only guards a malformed/partial row.
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $row = fullImageRow();

    $roundTripped = Image::fromRow($row)->toArray();

    expect($roundTripped)->toBe([
        'id' => 42,
        'file' => 'photo.jpg',
        'date_available' => '2026-08-01 12:00:00',
        'date_creation' => '2026-07-30 08:00:00',
        'name' => 'A photo',
        'comment' => 'A comment',
        'author' => 'torres',
        'hit' => 7,
        'filesize' => 123456,
        'width' => 800,
        'height' => 600,
        'coi' => 'TLBR',
        'representative_ext' => 'jpg',
        'date_metadata_update' => '2026-07-31',
        'rating_score' => 4.5,
        'path' => 'upload/2026/08/01/photo.jpg',
        'storage_category_id' => 3,
        'level' => 1,
        'md5sum' => 'abc123',
        'added_by' => 5,
        'rotation' => 2,
        'latitude' => 48.8566,
        'longitude' => 2.3522,
        'lastmodified' => '2026-08-01 12:00:00',
    ]);
});
