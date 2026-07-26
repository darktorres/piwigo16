<?php

declare(strict_types=1);

use Piwigo\Core\Request\LoungeMaintenanceRequest;

test('fromArray returns null for an empty REQUEST', function (): void {
    $request = LoungeMaintenanceRequest::fromArray([]);

    expect($request->requestMethod)->toBeNull();
});

test('fromArray reads the ws method', function (): void {
    $request = LoungeMaintenanceRequest::fromArray(['method' => 'pwg.images.upload']);

    expect($request->requestMethod)->toBe('pwg.images.upload');
});

test('fromArray narrows a non-string value to null', function (): void {
    $request = LoungeMaintenanceRequest::fromArray(['method' => ['nested']]);

    expect($request->requestMethod)->toBeNull();
});
