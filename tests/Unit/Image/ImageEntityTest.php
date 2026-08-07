<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Md5Sum;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Image\ImageEntity;

/**
 * @return array{file: string, dateAvailable: ?string, dateCreation: ?string, name: ?string, comment: ?string, author: ?string, hit: int, filesize: ?int, width: ?int, height: ?int, coi: ?string, representativeExt: ?string, dateMetadataUpdate: ?string, ratingScore: ?float, path: string, storageCategoryId: ?CategoryId, level: int, md5sum: ?Md5Sum, addedBy: ?UserId, rotation: ?int, latitude: ?float, longitude: ?float, lastmodified: string}
 */
function baseImageArgs(): array
{
    return [
        'file' => 'sunset-beach.jpg',
        'dateAvailable' => '2026-07-20 09:15:00',
        'dateCreation' => '2026-07-18 16:42:30',
        'name' => 'Sunset at the beach',
        'comment' => 'Golden hour shot from the pier',
        'author' => 'jane.doe',
        'hit' => 154,
        'filesize' => 2048576,
        'width' => 1920,
        'height' => 1080,
        'coi' => 'TLBR',
        'representativeExt' => 'webp',
        'dateMetadataUpdate' => '2026-07-19',
        'ratingScore' => 4.75,
        'path' => 'galleries/2026/07/sunset-beach.jpg',
        'storageCategoryId' => CategoryId::from(9),
        'level' => 2,
        'md5sum' => Md5Sum::from('9e107d9d372bb6826bd81d3542a419d6'),
        'addedBy' => UserId::from(11),
        'rotation' => 3,
        'latitude' => 43.2965,
        'longitude' => 5.3698,
        'lastmodified' => '2026-07-26 08:00:00',
    ];
}

test('constructs with distinct values for every property', function (): void {
    $image = new ImageEntity(...baseImageArgs());

    expect($image->id)->toBeNull()
        ->and($image->file)->toBe('sunset-beach.jpg')
        ->and($image->dateAvailable)->toBe('2026-07-20 09:15:00')
        ->and($image->dateCreation)->toBe('2026-07-18 16:42:30')
        ->and($image->name)->toBe('Sunset at the beach')
        ->and($image->comment)->toBe('Golden hour shot from the pier')
        ->and($image->author)->toBe('jane.doe')
        ->and($image->hit)->toBe(154)
        ->and($image->filesize)->toBe(2048576)
        ->and($image->width)->toBe(1920)
        ->and($image->height)->toBe(1080)
        ->and($image->coi)->toBe('TLBR')
        ->and($image->representativeExt)->toBe('webp')
        ->and($image->dateMetadataUpdate)->toBe('2026-07-19')
        ->and($image->ratingScore)->toBe(4.75)
        ->and($image->path)->toBe('galleries/2026/07/sunset-beach.jpg')
        ->and($image->storageCategoryId)->toEqual(CategoryId::from(9))
        ->and($image->level)->toBe(2)
        ->and($image->md5sum)->toEqual(Md5Sum::from('9e107d9d372bb6826bd81d3542a419d6'))
        ->and($image->addedBy)->toEqual(UserId::from(11))
        ->and($image->rotation)->toBe(3)
        ->and($image->latitude)->toBe(43.2965)
        ->and($image->longitude)->toBe(5.3698)
        ->and($image->lastmodified)->toBe('2026-07-26 08:00:00');
});

test('id is never assignable through the constructor, always starts null', function (): void {
    $image = new ImageEntity(...baseImageArgs());

    // $id is declared outside the constructor (Doctrine's GeneratedValue PK) --
    // only the ORM's reflection-based hydration ever fills it in.
    expect($image->id)->toBeNull();
});

test('constructs with dateAvailable null', function (): void {
    $args = baseImageArgs();
    $args['dateAvailable'] = null;

    $image = new ImageEntity(...$args);

    expect($image->dateAvailable)->toBeNull();
});

test('constructs with dateCreation null', function (): void {
    $args = baseImageArgs();
    $args['dateCreation'] = null;

    $image = new ImageEntity(...$args);

    expect($image->dateCreation)->toBeNull();
});

test('constructs with name null', function (): void {
    $args = baseImageArgs();
    $args['name'] = null;

    $image = new ImageEntity(...$args);

    expect($image->name)->toBeNull();
});

test('constructs with comment null', function (): void {
    $args = baseImageArgs();
    $args['comment'] = null;

    $image = new ImageEntity(...$args);

    expect($image->comment)->toBeNull();
});

test('constructs with author null', function (): void {
    $args = baseImageArgs();
    $args['author'] = null;

    $image = new ImageEntity(...$args);

    expect($image->author)->toBeNull();
});

test('constructs with filesize null', function (): void {
    $args = baseImageArgs();
    $args['filesize'] = null;

    $image = new ImageEntity(...$args);

    expect($image->filesize)->toBeNull();
});

test('constructs with width null', function (): void {
    $args = baseImageArgs();
    $args['width'] = null;

    $image = new ImageEntity(...$args);

    expect($image->width)->toBeNull();
});

test('constructs with height null', function (): void {
    $args = baseImageArgs();
    $args['height'] = null;

    $image = new ImageEntity(...$args);

    expect($image->height)->toBeNull();
});

test('constructs with coi null', function (): void {
    $args = baseImageArgs();
    $args['coi'] = null;

    $image = new ImageEntity(...$args);

    expect($image->coi)->toBeNull();
});

test('constructs with representativeExt null', function (): void {
    $args = baseImageArgs();
    $args['representativeExt'] = null;

    $image = new ImageEntity(...$args);

    expect($image->representativeExt)->toBeNull();
});

test('constructs with dateMetadataUpdate null', function (): void {
    $args = baseImageArgs();
    $args['dateMetadataUpdate'] = null;

    $image = new ImageEntity(...$args);

    expect($image->dateMetadataUpdate)->toBeNull();
});

test('constructs with ratingScore null', function (): void {
    $args = baseImageArgs();
    $args['ratingScore'] = null;

    $image = new ImageEntity(...$args);

    expect($image->ratingScore)->toBeNull();
});

test('constructs with storageCategoryId null', function (): void {
    $args = baseImageArgs();
    $args['storageCategoryId'] = null;

    $image = new ImageEntity(...$args);

    expect($image->storageCategoryId)->toBeNull();
});

test('constructs with md5sum null', function (): void {
    $args = baseImageArgs();
    $args['md5sum'] = null;

    $image = new ImageEntity(...$args);

    expect($image->md5sum)->toBeNull();
});

test('constructs with addedBy null', function (): void {
    $args = baseImageArgs();
    $args['addedBy'] = null;

    $image = new ImageEntity(...$args);

    expect($image->addedBy)->toBeNull();
});

test('constructs with rotation null', function (): void {
    $args = baseImageArgs();
    $args['rotation'] = null;

    $image = new ImageEntity(...$args);

    expect($image->rotation)->toBeNull();
});

test('constructs with latitude null', function (): void {
    $args = baseImageArgs();
    $args['latitude'] = null;

    $image = new ImageEntity(...$args);

    expect($image->latitude)->toBeNull();
});

test('constructs with longitude null', function (): void {
    $args = baseImageArgs();
    $args['longitude'] = null;

    $image = new ImageEntity(...$args);

    expect($image->longitude)->toBeNull();
});
