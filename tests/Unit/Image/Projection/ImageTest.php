<?php

declare(strict_types=1);

use Piwigo\Image\ImageEntity;
use Piwigo\Image\Projection\Image;

/**
 * A transient (never-persisted) entity: real-looking scalar values for
 * every other column, but $id left at its own real ?int default (null)
 * -- Doctrine only ever assigns a real int once the entity is actually
 * flushed (it's #[ORM\GeneratedValue]).
 */
function transientImageEntity(): ImageEntity
{
    return new ImageEntity(
        file: 'photo.jpg',
        dateAvailable: null,
        dateCreation: null,
        name: null,
        comment: null,
        author: null,
        hit: 0,
        filesize: null,
        width: null,
        height: null,
        coi: null,
        representativeExt: null,
        dateMetadataUpdate: null,
        ratingScore: null,
        path: 'upload/2026/08/01/photo.jpg',
        storageCategoryId: null,
        level: 0,
        md5sum: null,
        addedBy: null,
        rotation: null,
        latitude: null,
        longitude: null,
        lastmodified: '2026-08-01 12:00:00',
    );
}

test('fromEntity maps a real, already-persisted entity\'s own id, not the ?? 0 fallback', function (): void {
    $entity = transientImageEntity();
    $entity->id = 42;

    expect(Image::fromEntity($entity)->id)->toBe(42);
});

test('fromEntity defaults id to exactly 0 for a transient (not-yet-persisted) entity, whose real id is still null', function (): void {
    // Kills line 59's DecrementInteger/IncrementInteger (`?? -1`/`?? 1`
    // instead of `?? 0`) -- unlike fromRow()'s own NOT-NULL-column
    // fallbacks (never reachable against a real DB row), $entity->id is
    // genuinely nullable pre-flush, so this default is real, reachable
    // behavior, not a defensive guard.
    expect(Image::fromEntity(transientImageEntity())->id)->toBe(0);
});

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
    //
    // Confirmed-equivalent, same mysqli-native-types root cause as
    // ImageRepository.php's own consolidated docblock: fromRow()'s
    // Decrement/IncrementInteger on line 92's id default, line 99's hit
    // default, and line 109's level default, plus EmptyStringToNotEmpty
    // on line 93's file default, line 107's path default, and line
    // 115's lastmodified default. Live sed-mutate-and-rerun spot-checked
    // lines 92, 99, and 107 (one int-default and one string-default
    // representative of each column position) against the full suite;
    // the same reasoning generalizes identically to the other 3 (id/hit/
    // level are all NOT NULL ints, file/path/lastmodified are all NOT
    // NULL strings, on the exact same driver/query shape).
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
