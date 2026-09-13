<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Metadata\Projection\MetadataImage;

test('fromRow copies id/path/representativeExt straight through', function (): void {
    $image = MetadataImage::fromRow([
        'id' => ImageId::from(42),
        'path' => 'upload/2026/08/01/photo.jpg',
        'representativeExt' => 'jpg',
    ]);

    expect($image)
        ->not->toBeNull();
    if ($image === null) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    expect($image->id)
        ->toBe(42)
        ->and($image->path)
        ->toBe('upload/2026/08/01/photo.jpg')
        ->and($image->representativeExt)
        ->toBe('jpg');
});

test('fromRow leaves a null representativeExt as null', function (): void {
    $image = MetadataImage::fromRow([
        'id' => ImageId::from(42),
        'path' => 'upload/2026/08/01/photo.jpg',
        'representativeExt' => null,
    ]);

    expect($image?->representativeExt)
        ->toBeNull();
});

test('fromRow returns null when id is not a real ImageId', function (): void {
    // getArrayResult() still applies DBAL's custom Type conversion, so a
    // real caller's own `id` is always an ImageId -- this proves the
    // narrowing boundary itself, not a scenario either real caller's
    // fixed SELECT can actually produce.
    expect(MetadataImage::fromRow([
        'id' => 42,
        'path' => 'upload/2026/08/01/photo.jpg',
        'representativeExt' => null,
    ]))->toBeNull();
});

test('fromRow returns null when path is missing', function (): void {
    expect(MetadataImage::fromRow([
        'id' => ImageId::from(42),
        'representativeExt' => null,
    ]))->toBeNull();
});

test('toArray round-trips the exact same shape fromRow built', function (): void {
    $image = MetadataImage::fromRow([
        'id' => ImageId::from(42),
        'path' => 'upload/2026/08/01/photo.jpg',
        'representativeExt' => 'jpg',
    ]);

    expect($image?->toArray())
        ->toBe([
            'id' => 42,
            'path' => 'upload/2026/08/01/photo.jpg',
            'representative_ext' => 'jpg',
        ]);
});
