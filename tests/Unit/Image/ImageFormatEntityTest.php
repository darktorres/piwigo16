<?php

declare(strict_types=1);

use Piwigo\Image\ImageFormatEntity;

test('constructs with distinct values for every property', function (): void {
    $imageFormat = new ImageFormatEntity(imageId: 88, ext: 'webp', filesize: 204800);

    expect($imageFormat->formatId)->toBeNull()
        ->and($imageFormat->imageId)->toBe(88)
        ->and($imageFormat->ext)->toBe('webp')
        ->and($imageFormat->filesize)->toBe(204800);
});

test('formatId is never assignable through the constructor, always starts null', function (): void {
    $imageFormat = new ImageFormatEntity(imageId: 88, ext: 'webp', filesize: 204800);

    // $formatId is declared outside the constructor (Doctrine's GeneratedValue
    // PK) -- only the ORM's reflection-based hydration ever fills it in.
    expect($imageFormat->formatId)->toBeNull();
});

test('constructs with filesize null', function (): void {
    $imageFormat = new ImageFormatEntity(imageId: 88, ext: 'webp', filesize: null);

    expect($imageFormat->filesize)->toBeNull()
        ->and($imageFormat->imageId)->toBe(88)
        ->and($imageFormat->ext)->toBe('webp');
});
