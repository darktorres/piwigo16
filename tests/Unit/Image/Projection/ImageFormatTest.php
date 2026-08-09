<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Image\ImageFormatEntity;
use Piwigo\Image\Projection\ImageFormat;

/**
 * Piwigo\Image\Projection\ImageFormat -- had zero dedicated coverage.
 * Same fromRow()/fromEntity()/toArray() shape already established for
 * UserInfo/Plugin/ApiKey's own projection tests --
 * fromRow() itself has no real production caller (see this class's own
 * docblock: current readers go through fromEntity() instead), but stays
 * dead-but-callable, same documented shape as PluginConfig\Projection\
 * Plugin's own fromRow()/toArray().
 */
test('fromRow narrows a full real row', function (): void {
    $format = ImageFormat::fromRow([
        'format_id' => '3',
        'image_id' => '10',
        'ext' => 'raw',
        'filesize' => '2048',
    ]);

    expect($format->formatId)->toBe(3);
    expect($format->imageId)->toEqual(ImageId::from(10));
    expect($format->ext)->toBe('raw');
    expect($format->filesize)->toBe(2048);
});

/**
 * `image_id` is a NOT NULL DB column, so a real row always carries a
 * positive int -- the same "never actually reachable against a real DB
 * row" case {@see \Piwigo\Image\Projection\Image::fromRow()}'s own
 * docblock documents for its own NOT-NULL-column defaults. ImageFormat's
 * `imageId` is the ImageId VO, whose own `from()` rejects a non-positive
 * value, so a genuinely malformed/empty row throws here instead of
 * silently defaulting to imageId=0, since 0 was never a valid ImageId to
 * begin with. fromRow() has no real caller (see this class's own
 * docblock), so this documents the real current behavior rather than
 * adding dead defensive code for a row shape no real caller ever produces.
 */
test('fromRow throws for a missing/malformed row, image_id has no valid zero-default', function (): void {
    expect(fn () => ImageFormat::fromRow([]))
        ->toThrow(InvalidArgumentException::class, 'ImageId must be a positive integer, got 0');
});

test('fromRow defaults format_id to 0 and ext to an empty string when the row omits them', function (): void {
    // Kills fromRow()'s DecrementInteger/IncrementInteger on format_id's
    // `: 0` fallback (mutated to -1/1) and EmptyStringToNotEmpty on ext's
    // `: ''` fallback -- the "malformed row" test above never observes
    // either default: it passes a totally empty row, so
    // ImageId::from(0) throws before the constructor call even
    // completes, discarding both already-computed default values before
    // either is ever asserted. Here image_id is real and valid, so
    // construction succeeds and both defaults are directly observable.
    $format = ImageFormat::fromRow([
        'image_id' => '4',
    ]);

    expect($format->formatId)->toBe(0);
    expect($format->ext)->toBe('');
});

test('fromEntity copies every field from a real ImageFormatEntity', function (): void {
    $entity = new ImageFormatEntity(
        imageId: ImageId::from(5),
        ext: 'jpg',
        filesize: 1024,
    );
    $entity->formatId = 7;

    $format = ImageFormat::fromEntity($entity);

    expect($format->formatId)->toBe(7);
    expect($format->imageId)->toEqual(ImageId::from(5));
    expect($format->ext)->toBe('jpg');
    expect($format->filesize)->toBe(1024);
});

test('fromEntity defaults formatId to 0 when the entity has none yet', function (): void {
    $entity = new ImageFormatEntity(
        imageId: ImageId::from(1),
        ext: 'png',
        filesize: null,
    );

    expect(ImageFormat::fromEntity($entity)->formatId)->toBe(0);
    expect(ImageFormat::fromEntity($entity)->filesize)->toBeNull();
});

test('toArray round-trips every field', function (): void {
    $format = new ImageFormat(
        formatId: 1,
        imageId: ImageId::from(2),
        ext: 'webp',
        filesize: 512,
    );

    expect($format->toArray())->toBe([
        'format_id' => 1,
        'image_id' => 2,
        'ext' => 'webp',
        'filesize' => 512,
    ]);
});
