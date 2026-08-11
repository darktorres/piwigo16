<?php

declare(strict_types=1);

use Piwigo\Controller\Request\ImageDerivativeRequest;

test('fromArray detects the cache-busting b param regardless of its value', function (): void {
    $request = ImageDerivativeRequest::fromArray([
        'b' => '',
    ]);

    expect($request->hasCacheBust)
        ->toBeTrue();
});

test('fromArray reports no cache-busting when b is absent', function (): void {
    $request = ImageDerivativeRequest::fromArray([]);

    expect($request->hasCacheBust)
        ->toBeFalse();
});

test('fromArray recognizes ajaxload=true', function (): void {
    $request = ImageDerivativeRequest::fromArray([
        'ajaxload' => 'true',
    ]);

    expect($request->isAjaxLoad)
        ->toBeTrue();
});

test('fromArray treats any other ajaxload value as not ajax', function (): void {
    $request = ImageDerivativeRequest::fromArray([
        'ajaxload' => '1',
    ]);

    expect($request->isAjaxLoad)
        ->toBeFalse();
});
