<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Image\ImageEntity;
use Piwigo\Metadata\Projection\MetadataImage;

/**
 * A transient (never-persisted, real-looking scalar values) ImageEntity
 * fixture, own-named to avoid colliding with
 * tests/Unit/Image/Projection/ImageTest.php's own global
 * transientImageEntity() helper.
 */
function metadataImageTestEntity(?string $representativeExt = 'jpg'): ImageEntity
{
    $entity = new ImageEntity(
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
        representativeExt: $representativeExt,
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
    $entity->id = ImageId::from(42);

    return $entity;
}

test('fromEntity copies id/path/representativeExt straight through', function (): void {
    $image = MetadataImage::fromEntity(metadataImageTestEntity());

    expect($image->id)->toBe(42)
        ->and($image->path)->toBe('upload/2026/08/01/photo.jpg')
        ->and($image->representativeExt)->toBe('jpg');
});

test('fromEntity defaults id to 0 for a transient (never-persisted) entity', function (): void {
    $entity = metadataImageTestEntity();
    $entity->id = null;

    expect(MetadataImage::fromEntity($entity)->id)->toBe(0);
});

test('fromEntity leaves a null representativeExt as null', function (): void {
    expect(MetadataImage::fromEntity(metadataImageTestEntity(null))->representativeExt)->toBeNull();
});

test('toArray round-trips the exact same shape fromEntity built', function (): void {
    $roundTripped = MetadataImage::fromEntity(metadataImageTestEntity())->toArray();

    expect($roundTripped)->toBe([
        'id' => 42,
        'path' => 'upload/2026/08/01/photo.jpg',
        'representative_ext' => 'jpg',
    ]);
});
