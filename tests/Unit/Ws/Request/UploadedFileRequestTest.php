<?php

declare(strict_types=1);

use Piwigo\Ws\Request\UploadedFileRequest;

test('fromArray returns absent for a null file', function (): void {
    $request = UploadedFileRequest::fromArray(null);

    expect($request->present)
        ->toBeFalse()
        ->and($request->error)
        ->toBeNull()
        ->and($request->tmpName)
        ->toBeNull()
        ->and($request->name)
        ->toBeNull();
});

test('fromArray parses a well-formed uploaded file', function (): void {
    $request = UploadedFileRequest::fromArray([
        'error' => 0,
        'tmp_name' => '/tmp/php1234',
        'name' => 'photo.jpg',
    ]);

    expect($request->present)
        ->toBeTrue()
        ->and($request->error)
        ->toBe(0)
        ->and($request->tmpName)
        ->toBe('/tmp/php1234')
        ->and($request->name)
        ->toBe('photo.jpg');
});

test('fromArray narrows non-int error and non-string tmp_name/name to null', function (): void {
    $request = UploadedFileRequest::fromArray([
        'error' => 'oops',
        'tmp_name' => ['nested'],
        'name' => 123,
    ]);

    expect($request->present)
        ->toBeTrue()
        ->and($request->error)
        ->toBeNull()
        ->and($request->tmpName)
        ->toBeNull()
        ->and($request->name)
        ->toBeNull();
});
