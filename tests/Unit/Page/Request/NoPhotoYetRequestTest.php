<?php

declare(strict_types=1);

use Piwigo\Page\Request\NoPhotoYetRequest;

test('fromArray returns null for an empty GET', function (): void {
    $request = NoPhotoYetRequest::fromArray([]);

    expect($request->action)
        ->toBeNull();
});

test('fromArray reads the no_photo_yet action', function (): void {
    $browse = NoPhotoYetRequest::fromArray([
        'no_photo_yet' => 'browse',
    ]);
    $deactivate = NoPhotoYetRequest::fromArray([
        'no_photo_yet' => 'deactivate',
    ]);

    expect($browse->action)
        ->toBe('browse')
        ->and($deactivate->action)
        ->toBe('deactivate');
});

test('fromArray narrows a non-string value to null', function (): void {
    $request = NoPhotoYetRequest::fromArray([
        'no_photo_yet' => ['nested'],
    ]);

    expect($request->action)
        ->toBeNull();
});

test('fromGlobals reads the action from the real $_GET superglobal', function (): void {
    $original = $_GET;
    $_GET['no_photo_yet'] = 'browse';

    try {
        $request = NoPhotoYetRequest::fromGlobals();

        expect($request->action)
            ->toBe('browse');
    } finally {
        $_GET = $original;
    }
});
