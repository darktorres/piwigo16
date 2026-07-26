<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\SiteUpdateRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = SiteUpdateRequest::fromArrays([], []);

    expect($request->siteRaw)->toBeNull()
        ->and($request->quickSyncRequested)->toBeFalse()
        ->and($request->catId)->toBeNull()
        ->and($request->post)->toBe([]);
});

test('fromArrays reads the raw site value', function (): void {
    $request = SiteUpdateRequest::fromArrays(['site' => '2'], []);

    expect($request->siteRaw)->toBe('2');
});

test('fromArrays reports quickSyncRequested when quick_sync is present', function (): void {
    $request = SiteUpdateRequest::fromArrays(['quick_sync' => '1'], []);

    expect($request->quickSyncRequested)->toBeTrue();
});

test('fromArrays validates and parses a numeric cat_id', function (): void {
    $request = SiteUpdateRequest::fromArrays(['cat_id' => '42'], []);

    expect($request->catId)->toBe('42');
});

test('fromArrays rejects a non-digit cat_id', function (): void {
    expect(fn (): SiteUpdateRequest => SiteUpdateRequest::fromArrays(['cat_id' => 'abc'], []))
        ->toThrow(RuntimeException::class);
});

test('fromArrays retains the full raw post bag', function (): void {
    $request = SiteUpdateRequest::fromArrays([], ['sync' => 'files', 'cat' => '5']);

    expect($request->post)->toBe(['sync' => 'files', 'cat' => '5']);
});
