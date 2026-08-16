<?php

declare(strict_types=1);

use Piwigo\Category\CategoryEntity;
use Piwigo\Category\CategoryStatus;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Common\ValueObject\SiteId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Image\ImageEntity;

/**
 * A minimal, never-persisted `ImageEntity` standing in for the
 * `representativePicture` association -- `new ImageEntity(...)` is safe
 * here (this test never calls `flush()`), unlike a real write path, which
 * must use `$em->getReference()` instead (see `0.3`'s cascade-persist note).
 */
function representativeImage(): ImageEntity
{
    $image = new ImageEntity(
        file: 'representative.jpg',
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
        path: 'representative.jpg',
        storageCategoryId: null,
        level: 0,
        md5sum: null,
        addedBy: null,
        rotation: null,
        latitude: null,
        longitude: null,
        lastmodified: SqlDateTime::from('2026-07-26 14:30:00'),
    );
    $image->id = ImageId::from(458);

    return $image;
}

/**
 * @return array{name: string, idUppercat: ?int, comment: ?string, dir: ?string, rank: ?int, status: CategoryStatus, siteId: ?SiteId, visible: bool, representativePicture: ?ImageEntity, uppercats: string, commentable: bool, globalRank: ?string, imageOrder: ?string, permalink: ?Permalink, lastmodified: SqlDateTime}
 */
function baseCategoryArgs(): array
{
    return [
        'name' => 'Summer Vacation 2026',
        'idUppercat' => 12,
        'comment' => 'Photos from our trip to the coast',
        'dir' => 'summer-vacation-2026',
        'rank' => 3,
        'status' => CategoryStatus::Public,
        'siteId' => SiteId::from(1),
        'visible' => true,
        'representativePicture' => representativeImage(),
        'uppercats' => '1,12,27',
        'commentable' => false,
        'globalRank' => '000012000027',
        'imageOrder' => 'date_creation DESC',
        'permalink' => Permalink::from('summer-2026'),
        'lastmodified' => SqlDateTime::from('2026-07-26 14:30:00'),
    ];
}

test('constructs with distinct values for every property', function (): void {
    $category = new CategoryEntity(...baseCategoryArgs());

    expect($category->id)
        ->toBeNull()
        ->and($category->name)
        ->toBe('Summer Vacation 2026')
        ->and($category->idUppercat)
        ->toBe(12)
        ->and($category->comment)
        ->toBe('Photos from our trip to the coast')
        ->and($category->dir)
        ->toBe('summer-vacation-2026')
        ->and($category->rank)
        ->toBe(3)
        ->and($category->status)
        ->toBe(CategoryStatus::Public)
        ->and($category->siteId)
        ->toEqual(SiteId::from(1))
        ->and($category->visible)
        ->toBeTrue()
        ->and($category->representativePicture?->id)
        ->toEqual(ImageId::from(458))
        ->and($category->uppercats)
        ->toBe('1,12,27')
        ->and($category->commentable)
        ->toBeFalse()
        ->and($category->globalRank)
        ->toBe('000012000027')
        ->and($category->imageOrder)
        ->toBe('date_creation DESC')
        ->and($category->permalink)
        ->toEqual(Permalink::from('summer-2026'))
        ->and($category->lastmodified)
        ->toEqual(SqlDateTime::from('2026-07-26 14:30:00'));
});

test('id is never assignable through the constructor, always starts null', function (): void {
    $category = new CategoryEntity(...baseCategoryArgs());

    // $id is declared outside the constructor (Doctrine's GeneratedValue PK) --
    // only the ORM's reflection-based hydration ever fills it in.
    expect($category->id)
        ->toBeNull();
});

test('constructs with idUppercat null', function (): void {
    $args = baseCategoryArgs();
    $args['idUppercat'] = null;

    $category = new CategoryEntity(...$args);

    expect($category->idUppercat)
        ->toBeNull();
});

test('constructs with comment null', function (): void {
    $args = baseCategoryArgs();
    $args['comment'] = null;

    $category = new CategoryEntity(...$args);

    expect($category->comment)
        ->toBeNull();
});

test('constructs with dir null', function (): void {
    $args = baseCategoryArgs();
    $args['dir'] = null;

    $category = new CategoryEntity(...$args);

    expect($category->dir)
        ->toBeNull();
});

test('constructs with rank null', function (): void {
    $args = baseCategoryArgs();
    $args['rank'] = null;

    $category = new CategoryEntity(...$args);

    expect($category->rank)
        ->toBeNull();
});

test('constructs with siteId null', function (): void {
    $args = baseCategoryArgs();
    $args['siteId'] = null;

    $category = new CategoryEntity(...$args);

    expect($category->siteId)
        ->toBeNull();
});

test('constructs with representativePicture null', function (): void {
    $args = baseCategoryArgs();
    $args['representativePicture'] = null;

    $category = new CategoryEntity(...$args);

    expect($category->representativePicture)
        ->toBeNull();
});

test('constructs with globalRank null', function (): void {
    $args = baseCategoryArgs();
    $args['globalRank'] = null;

    $category = new CategoryEntity(...$args);

    expect($category->globalRank)
        ->toBeNull();
});

test('constructs with imageOrder null', function (): void {
    $args = baseCategoryArgs();
    $args['imageOrder'] = null;

    $category = new CategoryEntity(...$args);

    expect($category->imageOrder)
        ->toBeNull();
});

test('constructs with permalink null', function (): void {
    $args = baseCategoryArgs();
    $args['permalink'] = null;

    $category = new CategoryEntity(...$args);

    expect($category->permalink)
        ->toBeNull();
});
