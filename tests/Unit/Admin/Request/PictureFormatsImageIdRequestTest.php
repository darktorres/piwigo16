<?php

declare(strict_types=1);

use Piwigo\Admin\Request\PictureFormatsImageIdRequest;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Validation\InputValidator;

test('fromArray parses a numeric image_id', function (): void {
    $request = PictureFormatsImageIdRequest::fromArray([
        'image_id' => '42',
    ], new InputValidator());

    expect($request->imageId)
        ->toEqual(ImageId::from(42));
});

test('fromArray defaults to null when image_id is absent', function (): void {
    $request = PictureFormatsImageIdRequest::fromArray([], new InputValidator());

    expect($request->imageId)
        ->toBeNull();
});

test('fromArray rejects a non-digit image_id', function (): void {
    expect(fn (): PictureFormatsImageIdRequest => PictureFormatsImageIdRequest::fromArray([
        'image_id' => '1;DROP TABLE',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});
