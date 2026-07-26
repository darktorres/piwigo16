<?php

declare(strict_types=1);

use Piwigo\Admin\Request\PictureFormatsImageIdRequest;

test('fromArray parses a numeric image_id', function (): void {
    $request = PictureFormatsImageIdRequest::fromArray(['image_id' => '42']);

    expect($request->imageId)->toBe(42);
});

test('fromArray defaults to 0 when image_id is absent', function (): void {
    $request = PictureFormatsImageIdRequest::fromArray([]);

    expect($request->imageId)->toBe(0);
});

test('fromArray rejects a non-digit image_id', function (): void {
    expect(fn (): PictureFormatsImageIdRequest => PictureFormatsImageIdRequest::fromArray(['image_id' => '1;DROP TABLE']))
        ->toThrow(RuntimeException::class);
});
