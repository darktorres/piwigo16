<?php

declare(strict_types=1);

use Piwigo\Image\Request\EmptyLoungeRequest;

test('fromArray returns null for an empty REQUEST', function (): void {
    $request = EmptyLoungeRequest::fromArray([]);

    expect($request->requestMethod)
        ->toBeNull();
});

test('fromArray reads the ws method', function (): void {
    $request = EmptyLoungeRequest::fromArray([
        'method' => 'pwg.images.emptyLounge',
    ]);

    expect($request->requestMethod)
        ->toBe('pwg.images.emptyLounge');
});

test('fromArray narrows a non-string value to null', function (): void {
    $request = EmptyLoungeRequest::fromArray([
        'method' => ['nested'],
    ]);

    expect($request->requestMethod)
        ->toBeNull();
});
