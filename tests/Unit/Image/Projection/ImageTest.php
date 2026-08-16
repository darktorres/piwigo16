<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\Md5Sum;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
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
        storageCategory: null,
        level: 0,
        md5sum: null,
        addedBy: null,
        rotation: null,
        latitude: null,
        longitude: null,
        lastmodified: SqlDateTime::from('2026-08-01 12:00:00'),
    );
}

test('fromEntity maps a real, already-persisted entity\'s own id', function (): void {
    $entity = transientImageEntity();
    $entity->id = ImageId::from(42);

    expect(Image::fromEntity($entity)->id)->toEqual(ImageId::from(42));
});

test('fromEntity throws for a transient (not-yet-persisted) entity, whose real id is still null', function (): void {
    // $id is the ImageId VO, which has no valid zero-value to fall back
    // to -- fromEntity() throws instead of defaulting.
    expect(fn (): Image => Image::fromEntity(transientImageEntity()))
        ->toThrow(LogicException::class, 'ImageEntity::$id is only null before persist; fromEntity() expects an already-persisted entity');
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
        'md5sum' => 'd41d8cd98f00b204e9800998ecf8427e',
        'added_by' => '5',
        'rotation' => '2',
        'latitude' => '48.8566',
        'longitude' => '2.3522',
        'lastmodified' => '2026-08-01 12:00:00',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $image = Image::fromRow(fullImageRow());

    expect($image->id)
        ->toEqual(ImageId::from(42))
        ->and($image->file)
        ->toBe('photo.jpg')
        ->and($image->dateAvailable)
        ->toBe('2026-08-01 12:00:00')
        ->and($image->dateCreation)
        ->toBe('2026-07-30 08:00:00')
        ->and($image->name)
        ->toBe('A photo')
        ->and($image->comment)
        ->toBe('A comment')
        ->and($image->author)
        ->toBe('torres')
        ->and($image->hit)
        ->toBe(7)
        ->and($image->filesize)
        ->toBe(123456)
        ->and($image->width)
        ->toBe(800)
        ->and($image->height)
        ->toBe(600)
        ->and($image->coi)
        ->toBe('TLBR')
        ->and($image->representativeExt)
        ->toBe('jpg')
        ->and($image->dateMetadataUpdate)
        ->toBe('2026-07-31')
        ->and($image->ratingScore)
        ->toBe(4.5)
        ->and($image->path)
        ->toBe('upload/2026/08/01/photo.jpg')
        ->and($image->storageCategoryId)
        ->toBe(3)
        ->and($image->level)
        ->toBe(1)
        ->and($image->md5sum)
        ->toEqual(Md5Sum::from('d41d8cd98f00b204e9800998ecf8427e'))
        ->and($image->addedBy)
        ->toEqual(UserId::from(5))
        ->and($image->rotation)
        ->toBe(2)
        ->and($image->latitude)
        ->toBe(48.8566)
        ->and($image->longitude)
        ->toBe(2.3522)
        ->and($image->lastmodified)
        ->toBe('2026-08-01 12:00:00');
});

test('fromRow defaults every nullable column to null when absent', function (): void {
    $row = fullImageRow();
    foreach (['date_available', 'date_creation', 'name', 'comment', 'author', 'filesize',
        'width', 'height', 'coi', 'representative_ext', 'date_metadata_update', 'rating_score',
        'storage_category_id', 'md5sum', 'added_by', 'rotation', 'latitude', 'longitude'] as $key) {
        $row[$key] = null;
    }

    $image = Image::fromRow($row);

    expect($image->dateAvailable)
        ->toBeNull()
        ->and($image->dateCreation)
        ->toBeNull()
        ->and($image->name)
        ->toBeNull()
        ->and($image->comment)
        ->toBeNull()
        ->and($image->author)
        ->toBeNull()
        ->and($image->filesize)
        ->toBeNull()
        ->and($image->width)
        ->toBeNull()
        ->and($image->height)
        ->toBeNull()
        ->and($image->coi)
        ->toBeNull()
        ->and($image->representativeExt)
        ->toBeNull()
        ->and($image->dateMetadataUpdate)
        ->toBeNull()
        ->and($image->ratingScore)
        ->toBeNull()
        ->and($image->storageCategoryId)
        ->toBeNull()
        ->and($image->md5sum)
        ->toBeNull()
        ->and($image->addedBy)
        ->toBeNull()
        ->and($image->rotation)
        ->toBeNull()
        ->and($image->latitude)
        ->toBeNull()
        ->and($image->longitude)
        ->toBeNull();
    // The NOT NULL columns (id/file/hit/path/level/lastmodified) fall back
    // to their type's zero value instead, matching every other narrowing
    // helper in this codebase (is_numeric(...) ? (int) ... : 0, etc.) --
    // never actually null for a real fetched row, since these are NOT NULL
    // DB columns; this only guards a malformed/partial row. Those six
    // defaults are exact-value-asserted below ("throws when id cannot be
    // coerced...", "defaults file, path, and lastmodified...", "defaults
    // hit and level..."): the id case in particular is not an equivalent
    // mutant -- ImageId::from() rejects its own 0 fallback, so an
    // IncrementInteger mutant (0 -> 1) turns that throw into a silent
    // ImageId(1), which only an exact-message throw assertion catches.
});

test('fromRow throws when id cannot be coerced to a positive integer, since its only fallback (0) is itself an invalid ImageId', function (): void {
    $row = fullImageRow();
    $row['id'] = 'not-a-number';

    expect(fn (): Image => Image::fromRow($row))
        ->toThrow(InvalidArgumentException::class, 'ImageId must be a positive integer, got 0');
});

test('fromRow defaults file, path, and lastmodified to an empty string when the row value is not a string', function (): void {
    $row = fullImageRow();
    $row['file'] = null;
    $row['path'] = null;
    $row['lastmodified'] = null;

    $image = Image::fromRow($row);

    expect($image->file)
        ->toBe('')
        ->and($image->path)
        ->toBe('')
        ->and($image->lastmodified)
        ->toBe('');
});

test('fromRow defaults hit and level to 0 when the row value is not numeric', function (): void {
    $row = fullImageRow();
    $row['hit'] = 'not-a-number';
    $row['level'] = 'not-a-number';

    $image = Image::fromRow($row);

    expect($image->hit)
        ->toBe(0)
        ->and($image->level)
        ->toBe(0);
});

test('toArray reports null for storage_category_id, md5sum, and added_by (not a null-property-access warning) when the row has none of them', function (): void {
    $row = fullImageRow();
    $row['storage_category_id'] = null;
    $row['md5sum'] = null;
    $row['added_by'] = null;

    $roundTripped = Image::fromRow($row)->toArray();

    expect($roundTripped['storage_category_id'])->toBeNull()
        ->and($roundTripped['md5sum'])->toBeNull()
        ->and($roundTripped['added_by'])->toBeNull();
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $row = fullImageRow();

    $roundTripped = Image::fromRow($row)->toArray();

    expect($roundTripped)
        ->toBe([
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
            'md5sum' => 'd41d8cd98f00b204e9800998ecf8427e',
            'added_by' => 5,
            'rotation' => 2,
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'lastmodified' => '2026-08-01 12:00:00',
        ]);
});
